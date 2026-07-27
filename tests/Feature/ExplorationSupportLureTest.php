<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Area;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerExplorationSupportEffect;
use App\Models\PlayerExplorationSupportItemState;
use App\Models\User;
use App\Services\ApothecaryService;
use App\Services\ExplorationSupportService;
use App\Services\ExtraContentControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationSupportLureTest extends TestCase
{
    use RefreshDatabase;

    public function test_exploration_support_and_apothecary_are_available_from_initial_progression_by_default(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $character = $this->createCharacter();

        $this->assertTrue(app(ExplorationSupportService::class)->isEnabled());

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('apothecary.index'))
            ->assertOk()
            ->assertSeeText('薬屋')
            ->assertSeeText('探索補助品')
            ->assertSeeText('必要素材')
            ->assertDontSeeText('必要素材（1回で1個完成）');
    }

    public function test_switching_support_items_preserves_each_opened_items_remaining_battles(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $beastItem = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        $undeadItem = Item::query()->where('name', '誘魔香〈不死〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $beastItem->id]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $undeadItem->id]);

        $service->activate($character, 'support_lure_beast');
        $beastState = PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $beastItem->id)
            ->firstOrFail();
        $beastState->update(['battles_remaining' => 42, 'battles_elapsed_in_period' => 8]);
        PlayerExplorationSupportEffect::query()
            ->where('character_id', $character->id)
            ->update(['battles_remaining' => 42, 'battles_elapsed_in_period' => 8]);

        $service->activate($character, 'support_lure_undead');
        $beastRecipe = collect(app(ApothecaryService::class)->recipesFor($character))
            ->firstWhere('code', 'lure_beast');
        $this->assertSame(42, $beastRecipe['remaining_battles']);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('apothecary.index'))
            ->assertOk()
            ->assertSeeText('再装備（残り42戦）');

        $resumed = $service->activate($character, 'support_lure_beast');

        $this->assertSame(42, $resumed['remaining']);
        $this->assertSame('support_lure_beast', $resumed['item_key']);
        $this->assertDatabaseHas('player_exploration_support_item_states', [
            'character_id' => $character->id,
            'item_id' => $undeadItem->id,
            'battles_remaining' => 50,
        ]);
        $this->assertSame(0, CharacterItem::query()->where('character_id', $character->id)->count());

        $service->clear($character);
        $this->assertDatabaseMissing('player_exploration_support_effects', ['character_id' => $character->id]);
        $this->assertDatabaseHas('player_exploration_support_item_states', [
            'character_id' => $character->id,
            'item_id' => $beastItem->id,
            'battles_remaining' => 42,
        ]);
    }

    public function test_lure_snapshot_only_consumes_a_battle_after_an_eligible_normal_encounter_roll(): void
    {
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        $service->activate($character, 'support_lure_beast');

        $beast = new Enemy(['species_key' => 'beast', 'appearance_weight' => 10]);
        $other = new Enemy(['species_key' => 'undead', 'appearance_weight' => 10]);
        $modifier = $service->encounterModifierFor($character, collect([$beast, $other]));

        $this->assertSame('beast', $modifier['species_key']);
        $this->assertSame(3, $modifier['multiplier']);

        $outsideNormalExploration = $service->beginBattle($character, $beast);
        $this->assertFalse($outsideNormalExploration['consume_battle']);

        $beast->setAttribute('exploration_support_encounter_applied', true);
        $normalExploration = $service->beginBattle($character, $beast);
        $this->assertTrue($normalExploration['consume_battle']);
    }

    public function test_lure_auto_renew_stock_is_not_consumed_until_the_battle_actually_starts(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        $service->activate($character, 'support_lure_beast', true);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->update(['battles_remaining' => 0]);
        PlayerExplorationSupportEffect::query()
            ->where('character_id', $character->id)
            ->update(['battles_remaining' => 0, 'auto_renew' => true]);
        $beast = new Enemy(['species_key' => 'beast', 'appearance_weight' => 10]);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('apothecary.index'))
            ->assertOk()
            ->assertSeeText('補充する（予備1個）');

        $this->assertNotNull($service->encounterModifierFor($character, collect([$beast])));
        $this->assertSame(1, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $item->id)->count());
        $this->assertNull($service->beginBattle($character, $beast));
        $this->assertSame(1, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $item->id)->count());

        $beast->setAttribute('exploration_support_encounter_applied', true);
        $snapshot = $service->beginBattle($character, $beast);
        $this->assertTrue($snapshot['consume_battle']);
        $this->assertSame(50, $snapshot['battles_remaining']);
        $this->assertSame(0, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $item->id)->count());
    }

    public function test_belongings_hide_an_inactive_depleted_item_without_reserve_stock(): void
    {
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $beastItem = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        $undeadItem = Item::query()->where('name', '誘魔香〈不死〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $beastItem->id]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $undeadItem->id]);

        $service->activate($character, 'support_lure_beast');
        PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $beastItem->id)
            ->update(['battles_remaining' => 0]);
        PlayerExplorationSupportEffect::query()
            ->where('character_id', $character->id)
            ->update(['battles_remaining' => 0]);
        $service->activate($character, 'support_lure_undead');

        $belongings = collect($service->belongingsFor($character));

        $this->assertNull($belongings->firstWhere('item_key', 'support_lure_beast'));
        $this->assertTrue($belongings->firstWhere('item_key', 'support_lure_undead')['is_active']);
    }

    public function test_exhausted_active_belonging_is_marked_as_used_up(): void
    {
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        $service->activate($character, 'support_lure_beast');
        PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->update(['battles_remaining' => 0]);
        PlayerExplorationSupportEffect::query()
            ->where('character_id', $character->id)
            ->update(['battles_remaining' => 0]);
        $enemy = new Enemy(['species_key' => 'beast', 'appearance_weight' => 10]);
        $enemy->setAttribute('exploration_support_encounter_applied', true);

        $html = view('apothecary.partials.belongings-list', [
            'belongings' => $service->belongingsFor($character),
            'speciesLuresEligible' => true,
        ])->render();

        $this->assertNull($service->beginBattle($character, $enemy));
        $this->assertStringContainsString('使い切り', $html);
        $this->assertStringNotContainsString('使用中', $html);
        $this->assertStringContainsString('残り 0/50戦', $html);
    }

    public function test_empty_belongings_modal_does_not_link_to_the_apothecary(): void
    {
        $html = view('apothecary.partials.belongings-list', [
            'belongings' => [],
            'speciesLuresEligible' => true,
        ])->render();

        $this->assertStringContainsString('所持している補助品はありません。', $html);
        $this->assertStringNotContainsString('薬屋で調合する', $html);
        $this->assertStringNotContainsString('href="'.route('apothecary.index').'"', $html);
    }

    public function test_apothecary_exposes_the_approved_species_lure_recipes_with_one_output(): void
    {
        $character = $this->createCharacter();
        $recipes = collect(app(ApothecaryService::class)->recipesFor($character))->keyBy('code');
        $expected = [
            'lure_beast' => ['魔物の欠片' => 2, '獣牙' => 3],
            'lure_undead' => ['魔物の欠片' => 2, '古びた骨片' => 3],
            'lure_dragon' => ['魔物の欠片' => 2, '魔物の外殻' => 3],
            'lure_demon' => ['魔物の欠片' => 2, '黒結晶' => 2],
            'lure_aquatic' => ['魔物の欠片' => 2, '清流の雫' => 2],
            'lure_flying' => ['魔物の欠片' => 2, '薄い翼膜' => 3],
            'lure_insect' => ['魔物の欠片' => 2, '守樹の樹脂' => 2],
            'lure_machine' => ['魔物の欠片' => 2, '魔鉱片' => 3],
            'lure_slime' => ['魔物の欠片' => 2, 'スライムの粘液' => 5],
            'lure_soldier' => ['魔物の欠片' => 2, '古びた徽章' => 3],
            'lure_mage' => ['魔物の欠片' => 2, '魔物の魔核' => 2],
            'lure_spirit' => ['魔物の欠片' => 2, '妖精粉' => 3],
        ];

        foreach ($expected as $code => $materials) {
            $recipe = $recipes->get($code);
            $this->assertNotNull($recipe, "Missing recipe: {$code}");
            $this->assertTrue($recipe['unlocked'], "Recipe should be initially unlocked: {$code}");
            $this->assertSame(1, $recipe['output_quantity']);
            $this->assertSame($materials, collect($recipe['requirements'])->pluck('quantity', 'name')->all());
        }

        $this->assertFalse($recipes->get('apothecary_charm')['unlocked']);
        $this->assertSame(3, $recipes->get('apothecary_charm')['output_quantity']);
    }

    public function test_initial_character_can_craft_a_species_lure_when_materials_are_owned(): void
    {
        $character = $this->createCharacter();
        $character->update(['money' => 1000]);
        $monsterFragment = Material::query()->where('name', '魔物の欠片')->firstOrFail();
        $beastFang = Material::query()->where('name', '獣牙')->firstOrFail();
        $lure = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $monsterFragment->id,
            'quantity' => 2,
        ]);
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $beastFang->id,
            'quantity' => 3,
        ]);

        $result = app(ApothecaryService::class)->craft($character, 'lure_beast', 1);

        $this->assertSame('誘魔香〈獣〉', $result['name']);
        $this->assertSame(1, $result['quantity']);
        $this->assertSame(1, CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $lure->id)
            ->count());
        $this->assertDatabaseMissing('character_materials', [
            'character_id' => $character->id,
            'material_id' => $monsterFragment->id,
        ]);
        $this->assertDatabaseMissing('character_materials', [
            'character_id' => $character->id,
            'material_id' => $beastFang->id,
        ]);
    }

    public function test_lure_for_a_species_absent_from_the_current_exploration_cannot_be_equipped(): void
    {
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $area = Area::query()->create(['name' => '不死だけの試験場', 'slug' => 'undead-only-test']);
        Enemy::query()->create(['area_id' => $area->id, 'name' => '試験用スケルトン', 'species_key' => 'undead']);
        CharacterExplorationState::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'started_at' => now(),
        ]);
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);

        $row = collect($service->belongingsFor($character, $area->id))->firstWhere('item_key', 'support_lure_beast');
        $this->assertFalse($row['is_effective_here']);
        $this->assertFalse($row['can_activate']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('この探索地には獣系の敵がいないため装備できません。');
        try {
            $service->activate($character, 'support_lure_beast');
        } finally {
            $this->assertDatabaseHas('character_items', [
                'character_id' => $character->id,
                'item_id' => $item->id,
            ]);
        }
    }

    public function test_lure_is_marked_unavailable_outside_normal_exploration_context(): void
    {
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $area = Area::query()->create(['name' => '獣の試験場', 'slug' => 'beast-context-test']);
        Enemy::query()->create(['area_id' => $area->id, 'name' => '試験用ウルフ', 'species_key' => 'beast']);
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);

        $row = collect($service->belongingsFor($character, $area->id, false))
            ->firstWhere('item_key', 'support_lure_beast');

        $this->assertFalse($row['is_effective_here']);
        $this->assertFalse($row['can_activate']);
        $this->assertSame('対象外：誘魔香は通常探索でのみ効果があります。', $row['effectiveness_note']);
    }

    public function test_async_equipment_change_returns_the_refreshed_modal_and_active_summary(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $character = $this->createCharacter();
        $this->enabledService();
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        $otherItem = Item::query()->where('name', '誘魔香〈不死〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $otherItem->id]);

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('apothecary.activate'), ['item_key' => 'support_lure_beast']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active.item_key', 'support_lure_beast')
            ->assertJsonPath('active.remaining', 50)
            ->assertJsonPath('active.max_battles', 50)
            ->assertJsonFragment(['message' => '探索補助品を装備しました。']);
        $belongingsHtml = (string) $response->json('belongings_html');
        $this->assertStringContainsString('data-belongings-container', $belongingsHtml);
        $this->assertStringContainsString('data-active-item-key="support_lure_beast"', $belongingsHtml);
        $this->assertStringContainsString('data-item-key="support_lure_undead"', $belongingsHtml);
        $this->assertStringContainsString('data-consumes-reserve="1"', $belongingsHtml);
        $this->assertStringContainsString('data-belonging-switch-confirm-modal', $belongingsHtml);
        $this->assertStringContainsString('現在のもちものは消えず、残り戦数が保存されます。', $belongingsHtml);
        $this->assertStringContainsString('残り 50/50戦', $belongingsHtml);
        $this->assertStringContainsString('switchConfirmModal(container = null)', $belongingsHtml);
        $this->assertStringContainsString('container.nextElementSibling', $belongingsHtml);
        $this->assertStringContainsString('data-species-lures-eligible="1"', $belongingsHtml);
    }

    public function test_async_refresh_preserves_non_normal_exploration_context(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $character = $this->createCharacter();
        $service = $this->enabledService();
        $item = Item::query()->where('name', '誘魔香〈獣〉')->firstOrFail();
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
        $service->activate($character, 'support_lure_beast');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('apothecary.auto-renew'), [
                'item_key' => 'support_lure_beast',
                'auto_renew' => true,
                'species_lures_eligible' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active.item_key', 'support_lure_beast');
        $belongingsHtml = (string) $response->json('belongings_html');
        $this->assertStringContainsString('data-species-lures-eligible="0"', $belongingsHtml);
        $this->assertStringContainsString('対象外：誘魔香は通常探索でのみ効果があります。', $belongingsHtml);
    }

    public function test_battle_result_places_recovery_items_before_the_belongings_summary(): void
    {
        $template = file_get_contents(resource_path('views/battle/result.blade.php'));
        $recoveryPosition = strpos($template, '{{-- 回復アイテム --}}');
        $belongingsPosition = strpos($template, 'id="battle-support-summary"');

        $this->assertNotFalse($recoveryPosition);
        $this->assertNotFalse($belongingsPosition);
        $this->assertLessThan($belongingsPosition, $recoveryPosition);
    }

    private function enabledService(): ExplorationSupportService
    {
        app(ExtraContentControlService::class)->setEnabled(ExplorationSupportService::CONTENT_KEY, true);

        return app(ExplorationSupportService::class);
    }

    private function createCharacter(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '誘魔香テスト',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 0,
        ]);
    }
}
