<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\Skill;
use App\Models\User;
use App\Services\CharacterIconDesignService;
use App\Services\ExtraContentControlService;
use App\Services\JobArtService;
use App\Services\JobArtUpdateReadService;
use App\Services\MyHomeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobArtUpdateReadTest extends TestCase
{
    private const REVISION = '2026-10-12-defense-v1';

    private User $player;

    private JobArtUpdateReadService $reads;

    protected function setUp(): void
    {
        parent::setUp();
        $updates = config('job_art_updates');
        foreach ($updates as &$versions) { foreach ($versions as &$update) { $update['status'] = 'unreleased'; $update['published_at'] = null; } }
        config(['job_art_updates' => $updates]);
        foreach (['job_art_update_reads', 'characters', 'skills', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        Schema::create('characters', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
        });
        Schema::create('skills', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('job_id');
            $t->string('name');
            $t->string('skill_type');
            $t->unsignedTinyInteger('learn_rank');
        });
        (require database_path('migrations/2026_10_13_000000_create_job_art_update_reads_table.php'))->up();
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('characters')->insert([['id' => 11, 'user_id' => 1], ['id' => 12, 'user_id' => 1], ['id' => 21, 'user_id' => 2]]);
        DB::table('skills')->insert([
            ['id' => 88, 'job_id' => 15, 'name' => '不落要塞', 'skill_type' => 'job_art', 'learn_rank' => 9],
            ['id' => 103, 'job_id' => 20, 'name' => '大商隊の守護', 'skill_type' => 'job_art', 'learn_rank' => 9],
            ['id' => 106, 'job_id' => 21, 'name' => '金剛不壊', 'skill_type' => 'job_art', 'learn_rank' => 9],
            ['id' => 99, 'job_id' => 15, 'name' => '別の技', 'skill_type' => 'job_art', 'learn_rank' => 1],
        ]);
        $this->player = User::findOrFail(1);
        $this->reads = new JobArtUpdateReadService;
        // Availability is independently covered by JobArtService tests. Keep real auth,
        // binding, revision validation and persistence in these endpoint tests.
        $this->app->instance(JobArtService::class, new class extends JobArtService
        {
            public bool $accessible = true;

            public ?array $skillsByContext = null;

            public array $requestedContexts = [];

            public function availableArts(Character $character, string $context = 'pve'): Collection
            {
                $this->requestedContexts[] = $context;

                return ! $this->accessible ? collect() : ($this->skillsByContext === null
                    ? Skill::all() : Skill::whereIn('id', $this->skillsByContext[$context] ?? [])->get());
            }
        });
    }

    public function test_only_opened_art_is_read_and_receipt_survives_character_switch(): void
    {
        $arts = Skill::all();
        $this->reads->decorate($arts, 1);
        $this->assertTrue($arts->find(88)->job_art_update['unread']);
        $this->assertDatabaseCount('job_art_update_reads', 0);
        $url = route('job-arts.update-read', 88);
        $this->actingAs($this->player)->postJson($url, ['revision' => self::REVISION])->assertOk()->assertJson(['unread' => false]);
        $this->withSession(['current_character_id' => 12])->postJson($url, ['revision' => self::REVISION])->assertOk();
        $this->assertDatabaseCount('job_art_update_reads', 1);
        $this->reads->decorate($arts, 1);
        $this->assertFalse($arts->find(88)->job_art_update['unread']);
        $this->assertTrue($arts->find(103)->job_art_update['unread']);
        $this->reads->decorate($arts, 2);
        $this->assertTrue($arts->find(88)->job_art_update['unread']);
    }

    public function test_new_revision_becomes_unread_and_old_screen_cannot_clear_it(): void
    {
        $url = route('job-arts.update-read', 88);
        $this->actingAs($this->player)->postJson($url, ['revision' => self::REVISION])->assertOk();
        config(['job_art_updates.15:9:不落要塞.0.revision' => '2026-10-13-defense-v2']);
        $arts = Skill::all();
        $this->reads->decorate($arts, 1);
        $this->assertTrue($arts->find(88)->job_art_update['unread']);
        $this->postJson($url, ['revision' => self::REVISION])->assertStatus(409);
        $this->assertDatabaseCount('job_art_update_reads', 1);
        $this->postJson($url, ['revision' => '2026-10-13-defense-v2'])->assertOk();
        $this->assertDatabaseCount('job_art_update_reads', 2);
    }

    public function test_auth_availability_identity_and_post_are_required(): void
    {
        $url = route('job-arts.update-read', 88);
        $this->postJson($url, ['revision' => self::REVISION])->assertRedirect('/');
        $this->actingAs($this->player)->getJson($url)->assertStatus(405);
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson(route('job-arts.update-read', 99), ['revision' => self::REVISION])->assertNotFound();
        $this->app->make(JobArtService::class)->accessible = false;
        $this->postJson($url, ['revision' => self::REVISION])->assertForbidden();
        $this->assertDatabaseCount('job_art_update_reads', 0);
        $this->assertNull($this->reads->updateFor(new Skill(['job_id' => 15, 'learn_rank' => 9, 'name' => '不落要塞', 'skill_type' => 'passive'])));
    }

    public function test_receipts_are_batched_for_many_cards_and_all_context_copies(): void
    {
        $this->actingAs($this->player)->postJson(route('job-arts.update-read', 88), ['revision' => self::REVISION])->assertOk();
        $arts = collect();
        for ($i = 0; $i < 282; $i++) {
            $arts->push(Skill::findOrFail(88));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->reads->decorate($arts, 1);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'select * from "job_art_update_reads"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertTrue($arts->every(fn ($art) => $art->job_art_update['unread'] === false));
    }

    public function test_missing_table_keeps_unread_and_rejects_read_save(): void
    {
        Schema::drop('job_art_update_reads');
        $arts = Skill::all();
        $this->reads->decorate($arts, 1);
        $this->assertTrue($arts->find(88)->job_art_update['unread']);
        $this->actingAs($this->player)->postJson(route('job-arts.update-read', 88), ['revision' => self::REVISION])->assertStatus(503);
    }

    public function test_explanation_is_closed_initially_and_badge_is_hidden_only_after_receipt(): void
    {
        $art = Skill::findOrFail(88);
        $this->reads->decorate(collect([$art]), 1);
        $html = view('job-arts.partials.update-explanation', ['art' => $art, 'effectDescription' => '30%軽減'])->render();
        $this->assertStringContainsString('data-unread="true"', $html);
        $this->assertStringNotContainsString('<details open', $html);
        $this->assertStringContainsString('効果解説を見る', $html);
        $this->assertStringContainsString('30%軽減', $html);
        $this->assertDatabaseCount('job_art_update_reads', 0);
        $this->actingAs($this->player)->postJson(route('job-arts.update-read', 88), ['revision' => self::REVISION])->assertOk();
        $this->reads->decorate(collect([$art]), 1);
        $html = view('job-arts.partials.update-explanation', ['art' => $art, 'effectDescription' => '30%軽減'])->render();
        $this->assertStringContainsString('data-unread="false"', $html);
        $this->assertStringContainsString('hidden" data-job-art-update-unread', $html);
    }

    public function test_cannot_use_another_players_character_to_gain_read_access(): void
    {
        DB::table('characters')->where('user_id', 1)->delete();
        $this->actingAs($this->player)->withSession(['current_character_id' => 21])
            ->postJson(route('job-arts.update-read', 88), ['revision' => self::REVISION])->assertForbidden();
        $this->assertDatabaseCount('job_art_update_reads', 0);
    }

    public function test_entrypoint_summary_stays_unread_until_all_available_updates_are_read(): void
    {
        $character = Character::findOrFail(11);
        $this->assertTrue($this->reads->hasUnreadForCharacter($character));
        $this->reads->markRead(1, Skill::findOrFail(88), self::REVISION);
        $this->assertTrue($this->reads->hasUnreadForCharacter($character));
        foreach ([103, 106] as $id) {
            $this->reads->markRead(1, Skill::findOrFail($id), self::REVISION);
        }
        $this->assertFalse($this->reads->hasUnreadForCharacter($character));
        $this->assertTrue($this->reads->hasUnreadForCharacter(Character::findOrFail(21)));
        config(['job_art_updates.15:9:不落要塞.0.revision' => 'next-update']);
        $this->assertTrue($this->reads->hasUnreadForCharacter($character));
        $this->assertDatabaseCount('job_art_update_reads', 3);
    }

    public function test_summary_includes_boss_and_pvp_and_batches_the_receipt_query(): void
    {
        config(['battle.job_art_v2.pvp_set' => true]);
        $jobArts = $this->app->make(JobArtService::class);
        $jobArts->skillsByContext = ['pve' => [99], 'boss' => [88], 'champ' => [103]];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($this->reads->hasUnreadForCharacter(Character::findOrFail(11)));
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'select * from "job_art_update_reads"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(['pve', 'boss', 'champ'], $jobArts->requestedContexts);
        foreach ([88, 103] as $id) {
            $this->reads->markRead(1, Skill::findOrFail($id), self::REVISION);
        }
        // The unlearned 106 must not leave a dot that this character cannot clear.
        $this->assertFalse($this->reads->hasUnreadForCharacter(Character::findOrFail(11)));
        $jobArts->accessible = false;
        $this->assertFalse($this->reads->hasUnreadForCharacter(Character::findOrFail(11)));
    }

    public function test_no_visible_updates_skips_home_availability_queries(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse($this->reads->hasUnreadForCharacter(Character::findOrFail(11)));
        $this->assertSame([], $this->app->make(JobArtService::class)->requestedContexts);
        $this->app->detectEnvironment(fn () => 'testing');
        config(['job_art_updates' => []]);
        $this->assertFalse($this->reads->hasUnreadForCharacter(Character::findOrFail(11)));
        $this->assertSame([], $this->app->make(JobArtService::class)->requestedContexts);
    }

    public function test_home_menu_uses_receipts_and_dot_partial_renders_only_when_unread(): void
    {
        foreach ([MyHomeService::class => 'enabled',
            ExtraContentControlService::class => 'isActive',
            CharacterIconDesignService::class => 'isEnabled'] as $service => $method) {
            $this->app->instance($service, \Mockery::mock($service)->shouldReceive($method)->andReturn(false)->getMock());
        }
        $home = new MainScreen;
        $home->character = Character::findOrFail(11);
        $menu = new \ReflectionMethod($home, 'homeMenuItems');
        $item = collect($menu->invoke($home))->firstWhere('route', 'job-arts.index');
        $this->assertTrue($item['has_unread_job_art_updates']);
        $html = view('job-arts.partials.update-unread-dot', ['hasUnreadJobArtUpdates' => true])->render();
        $this->assertStringContainsString('data-job-art-update-unread-dot', $html);
        $this->assertStringContainsString('未読の戦技更新があります', $html);
        foreach ([88, 103, 106] as $id) {
            $this->reads->markRead(1, Skill::findOrFail($id), self::REVISION);
        }
        $item = collect($menu->invoke($home))->firstWhere('route', 'job-arts.index');
        $this->assertFalse($item['has_unread_job_art_updates']);
        $html = view('job-arts.partials.update-unread-dot', ['hasUnreadJobArtUpdates' => false])->render();
        $this->assertStringNotContainsString('data-job-art-update-unread-dot', $html);
        $home->character = null;
        $this->assertFalse(collect($menu->invoke($home))->firstWhere('route', 'job-arts.index')['has_unread_job_art_updates']);
    }
}
