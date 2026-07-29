<?php

namespace Tests\Feature;

use App\Http\Controllers\ChampBattleController;
use App\Models\Character;
use App\Models\ChampState;
use App\Models\User;
use App\Services\ChampBattleResultStore;
use App\Services\ChampBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Mockery;
use Tests\TestCase;

class ChampBattleResultStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_result_is_stored_as_json_and_scoped_to_the_character(): void
    {
        $result = $this->battleResult();
        $store = app(ChampBattleResultStore::class);

        $token = $store->store(101, $result);
        $stored = $store->retrieve(101, $token);

        $this->assertNotNull($stored);
        $this->assertSame('新しいチャンプ', $stored['champ_after_name']);
        $this->assertSame(['戦闘開始！'], $stored['battle_log']);
        $this->assertIsString($stored['next_available_at']);
        $this->assertNull($store->retrieve(202, $token));
        $this->assertNull($store->retrieve(101, 'invalid-token'));
    }

    public function test_result_page_uses_the_token_even_if_the_flash_session_was_lost(): void
    {
        $character = $this->character('結果確認者');
        $champ = ChampState::query()->firstOrFail();
        $result = $this->battleResult();
        $battleService = Mockery::mock(ChampBattleService::class);
        $battleService->shouldReceive('executeChallenge')
            ->once()
            ->withArgs(fn (
                Character $actualCharacter,
                int $expectedCharacterId,
                int $expectedAppointedAt
            ) => $actualCharacter->is($character)
                && $expectedCharacterId === 0
                && $expectedAppointedAt === $champ->appointed_at->getTimestamp())
            ->andReturn($result);

        $this->actingAs($character->user);
        $this->app['session']->start();
        session(['current_character_id' => $character->id]);

        $request = Request::create('/champ/challenge', 'POST', [
            'expected_champ_character_id' => 0,
            'expected_champ_appointed_at' => $champ->appointed_at->getTimestamp(),
        ]);
        $response = app(ChampBattleController::class)->challenge($request, $battleService);
        $targetUrl = $response->getTargetUrl();

        $this->assertStringContainsString('result_token=', $targetUrl);

        session()->forget(['champ_battle_result', 'lastChampBattleResult']);
        $this->app->instance('request', Request::create($targetUrl, 'GET'));

        $resultResponse = app(ChampBattleController::class)->result();

        $this->assertInstanceOf(View::class, $resultResponse);
        $this->assertSame('champ.result', $resultResponse->name());
        $this->assertSame('新しいチャンプ', $resultResponse->getData()['result']['champ_after_name']);
    }

    private function character(string $name): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'explore_stamina' => 0,
            'hp_base' => 100,
            'mp_base' => 0,
            'attack_base' => 10,
            'defense_base' => 8,
            'speed_base' => 8,
            'magic_base' => 8,
            'spirit_base' => 8,
            'luck_base' => 5,
        ]);
    }

    private function battleResult(): array
    {
        return [
            'ok' => true,
            'champ_defeated' => true,
            'damage' => 10,
            'turns' => 1,
            'battle_log' => ['戦闘開始！'],
            'champ_before_name' => '以前のチャンプ',
            'champ_after_name' => '新しいチャンプ',
            'challenger_actor' => [
                'name' => '挑戦者',
                'current_hp' => 90,
                'max_hp' => 100,
                'current_mp' => 5,
                'max_mp' => 10,
            ],
            'champ_actor' => [
                'player_name' => '以前のチャンプ',
                'current_hp' => 0,
                'max_hp' => 100,
                'current_mp' => 0,
                'max_mp' => 10,
            ],
            'champ_fatigue' => ['percent' => 0, 'defense_count' => 0],
            'champ_hp_before' => 10,
            'champ_hp_after' => 0,
            'champ_max_hp' => 100,
            'exp_gained' => 1,
            'job_exp_gained' => 1,
            'progression' => null,
            'gap_reward_note' => null,
            'material_code' => 'MAT_CHAMP_CHALLENGER_FRAGMENT',
            'material_icon_image' => '/images/material/material_001.webp',
            'material_name' => '挑戦者の欠片',
            'material_quantity' => 1,
            'level_result' => [],
            'next_available_at' => now()->addMinutes(10),
        ];
    }
}
