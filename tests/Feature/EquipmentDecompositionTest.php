<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\EquipmentDecompositionLog;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerRelic;
use App\Models\PlayerValmon;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\EquipmentDecompositionService;
use App\Services\EquipmentEnhancementService;
use App\Services\MapExplorationItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EquipmentDecompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.equipment_decomposition_enabled' => true]);
    }

    public function test_off_blocks_all_http_entry_points_and_preserves_assets(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        config(['features.equipment_decomposition_enabled' => false]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('smith.disassemble.index'))->assertNotFound();
        $this->get(route('smith.disassemble.confirm', $equipment))->assertNotFound();
        $this->post(route('smith.disassemble', $equipment), ['confirmed' => 1, 'confirmation_hash' => $candidate['confirmation_hash']])->assertNotFound();
        $this->postJson(route('smith.disassemble', $equipment), ['confirmed' => 1, 'confirmation_hash' => $candidate['confirmation_hash']])->assertNotFound();
        $this->post(route('equipment.disassemble', $equipment))->assertRedirect(route('equipment.index'))
            ->assertSessionHas('error', EquipmentDecompositionService::DISABLED_MESSAGE);
        $this->postJson(route('equipment.disassemble', $equipment))->assertUnprocessable()
            ->assertJsonPath('confirmation_url', null)->assertJsonPath('success', false);
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('character_materials', 0);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
        $this->assertSame(1000, (int) $character->fresh()->money);
    }

    public function test_off_blocks_service_calls_before_any_inventory_or_payment_processing(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        config(['features.equipment_decomposition_enabled' => false]);
        $this->mock(MapExplorationItemService::class)->shouldNotReceive('restoreActiveSession');
        $this->reject(fn () => $this->service()->candidates($character), '停止中');
        $this->reject(fn () => $this->service()->preview($character, $equipment), '停止中');
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '停止中');
        $this->assertFalse($this->service()->candidate($equipment)['can_disassemble']);
        $this->assertDatabaseCount('character_materials', 0);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
    }

    public function test_blacksmith_link_is_only_present_when_enabled(): void
    {
        $character = $this->player();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        config(['features.equipment_decomposition_enabled' => false]);
        $this->get(route('blacksmith.index'))->assertOk()->assertDontSee(route('smith.disassemble.index'));
        config(['features.equipment_decomposition_enabled' => true]);
        $this->get(route('blacksmith.index'))->assertOk()->assertSee(route('smith.disassemble.index'));
    }

    public function test_production_and_staging_defaults_are_off_unless_explicitly_enabled(): void
    {
        foreach (['production', 'staging'] as $environment) {
            foreach ([false, 'false', 'true'] as $flag) {
                // PHPUnit's immutable environment must not mask production defaults.
                $process = new \Symfony\Component\Process\Process([
                    PHP_BINARY, '-r', "require 'vendor/autoload.php'; echo json_encode((require 'config/features.php')['equipment_decomposition_enabled']);",
                ], base_path(), ['APP_ENV' => $environment, 'EQUIPMENT_DECOMPOSITION_ENABLED' => $flag]);
                $process->mustRun();
                $this->assertSame($flag === 'true', json_decode($process->getOutput(), true));
            }
        }
    }

    public function test_all_three_types_return_their_own_stones_after_cumulative_rounding(): void
    {
        $character = $this->player();
        foreach (['weapon' => 'MAT_ENHANCE_FRAGMENT', 'armor' => '5007', 'accessory' => 'ACC0007'] as $type => $fragment) {
            $equipment = $this->equipment($character, $type, 5);
            $candidate = $this->service()->preview($character, $equipment);
            $returns = array_column($candidate['expected_materials'], 'quantity', 'material_code');
            $this->assertSame(22, $returns[$fragment]); // 30個を累計後に75%。段階ごとの切り捨てではない。
            $this->assertSame(6, $returns['MAT_COMMON_MONSTER_CORE']);
            $this->assertArrayNotHasKey('WEV0023', $returns); // 1個の75%は0個。
            $result = $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']);
            $this->assertSame($candidate['expected_materials'], $result['obtained_materials']);
            $this->assertDatabaseMissing('character_items', ['id' => $equipment->id]);
            $this->assertDatabaseHas('character_materials', [
                'character_id' => $character->id, 'material_id' => Material::where('material_code', $fragment)->value('id'), 'quantity' => 22,
            ]);
        }
        $character->refresh();
        $this->assertSame(1000, (int) $character->money);
        $this->assertSame(2000, (int) $character->bank_gold);
        $this->assertSame(7, (int) $character->free_kiseki);
        $this->assertSame(11, (int) $character->paid_kiseki);
        $this->assertDatabaseCount('equipment_decomposition_logs', 3);
        $this->assertDatabaseCount('gold_transactions', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_plus_thirty_returns_the_agreed_amounts_and_adds_to_existing_stock(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character, 'weapon', 30);
        $stone = Material::where('material_code', 'MAT_ENHANCE_STONE')->firstOrFail();
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $stone->id, 'quantity' => 4]);
        $candidate = $this->service()->preview($character, $equipment);
        $returns = array_column($candidate['expected_materials'], 'quantity', 'material_code');
        $this->assertSame(121, $returns['MAT_ENHANCE_STONE']);
        $this->assertSame(63, $returns['MAT_ENHANCE_HIGH_STONE']);
        $this->assertSame(4, $returns['MAT_REFINING_CORE']);
        $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']);
        $this->assertDatabaseHas('character_materials', ['character_id' => $character->id, 'material_id' => $stone->id, 'quantity' => 125]);
        $log = EquipmentDecompositionLog::firstOrFail();
        $this->assertSame(30, $log->enhancement_level);
        $this->assertSame($equipment->id, (int) $log->equipment_instance_id);
        $this->assertSame($candidate['expected_materials'], $log->obtained_materials);
    }

    public function test_market_purchases_and_evolved_instances_use_the_current_level(): void
    {
        $character = $this->player();
        foreach (['market_purchase', 'evolution'] as $source) {
            $equipment = $this->equipment($character, 'weapon', 1);
            $equipment->update(['acquired_from' => $source, 'market_relistable_at' => now()->addHours(72)]);
            $candidate = $this->service()->preview($character, $equipment);
            $this->assertSame(3, $candidate['returned_total']);
            $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']);
        }
    }

    public function test_unenhanced_equipped_locked_listed_and_special_equipment_cannot_be_disassembled(): void
    {
        $character = $this->player();
        foreach ([['enhance_level' => 0], ['is_equipped' => true], ['is_locked' => true], ['market_listing_id' => 123]] as $state) {
            $equipment = $this->equipment($character);
            $equipment->update($state);
            $this->reject(fn () => $this->service()->disassemble($character, $equipment, str_repeat('a', 64)));
            $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        }
        foreach ([['sell_price' => 0], ['sub_type' => '神印'], ['type' => 'mark']] as $state) {
            $equipment = $this->equipment($character);
            $equipment->item->update($state);
            $this->reject(fn () => $this->service()->disassemble($character, $equipment, str_repeat('a', 64)));
            $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        }
        $this->assertDatabaseCount('character_materials', 0);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
    }

    public function test_socketed_equipment_is_protected_even_when_the_relic_feature_is_off(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        PlayerRelic::create(['character_id' => $character->id, 'character_item_id' => $equipment->id, 'slot_number' => 1, 'effect_key' => 'stat_str', 'rank' => 1]);
        config(['nameless_relics.enabled' => false]);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, str_repeat('a', 64)), '遺物');
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('player_relics', 1);
    }

    public function test_other_owners_cannot_preview_or_execute(): void
    {
        $owner = $this->player();
        $other = $this->player();
        $equipment = $this->equipment($owner);
        $candidate = $this->service()->preview($owner, $equipment);
        $this->reject(fn () => $this->service()->preview($other, $equipment), '所持');
        $this->reject(fn () => $this->service()->disassemble($other, $equipment, $candidate['confirmation_hash']), '所持');
        $this->actingAs($other->user)->withSession(['current_character_id' => $other->id])
            ->get(route('smith.disassemble.confirm', $equipment))->assertNotFound();
        $this->postJson(route('smith.disassemble', $equipment), ['confirmed' => 1, 'confirmation_hash' => $candidate['confirmation_hash']])->assertNotFound();
        $this->assertDatabaseCount('character_materials', 0);
    }

    public function test_confirmation_is_rejected_after_enhancement_quality_or_recipe_changes(): void
    {
        $character = $this->player();
        foreach ([['enhance_level' => 2], ['affix_quality' => 'excellent']] as $change) {
            $equipment = $this->equipment($character);
            $candidate = $this->service()->preview($character, $equipment);
            $equipment->update($change);
            $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '確認し直');
        }
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        config(['equipment_enhancement.weapon_material_recipes.0.materials.0.quantity' => 9]);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '確認し直');
        $this->assertDatabaseCount('character_materials', 0);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
    }

    public function test_full_storage_rejects_without_losing_equipment_and_exact_fit_succeeds(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $material = Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->firstOrFail();
        $stock = CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 498]);
        $candidate = $this->service()->preview($character, $equipment);
        $this->assertFalse($candidate['can_receive']);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '入りきり');
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertSame(498, (int) $stock->fresh()->quantity);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
        $stock->update(['quantity' => 497]);
        $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']);
        $this->assertSame(500, (int) $stock->fresh()->quantity);
    }

    public function test_missing_material_master_and_logging_failure_leave_all_assets_unchanged(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        $material = Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->firstOrFail();
        $material->update(['material_code' => 'TEST_MISSING_FRAGMENT']);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), 'マスタ');
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $material->update(['material_code' => 'MAT_ENHANCE_FRAGMENT']);
        $stock = CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 2]);
        EquipmentDecompositionLog::creating(fn () => throw new RuntimeException('分解ログ保存失敗'));
        try {
            $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), 'ログ');
        } finally {
            EquipmentDecompositionLog::flushEventListeners();
        }
        $this->assertSame(2, (int) $stock->fresh()->quantity);
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('equipment_decomposition_logs', 0);
    }

    public function test_repeated_execution_never_grants_materials_twice(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '分解済み');
        $this->assertSame(3, (int) CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
        $this->assertDatabaseCount('equipment_decomposition_logs', 1);
    }

    public function test_direct_service_execution_during_map_exploration_is_rejected(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        $this->mock(MapExplorationItemService::class)->shouldReceive('restoreActiveSession')->once()->andReturn(new TownMapRegistration());
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '地図');
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('character_materials', 0);
    }

    public function test_authenticated_pages_confirmation_and_post_work_without_ajax(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character, 'armor', 5);
        $this->equipment($character, 'weapon');
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('smith.disassemble.index', ['type' => 'armor']))->assertOk()
            ->assertSee($equipment->displayName())->assertDontSee('分解試験weapon')
            ->assertSee(route('smith.disassemble.confirm', $equipment));
        $candidate = $this->service()->preview($character, $equipment);
        $confirm = $this->get(route('smith.disassemble.confirm', $equipment))->assertOk()
            ->assertSee('この個体と、強化・品質・銘・特攻・耐性を失います。')
            ->assertSee('name="confirmed"', false)->assertSee('分解せずに戻る')
            ->assertSee('HTMLFormElement.prototype.submit', false);
        if (getenv('DECOMPOSITION_CAPTURE_HTML') === '1') {
            $path = base_path('scratch/equipment-decomposition-20261005/preview');
            if (!is_dir($path)) { mkdir($path, 0777, true); }
            file_put_contents($path.'/confirm.html', $confirm->getContent());
            file_put_contents($path.'/index.html', $this->get(route('smith.disassemble.index', ['type' => 'armor']))->getContent());
        }
        $this->post(route('smith.disassemble', $equipment), [
            'confirmed' => 1, 'confirmation_hash' => $candidate['confirmation_hash'],
        ])->assertRedirect(route('smith.disassemble.index', ['type' => 'armor']))->assertSessionHas('status');
        $this->assertDatabaseMissing('character_items', ['id' => $equipment->id]);
    }

    public function test_post_requires_explicit_confirmation_and_hash(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        foreach ([[], ['confirmed' => 1], ['confirmed' => 0, 'confirmation_hash' => str_repeat('a', 64)]] as $payload) {
            $this->postJson(route('smith.disassemble', $equipment), $payload)->assertUnprocessable();
        }
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('character_materials', 0);
    }

    public function test_json_execution_succeeds_once_and_deleted_instance_retry_is_rejected(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $payload = ['confirmed' => 1, 'confirmation_hash' => $candidate['confirmation_hash']];
        $this->postJson(route('smith.disassemble', $equipment), $payload)->assertOk()->assertJsonPath('success', true);
        $this->postJson(route('smith.disassemble', $equipment), $payload)->assertNotFound();
        $this->assertDatabaseCount('equipment_decomposition_logs', 1);
        $this->assertSame(3, (int) CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
    }

    public function test_protection_added_after_confirmation_prevents_consumption(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $candidate = $this->service()->preview($character, $equipment);
        $equipment->update(['is_locked' => true]);
        $this->reject(fn () => $this->service()->disassemble($character, $equipment, $candidate['confirmation_hash']), '保護中');
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id, 'is_locked' => true]);
        $this->assertDatabaseCount('character_materials', 0);
    }

    public function test_legacy_equipment_endpoint_opens_confirmation_without_consuming_anything(): void
    {
        $character = $this->player();
        $equipment = $this->equipment($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->post(route('equipment.disassemble', $equipment))->assertRedirect(route('smith.disassemble.confirm', $equipment));
        $this->postJson(route('equipment.disassemble', $equipment))->assertUnprocessable()
            ->assertJsonPath('confirmation_url', route('smith.disassemble.confirm', $equipment));
        $this->assertDatabaseHas('character_items', ['id' => $equipment->id]);
        $this->assertDatabaseCount('character_materials', 0);
    }

    public function test_guest_cannot_access_decomposition(): void
    {
        $this->get(route('smith.disassemble.index'))->assertRedirect();
        $this->assertGuest();
    }

    private function player(): Character
    {
        $city = City::create(['name' => '分解試験街', 'sort_order' => 1]);
        $character = Character::create([
            'user_id' => User::factory()->create()->id, 'name' => '分解試験冒険者', 'current_city_id' => $city->id,
            'money' => 1000, 'bank_gold' => 2000, 'free_kiseki' => 7, 'paid_kiseki' => 11, 'kiseki' => 18,
        ]);
        $master = ValmonMaster::create(['valmon_key' => 'decomposition-'.$character->id, 'name' => '試験モン', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);

        return $character;
    }

    private function equipment(Character $character, string $type = 'weapon', int $level = 1): CharacterItem
    {
        $item = Item::create([
            'name' => '分解試験'.$type, 'type' => $type, 'rarity' => 'EPIC', $type.'_rank' => 'EPIC',
            'sell_price' => 100, 'is_active' => true,
        ]);

        return CharacterItem::create([
            'character_id' => $character->id, 'item_id' => $item->id, 'enhance_level' => $level,
            'is_equipped' => false, 'is_locked' => false, 'acquired_from' => 'drop',
        ])->fresh(['item']);
    }

    private function service(): EquipmentDecompositionService
    {
        return app(EquipmentDecompositionService::class);
    }

    private function reject(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
            $this->fail('分解が拒否されませんでした。');
        } catch (RuntimeException $e) {
            if ($message !== null) { $this->assertStringContainsString($message, $e->getMessage()); }
        }
    }
}
