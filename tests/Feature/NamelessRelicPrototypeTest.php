<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GameSetting;
use App\Models\Item;
use App\Models\Material;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\GameSettingService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRuinService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NamelessRelicPrototypeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\SharedStorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        config(['nameless_relics.cleared_boss_encounter_bps' => 0, 'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.equipment_drop_chance_bps' => 0]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    public function test_disabled_feature_fails_closed_in_all_environments_with_existing_equipment(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertFalse($this->workshop()->ready());
            $this->assertSame(0, $this->workshop()->equippedBonuses($character)['str']);
            $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)->get(route('nameless-workshop.index'))->assertNotFound();
        }
        $this->app->instance('env', 'testing');
        config(['nameless_relics.enabled' => false]);
        $this->assertFalse($this->workshop()->ready());
        $this->reject(fn () => $this->workshop()->claim($character, 'armor', '鎧', $this->uuid()), '現在利用できません');
    }

    public function test_claim_is_unique_and_request_replay_cannot_change_payload(): void
    {
        $character = $this->character();
        $uuid = $this->uuid();
        $first = $this->workshop()->claim($character, 'weapon', '杖', $uuid);
        $this->assertSame($first, $this->workshop()->claim($character, 'weapon', '杖', $uuid));
        $this->workshop()->claim($character, 'weapon', '剣', $this->uuid());
        $this->assertSame(1, PlayerNamelessEquipment::query()->count());
        $this->reject(fn () => $this->workshop()->claim($character, 'armor', '鎧', $uuid), '内容を変更');
    }

    public function test_first_workshop_visit_shows_gantz_and_weapon_choices_until_claimed(): void
    {
        $character = $this->character();
        $this->body($character, 'armor'); // 旧配布済み防具を保持しても、武器の初回会話は表示する。
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);

        foreach (range(1, 2) as $visit) {
            $response = $this->get(route('nameless-workshop.index'))->assertOk()
                ->assertViewIs('nameless-workshop.introduction')->assertSee('鉄槌のガンツ')
                ->assertSee('images/npc/gantz-illustration.webp')->assertSee('data-nameless-intro-cut', false)
                ->assertSee('data-nameless-intro-talk', false)->assertSee('最初の一本を選ぶ');
            $response->assertSee("setAttribute('data-intro-ready', '')", false);
            // Model wire:navigate's scripting-disabled HTML parsing, including styles inside noscript.
            $dom = new \DOMDocument;
            $dom->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            $fallbackCss = $xpath->query('//noscript/style')->item(0)->textContent;
            $this->assertSame(1, preg_match('/([^{}]+)\{\s*display\s*:\s*none\s*\}/', $fallbackCss, $hiddenRule));
            $selector = (new \Symfony\Component\CssSelector\CssSelectorConverter)->toXPath(trim($hiddenRule[1]));
            $this->assertSame(1, $xpath->query($selector)->length); // no-JS fallback hides the inert button
            $xpath->query('//*[@data-nameless-introduction]')->item(0)->setAttribute('data-intro-ready', '');
            $this->assertSame(0, $xpath->query($selector)->length); // initialized conversation remains operable
            foreach (array_keys(\App\Services\NamelessEquipmentService::statOptionsFor('weapon')) as $type) {
                $response->assertSee('value="'.$type.'"', false);
            }
        }
        $this->assertSame(0, $character->namelessEquipments()->where('kind', 'weapon')->count());
        $payload = ['kind' => 'weapon', 'type' => '杖', 'request_uuid' => $this->uuid()];
        $receipt = $this->post(route('nameless-workshop.act', 'claim'), $payload);
        $weapon = $character->namelessEquipments()->where('kind', 'weapon')->sole();
        $receiptUrl = route('nameless-workshop.index', ['equipment' => $weapon->id, 'conversation' => 'received']);
        $receipt->assertRedirect($receiptUrl);
        foreach (range(1, 2) as $reload) {
            $this->get($receiptUrl)->assertOk()->assertViewIs('nameless-workshop.introduction')
                ->assertViewHas('receivedEquipment', fn ($body) => $body->id === $weapon->id)
                ->assertSee('data-nameless-receipt-dialogue', false)->assertSee('無限の力')
                ->assertSee('遺物を組み込める')->assertSee('工房を開く')
                ->assertDontSee('data-nameless-starter-choice', false)->assertDontSee('data-nameless-forge', false);
        }
        $this->assertSame('杖', $weapon->equipment_type);
        $this->assertSame('starter', $weapon->acquisition_source);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertFalse($this->workshop()->needsIntroduction($character));
        $this->get(route('nameless-workshop.index'))->assertOk()->assertViewIs('nameless-workshop.index')
            ->assertDontSee('data-nameless-introduction', false)->assertSee('育てる武具を選ぶ')
            ->assertDontSee('名もなき防具を受け取る');
        $this->post(route('nameless-workshop.act', 'claim'), $payload)->assertRedirect();
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'weapon', 'type' => '剣', 'request_uuid' => $this->uuid()])->assertRedirect();
        $this->assertSame(1, $character->namelessEquipments()->where('kind', 'weapon')->count());
        $this->assertSame('杖', $weapon->fresh()->equipment_type);
        $this->assertSame(0, \App\Models\NamelessEquipmentDiscovery::query()->where('character_id', $character->id)->count());
    }

    public function test_receipt_conversation_requires_owned_starter_weapon_and_keeps_filters(): void
    {
        $character = $this->character();
        $foreign = $this->body($this->character());
        $ruin = $this->droppedBody($character);
        $armor = $this->body($character, 'armor');
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'weapon', 'type' => '剣', 'gear_kind' => 'weapon', 'request_uuid' => $this->uuid()])->assertRedirect();
        $weapon = $character->namelessEquipments()->where('kind', 'weapon')->where('acquisition_source', 'starter')->sole();
        $url = route('nameless-workshop.index', ['conversation' => 'received', 'equipment' => $weapon->id, 'gear_kind' => 'weapon']);
        $this->get($url)->assertOk()->assertViewHas('workshopUrl', route('nameless-workshop.index', ['gear_kind' => 'weapon', 'tab' => 'workshop', 'equipment' => $weapon->id]));
        foreach ([$foreign->id, $ruin->id, $armor->id] as $id) {
            $this->get(route('nameless-workshop.index', ['conversation' => 'received', 'equipment' => $id]))->assertNotFound();
        }
        $this->get(route('nameless-workshop.index', ['conversation' => 'received']))->assertNotFound();
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(1, $character->namelessEquipments()->where('kind', 'weapon')->where('acquisition_source', 'starter')->count());
    }

    public function test_starter_armor_claim_is_disabled_in_http_and_service_without_losing_existing_armor(): void
    {
        $character = $this->character();
        $armor = $this->body($character, 'armor');
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'armor', 'type' => '鎧', 'request_uuid' => $this->uuid()])->assertSessionHasErrors('kind');
        $this->reject(fn () => $this->workshop()->claim($character, 'armor', '鎧', $this->uuid()), '武器だけ');
        $this->assertSame([$armor->id], $character->namelessEquipments()->pluck('id')->all());
        $this->assertSame(0, NamelessWorkshopOperation::query()->where('character_id', $character->id)->count());
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->body($character);
        $this->get(route('nameless-workshop.index'))->assertOk()->assertDontSee('名もなき防具を受け取る');
        $this->assertTrue($armor->fresh()->exists);
    }

    public function test_introduction_is_per_character_and_invalid_weapon_cannot_complete_it(): void
    {
        $veteran = $this->character();
        $this->body($veteran);
        $character = $this->character();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index'))->assertOk()->assertViewIs('nameless-workshop.introduction');
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'weapon', 'type' => '鎧', 'request_uuid' => $this->uuid()])
            ->assertRedirect()->assertSessionHas('error', 'この武具種別は選択できません。');
        $this->assertTrue($this->workshop()->needsIntroduction($character));
        $this->assertSame(0, $character->namelessEquipments()->count());
    }

    public function test_full_inventory_can_be_managed_before_the_introduction_weapon_is_claimed(): void
    {
        $character = $this->character();
        $body = PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'acquisition_source' => 'ruin']);
        $this->reserveEquipmentSlots($character, 0);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index'))->assertOk()->assertViewIs('nameless-workshop.index')
            ->assertSee('武具を保護・整理する');
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'weapon', 'type' => '杖', 'request_uuid' => $this->uuid()])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($this->workshop()->needsIntroduction($character));
        $this->assertSame(1, $character->namelessEquipments()->count());
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertViewIs('nameless-workshop.index');
    }

    public function test_ownership_slots_and_duplicate_effect_are_enforced(): void
    {
        $character = $this->character();
        $other = $this->character();
        $weapon = $this->body($character);
        $armor = $this->body($character, 'armor');
        $relic = $this->relic($character);
        $duplicate = $this->relic($character, 'stat_str', 2);
        $this->reject(fn () => $this->workshop()->attach($other, $weapon->id, 1, $relic->id, $this->uuid()), '所持していません');
        $this->reject(fn () => $this->workshop()->attach($character, $weapon->id, 4, $relic->id, $this->uuid()), '枠が不正');
        $this->workshop()->changeEquipment($character, $weapon->id, true, $this->uuid());
        $this->workshop()->changeEquipment($character, $armor->id, true, $this->uuid());
        $this->workshop()->attach($character, $weapon->id, 1, $relic->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->attach($character, $armor->id, 1, $duplicate->id, $this->uuid()), '一つまで');
        $this->workshop()->attach($character, $weapon->id, 1, $duplicate->id, $this->uuid());
        $this->assertNull($relic->fresh()->nameless_equipment_id);
        $this->assertSame(2, PlayerRelic::query()->count());
        $this->workshop()->detach($character, $duplicate->id, $this->uuid());
        $this->assertNull($duplicate->fresh()->nameless_equipment_id);
    }

    public function test_normal_and_nameless_equipment_are_mutually_exclusive_and_stats_follow_slots(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $item = Item::query()->create(['name' => '通常の試験剣', 'type' => 'weapon', 'str_bonus' => 300, 'is_active' => true]);
        $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'is_equipped' => true, 'equipped_slot' => 'weapon']);
        $relic = $this->relic($character, 'stat_str', 9);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->assertFalse((bool) $owned->fresh()->is_equipped);
        $this->assertSame(11519, app(CharacterStatusService::class)->getFinalStats($character)['str']);
        $result = app(EquipmentService::class)->equip($character, $owned);
        $this->assertTrue($result['success']);
        $this->assertFalse($body->fresh()->is_equipped);
        $this->assertSame(0, $this->workshop()->equippedBonuses($character)['str']);
    }

    public function test_removing_hp_relic_clamps_hp_and_equipping_does_not_heal(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $relic = $this->relic($character, 'stat_hp', 9);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->assertSame(10000, (int) $character->fresh()->current_hp);
        $character->update(['current_hp' => 10280]);
        $this->workshop()->detach($character, $relic->id, $this->uuid());
        $this->assertSame(10000, (int) $character->fresh()->current_hp);
    }

    public function test_normal_equipment_preview_removes_replaced_nameless_body_and_relics_only(): void
    {
        foreach (['weapon', 'armor'] as $kind) {
            $character = $this->character();
            $body = $this->body($character, $kind);
            $otherBody = $this->body($character, $kind === 'weapon' ? 'armor' : 'weapon');
            $body->update(['forge_level' => 99]);
            $otherBody->update(['forge_level' => 99]);
            $this->workshop()->attach($character, $body->id, 1, $this->relic($character, 'stat_all', 9)->id, $this->uuid());
            $this->workshop()->attach($character, $body->id, 2, $this->relic($character, 'stat_hp', 9)->id, $this->uuid());
            $this->workshop()->attach($character, $otherBody->id, 1, $this->relic($character, 'stat_mp', 5)->id, $this->uuid());
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
            $this->workshop()->changeEquipment($character, $otherBody->id, true, $this->uuid());
            $item = Item::query()->create(['name' => '比較用の通常装備', 'type' => $kind, 'str_bonus' => 300, 'mag_bonus' => 100, 'def_bonus' => 30, 'spr_bonus' => 20, 'hp_bonus' => 15, 'mp_bonus' => 7, 'agi_bonus' => 3, 'luk_bonus' => 2, 'is_active' => true]);
            $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id]);
            $service = app(CharacterStatusService::class);
            $preview = $service->equipmentSwapPreviewForItem($character, $item);
            $primary = $kind === 'weapon' ? $service->weaponEffectivePreview($character, $owned) : $service->armorEffectivePreview($character, $owned);
            $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
            $actual = $service->getFinalStats($character->fresh());
            foreach (['hp' => 'max_hp', 'mp' => 'max_mp', 'str' => 'str', 'def' => 'def', 'mag' => 'mag', 'spr' => 'spr', 'agi' => 'agi', 'luk' => 'luk'] as $key => $finalKey) {
                $this->assertSame($actual[$finalKey], $preview['after_stats'][$key], $kind.' '.$key);
            }
            foreach ($primary as $key => $value) {
                $this->assertSame($actual[$key], $value);
            }
            $this->assertTrue($otherBody->fresh()->is_equipped);
        }
    }

    public function test_feed_preview_and_confirmation_are_atomic_and_idempotent_without_effect_inheritance(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $relic = $this->relic($character, 'special_critical', 1);
        $this->relic($character, 'special_critical', 2);
        $materials = [$material->id => 20];
        $relics = [$relic->id];
        $preview = $this->workshop()->previewFeed($character, $body->id, $materials, $relics, 10, true);
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->assertSame(23, $preview['gained_exp']);
        $uuid = $this->uuid();
        $first = $this->workshop()->feed($character, $body->id, $materials, $relics, 10, true, $preview['confirmation_hash'], $uuid);
        $this->assertSame($first, $this->workshop()->feed($character, $body->id, $materials, $relics, 10, true, $preview['confirmation_hash'], $uuid));
        $this->assertSame(80, (int) $material->fresh()->quantity);
        $this->assertSame(23, $body->fresh()->growth_exp);
        $this->assertNull($relic->fresh());
        $this->assertCount(0, $body->fresh()->relics);
    }

    public function test_sp_relic_changes_maximum_without_healing_and_removal_clamps_current_sp(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $relic = $this->relic($character, 'stat_mp', 9);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->assertSame(1150, app(CharacterStatusService::class)->getFinalStats($character)['max_mp']);
        $this->assertSame(500, (int) $character->fresh()->current_mp);
        $character->forceFill(['current_mp' => 1150])->save();
        $this->workshop()->detach($character, $relic->id, $this->uuid());
        $this->assertSame(1000, (int) $character->fresh()->current_mp);
        $this->assertSame(1000, app(CharacterStatusService::class)->getFinalStats($character)['max_mp']);
    }

    public function test_feed_refuses_protection_highest_equipped_important_or_foreign_assets(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $relic = $this->relic($character);
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [], [$relic->id], 0, true), '最高ランク');
        $relic->update(['is_locked' => true]);
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [], [$relic->id], 0, false), '保護中');
        $relic->update(['is_locked' => false]);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [], [$relic->id], 0, false), '装着中');
        $material = $this->material($character);
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [$material->id => 100], [], 10, true), '残す');
        $material->material->update(['is_key_item' => true]);
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [$material->id => 1], [], 0, true), '吸収できない');
        $other = $this->material($this->character());
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [$other->id => 1], [], 0, true), '吸収できない');
        $this->assertSame(100, (int) $material->fresh()->quantity);
    }

    public function test_stale_feed_confirmation_does_not_consume_anything(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $preview = $this->workshop()->previewFeed($character, $body->id, [$material->id => 20], [], 0, true);
        $material->increment('quantity');
        $this->reject(fn () => $this->workshop()->feed($character, $body->id, [$material->id => 20], [], 0, true, $preview['confirmation_hash'], $this->uuid()), '確認し直して');
        $this->assertSame(101, (int) $material->fresh()->quantity);
        $this->assertSame(0, $body->fresh()->growth_exp);
    }

    public function test_forge_requires_exp_and_gold_respects_bank_choice_and_replay(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, true, $this->uuid()), 'EXPが足りません');
        $body->update(['growth_exp' => 100]);
        $character->update(['money' => 20, 'bank_gold' => 1000]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $this->uuid()), '確認が必要');
        $uuid = $this->uuid();
        $this->workshop()->forge($character, $body->id, 0, true, $uuid);
        $this->workshop()->forge($character, $body->id, 0, true, $uuid);
        $this->assertSame(1, $body->fresh()->forge_level);
        $this->assertSame(80, $body->fresh()->growth_exp);
        $this->assertSame(20, $character->fresh()->bank_gold);
        $this->assertSame(0, $character->fresh()->money);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, true, $this->uuid()), '状態が変わりました');
    }

    public function test_body_cap_depends_on_ruin_progress_and_maxed_body_cannot_eat(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 10, 'growth_exp' => 10000]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, true, $this->uuid()), '育成上限');
        NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 20]);
        $this->assertSame(99, $this->workshop()->forgeCap($character));
        $body->update(['forge_level' => 99]);
        $this->reject(fn () => $this->workshop()->previewFeed($character, $body->id, [], [], 0, false), '最大強化済み');
    }

    public function test_battle_effects_are_only_built_from_equipped_relics(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $relic = $this->relic($character, 'killer_beast', 9);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $actor = new BattleActor('検証', true, ['hp' => 100, 'max_hp' => 100]);
        app(NamelessRelicBattleService::class)->attach($character, $actor);
        $this->assertSame([], $actor->weaponKillerEffects);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        app(NamelessRelicBattleService::class)->attach($character, $actor);
        $this->assertSame('beast', $actor->weaponKillerEffects[0]['species_key']);
        $this->assertSame(.28, $actor->weaponKillerEffects[0]['damage_rate']);
    }

    public function test_actual_ruin_boss_battle_awards_once_advances_only_its_zone_and_consumes_stamina(): void
    {
        $character = $this->character();
        config(['nameless_relics.boss_drop_chance_bps' => 10000]);
        $uuid = $this->uuid();
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, true, $uuid);
        $this->assertSame('victory', $result['battle_result']);
        $this->assertSame('碑門の衛士ガルム', $result['enemy_name']);
        $this->assertTrue($result['advanced']);
        $this->assertSame($result, app(NamelessRuinService::class)->fight($character, 'sand', 1, true, $uuid));
        $this->assertSame(1, PlayerRelic::query()->count());
        $this->assertSame(97, $character->fresh()->explore_stamina);
        $this->assertSame(10000, $character->fresh()->money);
        $this->assertSame(0, (int) $character->fresh()->wins);
        $this->assertSame(2, (int) NamelessRuinProgress::query()->where('zone_key', 'sand')->value('unlocked_depth'));
        $this->assertSame(1, NamelessRuinProgress::query()->count());
        $this->assertSame(1, NamelessWorkshopOperation::query()->where('action', 'ruin')->count());
    }

    public function test_actual_ruin_defeat_has_no_drop_or_progress_and_persists_standard_defeat_resources(): void
    {
        $character = $this->character();
        $this->unlockThrough($character, 'forge');
        $character->update(['hp_base' => 10, 'current_hp' => 1, 'attack_base' => 1, 'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        config(['nameless_relics.boss_drop_chance_bps' => 10000]);
        $result = app(NamelessRuinService::class)->fight($character, 'forge', 1, true, $this->uuid());
        $this->assertSame('defeat', $result['battle_result']);
        $this->assertNull($result['drop']);
        $this->assertFalse($result['advanced']);
        $this->assertSame(3, (int) $character->fresh()->current_hp);
        $this->assertSame(100, (int) $character->fresh()->current_mp);
    }

    public function test_unopened_depth_and_full_inventory_do_not_consume_stamina(): void
    {
        $character = $this->character();
        $this->reject(fn () => app(NamelessRuinService::class)->fight($character, 'sand', 2, false, $this->uuid()), '未解放');
                $this->relic($character);
        $this->reserveMaterialSlots($character, 0);
        $this->reject(fn () => app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid()), '所持枠');
        $this->assertSame(100, $character->fresh()->explore_stamina);
        $this->assertSame(0, NamelessRuinProgress::query()->count());
    }

    public function test_all_pages_render_and_feed_requires_confirmation(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $this->relic($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        foreach (['workshop', 'relics'] as $tab) {
            $this->get(route('nameless-workshop.index', ['tab' => $tab]))->assertOk()->assertSee('名もなき')
                ->assertDontSee('action="'.route('nameless-workshop.act', 'fight').'"', false);
        }
        $payload = ['equipment_id' => $body->id, 'materials' => [$material->id => 5], 'keep' => 10, 'protect_best' => 1, 'request_uuid' => $this->uuid()];
        $this->post(route('nameless-workshop.act', 'preview-feed'), $payload)->assertOk()->assertSee('吸収内容の確認');
        $preview = $this->workshop()->previewFeed($character, $body->id, $payload['materials'], [], 10, true);
        $this->post(route('nameless-workshop.act', 'feed'), $payload + ['confirmation_hash' => $preview['confirmation_hash']])->assertSessionHasErrors('confirmed');
        $this->assertSame(100, (int) $material->fresh()->quantity);
    }

    public function test_relic_collection_and_failed_fight_return_directly_to_the_exploration_tab(): void
    {
        $character = $this->character();
        $this->body($character);
        $character->update(['explore_stamina' => 0, 'explore_stamina_updated_at' => now()]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['tab' => 'relics']))->assertOk()
            ->assertSee(route('nameless-workshop.return', ['tab' => 'dungeon']), false)->assertDontSee('tab=ruins', false);
        $this->post(route('nameless-workshop.act', 'fight'), [
            'zone' => 'water', 'depth' => 1, 'boss' => 0, 'request_uuid' => $this->uuid(),
        ])->assertRedirect(route('home', ['skip_resume' => 1]))
            ->assertSessionHas('current_location', 'dungeon')->assertSessionHas('error');
        $this->assertSame(0, (int) $character->fresh()->explore_stamina);
        $this->assertSame(0, PlayerRelic::query()->where('character_id', $character->id)->count());
        $this->assertSame(0, NamelessWorkshopOperation::query()->where('character_id', $character->id)->where('action', 'ruin')->count());
    }

    public function test_discard_keeps_protected_equipped_and_best_relics_and_frees_space_after_max_forge(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 99]);
        $best = $this->relic($character, 'stat_str', 9);
        $spare = $this->relic($character, 'stat_str', 1);
        $this->reject(fn () => $this->workshop()->discard($this->character(), $spare->id, $this->uuid()), '未所持');
        $this->reject(fn () => $this->workshop()->discard($character, $best->id, $this->uuid()), '最高ランク');
        $spare->update(['is_locked' => true]);
        $this->reject(fn () => $this->workshop()->discard($character, $spare->id, $this->uuid()), '保護中');
        $spare->update(['is_locked' => false]);
        $this->workshop()->attach($character, $body->id, 1, $spare->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->discard($character, $spare->id, $this->uuid()), '装着中');
        $this->workshop()->detach($character, $spare->id, $this->uuid());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $uuid = $this->uuid();
        $payload = ['relic_id' => $spare->id, 'request_uuid' => $uuid];
        $this->post(route('nameless-workshop.act', 'discard'), $payload)->assertSessionHasErrors('confirmed');
        $this->assertNotNull($spare->fresh());
        $this->post(route('nameless-workshop.act', 'discard'), $payload + ['confirmed' => 1])->assertRedirect();
        $result = $this->workshop()->discard($character, $spare->id, $uuid);
        $this->assertSame($spare->id, $result['discarded']['id']);
        $this->assertNull($spare->fresh());
        $this->assertNotNull($best->fresh());
        $this->assertSame(0, $body->fresh()->growth_exp);
        config(['nameless_relics.drop_chance_bps' => 10000]);
        $this->reserveMaterialSlots($character, 1);
        $battle = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid());
        $this->assertSame('victory', $battle['battle_result']);
        $this->assertNotNull($battle['drop']);
        $this->assertFalse($battle['boss']);
        $this->assertFalse($battle['advanced']);
        $normalNames = array_column(array_slice(config('nameless_ruins.sand.enemies'), 0, 4), 'name');
        $this->assertContains($battle['enemy_name'], $normalNames);
        $this->assertSame(99, $character->fresh()->explore_stamina);
    }

    public function test_workshop_town_install_is_idempotent_and_never_creates_normal_areas(): void
    {
        $service = app(\App\Services\NamelessTownService::class);
        $town = $service->installLocalTown();
        $this->assertSame($town->id, $service->installLocalTown()->id);
        $this->assertSame(0, (int) $town->sort_order);
        $this->assertFalse((bool) $town->is_initial);
        $this->assertSame(0, $town->areas()->count());
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertNull($service->availableTown());
            $this->reject(fn () => $service->installLocalTown(), '現在利用できません');
        }
    }

    public function test_workshop_town_travel_does_not_advance_normal_progress_and_is_hidden_when_disabled(): void
    {
        $character = $this->character();
        $capital = \App\Models\City::query()->create(['name' => '試験王都', 'sort_order' => 10, 'is_initial' => true]);
        $character->update(['current_city_id' => $capital->id, 'highest_city_id' => $capital->id]);
        $town = app(\App\Services\NamelessTownService::class)->availableTown();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('city.index'))->assertOk()->assertSee('data-nameless-town-entry', false);
        $this->post(route('city.travel', $town))->assertRedirect(route('nameless-workshop.index'))->assertSessionHas('current_location', 'town');
        $this->assertSame($town->id, $character->fresh()->current_city_id);
        $this->assertSame($capital->id, $character->fresh()->highest_city_id);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->post(route('city.travel', $capital))->assertRedirect()->assertSessionHas('current_location', 'dungeon');
        config(['nameless_relics.enabled' => false]);
        $this->get(route('city.index'))->assertOk()->assertDontSee('data-nameless-town-entry', false);
        $this->post(route('city.travel', $town))->assertNotFound();
        $this->assertSame($capital->id, $character->fresh()->current_city_id);
    }

    public function test_workshop_marker_uses_both_world_maps_and_current_location_state(): void
    {
        $character = $this->character();
        $town = app(\App\Services\NamelessTownService::class)->availableTown();
        $capital = \App\Models\City::query()->create(['name' => '王都アークレア', 'sort_order' => 10, 'is_initial' => true]);
        $character->update(['current_city_id' => $capital->id, 'highest_city_id' => $capital->id]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);

        $assertMarker = function (string $html, bool $current) use ($town): void {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $markers = $xpath->query('//*[@data-nameless-town-entry]');
            $this->assertSame(1, $markers->length);
            $marker = $markers->item(0);
            $this->assertStringContainsString('left: 28%; top: 12%;', $marker->getAttribute('style'));
            $this->assertStringContainsString('名もなき工房', $marker->textContent);
            $this->assertSame(1, $xpath->query('./preceding-sibling::img[@alt="ヴァルゼリア大陸MAP"]', $marker)->length);
            $forms = $xpath->query('.//form', $marker);
            $this->assertSame($current ? 0 : 1, $forms->length);
            if ($current) {
                $this->assertStringContainsString('現在', $marker->textContent);
            } else {
                $this->assertSame(route('city.travel', $town), $forms->item(0)->getAttribute('action'));
                $this->assertStringContainsString('移動', $marker->textContent);
            }
        };

        $assertMarker($this->get(route('city.index'))->assertOk()->getContent(), false);
        $assertMarker(\Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'move'])->html(), false);
        $this->post(route('city.travel', $town))->assertRedirect(route('nameless-workshop.index'))->assertSessionHas('current_location', 'town');
        $assertMarker($this->get(route('city.index'))->assertOk()->getContent(), true);
        $assertMarker(\Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'move'])->html(), true);
        $samples = app(\App\Services\CityPopulationService::class)->iconSamplesByCity();
        $this->assertSame(28.0, $samples[$town->id][0]['x']);
        $this->assertSame(12.0, $samples[$town->id][0]['y']);
        $this->assertSame($capital->id, $character->fresh()->highest_city_id);
        foreach (['local', 'production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            config(['nameless_relics.enabled' => false]);
            $this->get(route('city.index'))->assertOk()->assertDontSee('data-nameless-town-entry', false);
            \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'move'])
                ->assertDontSee('data-nameless-town-entry', false);
        }
    }

    public function test_workshop_is_only_used_in_town_and_old_ruin_links_open_the_exploration_tab(): void
    {
        $character = $this->character();
        $townId = $character->current_city_id;
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['tab' => 'ruins', 'zone' => 'water']))
            ->assertRedirect(route('home', ['skip_resume' => 1]))->assertSessionHas('current_location', 'dungeon');
        $this->get(route('nameless-workshop.index', ['tab' => 'ruins']))
            ->assertRedirect(route('home', ['skip_resume' => 1]))->assertSessionHas('current_location', 'dungeon');
        $this->get(route('nameless-workshop.index', ['tab' => 'ruins', 'zone' => 'invalid']))->assertNotFound();
        $this->get(route('nameless-workshop.return', ['tab' => 'dungeon']))->assertRedirect()->assertSessionHas('current_location', 'dungeon');
        $character->update(['current_city_id' => null]);
        $this->get(route('nameless-workshop.index'))->assertOk()->assertSee('無もなき工房街へ移動')->assertDontSee('武具を育てる');
        $this->post(route('nameless-workshop.act', 'claim'), ['kind' => 'weapon', 'type' => '剣', 'request_uuid' => $this->uuid()])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, PlayerNamelessEquipment::query()->where('character_id', $character->id)->count());
        $character->update(['current_city_id' => $townId]);
        $this->workshop()->claim($character, 'weapon', '剣', $this->uuid());
        $this->assertSame(1, PlayerNamelessEquipment::query()->where('character_id', $character->id)->count());
    }

    public function test_character_without_highest_city_can_return_to_initial_city_without_unlocking_others(): void
    {
        $character = $this->character();
        $capital = \App\Models\City::query()->create(['name' => '試験王都', 'sort_order' => 10, 'is_initial' => true]);
        $later = \App\Models\City::query()->create(['name' => '未到達街', 'sort_order' => 20]);
        $this->assertSame((int) \App\Models\City::query()->where('is_initial', true)->value('id'), app(\App\Services\NamelessTownService::class)->normalProgressCityId($character));
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->post(route('city.travel', $later))->assertSessionHas('error');
        $this->post(route('city.travel', $capital))->assertRedirect()->assertSessionHas('current_location', 'dungeon');
        $this->assertNull($character->fresh()->highest_city_id);
        $this->assertSame($capital->id, $character->fresh()->current_city_id);
    }

    public function test_ruins_appear_in_order_only_after_each_previous_depth_fifty_boss_is_cleared(): void
    {
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        $keys = array_keys(config('nameless_ruins'));
        // 旧試作で後方の遺跡を進めていても、前方の条件を飛ばせない。記録自体は保持する。
        NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'tomb', 'unlocked_depth' => 77]);
        $other = $this->character();
        $this->unlockThrough($other, 'tomb');
        $this->assertSame(['sand'], array_keys($service->availableZones($character)));
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $screen = \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'dungeon']);
        $screen->assertSee('砂埋の参道')->assertSee('深度50のボス撃破で、新たな遺跡への道が開きます。');
        foreach (array_slice(config('nameless_ruins'), 1) as $zone) {
            $screen->assertDontSee($zone['name']);
        }
        $this->assertSame(1, substr_count($screen->html(), 'data-nameless-ruin-card'));
        foreach (array_slice($keys, 0, -1) as $index => $key) {
            $progress = NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => $key, 'unlocked_depth' => 50]);
            $this->assertSame(array_slice($keys, 0, $index + 1), array_keys($service->availableZones($character)));
            $progress->update(['unlocked_depth' => 51]);
            $this->assertSame(array_slice($keys, 0, $index + 2), array_keys($service->availableZones($character)));
        }
        $this->assertSame(77, (int) NamelessRuinProgress::query()->where('character_id', $character->id)->where('zone_key', 'tomb')->value('unlocked_depth'));
    }

    public function test_locked_ruin_requests_cannot_fight_or_spend_resources(): void
    {
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        foreach (array_slice(array_keys(config('nameless_ruins')), 1) as $key) {
            foreach ([[false, 1], [false, 10], [true, 1]] as [$boss, $count]) {
                $this->reject(fn () => $service->fight($character, $key, 1, $boss, $this->uuid(), $count), '未解放の遺跡');
            }
        }
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'water', 'depth' => 1, 'boss' => 1, 'request_uuid' => $this->uuid()])
            ->assertRedirect(route('home', ['skip_resume' => 1]))->assertSessionHas('error', '未解放の遺跡です。先に直前の遺跡の深度50のボスを倒してください。');
        $this->assertSame(100, (int) $character->fresh()->explore_stamina);
        $this->assertSame(10000, (int) $character->fresh()->current_hp);
        $this->assertSame(500, (int) $character->fresh()->current_mp);
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
        $this->assertSame(0, NamelessRuinProgress::query()->count());
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_depth_fifty_boss_victory_unlocks_next_ruin_and_replay_preserves_rewards(): void
    {
        $character = $this->character();
        $character->update(['hp_base' => 300000, 'current_hp' => 300000, 'attack_base' => 100000, 'magic_base' => 100000, 'speed_base' => 10000]);
        $progress = NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 49]);
        $service = app(NamelessRuinService::class);
        $before = $service->fight($character, 'sand', 49, true, $this->uuid());
        $this->assertSame('victory', $before['battle_result']);
        $this->assertNull($before['unlocked_zone_name']);
        $this->assertSame(['sand'], array_keys($service->availableZones($character)));
        $normal = $service->fight($character, 'sand', 50, false, $this->uuid());
        $this->assertSame('victory', $normal['battle_result']);
        $this->assertNull($normal['unlocked_zone_name']);
        $this->assertSame(['sand'], array_keys($service->availableZones($character)));
        $uuid = $this->uuid();
        $result = $service->fight($character, 'sand', 50, true, $uuid);
        $this->assertSame('victory', $result['battle_result']);
        $this->assertTrue($result['advanced']);
        $this->assertSame('沈水の水路', $result['unlocked_zone_name']);
        $this->assertSame(51, (int) $progress->fresh()->unlocked_depth);
        $this->assertSame(['sand', 'water'], array_keys($service->availableZones($character)));
        $relicCount = PlayerRelic::query()->count();
        $this->assertSame($result, $service->fight($character, 'sand', 50, true, $uuid));
        $this->assertSame(93, (int) $character->fresh()->explore_stamina);
        $this->assertSame($relicCount, PlayerRelic::query()->count());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk()->assertSee('「沈水の水路」への道が開きました。');
        \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'dungeon'])
            ->assertSee('沈水の水路')->assertDontSee('褪火の鋳造区');
        $again = $service->fight($character, 'sand', 50, true, $this->uuid());
        $this->assertFalse($again['advanced']);
        $this->assertNull($again['unlocked_zone_name']);
        $this->assertSame(['sand', 'water'], array_keys($service->availableZones($character)));
    }

    public function test_depth_fifty_boss_defeat_does_not_unlock_next_ruin(): void
    {
        $character = $this->character();
        $character->update(['hp_base' => 10, 'current_hp' => 10, 'attack_base' => 1, 'magic_base' => 1, 'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        $progress = NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 50]);
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 50, true, $this->uuid());
        $this->assertSame('defeat', $result['battle_result']);
        $this->assertFalse($result['advanced']);
        $this->assertNull($result['unlocked_zone_name']);
        $this->assertSame(50, (int) $progress->fresh()->unlocked_depth);
        $this->assertSame(['sand'], array_keys($service->availableZones($character)));
    }

    public function test_exploration_tab_in_workshop_town_lists_six_ruins_and_shared_inn(): void
    {
        $character = $this->character();
        $this->unlockThrough($character, 'tomb');
        $character->update(['current_mp' => 0]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $screen = \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'dungeon']);
        foreach (config('nameless_ruins') as $key => $zone) {
            $screen->assertSee($zone['name'])->assertSee('name="zone" value="'.$key.'"', false)
                ->assertDontSee($zone['enemies'][0]['name']);
        }
        $screen->assertDontSee('action="'.route('battle.explore', ['area' => 1]).'"', false);
        $this->assertSame(6, substr_count($screen->html(), 'data-nameless-ruin-card'));
        $this->assertSame(6, substr_count($screen->html(), 'action="'.route('nameless-workshop.act', 'fight').'"'));
        $screen->assertDontSee('魔物・報酬の詳細')->assertDontSee('SPが少ないため、途中で敗北する可能性があります。')->assertDontSee('tab=ruins', false);
        \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'town'])
            ->assertSee('名もなき鍛冶屋')->assertSee('宿屋')->assertSee(route('inn.rest'), false);
        app(\App\Services\DiscoveryService::class)->ensureInitialDiscoveries($character->fresh());
        $this->assertNull($character->fresh()->highest_city_id);
        $this->assertDatabaseMissing('character_city_discoveries', ['character_id' => $character->id, 'city_id' => $character->current_city_id]);
        $capital = \App\Models\City::query()->create(['name' => '試験王都', 'sort_order' => 10, 'is_initial' => true]);
        $character->update(['current_city_id' => $capital->id, 'highest_city_id' => $capital->id]);
        app(\App\Services\DiscoveryService::class)->ensureInitialDiscoveries($character->fresh());
        $this->assertSame($capital->id, $character->fresh()->highest_city_id);
        $this->assertDatabaseMissing('character_city_discoveries', ['character_id' => $character->id, 'city_id' => app(\App\Services\NamelessTownService::class)->availableTown()->id]);
    }

    public function test_rename_is_blue_preserves_growth_and_relics_and_blank_restores_default(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 4, 'growth_exp' => 42]);
        $relic = $this->relic($character);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->workshop()->configure($character, $body->id, '剣', '星巡りの剣', $this->uuid());
        $this->assertTrue($body->fresh()->isRenamed());
        $this->assertSame(4, $body->fresh()->forge_level);
        $this->assertSame(42, $body->fresh()->growth_exp);
        $this->assertSame($body->id, $relic->fresh()->nameless_equipment_id);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index'))->assertOk()->assertSee('名もなき鍛冶屋')->assertSee('<span class="renamed-equipment">星巡りの剣 +4</span>', false)->assertDontSee('4,950,000 G');
        $material = $this->material($character);
        $preview = $this->workshop()->previewFeed($character, $body->id, [$material->id => 1], [], 0, true);
        $this->assertTrue($preview['equipment_renamed']);
        $this->reject(fn () => $this->workshop()->configure($character, $body->id, '剣', '<script>', $this->uuid()), '名前は');
        $this->workshop()->configure($character, $body->id, '剣', '', $this->uuid());
        $this->assertFalse($body->fresh()->isRenamed());
        $this->assertSame('名もなき剣', $body->fresh()->displayName());
        $this->workshop()->configure($character, $body->id, '剣', '0', $this->uuid());
        $this->assertSame('0', $body->fresh()->displayName());
        $this->assertTrue($body->fresh()->isRenamed());
    }

    public function test_only_continental_materials_can_be_fed_and_important_materials_stay_protected(): void
    {
        $material = new Material(['material_code' => 'MAT_COMMON_BEAST_FANG', 'material_type' => 'common_drop', 'name' => '獣牙']);
        $this->assertSame(1, $this->workshop()->materialFeedExp($material));
        $material->forceFill(['material_code' => 'MAT_REGION_ARKREA_RAW', 'material_type' => 'regional_drop', 'city_id' => 1]);
        $this->assertSame(3, $this->workshop()->materialFeedExp($material));
        foreach (config('nameless_relics.continental_material_codes') as $exp => $codes) {
            foreach ($codes as $code) {
                $material->forceFill(['material_code' => $code, 'material_type' => 'city', 'city_id' => 1]);
                $this->assertSame($exp, $this->workshop()->materialFeedExp($material));
            }
        }
        $material->forceFill(['material_code' => 'CITY_10_HIGH', 'material_type' => 'city_high']);
        $this->assertSame(3, $this->workshop()->materialFeedExp($material));
        foreach (['MAT_FERDIA_BLUE_LIFE_LEAF', 'MAT_UNKNOWN', 'CITY_11_HIGH'] as $code) {
            $material->forceFill(['material_code' => $code, 'material_type' => 'brewing', 'city_id' => 101]);
            $this->assertSame(0, $this->workshop()->materialFeedExp($material));
        }
        $material->forceFill(['material_code' => 'WEV0023', 'name' => '王都の鉄片', 'is_key_item' => true]);
        $this->assertSame(0, $this->workshop()->materialFeedExp($material));
        $material->forceFill(['is_key_item' => false, 'is_cash_item' => true]);
        $this->assertSame(0, $this->workshop()->materialFeedExp($material));
        $material->forceFill(['is_cash_item' => false, 'name' => '極印']);
        $this->assertSame(0, $this->workshop()->materialFeedExp($material));
    }

    public function test_forge_cumulative_gold_is_exactly_4950000_for_each_body(): void
    {
        $character = $this->character();
        foreach (['weapon', 'armor'] as $kind) {
            $body = $this->body($character, $kind);
            $total = 0;
            for ($level = 0; $level < 99; $level++) {
                $body->forge_level = $level;
                $cost = $this->workshop()->forgeSummary($character, $body);
                $this->assertSame(($level + 1) * 1000, $cost['gold']);
                $total += $cost['gold'];
            }
            $this->assertSame(4950000, $total);
            $this->assertSame($total, $cost['total_gold']);
        }
        $this->assertSame('無もなき工房街', app(\App\Services\NamelessTownService::class)->availableTown()->name);
    }

    public function test_fifty_ruin_battles_aggregate_relics_and_replay_without_extra_consumption(): void
    {
        config(['nameless_relics.drop_chance_bps' => 10000]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.stamina_cost'], ['value' => '5', 'value_type' => 'integer']);
        app(GameSettingService::class)->flush();
        $character = $this->character();
        $uuid = $this->uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid, 50);
        $this->assertSame(50, $result['batch_explore']['completed']);
        $this->assertCount(50, $result['relic_drops']);
        $this->assertCount(50, $result['batch_explore']['runs']);
        $this->assertSame(50, (int) $character->fresh()->explore_stamina);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, $result['batch_explore']['total_exp']);
        $this->assertSame(0, $result['batch_explore']['total_job_exp']);
        $this->assertSame(1, (int) $result['unlocked_depth']);
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid, 50));
        $this->assertSame(50, PlayerRelic::query()->count());
        $this->assertSame(1, NamelessWorkshopOperation::query()->where('action', 'ruin')->count());
        $this->reject(fn () => $service->fight($character, 'sand', 1, false, $uuid, 10), '内容を変更');
        $this->reject(fn () => $service->fight($character, 'sand', 1, true, $this->uuid(), 50), 'ボス挑戦は1回');
    }

    public function test_batch_preflight_rejects_shortage_low_hp_and_frozen_without_spending(): void
    {
        $character = $this->character();
        $character->update(['explore_stamina' => 49]);
        $service = app(NamelessRuinService::class);
        $this->reject(fn () => $service->fight($character, 'sand', 1, false, $this->uuid(), 50), '探索力が50必要');
        $character->update(['explore_stamina' => 100, 'current_hp' => 3000]);
        $this->reject(fn () => $service->fight($character, 'sand', 1, false, $this->uuid(), 10), 'HPが少なく');
        $character->update(['is_frozen' => true]);
        $this->reject(fn () => $service->fight($character, 'sand', 1, false, $this->uuid()), '凍結');
        $this->assertSame(100, (int) $character->fresh()->explore_stamina);
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_full_bag_stops_batch_after_earned_drop_without_extra_battle(): void
    {
        config(['nameless_relics.drop_chance_bps' => 10000]);
        $character = $this->character();
        $this->unlockThrough($character, 'water');
        $this->relic($character);
        $this->reserveMaterialSlots($character, 1);
        $result = app(NamelessRuinService::class)->fight($character, 'water', 1, false, $this->uuid(), 10);
        $this->assertSame(1, $result['batch_explore']['completed']);
        $this->assertSame('error', $result['batch_explore']['stop_reason']);
        $this->assertStringContainsString('所持枠', $result['batch_explore']['stop_text']);
        $this->assertCount(1, $result['relic_drops']);
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        $this->assertSame(2, PlayerRelic::query()->count());
    }

    public function test_all_thirty_ruin_enemies_resolve_existing_portraits(): void
    {
        $number = 0;
        foreach (app(NamelessRuinService::class)->zones() as $zone) {
            foreach ($zone['enemies'] as $enemy) {
                $expected = 'images/enemy/ruins/ruins_'.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT).'.webp';
                $this->assertSame($expected, $enemy['image'], $enemy['name']);
                $this->assertFileExists(public_path($enemy['image']));
            }
        }
        $this->assertSame(30, $number);
    }

    public function test_ruin_result_uses_shared_battle_view_and_is_owned_refreshable_and_replay_safe(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->workshop()->configure($character, $body->id, '剣', '表示確認の剣', $this->uuid());
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->unlockThrough($character, 'water');
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $uuid = $this->uuid();
        $payload = ['zone' => 'water', 'depth' => 1, 'boss' => 0, 'batch_count' => 10, 'request_uuid' => $uuid];
        $url = route('nameless-workshop.result', ['uuid' => $uuid]);
        $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect($url);
        $response = $this->get($url)->assertOk()->assertViewIs('battle.result')->assertSee('沈水の水路 深度1')->assertSee('data-nameless-relic-rewards', false)->assertSee('探索タブへ')->assertSee('簡易表示');
        $response->assertSee(route('nameless-workshop.return', ['tab' => 'dungeon']), false)
            ->assertSee('data-equipment-rank="nameless"', false)->assertSee('表示確認の剣 +0')
            ->assertSee('color:#1d4ed8', false)
            ->assertDontSee('action="'.route('nameless-workshop.act', 'fight').'"', false);
        $response->assertDontSee('action="'.route('battle.explore', ['area' => 0]).'"', false);
        $operation = NamelessWorkshopOperation::query()->where('request_uuid', $uuid)->sole();
        $snapshot = $operation->result;
        $image = config('enemy_images')[$snapshot['enemy']['name']];
        $this->assertSame($image, $snapshot['enemy_image_path']);
        $response->assertSee('src="'.asset($image).'"', false);
        // 修正前の保存結果も敵名から画像を解決でき、再戦やデータ移行を必要としない。
        $snapshot['enemy_image_path'] = null;
        $operation->update(['result' => $snapshot]);
        $this->get($url)->assertOk()->assertSee('src="'.asset($image).'"', false);
        $this->get($url)->assertOk();
        $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect($url);
        $this->assertSame(90, (int) $character->fresh()->explore_stamina);
        $other = $this->character();
        $this->actingAs($other->user)->withSession(['current_character_id' => $other->id])->get($url)->assertNotFound();
        config(['nameless_relics.enabled' => false]);
        $this->get($url)->assertNotFound();
    }

    public function test_batch_defeat_stops_after_first_battle_without_relic_or_depth_reward(): void
    {
        config(['nameless_relics.drop_chance_bps' => 10000]);
        $character = $this->character();
        $this->unlockThrough($character, 'forge');
        $character->update(['hp_base' => 10, 'current_hp' => 10, 'attack_base' => 1, 'magic_base' => 1, 'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        $result = app(NamelessRuinService::class)->fight($character, 'forge', 1, false, $this->uuid(), 50);
        $this->assertSame('defeat', $result['battle_result']);
        $this->assertSame(1, $result['batch_explore']['completed']);
        $this->assertSame('defeat', $result['batch_explore']['stop_reason']);
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        $this->assertSame(3, (int) $character->fresh()->current_hp);
        $this->assertSame(100, (int) $character->fresh()->current_mp);
        $this->assertSame([], $result['relic_drops']);
        $this->assertFalse($result['advanced']);
    }

    public function test_ruin_batch_stops_on_low_hp_or_timeout_and_spends_only_one_run(): void
    {
        config(['nameless_relics.drop_chance_bps' => 0]);
        foreach (['victory' => 'hp_pinch', 'timeout' => 'timeout'] as $outcome => $stopReason) {
            $character = $this->character();
            $this->mock(\App\Services\BattleService::class, function ($mock) use ($outcome) {
                $mock->shouldReceive('executeBattle')->once()->andReturnUsing(function ($runner) use ($outcome) {
                    $runner->update(['current_hp' => 3000]);
                    $battle = new \App\Services\Battle\BattleResult;
                    $battle->result = $outcome;
                    $battle->playerHpAfter = 3000;
                    $battle->playerMpAfter = 500;
                    return $battle;
                });
            });
            $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid(), 50);
            $this->assertSame($stopReason, $result['batch_explore']['stop_reason']);
            $this->assertSame(1, $result['batch_explore']['completed']);
            $this->assertSame(99, (int) $character->fresh()->explore_stamina);
            $this->assertSame([], $result['relic_drops']);
        }
    }

    public function test_rare_encounter_ticket_boundaries_and_required_inventory_space(): void
    {
        config(['nameless_relics.relic_goblin_encounter_bps' => 100, 'nameless_relics.cleared_boss_encounter_bps' => 200]);
        $service = app(NamelessRuinService::class);
        $zone = $service->zones()['sand'];
        foreach ([1 => 'relic_goblin', 100 => 'relic_goblin', 101 => 'cleared_boss', 300 => 'cleared_boss', 301 => 'normal', 10000 => 'normal'] as $ticket => $kind) {
            $this->assertSame($kind, $service->encounterForTicket($zone, true, 10, $ticket)['kind']);
        }
        $this->assertSame('normal', $service->encounterForTicket($zone, false, 10, 101)['kind']);
        $this->assertSame('normal', $service->encounterForTicket($zone, true, 9, 100)['kind']);
        $this->assertSame('cleared_boss', $service->encounterForTicket($zone, true, 9, 101)['kind']);
    }

    public function test_boss_reappears_only_at_cleared_depth_without_unlocking_or_extra_stamina_cost(): void
    {
        config(['nameless_relics.cleared_boss_encounter_bps' => 10000, 'nameless_relics.boss_drop_chance_bps' => 10000, 'nameless_relics.drop_chance_bps' => 0]);
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        $uncleared = $service->fight($character, 'sand', 1, false, $this->uuid());
        $this->assertSame('normal', $uncleared['encounter_kind']);
        $service->fight($character, 'sand', 1, true, $this->uuid());
        $uuid = $this->uuid();
        $result = $service->fight($character, 'sand', 1, false, $uuid);
        $this->assertSame('cleared_boss', $result['encounter_kind']);
        $this->assertSame('碑門の衛士ガルム', $result['enemy_name']);
        $this->assertTrue($result['enemy']['is_boss']);
        $this->assertFalse($result['boss']);
        $this->assertFalse($result['advanced']);
        $this->assertSame(2, (int) $result['unlocked_depth']);
        $this->assertCount(1, $result['relic_drops']);
        $this->assertSame(95, (int) $character->fresh()->explore_stamina);
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid));
        $frontier = $service->fight($character, 'sand', 2, false, $this->uuid());
        $this->unlockThrough($character, 'water');
        $otherZone = $service->fight($character, 'water', 1, false, $this->uuid());
        $this->assertSame('normal', $frontier['encounter_kind']);
        $this->assertSame('normal', $otherZone['encounter_kind']);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, (int) $character->fresh()->wins);
    }

    public function test_final_depth_requires_actual_boss_clear_record_and_does_not_advance(): void
    {
        config(['nameless_relics.cleared_boss_encounter_bps' => 10000, 'nameless_relics.enemy_depth_growth' => 0, 'nameless_relics.enemy_depth_quadratic_growth' => 0]);
        $character = $this->character();
        $progress = NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 100]);
        $service = app(NamelessRuinService::class);
        $this->assertFalse($service->bossCleared($character, 'sand', 100, $progress));
        $this->assertFalse($service->bossCleared($character, 'water', 99, $progress));
        $this->assertSame('normal', $service->fight($character, 'sand', 100, false, $this->uuid())['encounter_kind']);
        $boss = $service->fight($character, 'sand', 100, true, $this->uuid());
        $this->assertSame('victory', $boss['battle_result']);
        $this->assertFalse($boss['advanced']);
        $this->assertTrue($service->bossCleared($character, 'sand', 100, $progress));
        $this->assertFalse($service->bossCleared($this->character(), 'sand', 100, $progress));
        $reappearance = $service->fight($character, 'sand', 100, false, $this->uuid());
        $this->assertSame('cleared_boss', $reappearance['encounter_kind']);
        $this->assertFalse($reappearance['advanced']);
        $this->assertSame(100, (int) $progress->fresh()->unlocked_depth);
    }

    public function test_goblin_guarantees_ten_relics_with_zone_ranks_and_replays_once(): void
    {
        config(['nameless_relics.relic_goblin_encounter_bps' => 10000, 'nameless_relics.drop_chance_bps' => 0]);
        $character = $this->character();
        $this->unlockThrough($character, 'water');
        $this->relic($character);
        $this->relic($character);
        $this->reserveMaterialSlots($character, 10);
        $uuid = $this->uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'water', 1, false, $uuid);
        $this->assertSame('relic_goblin', $result['encounter_kind']);
        $this->assertSame('遺物ゴブリン', $result['enemy_name']);
        $this->assertCount(10, $result['relic_drops']);
        foreach ($result['relic_drops'] as $relic) {
            $this->assertContains($relic['effect_key'], config('nameless_ruins.water.effects'));
            $this->assertGreaterThanOrEqual(1, $relic['rank']);
            $this->assertLessThanOrEqual(9, $relic['rank']);
        }
        $this->assertSame(10, $result['rare_encounters'][0]['relic_count']);
        $this->assertSame(12, PlayerRelic::query()->count());
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        $this->assertSame($result, $service->fight($character, 'water', 1, false, $uuid));
        $this->assertSame(12, PlayerRelic::query()->count());
        $this->assertFalse($result['advanced']);
    }

    public function test_batch_aggregates_all_goblin_rewards_and_stops_when_bag_fills(): void
    {
        config(['nameless_relics.relic_goblin_encounter_bps' => 10000]);
        $character = $this->character();
        $this->reserveMaterialSlots($character, 30);
        $uuid = $this->uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid, 50);
        $this->assertSame(3, $result['batch_explore']['completed']);
        $this->assertCount(30, $result['relic_drops']);
        $this->assertCount(30, array_unique(array_column($result['relic_drops'], 'id')));
        $this->assertSame([1, 2, 3], array_column($result['rare_encounters'], 'index'));
        $this->assertSame([10, 10, 10], array_column($result['rare_encounters'], 'relic_count'));
        $this->assertSame(97, (int) $character->fresh()->explore_stamina);
        $this->assertSame(30, PlayerRelic::query()->count());
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid, 50));
        $this->assertSame(30, PlayerRelic::query()->count());
    }

    public function test_rare_enemy_defeat_gives_no_relics_and_never_unlocks_depth(): void
    {
        foreach (['relic_goblin', 'cleared_boss'] as $kind) {
            config(['nameless_relics.relic_goblin_encounter_bps' => $kind === 'relic_goblin' ? 10000 : 0, 'nameless_relics.cleared_boss_encounter_bps' => $kind === 'cleared_boss' ? 10000 : 0]);
            $character = $this->character();
            $character->update(['hp_base' => 10, 'current_hp' => 10, 'attack_base' => 1, 'magic_base' => 1, 'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
            NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 2]);
            $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid(), 10);
            $this->assertSame($kind, $result['encounter_kind']);
            $this->assertSame('defeat', $result['battle_result']);
            $this->assertSame([], $result['relic_drops']);
            $this->assertFalse($result['advanced']);
            $this->assertSame(2, (int) $result['unlocked_depth']);
            $this->assertSame(1, $result['batch_explore']['completed']);
            $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        }
    }

    public function test_equipment_drop_boundary_is_point_one_percent_without_guarantee(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10]);
        $service = app(\App\Services\NamelessEquipmentCollectionService::class);
        foreach ([1, 10] as $ticket) $this->assertTrue($service->dropsForTicket($ticket));
        foreach ([11, 10000] as $ticket) $this->assertFalse($service->dropsForTicket($ticket));
        $this->reject(fn () => $service->dropsForTicket(0), '抽選設定');
        $this->reject(fn () => $service->dropsForTicket(10001), '抽選設定');
        $this->assertSame(20, count($service->types()));
    }

    public function test_ruin_drops_collect_twenty_types_then_duplicates_and_replay_once(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000, 'nameless_relics.drop_chance_bps' => 0]);
        $character = $this->character();
        $starter = $this->body($character);
        $uuid = $this->uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid, 21);
        $this->assertSame(21, $result['batch_explore']['completed']);
        $this->assertCount(21, $result['nameless_equipment_drops']);
        $firstTwenty = array_slice($result['nameless_equipment_drops'], 0, 20);
        $this->assertCount(20, array_unique(array_column($firstTwenty, 'equipment_type')));
        $this->assertSame(array_fill(0, 20, true), array_column($firstTwenty, 'new_discovery'));
        $this->assertFalse($result['nameless_equipment_drops'][20]['new_discovery']);
        $this->assertSame(20, app(\App\Services\NamelessEquipmentCollectionService::class)->summary($character)['count']);
        $this->assertSame([], $result['relic_drops']);
        $this->assertSame(22, PlayerNamelessEquipment::query()->count());
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid, 21));
        $this->assertSame(22, PlayerNamelessEquipment::query()->count());
        $this->assertSame('starter', $starter->fresh()->acquisition_source);
        $this->assertSame(79, (int) $character->fresh()->explore_stamina);
        $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk()->assertSee('初めての発見！')->assertSee('この武具を鍛冶屋で見る')->assertSee('武具 21個');
    }

    public function test_equipment_and_relic_rolls_are_independent_and_bosses_also_drop(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000, 'nameless_relics.drop_chance_bps' => 10000, 'nameless_relics.boss_drop_chance_bps' => 10000]);
        $character = $this->character();
        foreach ([false, true] as $boss) {
            $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, $boss, $this->uuid());
            $this->assertSame('victory', $result['battle_result']);
            $this->assertCount(1, $result['relic_drops']);
            $this->assertCount(1, $result['nameless_equipment_drops']);
            $this->assertSame($boss, $result['advanced']);
            $this->assertSame(0, $result['gold_gained']);
        }
    }

    public function test_equipment_does_not_drop_on_defeat_even_at_forced_rate(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000]);
        $character = $this->character();
        $character->update(['hp_base' => 10, 'current_hp' => 10, 'attack_base' => 1, 'magic_base' => 1, 'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid(), 50);
        $this->assertSame('defeat', $result['battle_result']);
        $this->assertSame([], $result['nameless_equipment_drops']);
        $this->assertSame(0, PlayerNamelessEquipment::query()->count());
        $this->assertSame(0, \App\Models\NamelessEquipmentDiscovery::query()->count());
    }

    public function test_starter_remains_one_per_kind_and_never_counts_as_discovery(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000]);
        $character = $this->character();
        app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid(), 15);
        $weapon = $this->body($character);
        $armor = $this->body($character, 'armor');
        $this->assertSame($weapon->id, $this->workshop()->claim($character, 'weapon', '杖', $this->uuid())['equipment_id']);
        $this->assertSame(2, PlayerNamelessEquipment::query()->where('acquisition_source', 'starter')->count());
        $this->workshop()->configure($character, $weapon->id, '杖', '初期の杖', $this->uuid());
        $this->assertSame(15, \App\Models\NamelessEquipmentDiscovery::query()->count());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $weapon->id, $weapon->fresh()->revision, $this->uuid()), '初期配布');
        $this->assertTrue($armor->exists);
    }

    public function test_reserve_relics_can_overlap_but_equipped_pair_cannot(): void
    {
        $character = $this->character();
        $first = $this->body($character);
        $second = $this->droppedBody($character, 'weapon', '杖');
        $armor = $this->body($character, 'armor');
        foreach ([$first, $second, $armor] as $body) {
            $this->workshop()->attach($character, $body->id, 1, $this->relic($character, 'stat_str', 9)->id, $this->uuid());
        }
        $this->assertSame(0, $this->workshop()->equippedBonuses($character)['str']);
        $this->workshop()->changeEquipment($character, $first->id, true, $this->uuid());
        $this->assertSame(5, $this->workshop()->equippedBonuses($character)['str']);
        $this->workshop()->changeEquipment($character, $second->id, true, $this->uuid());
        $this->assertFalse($first->fresh()->is_equipped);
        $this->assertSame(0, $this->workshop()->equippedBonuses($character)['str']);
        $this->assertSame(5, $this->workshop()->equippedBonuses($character)['mag']);
        $this->reject(fn () => $this->workshop()->changeEquipment($character, $armor->id, true, $this->uuid()), '一つまで');
        $this->assertFalse($armor->fresh()->is_equipped);
        $this->assertTrue($second->fresh()->is_equipped);
        $this->reject(fn () => $this->workshop()->attach($character, $first->id, 2, $this->relic($character, 'stat_str')->id, $this->uuid()), '一本の武具内');
        $this->workshop()->detach($character, $armor->relics()->first()->id, $this->uuid());
        $this->workshop()->changeEquipment($character, $armor->id, true, $this->uuid());
        $this->reject(fn () => $this->workshop()->attach($character, $armor->id, 1, $this->relic($character, 'stat_str')->id, $this->uuid()), '一つまで');
    }

    public function test_each_body_has_own_name_growth_and_fixed_ruin_type(): void
    {
        $character = $this->character();
        $first = $this->droppedBody($character, 'weapon', '杖');
        $second = $this->droppedBody($character, 'weapon', '杖');
        $this->workshop()->configure($character, $first->id, '杖', '星の杖', $this->uuid());
        $first->update(['growth_exp' => 20]);
        $this->workshop()->forge($character, $first->id, $first->fresh()->revision, false, $this->uuid());
        $this->assertSame('星の杖', $first->fresh()->displayName());
        $this->assertSame(1, $first->fresh()->forge_level);
        $this->assertSame(0, $second->fresh()->forge_level);
        $this->assertSame(0, $second->fresh()->growth_exp);
        $this->assertSame('名もなき杖', $second->fresh()->displayName());
        $this->assertSame(4950000, $this->workshop()->forgeSummary($character, $second)['total_gold']);
        $this->reject(fn () => $this->workshop()->configure($character, $first->id, '剣', '変形', $this->uuid()), '種類は変更できません');
        $this->assertSame('星の杖', $first->fresh()->displayName());
        $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('nameless-workshop.index', ['equipment' => $first->id]))->assertOk()->assertSee('星の杖')->assertSee('renamed-equipment', false)->assertSee('所持 2個')->assertSee('名前を変更');
    }

    public function test_equipment_capacity_stops_batch_and_keeps_earned_rewards_and_replay(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000, 'nameless_relics.drop_chance_bps' => 10000]);
        $character = $this->character();
        $this->unlockThrough($character, 'water');
        $this->body($character);
        $this->reserveEquipmentSlots($character, 2);
        $uuid = $this->uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'water', 1, false, $uuid, 50);
        $this->assertSame(2, $result['batch_explore']['completed']);
        $this->assertCount(2, $result['nameless_equipment_drops']);
        $this->assertCount(2, $result['relic_drops']);
        $this->assertStringContainsString('装備倉庫の所持枠', $result['batch_explore']['stop_text']);
        $this->assertSame(98, (int) $character->fresh()->explore_stamina);
        $this->assertSame($result, $service->fight($character, 'water', 1, false, $uuid, 50));
        $this->reject(fn () => $service->fight($character, 'water', 1, false, $this->uuid()), '装備倉庫の所持枠');
        $this->assertSame(98, (int) $character->fresh()->explore_stamina);
        $this->assertSame(3, PlayerNamelessEquipment::query()->count());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        \Livewire\Livewire::test(\App\Livewire\MainScreen::class, ['fixedLocation' => 'dungeon'])
            ->assertSee('inventoryAvailable: false', false)->assertSee('鍛冶屋で不要な武具を整理');
    }

    public function test_protected_equipped_socketed_and_other_owned_bodies_cannot_be_discarded(): void
    {
        $character = $this->character();
        $other = $this->character();
        $body = $this->droppedBody($character);
        $this->reject(fn () => $this->workshop()->protectEquipment($other, $body->id, true, $this->uuid()), '所持していません');
        $this->workshop()->protectEquipment($character, $body->id, true, $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $body->id, $body->fresh()->revision, $this->uuid()), '保護中');
        $this->workshop()->protectEquipment($character, $body->id, false, $this->uuid());
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $body->id, $body->fresh()->revision, $this->uuid()), '装備中');
        $this->workshop()->changeEquipment($character, $body->id, false, $this->uuid());
        $this->workshop()->attach($character, $body->id, 1, $this->relic($character)->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $body->id, $body->fresh()->revision, $this->uuid()), '遺物装着中');
        $this->workshop()->detach($character, $body->relics()->first()->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($other, $body->id, $body->fresh()->revision, $this->uuid()), '所持していません');
        $this->assertTrue($body->fresh()->exists);
    }

    public function test_explicit_discard_requires_confirmation_and_keeps_discovery_record(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10000]);
        $character = $this->character();
        $drop = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $this->uuid())['nameless_equipment_drops'][0];
        $body = PlayerNamelessEquipment::query()->findOrFail($drop['id']);
        $this->workshop()->configure($character, $body->id, $body->equipment_type, '残す記録', $this->uuid());
        $this->reject(fn () => $this->workshop()->discardEquipment($character, $body->id, 0, $this->uuid()), '状態が変わりました');
        $uuid = $this->uuid();
        $revision = $body->fresh()->revision;
        $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->post(route('nameless-workshop.act', 'discard-equipment'), ['equipment_id' => $body->id, 'revision' => $revision, 'request_uuid' => $uuid])->assertSessionHasErrors('confirmed');
        $this->assertNotNull($body->fresh());
        $result = $this->workshop()->discardEquipment($character, $body->id, $revision, $uuid);
        $this->assertNull($body->fresh());
        $this->assertSame(1, app(\App\Services\NamelessEquipmentCollectionService::class)->summary($character)['count']);
        $this->assertSame($result, $this->workshop()->discardEquipment($character, $body->id, $revision, $uuid));
    }

    public function test_collection_migration_preserves_existing_body_and_attached_relic(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['custom_name' => '旧個体', 'forge_level' => 3, 'growth_exp' => 71]);
        $relic = $this->relic($character);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $before = $body->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_03_030000_enable_nameless_equipment_collection.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $body->fresh()->getAttributes());
        $this->assertSame($body->id, $relic->fresh()->nameless_equipment_id);
        $this->assertSame(0, \App\Models\NamelessEquipmentDiscovery::query()->count());
    }

    public function test_selected_equipment_is_owner_scoped_and_invalid_ids_are_rejected(): void
    {
        $character = $this->character();
        $other = $this->character();
        $body = $this->body($other);
        $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class);
        foreach ([$body->id, 'invalid', [$body->id]] as $id) {
            $this->get(route('nameless-workshop.index', ['equipment' => $id]))->assertNotFound();
        }
    }

    public function test_feed_cancellation_returns_to_selected_reserve_without_consuming_assets(): void
    {
        $character = $this->character();
        $this->body($character);
        $reserve = $this->droppedBody($character);
        $material = $this->material($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $cancelUrl = route('nameless-workshop.index', ['equipment' => $reserve->id]).'#absorb';
        $this->post(route('nameless-workshop.act', 'preview-feed'), [
            'equipment_id' => $reserve->id, 'materials' => [$material->id => 1], 'keep' => 10,
            'protect_best' => 1, 'request_uuid' => $this->uuid(),
        ])->assertOk()->assertSee('href="'.$cancelUrl.'"', false);
        $this->get($cancelUrl)->assertOk()->assertViewHas('selectedEquipment', fn ($selected) => $selected->id === $reserve->id);
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->assertSame(0, (int) $reserve->fresh()->growth_exp);
        $this->assertSame(10000, (int) $character->fresh()->money);
    }

    public function test_detach_returns_to_same_body_and_relic_list_identifies_active_or_reserve_attachment(): void
    {
        $character = $this->character();
        $active = $this->body($character);
        $reserve = $this->droppedBody($character);
        $reserve->update(['custom_name' => '控えの試験剣']);
        $this->workshop()->changeEquipment($character, $active->id, true, $this->uuid());
        $activeRelic = $this->relic($character, 'stat_str');
        $reserveRelic = $this->relic($character, 'stat_mag');
        $this->workshop()->attach($character, $active->id, 1, $activeRelic->id, $this->uuid());
        $this->workshop()->attach($character, $reserve->id, 2, $reserveRelic->id, $this->uuid());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['tab' => 'relics']))->assertOk()
            ->assertSee('装備中の武具に装着')->assertSee('控えの武具に装着')
            ->assertSee('装着先：［遺装］ 控えの試験剣 +0 · 遺物枠2');
        $back = route('nameless-workshop.index', ['tab' => 'workshop', 'equipment' => $reserve->id]);
        $this->post(route('nameless-workshop.act', 'detach'), [
            'equipment_id' => $reserve->id, 'relic_id' => $reserveRelic->id, 'request_uuid' => $this->uuid(),
        ])->assertRedirect($back);
        $this->get($back)->assertOk()->assertViewHas('selectedEquipment', fn ($selected) => $selected->id === $reserve->id);
        $this->assertNull($reserveRelic->fresh()->nameless_equipment_id);
        $this->assertSame($character->id, $reserveRelic->fresh()->character_id);
        $this->assertSame($active->id, $activeRelic->fresh()->nameless_equipment_id);
    }

    public function test_growth_screen_explains_missing_materials_and_money_and_failed_forge_keeps_assets(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $character->update(['money' => 0, 'bank_gold' => 0]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()
            ->assertSee('強化に使える素材がありません。')->assertSee('使う素材を選ぶ')
            ->assertSee('手持ちと預金を合わせてもゴールドが不足しています。');
        $body->update(['growth_exp' => 20]);
        $this->post(route('nameless-workshop.act', 'forge'), [
            'equipment_id' => $body->id, 'revision' => $body->revision, 'request_uuid' => $this->uuid(),
        ])->assertSessionHas('error');
        $this->assertSame(20, (int) $body->fresh()->growth_exp);
        $this->assertSame(0, (int) $body->fresh()->forge_level);
        $this->assertSame(0, (int) $character->fresh()->money);
    }

    public function test_equipment_filters_combine_and_never_include_other_players_or_change_total(): void
    {
        $character = $this->character();
        $starter = $this->body($character);
        $starter->update(['is_equipped' => true]);
        $reserve = $this->droppedBody($character, 'weapon', '杖');
        $reserve->update(['custom_name' => 'Moonの杖', 'is_locked' => true]);
        $armor = $this->droppedBody($character, 'armor', '盾');
        $foreign = $this->droppedBody($this->character(), 'weapon', '杖');
        $foreign->update(['custom_name' => 'Moonの杖', 'is_locked' => true]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        foreach ([
            [['gear_query' => 'moon'], [$reserve->id]],
            [['gear_query' => '#'.$armor->id], [$armor->id]],
            [['gear_type' => '杖', 'gear_state' => 'reserve', 'gear_protection' => 'locked', 'gear_source' => 'ruin'], [$reserve->id]],
            [['gear_kind' => 'armor'], [$armor->id]],
            [['gear_state' => 'equipped', 'gear_source' => 'starter', 'gear_protection' => 'unlocked'], [$starter->id]],
            [['gear_kind' => 'armor', 'gear_type' => '杖'], []],
            [['gear_query' => '%_'], []],
        ] as [$query, $ids]) {
            $this->get(route('nameless-workshop.index', $query))->assertOk()
                ->assertViewHas('filteredEquipment', fn ($rows) => $rows->keys()->all() === $ids)
                ->assertViewHas('equipment', fn ($rows) => $rows->count() === 3 && ! $rows->has($foreign->id));
        }
        $this->assertSame(3, PlayerNamelessEquipment::query()->where('character_id', $character->id)->count());
    }

    public function test_equipment_sorting_is_stable_and_shared_with_feed_picker(): void
    {
        $character = $this->character();
        $a = $this->body($character);
        $a->update(['custom_name' => 'Zulu', 'forge_level' => 5, 'growth_exp' => 3, 'is_equipped' => true]);
        $b = $this->droppedBody($character);
        $b->update(['custom_name' => 'Beta', 'forge_level' => 2, 'growth_exp' => 50]);
        $c = $this->droppedBody($character, 'armor', '盾');
        $c->update(['custom_name' => 'Alpha', 'forge_level' => 2, 'growth_exp' => 100]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        foreach ([
            'equipped' => [$a->id, $b->id, $c->id], 'newest' => [$c->id, $b->id, $a->id],
            'oldest' => [$a->id, $b->id, $c->id], 'forge_desc' => [$a->id, $b->id, $c->id],
            'forge_asc' => [$b->id, $c->id, $a->id], 'exp_desc' => [$c->id, $b->id, $a->id], 'name' => [$c->id, $b->id, $a->id],
        ] as $sort => $ids) {
            $this->get(route('nameless-workshop.index', ['gear_sort' => $sort]))->assertOk()
                ->assertViewHas('filteredEquipment', fn ($rows) => $rows->keys()->all() === $ids)
                ->assertViewHas('selectableEquipment', fn ($rows) => $rows->keys()->all() === $ids)
                ->assertViewHas('selectedEquipment', fn ($body) => $body->id === $ids[0]);
        }
    }

    public function test_empty_equipment_filter_does_not_silently_select_another_body(): void
    {
        $character = $this->character();
        $starter = $this->body($character);
        $reserve = $this->droppedBody($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['gear_query' => '該当しない銘']))->assertOk()
            ->assertViewHas('selectedEquipment', null)->assertViewHas('selectableEquipment', fn ($rows) => $rows->isEmpty())
            ->assertSee('条件に合う武具はありません。')->assertDontSee('data-nameless-equipment=', false);
        $this->get(route('nameless-workshop.index', ['gear_query' => '該当しない銘', 'equipment' => $reserve->id]))->assertOk()
            ->assertViewHas('selectedEquipment', fn ($body) => $body->id === $reserve->id)
            ->assertViewHas('filteredEquipment', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('selectableEquipment', fn ($rows) => $rows->keys()->all() === [$reserve->id])
            ->assertSee('選択中の武具は条件の対象外です。');
        $this->assertSame(0, (int) $starter->fresh()->growth_exp);
        $this->assertSame(0, (int) $reserve->fresh()->growth_exp);
    }

    public function test_equipment_filter_normalization_ignores_malformed_values_and_unknown_sorts(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['gear_query' => ['bad'], 'gear_type' => ['剣'], 'gear_sort' => 'money; drop table']))->assertOk()
            ->assertViewHas('equipmentFilters', \App\Services\NamelessEquipmentListService::DEFAULTS)
            ->assertViewHas('filteredEquipment', fn ($rows) => $rows->keys()->all() === [$body->id]);
        $filters = app(\App\Services\NamelessEquipmentListService::class)->normalize(['gear_query' => str_repeat('あ', 100)]);
        $this->assertSame(64, mb_strlen($filters['gear_query']));
    }

    public function test_rename_keeps_equipment_filters_and_selection_even_when_new_name_no_longer_matches(): void
    {
        $character = $this->character();
        $this->body($character);
        $body = $this->droppedBody($character);
        $body->update(['custom_name' => 'Beta']);
        $context = ['gear_query' => 'Beta', 'gear_source' => 'ruin', 'gear_sort' => 'exp_desc'];
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $back = route('nameless-workshop.index', $context + ['tab' => 'workshop', 'equipment' => $body->id]);
        $this->post(route('nameless-workshop.act', 'configure'), $context + [
            'equipment_id' => $body->id, 'type' => '剣', 'name' => 'Gamma', 'request_uuid' => $this->uuid(),
        ])->assertRedirect($back);
        $this->get($back)->assertOk()->assertViewHas('equipmentFilterQuery', $context)
            ->assertViewHas('selectedEquipment', fn ($selected) => $selected->id === $body->id && $selected->custom_name === 'Gamma')
            ->assertViewHas('selectionOutsideFilter', true);
    }

    public function test_feed_preview_cancel_and_confirmation_keep_equipment_filters(): void
    {
        $character = $this->character();
        $this->body($character);
        $body = $this->droppedBody($character);
        $material = $this->material($character);
        $context = ['gear_kind' => 'weapon', 'gear_source' => 'ruin', 'gear_sort' => 'newest'];
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $payload = $context + ['equipment_id' => $body->id, 'materials' => [$material->id => 1], 'keep' => 10, 'protect_best' => 1, 'request_uuid' => $this->uuid()];
        $cancel = route('nameless-workshop.index', $context + ['equipment' => $body->id]).'#absorb';
        $this->post(route('nameless-workshop.act', 'preview-feed'), $payload)->assertOk()
            ->assertViewHas('equipmentFilterQuery', $context)->assertSee('href="'.e($cancel).'"', false);
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $preview = $this->workshop()->previewFeed($character, $body->id, $payload['materials'], [], 10, true);
        $this->post(route('nameless-workshop.act', 'feed'), $payload + ['confirmation_hash' => $preview['confirmation_hash'], 'confirmed' => 1])
            ->assertRedirect(route('nameless-workshop.index', $context + ['tab' => 'workshop', 'equipment' => $body->id]));
        $this->assertSame(99, (int) $material->fresh()->quantity);
        $this->assertSame(1, (int) $body->fresh()->growth_exp);
    }

    public function test_equipment_search_finds_one_body_in_full_inventory_without_hiding_capacity_limit(): void
    {
        $character = $this->character();
        $this->body($character);
        for ($i = 0; $i < 59; $i++) {
            $last = $this->droppedBody($character);
        }
        $this->reserveEquipmentSlots($character, 0);
        $last->update(['custom_name' => '育成中の最後の一本', 'is_locked' => true]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['gear_query' => '最後の一本', 'gear_protection' => 'locked']))->assertOk()
            ->assertViewHas('filteredEquipment', fn ($rows) => $rows->keys()->all() === [$last->id])
            ->assertViewHas('selectedEquipment', fn ($body) => $body->id === $last->id)
            ->assertSee('条件に合う武具 1 / 所持 60 個')
            ->assertSee('装備倉庫の所持枠がいっぱいです。');
    }

    public function test_direct_forge_consumes_only_required_materials_and_gold_once(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $uuid = $this->uuid();
        $result = $this->workshop()->forge($character, $body->id, 0, false, $uuid, $material->id);
        $this->assertSame(1, $body->fresh()->forge_level);
        $this->assertSame(0, $body->fresh()->growth_exp);
        $this->assertSame(80, (int) $material->fresh()->quantity);
        $this->assertSame(9000, (int) $character->fresh()->money);
        $this->assertSame(10000, (int) $character->fresh()->current_hp);
        $this->assertSame(20, $result['spent_materials'][0]['quantity']);
        $this->assertSame($result, $this->workshop()->forge($character, $body->id, 0, false, $uuid, $material->id));
        $this->assertSame(80, (int) $material->fresh()->quantity);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $uuid), '内容を変更');
    }

    public function test_direct_forge_keeps_existing_feed_credit_and_rounding_remainder(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['growth_exp' => 10]);
        $material = $this->material($character);
        $material->material->update(['material_type' => 'city_high']);
        config(['nameless_relics.continental_material_codes.3' => array_merge(config('nameless_relics.continental_material_codes.3'), [$material->material->material_code])]);
        $this->assertSame(4, $this->workshop()->forgeMaterialQuantity($body, $material->material->fresh()));
        $this->workshop()->forge($character, $body->id, 0, false, $this->uuid(), $material->id);
        $this->assertSame(2, $body->fresh()->growth_exp);
        $this->assertSame(96, (int) $material->fresh()->quantity);
        $this->assertSame(13, $this->workshop()->forgeMaterialQuantity($body->fresh(), $material->material->fresh()));
        $this->workshop()->forge($character, $body->id, 1, false, $this->uuid(), $material->id);
        $this->assertSame(1, $body->fresh()->growth_exp);
        $this->assertSame(83, (int) $material->fresh()->quantity);
    }

    public function test_direct_forge_rejects_foreign_important_and_short_materials_without_consumption(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $foreign = $this->material($this->character());
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $this->uuid(), $foreign->id), '所持していません');
        $material->material->update(['is_key_item' => true]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $this->uuid(), $material->id), '強化に使えません');
        $material->material->update(['is_key_item' => false]);
        $material->update(['quantity' => 19]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $this->uuid(), $material->id), '素材が不足');
        $this->assertSame(19, (int) $material->fresh()->quantity);
        $this->assertSame(100, (int) $foreign->fresh()->quantity);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, $body->fresh()->forge_level);
        $this->assertSame(0, NamelessWorkshopOperation::query()->where('action', 'forge')->count());
    }

    public function test_direct_forge_bank_and_cap_failures_do_not_consume_materials(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $character->update(['money' => 0, 'bank_gold' => 1000]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, 0, false, $this->uuid(), $material->id), '預金');
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->assertSame(0, $body->fresh()->forge_level);
        $this->workshop()->forge($character, $body->id, 0, true, $this->uuid(), $material->id);
        $this->assertSame(80, (int) $material->fresh()->quantity);
        $this->assertSame(0, (int) $character->fresh()->bank_gold);
        $body->refresh()->update(['forge_level' => 10]);
        $this->reject(fn () => $this->workshop()->forge($character, $body->id, $body->revision, true, $this->uuid(), $material->id), '育成上限');
        $this->assertSame(80, (int) $material->fresh()->quantity);
    }

    public function test_compact_workshop_separates_forge_and_relic_sets_and_keeps_tab_after_attach(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $relic = $this->relic($character, 'stat_str', 3);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $forge = $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()
            ->assertSee('武具強化する')->assertSee('武具に遺物をセットする')
            ->assertSee('data-nameless-forge', false)->assertDontSee('data-nameless-relic-slots', false)
            ->assertSee('data-nameless-forge-progress', false)->assertSee('aria-valuemax="20"', false)->assertSee('攻撃 +5 → +11')
            ->assertSee('所持 100 個')->assertDontSee('この1本を')->assertDontSee('現在の育成上限');
        $forge->assertDontSee('成長EXPがあと')->assertDontSee('<p>成長EXP', false);
        $back = route('nameless-workshop.index', ['tab' => 'sets', 'equipment' => $body->id]);
        $this->get($back)->assertOk()->assertSee('data-nameless-relic-slots', false)->assertDontSee('data-nameless-forge', false)
            ->assertSee('選んだ遺物の効果を見る')->assertViewHas('relicDescriptions', fn ($values) => $values[$relic->id]['summary'] === '攻撃+3%')
            ->assertViewHas('relicDescriptions', fn ($values) => $values[$relic->id]['image_url'] === asset('images/relics/stat_str.webp') && $values[$relic->id]['name'] === $relic->displayName());
        $this->post(route('nameless-workshop.act', 'attach'), [
            'equipment_id' => $body->id, 'slot' => 1, 'relic_id' => $relic->id, 'workshop_tab' => 'sets', 'request_uuid' => $this->uuid(),
        ])->assertRedirect($back);
        $attachedView = $this->get($back)->assertOk()->assertSee($relic->displayName())->assertSee('攻撃+3%');
        $dom = new \DOMDocument;
        $dom->loadHTML($attachedView->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $images = (new \DOMXPath($dom))->query('//*[@data-nameless-relic-slots]//summary/img');
        $this->assertSame(1, $images->length);
        $this->assertSame(asset('images/relics/stat_str.webp'), $images->item(0)->getAttribute('src'));
        $this->assertSame(100, (int) $material->fresh()->quantity);
    }

    public function test_mixed_forge_consumes_exact_selection_once_and_carries_surplus_without_inheriting_effects(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['growth_exp' => 2]);
        $common = $this->material($character);
        $valuable = $this->material($character);
        $valuable->material->update(['material_type' => 'city_high']);
        config(['nameless_relics.continental_material_codes.3' => [$valuable->material->material_code]]);
        $relics = collect([3, 6, 9])->map(fn ($rank) => $this->relic($character, 'special_critical', $rank));
        $materials = [$common->id => 4, $valuable->id => 2];
        $ids = $relics->pluck('id')->all();
        $preview = $this->workshop()->previewForge($character, $body->id, 0, $materials, $ids, false, false);
        $this->assertSame(22, $preview['gained_exp']);
        $this->assertSame(4, $preview['exp_after']);
        $this->assertSame(100, (int) $common->fresh()->quantity);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertCount(3, PlayerRelic::query()->get());
        $uuid = $this->uuid();
        $result = $this->workshop()->forgeCombined($character, $body->id, 0, $materials, $ids, false, false, $preview['confirmation_hash'], $uuid);
        $this->assertSame($result, $this->workshop()->forgeCombined($character, $body->id, 0, $materials, array_reverse($ids), false, false, $preview['confirmation_hash'], $uuid));
        $this->assertSame(96, (int) $common->fresh()->quantity);
        $this->assertSame(98, (int) $valuable->fresh()->quantity);
        $this->assertSame(9000, (int) $character->fresh()->money);
        $this->assertSame(1, $body->fresh()->forge_level);
        $this->assertSame(1, $body->fresh()->revision);
        $this->assertSame(4, $body->fresh()->growth_exp);
        $this->assertCount(0, $body->fresh()->relics);
        $this->assertSame(0, PlayerRelic::query()->count());
        $this->assertCount(2, $result['spent_materials']);
        $this->assertSame([3, 6, 9], array_column($result['spent_relics'], 'rank'));
        $this->assertSame($result, NamelessWorkshopOperation::query()->where('request_uuid', $uuid)->firstOrFail()->result);
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, 0, [], $ids, false, false, $preview['confirmation_hash'], $uuid), '内容を変更');
        $this->assertSame(500, (int) $character->fresh()->current_mp);
    }

    public function test_mixed_forge_accepts_partial_stacks_and_existing_credit_with_no_new_sources(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $a = $this->material($character);
        $b = $this->material($character);
        $a->update(['quantity' => 8]);
        $b->update(['quantity' => 12]);
        $materials = [$a->id => 8, $b->id => 12];
        $preview = $this->workshop()->previewForge($character, $body->id, 0, $materials, [], true, false);
        $this->workshop()->forgeCombined($character, $body->id, 0, $materials, [], true, false, $preview['confirmation_hash'], $this->uuid());
        $this->assertSame(0, (int) $a->fresh()->quantity);
        $this->assertSame(0, (int) $b->fresh()->quantity);
        $body->refresh()->update(['growth_exp' => 45]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()
            ->assertViewHas('forgeSelection', fn ($selection) => $selection['required'] === 0 && $selection['carryBase'] === 5)
            ->assertSee('持ち越し 45 pt')->assertSee('aria-valuemax="40"', false)->assertSee('aria-valuenow="40"', false);
        $preview = $this->workshop()->previewForge($character, $body->id, 1, [], [], true, false);
        $this->assertSame([], $preview['sources']);
        $this->workshop()->forgeCombined($character, $body->id, 1, [], [], true, false, $preview['confirmation_hash'], $this->uuid());
        $this->assertSame(2, $body->fresh()->forge_level);
        $this->assertSame(5, $body->fresh()->growth_exp);
        $this->assertSame(7000, (int) $character->fresh()->money);
    }

    public function test_mixed_forge_relic_only_and_approved_rank_values(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $catalog = app(\App\Services\NamelessRelicCatalog::class);
        foreach ([1 => 3, 3, 3, 4, 4, 4, 5, 5, 5] as $rank => $value) {
            $this->assertSame($value, $catalog->feedExp($rank));
        }
        $ids = collect(range(1, 4))->map(fn () => $this->relic($character, 'stat_str', 9)->id)->all();
        $preview = $this->workshop()->previewForge($character, $body->id, 0, [], $ids, false, false);
        $this->workshop()->forgeCombined($character, $body->id, 0, [], $ids, false, false, $preview['confirmation_hash'], $this->uuid());
        $this->assertSame(1, $body->fresh()->forge_level);
        $this->assertSame(0, $body->fresh()->growth_exp);
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_mixed_forge_refuses_invalid_sources_and_stale_confirmation_without_any_consumption(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $relic = $this->relic($character, 'stat_str', 3);
        $foreign = $this->relic($this->character());
        $materials = [$material->id => 20];
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, $materials, [$relic->id], true, false), '最高ランク');
        $relic->update(['is_locked' => true]);
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, $materials, [$relic->id], false, false), '保護中');
        $relic->update(['is_locked' => false, 'nameless_equipment_id' => $body->id, 'slot_number' => 1]);
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, $materials, [$relic->id], false, false), '装着中');
        $relic->update(['nameless_equipment_id' => null, 'slot_number' => null]);
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, $materials, [$foreign->id], false, false), '未所持');
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, [$material->id => 101], [], true, false), '所持数');
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, [$material->id => -1], [], true, false), '0以上');
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, [$material->id => 60, '0'.$material->id => 60], [], true, false), '正しい整数');
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, [$material->id.'x' => 20], [], true, false), '正しい整数');
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, [], [], true, false), '不足');
        $preview = $this->workshop()->previewForge($character, $body->id, 0, $materials, [$relic->id], false, false);
        $material->increment('quantity');
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, 0, $materials, [$relic->id], false, false, $preview['confirmation_hash'], $this->uuid()), '確認し直して');
        $material->decrement('quantity');
        $relic->update(['rank' => 4]);
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, 0, $materials, [$relic->id], false, false, $preview['confirmation_hash'], $this->uuid()), '確認し直して');
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, $body->fresh()->forge_level);
        $this->assertNotNull($relic->fresh());
        $this->assertSame(0, NamelessWorkshopOperation::query()->where('action', 'forge')->count());
    }

    public function test_mixed_forge_bank_balance_cap_and_revision_failures_keep_assets(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $materials = [$material->id => 20];
        $character->update(['money' => 0, 'bank_gold' => 1000]);
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 0, $materials, [], true, false), '預金');
        $preview = $this->workshop()->previewForge($character, $body->id, 0, $materials, [], true, true);
        $character->update(['bank_gold' => 999]);
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, 0, $materials, [], true, true, $preview['confirmation_hash'], $this->uuid()), 'ゴールドが不足');
        $character->update(['bank_gold' => 1000]);
        $body->update(['revision' => 1]);
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, 0, $materials, [], true, true, $preview['confirmation_hash'], $this->uuid()), '状態が変わりました');
        $body->update(['forge_level' => 10]);
        $this->reject(fn () => $this->workshop()->previewForge($character, $body->id, 1, $materials, [], true, true), '育成上限');
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->assertSame(1000, (int) $character->fresh()->bank_gold);
        $body->update(['forge_level' => 0]);
        $preview = $this->workshop()->previewForge($character, $body->id, 1, $materials, [], true, true);
        $this->workshop()->forgeCombined($character, $body->id, 1, $materials, [], true, true, $preview['confirmation_hash'], $this->uuid());
        $this->assertSame(0, (int) $character->fresh()->bank_gold);
        $this->assertSame(80, (int) $material->fresh()->quantity);
    }

    public function test_mixed_forge_http_preview_cancel_confirm_and_validation_keep_selection_context(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $material = $this->material($character);
        $relic = $this->relic($character, 'stat_str', 1);
        $this->relic($character, 'stat_str', 9);
        $locked = $this->relic($character, 'stat_mag', 2);
        $locked->update(['is_locked' => true]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()
            ->assertSee('name="materials['.$material->id.']"', false)->assertSee('name="relics[]"', false)
            ->assertDontSee('素材・不要な遺物を吸収する')->assertViewHas('forgeRelics', fn ($relics) => ! $relics->contains('id', $locked->id));
        $input = ['equipment_id' => $body->id, 'revision' => 0, 'materials' => [$material->id => 17], 'relics' => [$relic->id], 'protect_best' => 1, 'request_uuid' => $this->uuid(), 'gear_query' => '名もなき', 'gear_sort' => 'forge_desc'];
        $response = $this->post(route('nameless-workshop.act', 'preview-forge'), $input)->assertOk()
            ->assertSee('強化内容の確認')->assertSee('試験用の木片 ×17')->assertSee($relic->displayName())->assertSee('消費せず戻る');
        $preview = $response->viewData('preview');
        $back = route('nameless-workshop.index', ['gear_query' => '名もなき', 'gear_sort' => 'forge_desc', 'tab' => 'workshop', 'equipment' => $body->id]);
        $this->assertSame(100, (int) $material->fresh()->quantity);
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'equipment' => $body->id]))->assertOk();
        $this->get(route('nameless-workshop.index', ['tab' => 'sets', 'equipment' => $body->id]))->assertOk();
        $this->get($back)->assertOk()->assertViewHas('forgeSelection', fn ($selection) => $selection['quantities'][$material->id] === 17 && $selection['relicIds'] === [(string) $relic->id]);
        $this->post(route('nameless-workshop.act', 'forge-combined'), $input + ['confirmation_hash' => $preview['confirmation_hash']])->assertSessionHasErrors('confirmed');
        $this->post(route('nameless-workshop.act', 'forge-combined'), $input + ['confirmation_hash' => $preview['confirmation_hash'], 'confirmed' => 1])->assertRedirect($back);
        $this->assertNull(session('nameless_forge_selection'));
        $this->assertSame(83, (int) $material->fresh()->quantity);
        $this->assertNull($relic->fresh());
        $this->assertSame(1, $body->fresh()->forge_level);
        $this->post(route('nameless-workshop.act', 'preview-forge'), array_replace($input, ['relics' => [1, 1]]))->assertSessionHasErrors('relics.0');
    }

    public function test_acquired_list_includes_owned_starter_and_deduplicates_types_without_changing_ruin_discovery(): void
    {
        $character = $this->character();
        $starter = $this->body($character);
        $this->droppedBody($character, 'weapon', '剣');
        $this->droppedBody($this->character(), 'armor', '盾');
        $collection = app(\App\Services\NamelessEquipmentCollectionService::class);
        $summary = $collection->summary($character);
        $this->assertSame(['剣'], $summary['acquired']);
        $this->assertSame(1, $summary['acquired_count']);
        $this->assertSame([], $summary['found']);
        $this->assertSame(0, $summary['count']);
        $this->assertSame(0, \App\Models\NamelessEquipmentDiscovery::query()->count());
        \App\Models\NamelessEquipmentDiscovery::query()->create(['character_id' => $character->id, 'kind' => 'armor', 'equipment_type' => '服']);
        $this->assertSame(['剣', '服'], $collection->summary($character)['acquired']);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $starter->id]))->assertOk()->assertSee('武具の入手 2／20')
            ->assertSee('入手済み 剣')->assertSee('未入手 盾')->assertSee('入手済み 服');
        $this->workshop()->configure($character, $starter->id, '杖', '初期の杖', $this->uuid());
        $this->assertSame(['剣', '杖', '服'], $collection->summary($character)['acquired']);
        $this->assertSame(['服'], $collection->summary($character)['found']);
    }

    public function test_starter_shape_display_tracks_current_shape_without_creating_permanent_discovery_records(): void
    {
        $character = $this->character();
        $starter = $this->body($character);
        $collection = app(\App\Services\NamelessEquipmentCollectionService::class);
        $this->assertSame(['剣'], $collection->summary($character)['acquired']);
        $this->workshop()->configure($character, $starter->id, '杖', '', $this->uuid());
        $this->assertSame(['杖'], $collection->summary($character)['acquired']);
        $this->assertSame(1, $collection->summary($character)['acquired_count']);
        $this->assertSame(0, \App\Models\NamelessEquipmentDiscovery::query()->count());
    }

    public function test_workshop_rename_is_accessible_above_forging_and_http_rename_preserves_type_growth_relics_and_filters(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 4, 'growth_exp' => 42]);
        $relic = $this->relic($character);
        $this->workshop()->attach($character, $body->id, 1, $relic->id, $this->uuid());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $page = $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk();
        $this->assertLessThan(strpos($page->getContent(), 'data-nameless-forge'), strpos($page->getContent(), 'data-nameless-rename'));
        $input = ['equipment_id' => $body->id, 'type' => '剣', 'name' => '暁の剣', 'workshop_tab' => 'workshop', 'gear_type' => '剣', 'request_uuid' => $this->uuid()];
        $back = route('nameless-workshop.index', ['gear_type' => '剣', 'tab' => 'workshop', 'equipment' => $body->id]);
        $this->post(route('nameless-workshop.act', 'rename'), $input)->assertRedirect($back);
        $this->get($back)->assertOk()->assertSee('<span class="renamed-equipment">暁の剣 +4</span>', false);
        $this->assertSame('剣', $body->fresh()->equipment_type);
        $this->assertSame(4, $body->fresh()->forge_level);
        $this->assertSame(42, $body->fresh()->growth_exp);
        $this->assertSame($body->id, $relic->fresh()->nameless_equipment_id);
        $this->post(route('nameless-workshop.act', 'rename'), array_replace($input, ['name' => '', 'request_uuid' => $this->uuid()]))->assertRedirect($back);
        $this->get($back)->assertOk()->assertSee('<span class="">名もなき剣 +4</span>', false);
    }

    public function test_name_only_rename_preserves_shape_in_other_tabs_and_is_idempotent(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $this->workshop()->configure($character, $body->id, '杖', '', $this->uuid());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $input = ['equipment_id' => $body->id, 'type' => '剣', 'name' => '星巡り', 'request_uuid' => $this->uuid()];
        $back = route('nameless-workshop.index', ['tab' => 'workshop', 'equipment' => $body->id]);
        $this->post(route('nameless-workshop.act', 'rename'), $input)->assertRedirect($back);
        $this->post(route('nameless-workshop.act', 'rename'), $input)->assertRedirect($back);
        $this->assertSame('杖', $body->fresh()->equipment_type);
        $this->assertSame('星巡り', $body->fresh()->custom_name);
        $this->assertSame(2, $body->fresh()->revision);
        $this->assertSame(1, NamelessWorkshopOperation::query()->where('action', 'rename')->count());
        $this->post(route('nameless-workshop.act', 'rename'), array_replace($input, ['name' => '別名']))->assertSessionHas('error');
        $this->assertSame('星巡り', $body->fresh()->custom_name);
    }

    public function test_name_only_rename_rejects_invalid_and_foreign_names_without_changes(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $foreign = $this->body($this->character());
        $this->reject(fn () => $this->workshop()->rename($character, $body->id, '<script>', $this->uuid()), '名前は');
        $this->reject(fn () => $this->workshop()->rename($character, $body->id, str_repeat('名', 33), $this->uuid()), '32文字');
        $this->reject(fn () => $this->workshop()->rename($character, $foreign->id, '他人の剣', $this->uuid()), 'その名もなき武具は所持していません。');
        $this->assertFalse($body->fresh()->isRenamed());
        $this->assertFalse($foreign->fresh()->isRenamed());
        $this->assertSame(0, $body->fresh()->revision);
    }

    private function unlockThrough(Character $character, string $zone): void
    {
        foreach (array_keys(config('nameless_ruins')) as $key) {
            if ($key === $zone) {
                break;
            }
            NamelessRuinProgress::query()->updateOrCreate(['character_id' => $character->id, 'zone_key' => $key], ['unlocked_depth' => 51]);
        }
    }

    public function test_weapon_curve_grows_past_epic_without_rewriting_owned_data_and_stops_when_disabled(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 99, 'custom_name' => '星砕き', 'growth_exp' => 17]);
        $before = $body->getAttributes();
        $this->assertSame(12500, $body->power());
        foreach ([0 => 5, 1 => 11, 2 => 19, 10 => 177, 50 => 3315, 70 => 6354, 71 => 6532, 90 => 10372, 99 => 12500] as $level => $power) {
            $this->assertSame($power, $body->powerAt($level));
        }
        foreach (range(1, 99) as $level) {
            $this->assertGreaterThan($body->powerAt($level - 1), $body->powerAt($level));
        }
        $this->assertSame($before, $body->fresh()->getAttributes());
        $this->assertSame(8000, $this->body($character, 'armor')->powerAt(99));
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertSee('武器性能 攻撃 +12500');
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertSame(500, $body->power());
        }
        $this->app->instance('env', 'testing');
        config(['nameless_relics.enabled' => false]);
        $this->assertSame(500, $body->power());
    }

    public function test_max_nameless_weapon_exceeds_epic_primary_performance_and_equipped_ability(): void
    {
        foreach ([[0.30, 'str', '剣', 2824, 5852], [0.40, 'str', '剣', 2824, 6415], [0.30, 'mag', '魔導書', 2216, 4592], [0.40, 'mag', '魔導書', 2216, 5033]] as [$engravingRate, $stat, $type, $epicBase, $epicPower]) {
            config(['equipment_affix.engraving_effect_rates.5' => $engravingRate]);
            foreach ([1000, 10000] as $baseStat) {
                $character = $this->character();
                $character->update(['attack_base' => $baseStat, 'magic_base' => $baseStat]);
                $item = Item::query()->create(['name' => '最大性能比較用EPIC', 'type' => 'weapon', 'weapon_rank' => 'EPIC', $stat.'_bonus' => $epicBase, 'is_active' => true]);
                $prefix = \App\Models\EquipmentAffixPrefix::query()->create(['name' => '比較用の銘', 'affix_key' => 'comparison_'.$epicPower.'_'.$baseStat, 'target_stat' => $stat, 'is_active' => true]);
                $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'enhance_level' => 30, 'affix_prefix_id' => $prefix->id, 'affix_prefix_level' => 5, 'affix_quality' => 'excellent']);
                $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
                $epicStats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                $this->assertSame($epicPower, $epicStats['weapon_offense'][$stat]);
                $body = $this->body($character);
                $body->update(['forge_level' => 99, 'equipment_type' => $type]);
                $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
                $namelessStats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                $this->assertSame(12500, $namelessStats['weapon_offense'][$stat]);
                $this->assertGreaterThan($epicStats[$stat], $namelessStats[$stat]);
                $this->assertFalse($owned->fresh()->is_equipped);
            }
        }
    }

    public function test_forging_uses_new_curve_with_unchanged_points_gold_and_replay_safety(): void
    {
        $character = $this->character();
        $body = $this->body($character);
        $body->update(['forge_level' => 1, 'growth_exp' => 40]);
        $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        $revision = $body->fresh()->revision;
        $this->assertSame(10037, app(CharacterStatusService::class)->getFinalStats($character->fresh())['str']);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertSee('武器性能 攻撃 +11 → +19');
        $preview = $this->workshop()->previewForge($character, $body->id, $revision, [], [], true, false);
        $this->assertSame(40, $preview['spent_exp']);
        $this->assertSame(2000, $preview['gold']);
        $uuid = $this->uuid();
        $result = $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid);
        $this->assertSame($result, $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid));
        $this->assertSame(19, $body->fresh()->power());
        $this->assertSame(2, $body->fresh()->forge_level);
        $this->assertSame(0, $body->fresh()->growth_exp);
        $this->assertSame(8000, (int) $character->fresh()->money);
        $this->assertSame(10063, app(CharacterStatusService::class)->getFinalStats($character->fresh())['str']);
    }

    public function test_armor_curve_preserves_owned_data_and_all_six_types_and_fails_closed(): void
    {
        $character = $this->character();
        $body = $this->body($character, 'armor');
        $body->update(['forge_level' => 99, 'custom_name' => '星護り', 'growth_exp' => 17, 'is_locked' => true]);
        $this->workshop()->attach($character, $body->id, 1, $this->relic($character, 'stat_hp', 9)->id, $this->uuid());
        $body->refresh();
        $before = $body->getAttributes();
        $relicBefore = $body->relics->first()->getAttributes();
        foreach ([0 => 5, 1 => 10, 2 => 18, 10 => 131, 50 => 2168, 90 => 6653, 99 => 8000] as $level => $power) {
            $this->assertSame($power, $body->powerAt($level));
        }
        foreach (range(1, 99) as $level) {
            $this->assertGreaterThan($body->powerAt($level - 1), $body->powerAt($level));
        }
        foreach (['鎧', '服', '外套', '盾', '装束', 'ローブ'] as $type) {
            $copy = clone $body;
            $copy->equipment_type = $type;
            $targets = config('nameless_relics.armor_stat_targets_at_max.'.$type);
            $this->assertSame($targets, $copy->performanceStats());
            $primary = \App\Services\NamelessEquipmentService::statFor('armor', $type)['key'];
            $this->assertSame($targets[$primary], $copy->power());
            $this->assertSame(['def' => 5, 'spr' => 5], $copy->performanceStatsAt(0));
            foreach (range(1, 99) as $level) {
                foreach (['def', 'spr'] as $stat) {
                    $this->assertGreaterThan($copy->performanceStatsAt($level - 1)[$stat], $copy->performanceStatsAt($level)[$stat]);
                }
            }
        }
        $this->assertSame($before, $body->fresh()->getAttributes());
        $this->assertSame($relicBefore, $body->relics->first()->fresh()->getAttributes());
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertSee('防具性能 防御 +8000 / 精神 +4300');
        config(['nameless_relics.enabled' => false]);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->assertSame(500, $body->power());
            $this->assertSame(['def' => 500], $body->performanceStats());
            $this->assertSame(['def' => 0, 'spr' => 0], $this->workshop()->equippedArmorDefense($character));
        }
        $this->app->instance('env', 'testing');
        config(['nameless_relics.enabled' => false]);
        $this->assertSame(500, $body->power());
    }

    public function test_max_nameless_armor_exceeds_epic_primary_performance_and_equipped_ability(): void
    {
        foreach ([[0.30, 'def', '鎧', 2248, 4658], [0.40, 'def', '鎧', 2248, 5106], [0.30, 'spr', 'ローブ', 2088, 4326], [0.40, 'spr', 'ローブ', 2088, 4742]] as [$engravingRate, $stat, $type, $epicBase, $epicPower]) {
            config(['equipment_affix.engraving_effect_rates.5' => $engravingRate]);
            foreach ([0, 1000, 10000] as $baseStat) {
                $character = $this->character();
                $character->update(['defense_base' => $baseStat, 'spirit_base' => $baseStat]);
                $item = Item::query()->create(['name' => '最大性能比較用EPIC防具', 'type' => 'armor', 'armor_rank' => 'EPIC', $stat.'_bonus' => $epicBase, 'is_active' => true]);
                $prefix = \App\Models\EquipmentAffixPrefix::query()->create(['name' => '比較用の銘', 'affix_key' => 'armor_comparison_'.$epicPower.'_'.$baseStat, 'target_stat' => $stat, 'is_active' => true]);
                $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'enhance_level' => 30, 'affix_prefix_id' => $prefix->id, 'affix_prefix_level' => 5, 'affix_quality' => 'excellent']);
                $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
                $epicStats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                $this->assertSame($epicPower, $epicStats['armor_defense'][$stat]);
                $body = $this->body($character, 'armor');
                $body->update(['forge_level' => 99, 'equipment_type' => $type]);
                $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
                $namelessStats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                $this->assertSame(8000, $namelessStats['armor_defense'][$stat]);
                $this->assertSame($baseStat, $namelessStats['armor_base'][$stat]);
                $this->assertGreaterThan($epicStats[$stat], $namelessStats[$stat]);
                $this->assertFalse($owned->fresh()->is_equipped);
            }
        }
    }

    public function test_nameless_armor_body_is_defense_performance_while_relics_are_percentage_and_bench_is_excluded(): void
    {
        $character = $this->character();
        $weapon = $this->body($character);
        $weapon->update(['forge_level' => 99]);
        $armor = $this->body($character, 'armor');
        $armor->update(['forge_level' => 99]);
        $this->workshop()->attach($character, $armor->id, 1, $this->relic($character, 'stat_def', 9)->id, $this->uuid());
        $this->workshop()->attach($character, $weapon->id, 1, $this->relic($character, 'stat_spr', 9)->id, $this->uuid());
        $this->workshop()->changeEquipment($character, $armor->id, true, $this->uuid());
        $this->workshop()->changeEquipment($character, $weapon->id, true, $this->uuid());
        $bench = $this->droppedBody($character, 'armor', 'ローブ');
        $bench->update(['forge_level' => 99]);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame(['def' => 8000, 'spr' => 4300], $stats['armor_defense']);
        $this->assertSame(['def' => 1000, 'spr' => 1000], $stats['armor_base']);
        $this->assertSame(0, $this->workshop()->equippedFixedBonuses($character)['def']);
        $this->assertSame(0, $this->workshop()->equippedFixedBonuses($character)['spr']);
        $this->assertSame(4982, $stats['def']);
        $this->assertSame(3210, $stats['spr']);
        $this->assertSame(51667, $stats['str']);
        $this->assertFalse($bench->fresh()->is_equipped);
        $this->workshop()->changeEquipment($character, $armor->id, false, $this->uuid());
        $after = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame(['def' => 0, 'spr' => 0], $after['armor_defense']);
        $this->assertSame(1000, $after['def']);
        $this->assertSame(1150, $after['spr']);
    }

    public function test_armor_forging_keeps_points_gold_and_replay_safety_and_shows_performance(): void
    {
        foreach (['鎧' => ['def', '防御'], 'ローブ' => ['spr', '精神']] as $type => [$stat, $label]) {
            $character = $this->character();
            $body = $this->body($character, 'armor');
            $body->update(['forge_level' => 1, 'equipment_type' => $type, 'growth_exp' => 40]);
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
            $revision = $body->fresh()->revision;
            $this->assertSame(1004, app(CharacterStatusService::class)->getFinalStats($character->fresh())[$stat]);
            $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
            $this->get(route('nameless-workshop.index', ['equipment' => $body->id]))->assertOk()->assertSee($type === '鎧' ? '防具性能 防御 +10 → +18 / 精神 +10 → +16' : '防具性能 防御 +10 → +16 / 精神 +10 → +18');
            $preview = $this->workshop()->previewForge($character, $body->id, $revision, [], [], true, false);
            $this->assertSame(40, $preview['spent_exp']);
            $this->assertSame(2000, $preview['gold']);
            $uuid = $this->uuid();
            $result = $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid);
            $this->assertSame($result, $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $uuid));
            $this->assertSame(18, $body->fresh()->power());
            $this->assertSame(2, $body->fresh()->forge_level);
            $this->assertSame(0, $body->fresh()->growth_exp);
            $this->assertSame(8000, (int) $character->fresh()->money);
            $this->assertSame(1008, app(CharacterStatusService::class)->getFinalStats($character->fresh())[$stat]);
            $otherStat = $stat === 'def' ? 'spr' : 'def';
            $this->assertSame(1007, app(CharacterStatusService::class)->getFinalStats($character->fresh())[$otherStat]);
            $this->assertSame(10000, (int) $character->fresh()->current_hp);
            $this->assertSame(500, (int) $character->fresh()->current_mp);
        }
    }

    public function test_accessory_swap_preview_recalculates_equipped_nameless_weapon_and_armor_performance(): void
    {
        $character = $this->character();
        foreach (['weapon', 'armor'] as $kind) {
            $body = $this->body($character, $kind);
            $body->update(['forge_level' => 99]);
            $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
        }
        $previous = Item::query()->create(['name' => '比較前の指輪', 'type' => 'accessory', 'str_bonus' => 20, 'mag_bonus' => 40, 'def_bonus' => 80, 'spr_bonus' => 16, 'accessory_performance_scale_version' => 2, 'is_active' => true]);
        $previousOwned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $previous->id, 'is_equipped' => true, 'equipped_slot' => 'accessory']);
        $next = Item::query()->create(['name' => '比較後の指輪', 'type' => 'accessory', 'str_bonus' => 30, 'mag_bonus' => 50, 'def_bonus' => 120, 'spr_bonus' => 24, 'accessory_performance_scale_version' => 2, 'is_active' => true]);
        $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $next->id]);
        CharacterStatusService::clearRequestCache($character->id);
        $service = app(CharacterStatusService::class);
        $preview = $service->equipmentSwapPreviewForItem($character->fresh(), $next, $previousOwned);
        $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
        $actual = $service->getFinalStats($character->fresh());
        foreach (['str', 'mag', 'def', 'spr'] as $key) {
            $this->assertSame($actual[$key], $preview['after_stats'][$key], $key);
        }
        $this->assertSame(12500, $actual['weapon_offense']['str']);
        $this->assertSame(8000, $actual['armor_defense']['def']);
        $this->assertSame(4300, $actual['armor_defense']['spr']);
    }

    public function test_dual_armor_exceeds_matching_epic_in_both_stats_and_switch_preview_matches(): void
    {
        $profiles = [
            ['鎧', 2248, 808, ['def' => 8000, 'spr' => 4300]],
            ['盾', 2248, 808, ['def' => 8000, 'spr' => 4300]],
            ['ローブ', 968, 2088, ['def' => 4300, 'spr' => 8000]],
            ['服', 1128, 1128, ['def' => 6400, 'spr' => 6400]],
            ['外套', 1128, 1128, ['def' => 6400, 'spr' => 6400]],
            ['装束', 1128, 1128, ['def' => 6400, 'spr' => 6400]],
        ];
        foreach ([.30, .40] as $engravingRate) {
            config(['equipment_affix.engraving_effect_rates.5' => $engravingRate]);
            foreach ($profiles as [$type, $epicDef, $epicSpr, $targets]) {
                foreach (['def', 'spr', 'all'] as $engravingStat) {
                    $character = $this->character();
                    $item = Item::query()->create(['name' => '防具二能力比較EPIC', 'type' => 'armor', 'armor_rank' => 'EPIC', 'def_bonus' => $epicDef, 'spr_bonus' => $epicSpr, 'is_active' => true]);
                    $prefix = \App\Models\EquipmentAffixPrefix::query()->create(['name' => '二能力比較の銘', 'affix_key' => 'armor_dual_'.$character->id, 'target_stat' => $engravingStat, 'is_active' => true]);
                    $owned = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'enhance_level' => 30, 'affix_prefix_id' => $prefix->id, 'affix_prefix_level' => 5, 'affix_quality' => 'excellent']);
                    $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
                    $epicStats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                    $body = $this->body($character, 'armor');
                    $body->update(['forge_level' => 99, 'equipment_type' => $type]);
                    $this->workshop()->changeEquipment($character, $body->id, true, $this->uuid());
                    $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                    $this->assertSame($targets, $stats['armor_defense']);
                    foreach (['def', 'spr'] as $stat) {
                        $this->assertGreaterThan($epicStats['armor_defense'][$stat], $stats['armor_defense'][$stat], $type.' '.$engravingStat.' '.$stat);
                        $this->assertGreaterThan($epicStats[$stat], $stats[$stat], $type.' '.$engravingStat.' final '.$stat);
                    }
                    $preview = app(CharacterStatusService::class)->armorEffectivePreview($character->fresh(), $owned);
                    $this->assertTrue(app(EquipmentService::class)->equip($character, $owned)['success']);
                    $actual = app(CharacterStatusService::class)->getFinalStats($character->fresh());
                    foreach (['def', 'spr'] as $stat) {
                        $this->assertSame($actual[$stat], $preview[$stat]);
                    }
                    $this->assertSame(['def' => 0, 'spr' => 0], $this->workshop()->equippedArmorDefense($character));
                }
            }
        }
    }

    public function test_dual_armor_forge_confirmation_contains_both_gains_and_rechecks_balance_changes(): void
    {
        $character = $this->character();
        $body = $this->body($character, 'armor');
        $body->update(['forge_level' => 1, 'growth_exp' => 40]);
        $revision = $body->fresh()->revision;
        $preview = $this->workshop()->previewForge($character, $body->id, $revision, [], [], true, false);
        $this->assertSame(['def' => 10, 'spr' => 10], $preview['performance_before']);
        $this->assertSame(['def' => 18, 'spr' => 16], $preview['performance_after']);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $input = ['equipment_id' => $body->id, 'revision' => $revision, 'materials' => [], 'relics' => [], 'protect_best' => 1, 'request_uuid' => $this->uuid()];
        $this->post(route('nameless-workshop.act', 'preview-forge'), $input)->assertOk()
            ->assertSee('防具性能 防御 +10 → +18 / 精神 +10 → +16');
        $before = $body->fresh()->getAttributes();
        $gold = $character->fresh()->money;
        config(['nameless_relics.armor_stat_targets_at_max.鎧.spr' => 6500]);
        $this->reject(fn () => $this->workshop()->forgeCombined($character, $body->id, $revision, [], [], true, false, $preview['confirmation_hash'], $this->uuid()), '確認し直して');
        $this->assertSame($before, $body->fresh()->getAttributes());
        $this->assertSame($gold, $character->fresh()->money);
    }

    private function droppedBody(Character $character, string $kind = 'weapon', string $type = '剣'): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => $kind, 'equipment_type' => $type,
            'acquisition_source' => 'ruin', 'forge_level' => 0, 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]);
    }

    private function workshop(): NamelessWorkshopService
    {
        return app(NamelessWorkshopService::class);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function body(Character $character, string $kind = 'weapon'): PlayerNamelessEquipment
    {
        if ($kind === 'armor') {
            // 初期防具の配布は停止。互換性確認には旧配布済みの所有行を用意する。
            return PlayerNamelessEquipment::query()->firstOrCreate(
                ['character_id' => $character->id, 'kind' => 'armor', 'acquisition_source' => 'starter'],
                ['equipment_type' => '鎧', 'forge_level' => 0, 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]
            );
        }
        $result = $this->workshop()->claim($character, $kind, $kind === 'weapon' ? '剣' : '鎧', $this->uuid());

        return PlayerNamelessEquipment::query()->findOrFail($result['equipment_id']);
    }

    public function test_exclusive_groups_are_checked_on_attachment_and_equipment_switch(): void
    {
        $character = $this->character();
        $weapon = $this->body($character);
        $armor = $this->body($character, 'armor');
        $this->workshop()->attach($character, $weapon->id, 1, $this->relic($character, 'brand_dragon')->id, $this->uuid());
        $this->reject(fn () => $this->workshop()->attach($character, $weapon->id, 2, $this->relic($character, 'brand_beast')->id, $this->uuid()), '一種類まで');
        $this->workshop()->attach($character, $armor->id, 1, $this->relic($character, 'brand_beast')->id, $this->uuid());
        $this->workshop()->changeEquipment($character, $weapon->id, true, $this->uuid());
        $this->reject(fn () => $this->workshop()->changeEquipment($character, $armor->id, true, $this->uuid()), '一種類まで');
        $this->assertFalse($armor->fresh()->is_equipped);
        $replacement = $this->relic($character, 'brand_spirit');
        $this->workshop()->attach($character, $weapon->id, 1, $replacement->id, $this->uuid());
        $this->assertSame('brand_spirit', $weapon->relics()->first()->effect_key);
    }

    public function test_pvp_and_six_hero_use_relics_on_both_sides_without_mutating_characters(): void
    {
        $a = $this->character(); $b = $this->character();
        foreach ([$a, $b] as $c) { $c->update(['attack_base' => 1000, 'magic_base' => 1000]); }
        $b->update(['speed_base' => 1100]);
        $weaponA = $this->body($a); $weaponB = $this->body($b);
        foreach ([$a->id => [$a, $weaponA, ['brand_dragon', 'killer_dragon', 'form_spirit']], $b->id => [$b, $weaponB, ['brand_beast', 'counter_cleanse', 'special_convert_magical']]] as [$c, $weapon, $keys]) {
            foreach ($keys as $slot => $key) { $this->workshop()->attach($c, $weapon->id, $slot + 1, $this->relic($c, $key, 9)->id, $this->uuid()); }
            $this->workshop()->changeEquipment($c, $weapon->id, true, $this->uuid());
        }
        $beforeA = $a->fresh()->getAttributes(); $beforeB = $b->fresh()->getAttributes();
        $contexts = [\App\Services\Battle\PvPBattleExecutionContext::trainingGround()];
        foreach (\App\Enums\SixHeroRoomKey::cases() as $room) { $contexts[] = app(\App\Services\Battle\SixHeroBattleContextFactory::class)->makePractice($room); }
        foreach ($contexts as $context) {
            $resolution = app(\App\Services\PvPBattleService::class)->resolveBattle($a, $b, $context);
            $logs = implode(' ', $resolution->result->logs);
            $this->assertStringContainsString('種族刻印', $logs);
            $this->assertStringContainsString('浄刻の鏡', $logs);
            $this->assertGreaterThan(0, $resolution->turnCount);
            $this->assertGreaterThanOrEqual(0, $resolution->attackerHp);
            $this->assertGreaterThanOrEqual(0, $resolution->defenderHp);
        }
        $this->assertSame($beforeA, $a->fresh()->getAttributes()); $this->assertSame($beforeB, $b->fresh()->getAttributes());
        $this->assertSame(6, PlayerRelic::query()->whereIn('character_id', [$a->id, $b->id])->count());
        $this->assertDatabaseCount('arena_logs', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    private function relic(Character $character, string $effect = 'stat_str', int $rank = 1): PlayerRelic
    {
        return PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => $effect, 'rank' => $rank]);
    }

    private function material(Character $character): CharacterMaterial
    {
        $material = Material::query()->create(['material_code' => 'MAT_COMMON_TEST_'.$this->uuid(), 'name' => '試験用の木片', 'category' => '素材', 'rarity' => 'N', 'material_type' => 'common_drop', 'is_key_item' => false, 'is_cash_item' => false]);

        return CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 100]);
    }

    private function character(): Character
    {
        $town = app(\App\Services\NamelessTownService::class)->installLocalTown();
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '遺跡検証', 'current_city_id' => $town->id, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(), 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 10000, 'current_mp' => 500, 'attack_base' => 10000, 'magic_base' => 10000, 'defense_base' => 1000, 'spirit_base' => 1000, 'speed_base' => 1000, 'luck_base' => 10, 'money' => 10000]);
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
