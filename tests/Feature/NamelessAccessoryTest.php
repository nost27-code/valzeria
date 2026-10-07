<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\LeftSidebar;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\GameSetting;
use App\Models\Item;
use App\Models\NamelessEquipmentDiscovery;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\BattleEquipmentSummaryService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\GameSettingService;
use App\Services\NamelessEquipmentCollectionService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class NamelessAccessoryTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\NamelessRuinRewardReferences;

    private const TARGETS = [
        '指輪' => ['str' => 4000, 'mag' => 4000],
        '腕輪' => ['def' => 4000, 'spr' => 4000],
        '首飾り' => ['hp' => 5000, 'mp' => 5000],
        '羽飾り' => ['agi' => 4000, 'luk' => 4000],
        '護符' => ['str' => 2000, 'def' => 2000, 'mag' => 2000, 'spr' => 2000, 'agi' => 2000, 'luk' => 2000],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['gold.battle.normal_drop_rate' => 0, 'gold.battle.boss_drop_rate' => 0]);
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'nameless_relics.equipment_drop_chance_bps' => 0, 'nameless_relics.drop_chance_bps' => 0,
            'nameless_relics.cleared_boss_encounter_bps' => 0, 'nameless_relics.relic_goblin_encounter_bps' => 0]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
        CharacterStatusService::clearRequestCache();
    }

    public function test_all_five_accessories_grow_monotonically_to_approved_targets(): void
    {
        $character = $this->character();
        foreach (self::TARGETS as $type => $targets) {
            $body = $this->body($character, $type);
            $this->assertSame(array_fill_keys(array_keys($targets), 5), $body->performanceStatsAt(0));
            $this->assertSame($targets, $body->performanceStatsAt(99));
            $this->assertSame($targets, $body->performanceStatsAt(100));
            $previous = $body->performanceStatsAt(0);
            foreach (range(1, 99) as $level) {
                foreach ($body->performanceStatsAt($level) as $stat => $value) {
                    $this->assertGreaterThan($previous[$stat], $value, $type.' '.$stat.' +'.$level);
                    $previous[$stat] = $value;
                }
            }
            $this->assertSame('装飾品', $body->kindLabel());
            $this->assertFileExists(public_path($body->imagePath()));
            $summary = $this->workshop()->forgeSummary($character, $body);
            $this->assertSame(4950000, $summary['total_gold']);
            $this->assertSame(20, $summary['exp']);
        }
    }

    public function test_fixed_accessory_stats_enter_weapon_and_armor_formulas_before_relic_percentages(): void
    {
        $character = $this->character();
        $weapon = $this->body($character, '剣', 99, 'weapon');
        $armor = $this->body($character, '鎧', 99, 'armor');
        $ring = $this->body($character, '指輪', 99);
        foreach ([$weapon, $armor, $ring] as $body) {
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        }
        $this->workshop()->attach($character, $ring->id, 1, $this->relic($character, 'stat_str')->id, $this->uuid());
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame(['str' => 5000, 'mag' => 5000], $stats['weapon_base']);
        $this->assertSame(25833, $stats['relic_baseline']['str']);
        $this->assertSame(29707, $stats['str']);
        $this->assertSame(11333, $stats['mag']);
        $this->assertSame(4333, $stats['def']);
        $this->assertSame(2792, $stats['spr']);

        $bracelet = $this->body($character, '腕輪', 99);
        $this->workshop()->changeEquipment($character, $bracelet->id, true, $this->uuid());
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame(['def' => 5000, 'spr' => 5000], $stats['armor_base']);
        $this->assertSame(21667, $stats['def']);
        $this->assertSame(13958, $stats['spr']);
        $this->assertSame(5167, $stats['str']);
        $this->assertSame(0.0, $this->workshop()->equippedStatRates($character)['str']);
    }

    public function test_three_equipment_slots_share_relic_caps_duplicates_and_exclusive_groups(): void
    {
        $character = $this->character();
        $weapon = $this->body($character, '剣', 0, 'weapon');
        $armor = $this->body($character, '鎧', 0, 'armor');
        $accessory = $this->body($character);
        foreach ([$weapon, $armor, $accessory] as $body) {
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        }
        $this->workshop()->attach($character, $weapon->id, 1, $this->relic($character, 'stat_str')->id, $this->uuid());
        $this->workshop()->attach($character, $armor->id, 1, $this->relic($character, 'compound_polarity')->id, $this->uuid());
        $this->workshop()->attach($character, $accessory->id, 1, $this->relic($character, 'form_dragon')->id, $this->uuid());
        $this->assertSame(.30, $this->workshop()->equippedStatRates($character)['str']);
        $this->assertEqualsWithDelta(.25, $this->workshop()->equippedStatRates($character, 'weapon')['str'], .00001);
        $this->assertSame(.30, $this->workshop()->equippedStatRates($character, 'armor')['str']);
        $this->assertEqualsWithDelta(.25, $this->workshop()->equippedStatRates($character, 'accessory')['str'], .00001);
        $duplicate = $this->relic($character, 'stat_str');
        $this->reject(fn () => $this->workshop()->attach($character, $accessory->id, 2, $duplicate->id, $this->uuid()), '一つまで');
        $this->assertNull($duplicate->fresh()->nameless_equipment_id);
        $this->reject(fn () => $this->workshop()->attach($character, $accessory->id, 2, $this->relic($character, 'form_spirit')->id, $this->uuid()), '一種類まで');
        $this->reject(fn () => $this->workshop()->attach($character, $accessory->id, 4, $this->relic($character, 'stat_luk')->id, $this->uuid()), '遺物枠');
        $reserve = $this->body($character, '羽飾り');
        $this->workshop()->attach($character, $reserve->id, 1, $duplicate->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->changeEquipment($character, $reserve->id, true, $this->uuid()), '一つまで');
        $this->assertTrue($accessory->fresh()->is_equipped);
        $this->assertFalse($reserve->fresh()->is_equipped);
    }

    public function test_shop_preview_and_actual_accessory_swap_match_for_every_profile_and_weapon_source(): void
    {
        foreach ([false, true] as $namelessWeapons) {
            foreach (array_keys(self::TARGETS) as $type) {
                $character = $this->character();
                if ($namelessWeapons) {
                    foreach (['weapon' => '剣', 'armor' => '鎧'] as $kind => $gearType) {
                        $body = $this->body($character, $gearType, 99, $kind);
                        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
                        $this->workshop()->attach($character, $body->id, 1, $this->relic($character, $kind === 'weapon' ? 'stat_str' : 'stat_spr')->id, $this->uuid());
                    }
                } else {
                    $this->ordinary($character, 'weapon', true, ['str_bonus' => 2400, 'mag_bonus' => 900]);
                    $this->ordinary($character, 'armor', true, ['def_bonus' => 3200, 'spr_bonus' => 2400]);
                }
                $accessory = $this->body($character, $type, 99);
                $this->workshop()->changeEquipment($character, $accessory->id, true, $this->uuid());
                $this->workshop()->attach($character, $accessory->id, 1, $this->relic($character, 'stat_all')->id, $this->uuid());
                $ordinary = $this->ordinary($character, 'accessory', false, ['hp_bonus' => 37, 'mp_bonus' => 19,
                    'str_bonus' => 111, 'mag_bonus' => 73, 'def_bonus' => 91, 'spr_bonus' => 83, 'agi_bonus' => 59, 'luk_bonus' => 43]);
                $preview = app(CharacterStatusService::class)->equipmentSwapPreviewForItem($character->fresh(), $ordinary->item);
                $this->assertTrue(app(EquipmentService::class)->equip($character, $ordinary)['success']);
                $actual = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                foreach (['hp' => 'max_hp', 'mp' => 'max_mp', 'str' => 'str', 'def' => 'def', 'mag' => 'mag', 'spr' => 'spr', 'agi' => 'agi', 'luk' => 'luk'] as $key => $finalKey) {
                    $this->assertSame($actual[$finalKey], $preview['after_stats'][$key], $type.' '.$key.' nameless='.$namelessWeapons);
                }
                $this->assertFalse($accessory->fresh()->is_equipped);
                $this->assertTrue($ordinary->fresh()->is_equipped);
                $this->workshop()->changeEquipment($character, $accessory->id, true, $this->uuid());
                $this->assertFalse($ordinary->fresh()->is_equipped);
                $this->assertNull($ordinary->fresh()->equipped_slot);
            }
        }
    }

    public function test_weapon_and_armor_previews_keep_accessory_body_and_relics(): void
    {
        $character = $this->character();
        $charm = $this->body($character, '護符', 99);
        $this->workshop()->changeEquipment($character, $charm->id, true, $this->uuid());
        $this->workshop()->attach($character, $charm->id, 1, $this->relic($character, 'stat_all')->id, $this->uuid());
        foreach (['weapon' => '剣', 'armor' => '鎧'] as $kind => $type) {
            $body = $this->body($character, $type, 99, $kind);
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
            $this->workshop()->attach($character, $body->id, 1, $this->relic($character, $kind === 'weapon' ? 'stat_str' : 'stat_spr')->id, $this->uuid());
            $ordinary = $this->ordinary($character, $kind, false, ['str_bonus' => 1000, 'mag_bonus' => 800, 'def_bonus' => 600, 'spr_bonus' => 400]);
            $status = app(CharacterStatusService::class);
            $preview = $status->equipmentSwapPreviewForItem($character->fresh(), $ordinary->item);
            $primary = $kind === 'weapon' ? $status->weaponEffectivePreview($character->fresh(), $ordinary) : $status->armorEffectivePreview($character->fresh(), $ordinary);
            $this->assertTrue(app(EquipmentService::class)->equip($character, $ordinary)['success']);
            $actual = $status->getFinalStats($character->fresh());
            foreach (['str', 'mag', 'def', 'spr', 'agi', 'luk'] as $key) {
                $this->assertSame($actual[$key], $preview['after_stats'][$key], $kind.' '.$key);
            }
            foreach ($primary as $key => $value) {
                $this->assertSame($actual[$key], $value);
            }
            $this->assertTrue($charm->fresh()->is_equipped);
            $this->assertSame(.05, $this->workshop()->equippedStatRates($character)['str']);
        }
    }

    public function test_necklace_does_not_heal_and_removal_clamps_current_hp_and_sp(): void
    {
        $character = $this->character();
        $body = $this->body($character, '首飾り', 99);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->assertSame(10000, $character->fresh()->current_hp);
        $this->assertSame(500, $character->fresh()->current_mp);
        $this->workshop()->attach($character, $body->id, 1, $this->relic($character, 'stat_hp')->id, $this->uuid());
        $this->workshop()->attach($character, $body->id, 2, $this->relic($character, 'stat_mp')->id, $this->uuid());
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame(17250, $stats['max_hp']);
        $this->assertSame(6900, $stats['max_mp']);
        $character->forceFill(['current_hp' => 17250, 'current_mp' => 6900])->save();
        $ordinary = $this->ordinary($character);
        $this->assertTrue(app(EquipmentService::class)->equip($character, $ordinary)['success']);
        $this->assertSame(10000, $character->fresh()->current_hp);
        $this->assertSame(1000, $character->fresh()->current_mp);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->assertSame(10000, $character->fresh()->current_hp);
        $this->assertSame(1000, $character->fresh()->current_mp);
        $character->forceFill(['current_hp' => 17250, 'current_mp' => 6900])->save();
        $this->workshop()->changeEquipment($character, $body->id, false, $this->uuid());
        $this->assertSame(10000, $character->fresh()->current_hp);
        $this->assertSame(1000, $character->fresh()->current_mp);
    }

    public function test_all_profiles_forge_with_existing_costs_and_replay_once(): void
    {
        $character = $this->character();
        $character->update(['money' => 20000]);
        foreach (self::TARGETS as $type => $targets) {
            $body = $this->body($character, $type, 1);
            $body->update(['growth_exp' => 40]);
            $revision = $body->fresh()->revision;
            $preview = $this->workshop()->previewForge($character, $body->id, $revision, [], [], true, false);
            $this->assertSame(2000, $preview['gold']);
            $this->assertSame(40, $preview['spent_exp']);
            $this->assertSame($body->performanceStatsAt(2), $preview['performance_after']);
            $this->assertStringStartsWith('装飾品性能 ', $preview['performance_label']);
            $money = $character->fresh()->money;
            $uuid = $this->uuid();
            $result = $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid);
            $this->assertSame($result, $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid));
            $this->assertSame(2, $body->fresh()->forge_level);
            $this->assertSame(0, $body->fresh()->growth_exp);
            $this->assertSame($money - 2000, $character->fresh()->money);
        }
        $body = $this->body($character, '首飾り', 1);
        $body->update(['growth_exp' => 40]);
        $revision = $body->fresh()->revision;
        $preview = $this->workshop()->previewForge($character, $body->id, $revision, [], [], true, false);
        config(['nameless_relics.accessory_stat_targets_at_max.首飾り.hp' => 6000]);
        $before = $body->fresh()->getAttributes();
        $money = $character->fresh()->money;
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $this->uuid()), '確認し直して');
        $this->assertSame($before, $body->fresh()->getAttributes());
        $this->assertSame($money, $character->fresh()->money);
    }

    public function test_existing_fifteen_discoveries_prioritize_each_new_accessory_in_normal_and_boss_drops(): void
    {
        foreach ([false, true] as $boss) {
            foreach (array_keys(self::TARGETS) as $type) {
                $character = $this->character();
                $this->discoverAllExcept($character, $type);
                config(['nameless_relics.equipment_drop_chance_bps' => 10000]);
                $uuid = $this->uuid();
                $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, $boss, $uuid);
                $this->assertSame('victory', $result['battle_result']);
                $this->assertSame('accessory', $result['nameless_equipment_drops'][0]['kind']);
                $this->assertSame($type, $result['nameless_equipment_drops'][0]['equipment_type']);
                $this->assertTrue($result['nameless_equipment_drops'][0]['new_discovery']);
                $this->assertSame(20, app(NamelessEquipmentCollectionService::class)->summary($character)['count']);
                $this->assertSame(1, $character->namelessEquipments()->count());
                $this->assertSame($result, app(NamelessRuinService::class)->fight($character, 'sand', 1, $boss, $uuid));
                $this->assertSame(1, $character->namelessEquipments()->count());
                $this->login($character);
                $this->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk()->assertSee('名もなき'.$type);
            }
        }
    }

    public function test_rare_goblin_uses_same_equipment_drop_pool(): void
    {
        $character = $this->character();
        $this->discoverAllExcept($character, '護符');
        config(['nameless_relics.equipment_drop_chance_bps' => 10000, 'nameless_relics.relic_goblin_encounter_bps' => 10000]);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid());
        $this->assertSame('遺物ゴブリン', $result['enemy']['name']);
        $this->assertSame('victory', $result['battle_result']);
        $this->assertCount(10, $result['relic_drops']);
        $this->assertSame('護符', $result['nameless_equipment_drops'][0]['equipment_type']);
    }

    public function test_workshop_list_confirmation_sidebar_and_battle_show_accessory_slot(): void
    {
        $character = $this->character();
        $charm = $this->body($character, '護符', 1);
        $charm->update(['growth_exp' => 40, 'custom_name' => '夜明けの護符']);
        $this->body($character, '剣', 0, 'weapon');
        $this->body($this->character(), '首飾り')->update(['custom_name' => '他人の首飾り']);
        $this->workshop()->changeEquipment($character, $charm->id, true, $this->uuid());
        $this->login($character);
        $this->get(route('nameless-workshop.index', ['equipment' => $charm->id, 'gear_kind' => 'accessory']))->assertOk()
            ->assertSee('装飾品性能 攻撃 +10 / 防御 +10 / 魔力 +10 / 精神 +10 / 敏捷 +10 / 運 +10')
            ->assertSee('武具の入手 2／20')->assertDontSee('他人の首飾り')
            ->assertViewHas('equipmentFilterQuery', ['gear_kind' => 'accessory']);
        $this->get(route('equipment.index'))->assertOk()->assertSee('［遺装］ 夜明けの護符 +1')->assertSee('装飾品');
        $this->post(route('nameless-workshop.act', 'preview-forge'), ['equipment_id' => $charm->id, 'revision' => $charm->fresh()->revision,
            'materials' => [], 'relics' => [], 'protect_best' => 1, 'request_uuid' => $this->uuid()])->assertOk()
            ->assertSee('装飾品性能 攻撃 +10 → +15')->assertSee('運 +10 → +15');
        Livewire::test(LeftSidebar::class)->assertSee('夜明けの護符 +1')->assertSee('遺装');
        $summary = app(BattleEquipmentSummaryService::class)->forEnemy($character, 'dragon');
        $this->assertSame('装飾品', $summary[0]['slot']);
        $this->assertSame('遺装', $summary[0]['rank']);
        $this->assertSame($charm->imagePath(), $summary[0]['icon']);
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'accessory', 'type' => '指輪', 'request_uuid' => $this->uuid()])
            ->assertSessionHasErrors('kind');
    }

    public function test_accessory_ownership_rename_protection_and_discard_keep_discovery(): void
    {
        $character = $this->character();
        $other = $this->character();
        $body = $this->body($character);
        NamelessEquipmentDiscovery::query()->create(['character_id' => $character->id, 'kind' => 'accessory', 'equipment_type' => '指輪']);
        $this->reject(fn () => $this->workshop()->changeEquipment($other, $body->id, true, $this->uuid()), '所持していません');
        $this->workshop()->rename($character, $body->id, '星の指輪', $this->uuid());
        $this->reject(fn () => $this->workshop()->configure($character, $body->id, '腕輪', '変形', $this->uuid()), '種類は変更できません');
        $this->assertSame('星の指輪', $body->fresh()->displayName());
        $this->workshop()->protectEquipment($character, $body->id, true, $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $body->id, $body->fresh()->revision, $this->uuid()), '保護');
        $this->workshop()->protectEquipment($character, $body->id, false, $this->uuid());
        $this->workshop()->discardEquipment($character, $body->id, $body->fresh()->revision, $this->uuid());
        $this->assertNull($body->fresh());
        $this->assertSame(1, app(NamelessEquipmentCollectionService::class)->summary($character)['count']);
        $migration = require database_path('migrations/2026_10_05_070000_extend_nameless_equipment_kind_for_accessories.php');
        $this->reject(fn () => $migration->down(), '発見記録');
    }

    public function test_kind_migration_preserves_existing_equipment_relics_operations_and_indexes(): void
    {
        // RefreshDatabaseの外側transactionに影響しない隔離接続で、実際の移行順を確認する。
        $previousConnection = DB::getDefaultConnection();
        config(['database.connections.accessory_migration' => config('database.connections.sqlite')]);
        config(['database.connections.accessory_migration.database' => ':memory:']);
        DB::setDefaultConnection('accessory_migration');
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'accessory_migration', '--force' => true]);
            $character = $this->character();
            $weapon = $this->body($character, '剣', 30, 'weapon');
            $weapon->update(['custom_name' => '以前の剣', 'growth_exp' => 77, 'is_locked' => true]);
            $this->workshop()->attach($character, $weapon->id, 1, $this->relic($character, 'stat_str')->id, $this->uuid());
            $this->workshop()->changeEquipment($character, $weapon->id, true, $this->uuid());
            NamelessEquipmentDiscovery::query()->create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣']);
            $tables = ['player_nameless_equipments', 'player_relics', 'nameless_workshop_operations', 'nameless_equipment_discoveries'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            $migration = require database_path('migrations/2026_10_05_070000_extend_nameless_equipment_kind_for_accessories.php');
            $migration->down();
            try {
                $this->body($character);
                $this->fail('旧enumは装飾品を拒否する必要があります。');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('CHECK constraint', $exception->getMessage());
            }
            $migration->up();
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
            }
            $this->assertContains('nameless_equipment_owner_kind', array_column(Schema::getIndexes('player_nameless_equipments'), 'name'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            $accessory = $this->body($character);
            $this->assertSame('accessory', $accessory->fresh()->kind);
            $this->reject(fn () => $migration->down(), '所有');
        } finally {
            DB::setDefaultConnection($previousConnection);
            DB::purge('accessory_migration');
            CharacterStatusService::clearRequestCache();
        }
    }

    public function test_disabled_feature_ignores_accessory_stats_and_relics_in_all_environments(): void
    {
        $character = $this->character();
        $body = $this->body($character, '護符', 99);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->workshop()->attach($character, $body->id, 1, $this->relic($character, 'stat_str')->id, $this->uuid());
        $this->login($character);
        foreach (['testing', 'production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            config(['nameless_relics.enabled' => false]);
            CharacterStatusService::clearRequestCache();
            $this->assertSame(1000, app(CharacterStatusService::class)->getFinalStats($character->fresh())['str']);
            $this->assertSame(0, array_sum($this->workshop()->equippedFixedBonuses($character)));
            $this->get(route('nameless-workshop.index'))->assertNotFound();
            $this->get(route('equipment.index'))->assertOk()->assertDontSee('［遺装］');
        }
    }

    private function discoverAllExcept(Character $character, string $except): void
    {
        foreach (app(NamelessEquipmentCollectionService::class)->types() as $type => $entry) {
            if ($type !== $except) {
                NamelessEquipmentDiscovery::query()->create(['character_id' => $character->id, 'kind' => $entry['kind'], 'equipment_type' => $type]);
            }
        }
    }

    private function body(Character $character, string $type = '指輪', int $level = 0, string $kind = 'accessory'): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => $kind, 'equipment_type' => $type,
            'acquisition_source' => 'ruin', 'forge_level' => $level, 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]);
    }

    private function ordinary(Character $character, string $kind = 'accessory', bool $equipped = false, array $stats = []): CharacterItem
    {
        $item = Item::query()->create($stats + ['name' => '通常の'.$kind, 'type' => $kind, 'rarity' => 'EPIC', 'is_active' => true]);
        return CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id,
            'is_equipped' => $equipped, 'equipped_slot' => $equipped ? $kind : null, 'enhance_level' => 0]);
    }

    private function relic(Character $character, string $effect): PlayerRelic
    {
        return PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => $effect, 'rank' => 9]);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '装飾品検証', 'current_city_id' => $town->id,
            'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 10000, 'current_mp' => 500,
            'attack_base' => 1000, 'magic_base' => 1000, 'defense_base' => 1000, 'spirit_base' => 1000,
            'speed_base' => 1000, 'luck_base' => 10, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(), 'money' => 10000]);
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function workshop(): NamelessWorkshopService
    {
        return app(NamelessWorkshopService::class);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('拒否されるべき操作が成功しました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
