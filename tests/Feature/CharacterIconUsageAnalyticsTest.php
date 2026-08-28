<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Character;
use App\Models\CharacterIconEntitlement;
use App\Models\User;
use App\Services\Admin\CharacterIconUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CharacterIconUsageAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_current_icons_and_excludes_admin_and_tester_characters(): void
    {
        $firstUser = User::factory()->create(['role' => 'user']);
        $secondUser = User::factory()->create(['role' => 'user']);
        $thirdUser = User::factory()->create(['role' => 'user']);
        $unknownIconUser = User::factory()->create(['role' => 'user']);
        $exclusiveIconUser = User::factory()->create(['role' => 'user']);
        $adminUser = User::factory()->create(['role' => 'admin']);
        $testerUser = User::factory()->create([
            'role' => 'user',
            'email' => 'tester_icon_usage@valzeria.local',
        ]);

        $this->createCharacter($firstUser, '/images/chara/chara_002.webp');
        $this->createCharacter($secondUser, '/images/chara/chara_002.webp');
        $this->createCharacter($thirdUser, null);
        $this->createCharacter($unknownIconUser, '/images/chara/chara_999.webp');
        $exclusiveCharacter = $this->createCharacter(
            $exclusiveIconUser,
            '/images/chara/exclusive/exclusive_000/01_normal.webp'
        );
        CharacterIconEntitlement::query()->create([
            'character_id' => $exclusiveCharacter->id,
            'icon_set_key' => 'exclusive_000',
            'granted_at' => now(),
        ]);
        $this->createCharacter($adminUser, '/images/chara/chara_003.webp');
        $this->createCharacter($testerUser, '/images/chara/chara_004.webp');

        $summary = app(CharacterIconUsageService::class)->summary();
        $rows = collect($summary['rows'])->keyBy('number');

        $this->assertSame(5, $summary['total_characters']);
        $this->assertSame(270, $summary['selectable_icon_count']);
        $this->assertSame(2, $summary['used_icon_count']);
        $this->assertSame(268, $summary['unused_icon_count']);
        $this->assertSame(1, $summary['exclusive_character_count']);
        $this->assertSame(1, $summary['unrecognized_character_count']);
        $this->assertSame(1, $rows[1]['count']);
        $this->assertSame(2, $rows[2]['count']);
        $this->assertSame(0, $rows[3]['count']);
        $this->assertSame(0, $rows[4]['count']);
    }

    public function test_admin_can_open_and_filter_character_icon_usage_statistics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'user']);
        $this->createCharacter($player, '/images/chara/chara_270.webp');

        Livewire::actingAs($admin)
            ->test(AdminDashboard::class)
            ->assertSee('キャラ画像利用統計')
            ->assertSee('現在の統計を見る')
            ->call('toggleCharacterIconUsage')
            ->assertSee('集計キャラクター')
            ->assertSee('#001')
            ->call('setCharacterIconUsageFilter', 'used')
            ->assertSee('#270')
            ->assertSee('1人')
            ->call('setCharacterIconUsageFilter', 'all')
            ->call('nextCharacterIconUsagePage')
            ->assertSee('#049');
    }

    private function createCharacter(User $user, ?string $iconPath): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '統計確認',
            'icon_path' => $iconPath,
        ]);
    }
}
