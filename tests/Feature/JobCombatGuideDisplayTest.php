<?php

namespace Tests\Feature;

use App\Livewire\JobChange;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\PlayerValmon;
use App\Models\Skill;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class JobCombatGuideDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_weapon_shop_shows_current_job_guide_and_each_items_effective_rate(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        [$user, $character, $merchant] = $this->merchantPlayer();

        $nativeWeapon = $this->shopWeapon('店売りの短剣', 'dagger');
        $nativeWeapon->forceFill(['str_bonus' => 10])->save();
        $nonNativeWeapon = $this->shopWeapon('店売りの剣', 'sword');
        $nonNativeWeapon->forceFill(['str_bonus' => 500])->save();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'weapon']))
            ->assertOk()
            ->assertSee('現在職：商人')
            ->assertSee('通常攻撃：攻撃参照')
            ->assertSee('適正武器：')
            ->assertSee('短剣')
            ->assertSee('杖')
            ->assertSee('銃')
            ->assertSee('おすすめ順（適正優先）')
            ->assertSee('短剣適正')
            ->assertSee('剣適性外 65%')
            ->assertSee('bg-emerald-50/40', false)
            ->assertSeeInOrder(['店売りの短剣', '店売りの剣']);
    }

    public function test_weapon_shop_keeps_the_strict_restriction_message_when_penalties_are_disabled(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => false]);
        [$user, $character] = $this->merchantPlayer();

        $this->shopWeapon('装備できない剣', 'sword');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'weapon']))
            ->assertOk()
            ->assertSee('現在は適正武器のみ装備できます。')
            ->assertSee('現在の職業では装備できません')
            ->assertSee('武器性能')
            ->assertDontSee('剣適性外 65%');
    }

    public function test_weapon_shop_separates_equipped_ability_from_effective_and_raw_weapon_performance(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        [$user, $character] = $this->merchantPlayer();
        $character->forceFill([
            'attack_base' => 1200,
            'magic_base' => 800,
            'speed_base' => 300,
        ])->save();

        $currentWeapon = $this->shopWeapon('影炎の短剣', 'dagger');
        $currentWeapon->forceFill([
            'str_bonus' => 528,
            'mag_bonus' => 160,
        ])->save();
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $currentWeapon->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'enhance_level' => 1,
        ]);

        $candidateWeapon = $this->shopWeapon('熱砂の戦斧', 'axe');
        $candidateWeapon->forceFill([
            'sub_type' => '斧',
            'str_bonus' => 568,
            'agi_bonus' => -80,
        ])->save();
        CharacterStatusService::clearRequestCache($character->id);
        $preview = app(CharacterStatusService::class)->equipmentSwapPreviewForItem(
            $character,
            $candidateWeapon,
            CharacterItem::query()
                ->where('character_id', $character->id)
                ->where('equipped_slot', 'weapon')
                ->first(),
        );
        $this->assertSame(['str', 'agi'], $preview['performance_visible_stats']);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'weapon']))
            ->assertOk()
            ->assertSee('武器性能：')
            ->assertSee('攻撃 +543')
            ->assertSee('魔力 +164')
            ->assertSee('一撃型')
            ->assertSee('重量武器適性外 75%')
            ->assertSee('装備後の能力')
            ->assertSee('適性反映後の武器性能')
            ->assertSee('data-shop-effective-stat="str"', false)
            ->assertSee('+426')
            ->assertSee('補正前 +568')
            ->assertSee('-60')
            ->assertSee('補正前 -80')
            ->assertSee('↑')
            ->assertSee('↓');
    }

    public function test_explicit_attack_sort_keeps_attack_order_instead_of_native_priority(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        [$user, $character] = $this->merchantPlayer();

        $nativeWeapon = $this->shopWeapon('攻撃順の短剣', 'dagger');
        $nativeWeapon->forceFill(['str_bonus' => 10])->save();
        $nonNativeWeapon = $this->shopWeapon('攻撃順の剣', 'sword');
        $nonNativeWeapon->forceFill(['str_bonus' => 500])->save();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'weapon', 'sort' => 'attack_desc']))
            ->assertOk()
            ->assertSeeInOrder(['攻撃順の剣', '攻撃順の短剣']);
    }

    public function test_recommended_armor_sort_also_puts_native_armor_first(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        [$user, $character, $merchant] = $this->merchantPlayer();
        DB::table('job_armor_permissions')->insert([
            'job_id' => $merchant->id,
            'armor_category' => 'robe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nativeArmor = $this->shopArmor('おすすめ順のローブ', 'robe');
        $nativeArmor->forceFill(['def_bonus' => 10])->save();
        $nonNativeArmor = $this->shopArmor('おすすめ順の重鎧', 'heavy_armor');
        $nonNativeArmor->forceFill(['def_bonus' => 500])->save();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'armor']))
            ->assertOk()
            ->assertSeeInOrder(['おすすめ順のローブ', 'おすすめ順の重鎧']);
    }

    public function test_job_detail_hides_retired_special_skill_and_shows_job_art_damage_references(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        config(['battle.job_art_v2.loadout_v2' => true]);
        [$user, $character, $merchant] = $this->merchantPlayer();

        Skill::query()->updateOrCreate([
            'job_id' => $merchant->id,
            'skill_type' => 'special',
        ], [
            'name' => '廃止確認用の固有必殺技',
            'damage_type' => 'drop',
            'power_multiplier' => 1.25,
        ]);
        $art = Skill::query()->create([
            'job_id' => $merchant->id,
            'skill_type' => 'job_art',
            'name' => '金貨投げ',
            'learn_rank' => 1,
            'effect_template' => 'PHYSICAL_DAMAGE_GOLD_REWARD',
            'damage_type' => 'physical',
            'power' => 120,
            'power_multiplier' => 1.20,
            'hit_count' => 1,
        ]);

        session(['current_character_id' => $character->id]);
        Livewire::actingAs($user)
            ->test(JobChange::class)
            ->call('showJobDetail', $merchant->id)
            ->assertSet('showingJobDetail', true)
            ->assertSee('戦い方と適正武器')
            ->assertSee('通常攻撃')
            ->assertDontSee('廃止確認用の固有必殺技')
            ->assertSee('金貨投げ')
            ->assertSee('Cost 1')
            ->assertDontSee('Cost 5')
            ->assertSee('攻撃参照')
            ->assertSet(
                'detailJobCombatGuide.job_art_damage_references.' . $art->id,
                '攻撃参照',
            );
    }

    public function test_job_detail_uses_the_current_canonical_job_art_description(): void
    {
        [$user, $character] = $this->merchantPlayer();
        $magicThief = JobClass::query()->findOrFail(19);
        $magicThief->forceFill([
            'is_hidden' => false,
            'is_active' => true,
        ])->save();

        Skill::query()->updateOrCreate([
            'job_id' => $magicThief->id,
            'skill_type' => 'job_art',
            'learn_rank' => 5,
        ], [
            'name' => 'スピリットスティール',
            'memo' => 'HP/SP吸収＋敵SPR低下',
            'description' => '旧説明',
            'effect_template' => 'DRAIN',
            'damage_type' => 'magical',
            'power' => 165,
            'power_multiplier' => 1.65,
            'hit_count' => 1,
            'mp_recover_percent' => 10,
        ]);

        session(['current_character_id' => $character->id]);
        Livewire::actingAs($user)
            ->test(JobChange::class)
            ->call('showJobDetail', $magicThief->id)
            ->assertSee('冥蝕を-4し、相手に威力165%の魔力ダメージを与え、与えたダメージの30%分、自分のHPを回復する。')
            ->assertSee('相手の現在SPを最大SPの3%分減らす。')
            ->assertDontSee('HP/SP吸収＋敵SPR低下')
            ->assertDontSee('最大SP回復 +10%');
    }

    public function test_hero_job_detail_uses_the_dedicated_full_screen_showcase(): void
    {
        [$user, $character] = $this->merchantPlayer();
        $hero = JobClass::query()->findOrFail(70);
        $hero->forceFill([
            'name' => '暁の勇者',
            'description' => '暁の光を掲げ、仲間を勝利へ導く英雄職。',
            'rank' => 'hero',
            'normal_attack_type' => 'physical',
            'hp_rate' => 225,
            'mp_rate' => 195,
            'atk_rate' => 235,
            'def_rate' => 215,
            'mag_rate' => 205,
            'spr_rate' => 215,
            'spd_rate' => 170,
            'luck_rate' => 150,
            'is_hidden' => false,
            'is_active' => true,
        ])->save();

        session(['current_character_id' => $character->id]);
        Livewire::actingAs($user)
            ->test(JobChange::class)
            ->call('showJobDetail', $hero->id)
            ->assertSet('showingJobDetail', false)
            ->assertSet('showingHeroJobDetail', true)
            ->assertSee('英雄職の間')
            ->assertSee('暁の勇者')
            ->assertSee('成長する能力')
            ->assertSee('覚える奥義')
            ->assertSee('マスター恩恵')
            ->assertSee('images/jobbadge/jobbadge_070.webp', false)
            ->assertSee('images/symbol/hero_trial_070.webp', false)
            ->assertSee('images/job_portrait/hero_trial_070.webp', false)
            ->assertSee('data-hero-job-portrait="70"', false)
            ->assertSee('data-hero-job-topbar', false)
            ->assertDontSee('この職業を極めると、転職後も恩恵が残ります。');
    }

    public function test_all_hero_job_showcases_have_badge_trial_symbol_and_portrait_assets(): void
    {
        foreach (range(70, 79) as $jobId) {
            $number = str_pad((string) $jobId, 3, '0', STR_PAD_LEFT);

            $this->assertFileExists(public_path("images/jobbadge/jobbadge_{$number}.webp"));
            $this->assertFileExists(public_path("images/symbol/hero_trial_{$number}.webp"));
            $this->assertFileExists(public_path("images/job_portrait/hero_trial_{$number}.webp"));
        }
    }

    /**
     * @return array{User, Character, JobClass}
     */
    private function merchantPlayer(): array
    {
        $user = User::factory()->create();
        $merchant = JobClass::query()->firstOrCreate([
            'key' => 'merchant',
        ], [
            'name' => '商人',
            'rank' => 'normal',
        ]);
        $merchant->forceFill([
            'name' => '商人',
            'rank' => 'normal',
            'normal_attack_type' => 'physical',
            'is_active' => true,
        ])->save();
        DB::table('job_weapon_permissions')->insertOrIgnore(array_map(
            fn (string $category): array => [
                'job_id' => $merchant->id,
                'weapon_category' => $category,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['dagger', 'gun', 'staff'],
        ));
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '商人テスト',
            'level' => 30,
            'current_job_id' => $merchant->id,
            'current_city_id' => 1,
            'money' => 10_000,
        ]);
        $valmon = ValmonMaster::query()->create([
            'valmon_key' => 'merchant-guide-' . $user->id,
            'name' => '商人案内モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmon->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character, $merchant];
    }

    private function shopWeapon(string $name, string $weaponCategory): Item
    {
        return Item::query()->create([
            'name' => $name,
            'type' => 'weapon',
            'weapon_category' => $weaponCategory,
            'weapon_rank' => 'G',
            'price' => 100,
            'unlock_city_id' => 1,
            'is_shop_item' => true,
            'is_active' => true,
            'str_bonus' => 10,
        ]);
    }

    private function shopArmor(string $name, string $armorCategory): Item
    {
        return Item::query()->create([
            'name' => $name,
            'type' => 'armor',
            'armor_category' => $armorCategory,
            'armor_rank' => 'G',
            'price' => 100,
            'unlock_city_id' => 1,
            'is_shop_item' => true,
            'is_active' => true,
            'def_bonus' => 10,
        ]);
    }
}
