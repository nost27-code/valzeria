<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationRaidBattleTelemetryLog;
use App\Models\Skill;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\JobArtService;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTrialCoordinationService;
use App\Services\Nation\Raid\NationRaidTrialService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class NationRaidTrialTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    /** @var list<int> */
    private array $characterIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = $this->app->environment();
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->characterIds as $characterId) {
            CharacterStatusService::clearRequestCache($characterId);
        }
        Carbon::setTestNow();
        $this->app['env'] = $this->originalEnvironment;

        parent::tearDown();
    }

    public function test_trial_routes_fail_closed_outside_local_or_when_the_feature_flag_is_off(): void
    {
        $user = User::factory()->create();
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->app['env'] = 'production';

        $this->actingAs($user)
            ->get(route('nation-raid.trial'))
            ->assertNotFound();
        $this->post(route('nation-raid.trial.battle'), [
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
        ])->assertNotFound();

        $this->app['env'] = 'local';
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->get(route('nation-raid.trial'))->assertNotFound();
    }

    public function test_local_trial_screen_exposes_server_derived_encounter_and_current_character_snapshot(): void
    {
        $this->enableTrial();
        [$user, $character] = $this->character('試遊画面冒険者');

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('nation-raid.trial'));

        $response->assertOk()
            ->assertSee('ローカル確認')
            ->assertSee('十系喰らいの黒天竜 ヴァルグレイド')
            ->assertSee('探索力10は実際に消費します。')
            ->assertSee('現在の探索力')
            ->assertSee('試遊画面冒険者')
            ->assertSee('data-nation-raid-local-safety-notice', false)
            ->assertSee('data-nation-raid-event-status', false)
            ->assertSee('data-nation-raid-boss-hp', false)
            ->assertSee('data-nation-raid-stage-track', false)
            ->assertSee('data-nation-raid-event-summary', false)
            ->assertSee('data-nation-raid-hero-boss-art', false)
            ->assertSee('data-nation-raid-server-context', false)
            ->assertSee('第1 / 20再臨《微睡》')
            ->assertSee('種族 竜')
            ->assertSee('本日の対抗対象：系譜観測（対抗系譜なし）')
            ->assertSee('現在個体HP')
            ->assertSee('10,000,000')
            ->assertSee('本編進行')
            ->assertSee('次の進行報酬')
            ->assertSee('回数制限なし')->assertDontSee('本日の残り出撃')
            ->assertSee('残り時間')
            ->assertSee('第一形態')
            ->assertDontSee('作戦を選ぶ')
            ->assertDontSee('name="strategy"', false)
            ->assertSee('敵の再臨・形態・対抗系譜は、現在の戦況から自動的に決まります。')
            ->assertSee('ヴァルグレイドに挑む')
            ->assertSee('data-nation-raid-sortie-stamina-cost', false)
            ->assertSee('images/icon/icon_082.webp', false)
            ->assertSee('-10')
            ->assertSee('data-nation-raid-sortie-form', false)
            ->assertSee('data-nation-raid-sortie-preparation', false)
            ->assertDontSee('data-nation-raid-next-sortie', false)
            ->assertSee('images/raid/valgreid_form_01.webp', false)
            ->assertDontSee('name="stage"', false)
            ->assertDontSee('name="form"', false)
            ->assertDontSee('name="dominant_lineage"', false)
            ->assertDontSee('開始形態')
            ->assertDontSee('ボスが対抗する最多系譜');
    }

    public function test_local_trial_screen_presents_the_equipped_boss_art_lineage(): void
    {
        $this->enableTrial();
        [, $character] = $this->character('戦技表示冒険者');
        $art = new Skill([
            'job_id' => 1,
            'name' => '試遊用反撃戦技',
            'skill_type' => 'job_art',
        ]);
        $art->setAttribute('id', 10_001);
        $art->setAttribute('slot_no', 1);

        $this->app->instance(JobArtService::class, new class($art) extends JobArtService
        {
            public function __construct(private readonly Skill $art)
            {
                parent::__construct();
            }

            public function battleArtsFor(Character $character, string $context = 'pve'): Collection
            {
                return collect([$this->art]);
            }
        });

        $screen = app(NationRaidTrialService::class)->screen($character);

        $this->assertSame('試遊用反撃戦技', $screen['boss_set'][0]['name']);
        $this->assertSame('反撃', $screen['boss_set'][0]['lineage_name']);
    }

    public function test_local_trial_battle_uses_prg_persists_only_stamina_and_does_not_write_battle_or_telemetry(): void
    {
        $this->enableTrial();
        [$user, $character] = $this->character('試遊戦闘冒険者');
        $before = $this->gameplayState($character);
        $battleLogsBefore = BattleLog::query()->count();
        $telemetryBefore = NationRaidBattleTelemetryLog::query()->count();

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('nation-raid.trial.battle'), [
                // Production derives these values from the active event; client values must not win.
                'stage' => 13,
                'form' => NationRaidRules::FORM_LINEAGE_INVASION,
                'strategy' => NationRaidRules::STRATEGY_INTERCEPT,
                'dominant_lineage' => 'counter',
            ]);

        $response->assertRedirect(route('nation-raid.trial'))
            ->assertSessionHas('nation_raid_trial_result', function (array $result): bool {
                return $result['schema_version'] === 'nation-raid-local-trial-result-v3'
                    && $result['stage'] === 1
                    && $result['form']['key'] === NationRaidRules::FORM_SEALED_SCALE
                    && $result['strategy'] === 'boss_set'
                    && $result['dominant_lineage'] === null
                    && $result['turns_completed'] >= 1
                    && count($result['turns']) === $result['turns_completed']
                    && $result['calculated_boss_damage'] >= 0
                    && $result['coordination_damage'] === 0
                    && $result['shared_hp_damage'] === $result['calculated_boss_damage']
                    && $result['boss_remaining_hp'] === $result['form']['starting_hp'] - $result['shared_hp_damage']
                    && $result['exploration_stamina_cost'] === 10
                    && $result['exploration_stamina']['current'] === 240
                    && $result['player_battle_logs'] !== []
                    && $result['battle_log']['opening_logs'] !== []
                    && count($result['battle_log']['turns']) === $result['turns_completed'];
            });

        $after = $this->gameplayState($character);
        $this->assertSame($before['current_hp'], $after['current_hp']);
        $this->assertSame($before['current_mp'], $after['current_mp']);
        $this->assertSame(240, $after['explore_stamina']);
        $this->assertSame($before['last_battle_at'], $after['last_battle_at']);
        $this->assertSame($battleLogsBefore, BattleLog::query()->count());
        $this->assertSame($telemetryBefore, NationRaidBattleTelemetryLog::query()->count());

        $result = $response->getSession()->get('nation_raid_trial_result');
        $this->assertIsArray($result);
        $resultScreen = $this->get(route('nation-raid.trial'));

        $resultScreen
            ->assertOk()
            ->assertSee('戦闘ログ')
            ->assertSee('data-nation-raid-full-battle-log', false)
            ->assertSee('【戦闘開始】')
            ->assertSee('【敵技】')
            ->assertSee('【レイドボスへのダメージ】')
            ->assertDontSee('技名・種族特攻の既存戦闘ログを見る')
            ->assertSee('第1 / 20再臨《微睡》')
            ->assertSee('第一形態《封鱗》への出撃結果')
            ->assertSee('data-nation-raid-result-header', false)
            ->assertSee('data-nation-raid-outcome-label', false)
            ->assertSee('data-nation-raid-result-summary', false)
            ->assertSee('レイドボスへのダメージ')
            ->assertSee('ボスの残りHP')
            ->assertSee(number_format((int) $result['boss_remaining_hp']).' / 10,000,000')
            ->assertDontSee('>残りHP<', false)
            ->assertSee('探索力 -10')
            ->assertSee('data-nation-raid-local-debug', false)
            ->assertSee('次の出撃準備へ')
            ->assertSee('data-nation-raid-next-sortie', false)
            ->assertDontSee('data-nation-raid-sortie-form', false)
            ->assertDontSee('data-nation-raid-sortie-preparation', false)
            ->assertDontSee('作戦を選ぶ')
            ->assertDontSee('ボス戦セット')
            ->assertSeeInOrder([
                'data-nation-raid-full-battle-log',
                'data-nation-raid-result-summary',
                'data-nation-raid-local-debug',
                'data-nation-raid-next-sortie',
            ]);

        $this->assertSame(1, substr_count((string) $resultScreen->getContent(), (string) $result['outcome_label']));

        $preparationScreen = $this->get(route('nation-raid.trial'));
        $preparationScreen
            ->assertOk()
            ->assertSee('data-nation-raid-sortie-form', false)
            ->assertSee('data-nation-raid-sortie-preparation', false)
            ->assertDontSee('data-nation-raid-next-sortie', false);
    }

    public function test_local_trial_coordination_counts_unique_nation_members_without_refreshing_repeat_expiry(): void
    {
        $this->enableTrial();
        Carbon::setTestNow('2026-09-03 18:00:00');
        [, $first] = $this->character('連携一番手');
        [, $second] = $this->character('連携二番手');
        $nation = $this->nation([$first, $second]);
        $service = app(NationRaidTrialCoordinationService::class);

        $firstEntry = $service->register($first);
        $this->assertSame(1, $firstEntry['unique_count']);
        $this->assertSame(0.0, $firstEntry['bonus_rate']);
        $firstTimestamp = $firstEntry['participated_at'][0];

        Carbon::setTestNow('2026-09-03 19:00:00');
        $repeat = $service->register($first);
        $this->assertFalse($repeat['newly_registered']);
        $this->assertSame([$firstTimestamp], $repeat['participated_at']);

        Carbon::setTestNow('2026-09-03 20:59:00');
        $secondEntry = $service->register($second);
        $this->assertSame((int) $nation->id, $secondEntry['nation_id']);
        $this->assertSame(2, $secondEntry['unique_count']);
        $this->assertSame(0.03, $secondEntry['bonus_rate']);

        Carbon::setTestNow('2026-09-03 21:01:00');
        $afterFirstExpiry = $service->snapshot($second);
        $this->assertSame([$second->id], $afterFirstExpiry['participant_ids']);
        $this->assertSame(1, $afterFirstExpiry['unique_count']);
        $this->assertSame(0.0, $afterFirstExpiry['bonus_rate']);
    }

    public function test_local_trial_result_shows_large_boss_and_active_nation_supporters_around_player(): void
    {
        $this->enableTrial();
        Carbon::setTestNow('2026-09-03 20:00:00');
        [, $first] = $this->character('共闘する先陣');
        [, $second] = $this->character('共闘する後詰');
        [$user, $character] = $this->character('共闘する本人');
        // Use poses already included in the production baseline.
        $first->update(['icon_path' => '/images/chara/chara_270.webp']);
        $second->update(['icon_path' => '/images/chara/chara_131.webp']);
        $character->update(['icon_path' => '/images/chara/chara_003.webp']);
        $this->nation([$first, $second, $character]);
        app(NationRaidTrialCoordinationService::class)->register($first);
        Carbon::setTestNow('2026-09-03 20:01:00');
        app(NationRaidTrialCoordinationService::class)->register($second);
        Carbon::setTestNow('2026-09-03 20:02:00');

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('nation-raid.trial.battle'), [
                'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            ]);

        $response->assertRedirect(route('nation-raid.trial'))
            ->assertSessionHas('nation_raid_trial_result', function (array $result): bool {
                return $result['coordination']['unique_count'] === 3
                    && $result['coordination']['bonus_rate'] === 0.06
                    && count($result['coordination']['participants']) === 3
                    && count($result['coordination']['left_supporters']) === 1
                    && count($result['coordination']['right_supporters']) === 1
                    && $result['coordination_damage'] === (int) floor($result['calculated_boss_damage'] * 0.06)
                    && $result['shared_hp_damage'] === $result['calculated_boss_damage'] + $result['coordination_damage'];
            });

        $resultScreen = $this->get(route('nation-raid.trial'));

        $resultScreen
            ->assertOk()
            ->assertSee('国家連携 3人')
            ->assertSee('連携ダメージ +6%')
            ->assertSee('レイドボスへのダメージ')
            ->assertSee('data-nation-raid-battle-scene', false)
            ->assertSee('data-nation-raid-boss-art', false)
            ->assertSee('data-nation-raid-current-player-art', false)
            ->assertSee('data-nation-raid-supporter-art', false)
            ->assertSee('data-nation-raid-supporter-pose="battle"', false)
            ->assertSee('/images/chara/poses/chara_270/03_battle.webp', false)
            ->assertSee('/images/chara/poses/chara_131/03_battle.webp', false)
            ->assertDontSee('aspect-square overflow-hidden rounded-full border-2 border-amber-300', false)
            ->assertDontSee('aspect-square overflow-hidden rounded-full border border-sky-300/70', false)
            ->assertSee('from-white via-slate-50 to-slate-100', false)
            ->assertSee('sm:grid-cols-2', false)
            ->assertSee('max-w-36', false)
            ->assertSeeInOrder([
                'data-nation-raid-supporters-left',
                '共闘する先陣',
                'data-nation-raid-current-player',
                '共闘する本人',
                'data-nation-raid-supporters-right',
                '共闘する後詰',
            ]);

        $this->assertSame(2, substr_count((string) $resultScreen->getContent(), 'data-nation-raid-supporter-art'));
        $this->assertDoesNotMatchRegularExpression(
            '/data-nation-raid-supporter-art[^>]*(?:rounded-full|border)/',
            (string) $resultScreen->getContent(),
        );
    }

    public function test_local_trial_limits_supporters_to_three_rows_per_side(): void
    {
        $this->enableTrial();
        Carbon::setTestNow('2026-09-03 20:00:00');
        [, $character] = $this->character('共闘配置の本人');
        $supporters = [];
        foreach (range(1, 8) as $index) {
            [, $supporter] = $this->character('共闘配置'.$index);
            $supporters[] = $supporter;
        }
        $this->nation([$character, ...$supporters]);

        $coordination = app(NationRaidTrialCoordinationService::class);
        $coordination->register($character);
        foreach ($supporters as $supporter) {
            $coordination->register($supporter);
        }

        $screen = app(NationRaidTrialService::class)->screen($character);

        $this->assertSame(9, $screen['coordination']['unique_count']);
        $this->assertCount(3, $screen['coordination']['left_supporters']);
        $this->assertCount(3, $screen['coordination']['right_supporters']);
        $this->assertSame(2, $screen['coordination']['hidden_supporter_count']);
    }

    public function test_local_trial_rejects_unknown_strategy_when_enabled(): void
    {
        $this->enableTrial();
        config()->set('nation_raid.strategy_enabled', true);
        [$user, $character] = $this->character('試遊入力冒険者');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('nation-raid.trial'))
            ->post(route('nation-raid.trial.battle'), [
                'strategy' => 'unknown-strategy',
            ])
            ->assertRedirect(route('nation-raid.trial'))
            ->assertSessionHasErrors(['strategy']);
    }

    public function test_local_trial_does_not_require_strategy_and_ignores_forged_values_while_off(): void
    {
        $this->enableTrial();
        config()->set('nation_raid.strategy_enabled', false);
        [$user, $character] = $this->character('作戦停止冒険者');
        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);
        foreach ([[], ['strategy' => ['fortify']]] as $payload) {
            $this->post(route('nation-raid.trial.battle'), $payload)
                ->assertSessionHasNoErrors()->assertRedirect(route('nation-raid.trial'))
                ->assertSessionHas('nation_raid_trial_result', fn (array $result): bool => $result['strategy'] === 'boss_set');
            $this->get(route('nation-raid.trial'))->assertOk()->assertDontSee('作戦：');
        }
        $this->assertSame(230, $character->fresh()->explore_stamina);
        $result = app(NationRaidTrialService::class)->fight($character, 'intercept');
        $this->assertSame('boss_set', $result['strategy']);
    }

    public function test_disabled_strategy_is_ignored_even_when_an_error_flashes_old_input(): void
    {
        $this->enableTrial();
        config()->set('nation_raid.strategy_enabled', false);
        Carbon::setTestNow('2026-09-03 20:00:00');
        [$user, $character] = $this->character('作戦停止後の不足確認');
        $character->update(['explore_stamina' => 9, 'explore_stamina_updated_at' => now()]);
        $this->actingAs($user)->withSession(['current_character_id' => $character->id])
            ->post(route('nation-raid.trial.battle'), ['strategy' => ['fortify']])
            ->assertRedirect(route('nation-raid.trial'))
            ->assertSessionHas('error', 'レイドボスへの出撃には探索力10が必要です。');
        $this->get(route('nation-raid.trial'))->assertOk()->assertDontSee('name="strategy"', false);
        $this->assertSame(9, $character->fresh()->explore_stamina);
    }

    public function test_local_trial_rejects_sortie_when_exploration_stamina_is_below_ten(): void
    {
        $this->enableTrial();
        Carbon::setTestNow('2026-09-03 20:00:00');
        [$user, $character] = $this->character('探索力不足冒険者');
        $character->update([
            'explore_stamina' => 9,
            'explore_stamina_updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('nation-raid.trial'))
            ->post(route('nation-raid.trial.battle'), [
                'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            ])
            ->assertRedirect(route('nation-raid.trial'))
            ->assertSessionHas('error', 'レイドボスへの出撃には探索力10が必要です。');

        $this->assertSame(9, (int) $character->fresh()->explore_stamina);
        $this->assertSame(0, app(NationRaidTrialCoordinationService::class)->snapshot($character)['unique_count']);
    }

    public function test_local_trial_result_shows_player_status_and_highlights_effective_raid_equipment(): void
    {
        $this->enableTrial();
        [$user, $character] = $this->character('装備表示冒険者');
        $weapon = Item::query()->create([
            'name' => '黒天竜狩りの剣',
            'type' => 'weapon',
            'weapon_rank' => 'G',
            'str_bonus' => 0,
            'is_active' => true,
        ]);
        $armor = Item::query()->create([
            'name' => '黒天竜耐性の鎧',
            'type' => 'armor',
            'armor_rank' => 'G',
            'def_bonus' => 0,
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weapon->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'killer_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'killer_damage_rate' => 0.30,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $armor->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'resist_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'species_damage_reduction_rate' => 0.25,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('nation-raid.trial.battle'), [
                'strategy' => NationRaidRules::STRATEGY_FORTIFY,
            ]);

        $response->assertRedirect(route('nation-raid.trial'));
        $result = $response->getSession()->get('nation_raid_trial_result');
        $this->assertSame(0.25, $result['raid_resistance_rate']);

        $this->get(route('nation-raid.trial'))
            ->assertOk()
            ->assertSee('data-nation-raid-player-status', false)
            ->assertSee('data-nation-raid-player-equipment', false)
            ->assertSee('data-nation-raid-killer-active="true"', false)
            ->assertSee('data-nation-raid-resistance-active="true"', false)
            ->assertSee('黒天竜狩りの剣')
            ->assertSee('特攻発動 与ダメージ +60%')
            ->assertSee('黒天竜耐性の鎧')
            ->assertSee('耐性発動 被ダメージ -25%')
            ->assertSee('有効打：竜特攻 +60%')
            ->assertSee('有効防御：竜耐性 -25%')
            ->assertSee('攻撃')
            ->assertSee('防御')
            ->assertSee('魔力')
            ->assertSee('精神')
            ->assertSee('敏捷')
            ->assertSee('運');
    }

    private function enableTrial(): void
    {
        $this->app['env'] = 'local';
        config()->set('features.nation_competitive_raid_enabled', true);
        config()->set('nation_raid.trial_coordination_cache_store', 'array');
    }

    /** @param list<Character> $characters */
    private function nation(array $characters): Nation
    {
        $nation = Nation::query()->create([
            'name' => '試遊連携',
            'nation_type' => 'kingdom',
            'status' => Nation::STATUS_ACTIVE,
            'founded_at' => now(),
        ]);
        foreach ($characters as $index => $character) {
            NationMembership::query()->create([
                'nation_id' => $nation->id,
                'character_id' => $character->id,
                'role' => $index === 0 ? 'ruler' : 'citizen',
                'joined_at' => now()->subDay(),
            ]);
        }

        return $nation;
    }

    /** @return array{current_hp:int,current_mp:int,explore_stamina:int,last_battle_at:mixed} */
    private function gameplayState(Character $character): array
    {
        $fresh = $character->fresh();

        return [
            'current_hp' => (int) $fresh->current_hp,
            'current_mp' => (int) $fresh->current_mp,
            'explore_stamina' => (int) $fresh->explore_stamina,
            'last_battle_at' => $fresh->getRawOriginal('last_battle_at'),
        ];
    }

    /** @return array{User, Character} */
    private function character(string $name): array
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = JobClass::query()->create([
            'key' => 'nation_raid_trial_'.strtolower(str()->random(8)),
            'name' => '試遊職',
            'rank' => 'normal',
        ]);
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'level' => 50,
            'current_job_id' => $job->id,
            'last_battle_at' => now()->subMinutes(10),
            'hp_base' => 25_000,
            'mp_base' => 200,
            'attack_base' => 2_500,
            'defense_base' => 1_500,
            'magic_base' => 2_000,
            'spirit_base' => 1_400,
            'speed_base' => 1_000,
            'luck_base' => 300,
            'current_hp' => 12_345,
            'current_mp' => 123,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
        ]);
        $this->characterIds[] = (int) $character->id;

        return [$user, $character];
    }
}
