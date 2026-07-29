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

        $this->shopWeapon('店売りの短剣', 'dagger');
        $this->shopWeapon('店売りの剣', 'sword');

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
            ->assertSee('適正武器・装備効果100%')
            ->assertSee('適性外・装備効果65%');
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
            ->assertSee('強化前の基本値')
            ->assertDontSee('適性外・装備効果65%');
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
            'str_bonus' => 568,
            'agi_bonus' => -80,
        ])->save();
        CharacterStatusService::clearRequestCache($character->id);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('shop.equipment', ['type' => 'weapon']))
            ->assertOk()
            ->assertSee('武器性能：')
            ->assertSee('攻撃 +543')
            ->assertSee('魔力 +164')
            ->assertSee('装備後の能力')
            ->assertSee('現在装備から交換した場合')
            ->assertSee('武器性能')
            ->assertSee('現在職で実際に反映される値')
            ->assertSee('data-shop-effective-stat="str"', false)
            ->assertSee('+426')
            ->assertSee('補正前 +568')
            ->assertSee('-60')
            ->assertSee('補正前 -80')
            ->assertSee('低下');
    }

    public function test_job_detail_shows_special_skill_and_job_art_damage_references(): void
    {
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        [$user, $character, $merchant] = $this->merchantPlayer();

        Skill::query()->updateOrCreate([
            'job_id' => $merchant->id,
            'skill_type' => 'special',
        ], [
            'name' => '幸運の一手',
            'damage_type' => 'drop',
            'power_multiplier' => 1.25,
        ]);
        $art = Skill::query()->create([
            'job_id' => $merchant->id,
            'skill_type' => 'job_art',
            'name' => '金貨投げ',
            'learn_rank' => 2,
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
            ->assertSee('幸運の一手')
            ->assertSee('金貨投げ')
            ->assertSee('攻撃参照')
            ->assertSet(
                'detailJobCombatGuide.job_art_damage_references.' . $art->id,
                '攻撃参照',
            );
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
}
