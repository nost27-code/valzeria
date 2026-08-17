<?php

namespace Tests\Feature;

use App\Livewire\Admin\JobArtAnalyticsManager;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\CharacterJobArtContextSetting;
use App\Models\CharacterJobArtSlot;
use App\Models\JobClass;
use App\Models\Skill;
use App\Models\User;
use App\Services\Admin\JobArtAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminJobArtAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_reports_context_order_pairs_and_available_owner_adoption(): void
    {
        [$admin, $sourceJob, $otherJob, $opening, $link, $unused, $bossArt] = $this->masterData();

        $first = $this->createPlayer('第一冒険者', $sourceJob, 120, 80, 20);
        $second = $this->createPlayer('第二冒険者', $otherJob, 140, 60, 40);
        $withoutSet = $this->createPlayer('未設定冒険者', $sourceJob, 110, 10, 5);
        $this->learnJob($first, $sourceJob, 10, true);
        $this->learnJob($second, $otherJob, 10, true);
        $this->learnJob($second, $sourceJob, 10, true);
        $this->learnJob($withoutSet, $sourceJob, 10, true);

        $this->setSlots($first, 'normal', [$opening, $link]);
        $this->setSlots($second, 'normal', [$link, $opening]);
        $this->setSlots($first, 'boss', [$bossArt]);
        CharacterJobArtContextSetting::query()->create([
            'character_id' => $first->id,
            'battle_context' => 'normal',
            'sp_policy' => 'conserve',
        ]);

        $adminCharacter = Character::query()->create([
            'user_id' => $admin->id,
            'name' => '管理者キャラ',
            'current_job_id' => $sourceJob->id,
            'last_seen_at' => now(),
        ]);
        $this->learnJob($adminCharacter, $sourceJob, 10, true);
        $this->setSlots($adminCharacter, 'normal', [$opening]);

        $tester = User::factory()->create([
            'role' => 'user',
            'email' => 'tester_job_art_analytics@valzeria.local',
        ]);
        $testerCharacter = Character::query()->create([
            'user_id' => $tester->id,
            'name' => 'テスターキャラ',
            'current_job_id' => $sourceJob->id,
            'last_seen_at' => now(),
        ]);
        $this->learnJob($testerCharacter, $sourceJob, 10, true);
        $this->setSlots($testerCharacter, 'normal', [$opening]);

        $analysis = app(JobArtAnalyticsService::class)->analyze([
            'battle_context' => 'normal',
            'activity_window' => '30',
            'art_sort' => 'popular',
        ]);

        $this->assertTrue($analysis['ready']);
        $this->assertSame(3, $analysis['cards']['cohort_players']);
        $this->assertSame(2, $analysis['cards']['configured_players']);
        $this->assertSame(2, $analysis['cards']['unique_loadouts']);

        $arts = collect($analysis['artRows'])->keyBy('skill_id');
        $this->assertSame(2, $arts[$opening->id]['selected_count']);
        $this->assertSame(3, $arts[$opening->id]['eligible_count']);
        $this->assertSame(66.7, $arts[$opening->id]['eligible_adoption_rate']);
        $this->assertSame('分析の構えの効果説明', $arts[$opening->id]['effect_description']);
        $this->assertSame(1, $arts[$opening->id]['slot_counts'][1]);
        $this->assertSame(1, $arts[$opening->id]['slot_counts'][2]);
        $this->assertSame(0, $arts[$unused->id]['selected_count']);
        $this->assertSame(3, $arts[$unused->id]['eligible_count']);
        $this->assertArrayNotHasKey($bossArt->id, $arts);

        $this->assertCount(2, $analysis['loadoutRows']);
        $this->assertSame(2, $analysis['pairRows'][0]['count']);
        $this->assertEqualsCanonicalizing(
            [$opening->name, $link->name],
            [$analysis['pairRows'][0]['first_name'], $analysis['pairRows'][0]['second_name']],
        );

        $players = collect($analysis['playerRows'])->keyBy('name');
        $this->assertSame([$opening->name, $link->name], collect($players['第一冒険者']['slots'])->pluck('name')->all());
        $this->assertSame([$link->name, $opening->name], collect($players['第二冒険者']['slots'])->pluck('name')->all());
        $this->assertSame('温存', $players['第一冒険者']['sp_policy_label']);
        $this->assertFalse($players->has('管理者キャラ'));
        $this->assertFalse($players->has('テスターキャラ'));

        $lowAdoption = app(JobArtAnalyticsService::class)->analyze([
            'battle_context' => 'normal',
            'activity_window' => '30',
            'art_sort' => 'low',
        ]);
        $this->assertSame($unused->id, $lowAdoption['artRows'][0]['skill_id']);

        $exportRows = app(JobArtAnalyticsService::class)->exportPlayerRows([
            'battle_context' => 'normal',
            'activity_window' => '30',
        ]);
        $this->assertCount(2, $exportRows);
        $this->assertSame(
            [$opening->name, $link->name],
            collect($exportRows[0]['slots'])->pluck('name')->all(),
        );
    }

    public function test_admin_page_is_protected_and_can_switch_contexts(): void
    {
        [$admin, $sourceJob, , $opening, , , $bossArt] = $this->masterData();
        $player = $this->createPlayer('画面確認冒険者', $sourceJob, 100, 7, 3);
        $this->learnJob($player, $sourceJob, 10, true);
        $this->setSlots($player, 'normal', [$opening]);
        $this->setSlots($player, 'boss', [$bossArt]);

        $this->get(route('admin.job-art-analytics'))->assertRedirect();

        $normalUser = User::factory()->create(['role' => 'user']);
        $this->actingAs($normalUser)
            ->get(route('admin.job-art-analytics'))
            ->assertRedirect('/admin/login');

        $this->actingAs($admin)
            ->get(route('admin.job-art-analytics'))
            ->assertOk()
            ->assertSee('戦技メタ分析')
            ->assertSee('プレイヤー別CSV');

        Livewire::actingAs($admin)
            ->test(JobArtAnalyticsManager::class)
            ->assertSee($opening->name)
            ->assertSeeHtml('data-job-art-effect-tooltip="'.$opening->id.'"')
            ->assertSee('効果：'.$opening->memo)
            ->set('battleContext', 'boss')
            ->assertSee($bossArt->name)
            ->assertDontSee($opening->name);
    }

    /**
     * @return array{User, JobClass, JobClass, Skill, Skill, Skill, Skill}
     */
    private function masterData(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sourceJob = JobClass::query()->create([
            'key' => 'analytics-source-'.str()->random(8),
            'name' => '分析剣士',
            'rank' => 'basic',
            'max_job_level' => 10,
        ]);
        $otherJob = JobClass::query()->create([
            'key' => 'analytics-other-'.str()->random(8),
            'name' => '分析賢者',
            'rank' => 'basic',
            'max_job_level' => 10,
        ]);

        $opening = $this->createArt($sourceJob, '分析の構え', 1, true, false, true);
        $link = $this->createArt($sourceJob, '分析連撃', 5, true, false, true);
        $unused = $this->createArt($sourceJob, '未採用の奥義', 9, true, false, true);
        $bossArt = $this->createArt($sourceJob, '対ボス分析技', 1, false, true, true);

        return [$admin, $sourceJob, $otherJob, $opening, $link, $unused, $bossArt];
    }

    private function createArt(JobClass $job, string $name, int $rank, bool $pve, bool $boss, bool $champ): Skill
    {
        return Skill::query()->create([
            'job_id' => $job->id,
            'name' => $name,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'limit_group' => 'NONE',
            'inherit_on_master' => true,
            'pve_enabled' => $pve,
            'boss_enabled' => $boss,
            'champ_enabled' => $champ,
            'memo' => $name.'の効果説明',
        ]);
    }

    private function createPlayer(string $name, JobClass $job, int $level, int $wins, int $losses): Character
    {
        $user = User::factory()->create(['role' => 'user']);

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'level' => $level,
            'current_job_id' => $job->id,
            'wins' => $wins,
            'losses' => $losses,
            'last_seen_at' => now(),
        ]);
    }

    private function learnJob(Character $character, JobClass $job, int $level, bool $mastered): void
    {
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $job->id,
            'job_level' => $level,
            'job_exp' => 0,
            'is_mastered' => $mastered,
            'mastered_at' => $mastered ? now() : null,
        ]);
    }

    /** @param  array<int, Skill>  $arts */
    private function setSlots(Character $character, string $context, array $arts): void
    {
        foreach (array_values($arts) as $index => $art) {
            CharacterJobArtSlot::query()->create([
                'character_id' => $character->id,
                'battle_context' => $context,
                'slot_no' => $index + 1,
                'skill_id' => $art->id,
                'activation_policy' => 'normal',
                'condition_key' => 'always',
            ]);
        }
    }
}
