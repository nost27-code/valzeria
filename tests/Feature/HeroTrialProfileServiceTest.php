<?php

namespace Tests\Feature;

use App\Services\HeroTrialProfileService;
use Tests\TestCase;

class HeroTrialProfileServiceTest extends TestCase
{
    public function test_all_trial_masters_have_valid_species_assignments(): void
    {
        $expected = [
            '双極天騎アウローラ' => ['spirit', 'soldier'],
            '月喰影獣ルナグリム' => ['beast', 'demon'],
            '天象魔導核アステリオン' => ['mage', 'machine'],
            '蒼穹古竜アズラギオン' => ['dragon', 'flying'],
            '天機偵察機シーカー' => ['machine', 'flying'],
            '天機重装機バスティオン' => ['machine', 'soldier'],
            '天機演算核オラクル' => ['machine', 'mage'],
            '天秤聖獣ユスティア' => ['beast', 'spirit'],
            '魂葬王ネクロディア' => ['undead', 'mage'],
            '時環の観測者エオン' => ['mage', 'spirit'],
            '雷嵐神獣テンペスタ' => ['beast', 'spirit'],
            '白銀城塞アルジェオン' => ['machine', 'spirit'],
        ];
        $labels = (array) config('enemy_species.labels', []);
        $masters = collect(config('hero_trials.trial_masters', []));

        $this->assertCount(12, $masters);
        $this->assertSame($expected, $masters->pluck('species_keys', 'name')->all());
        $masters->each(function (array $master) use ($labels): void {
            $this->assertCount(2, $master['species_keys']);
            foreach ($master['species_keys'] as $speciesKey) {
                $this->assertArrayHasKey($speciesKey, $labels);
            }
        });
    }

    public function test_dawn_trial_phases_create_spirit_enemies_for_killer_and_resistance_rules(): void
    {
        $profileService = app(HeroTrialProfileService::class);
        $profile = $profileService->profile('dawn_hero_balanced');
        $enemies = $profileService->enemies('dawn_hero_balanced');

        $this->assertCount(2, $profile['phases']);
        $this->assertSame(
            [['spirit', 'soldier'], ['spirit', 'soldier']],
            collect($profile['phases'])->pluck('species_keys')->all()
        );
        $this->assertSame(
            ['双極天騎アウローラ', '双極天騎アウローラ'],
            collect($profile['phases'])->pluck('name')->all()
        );
        $this->assertSame(['spirit', 'spirit'], $enemies->pluck('species_key')->all());
        $this->assertSame(
            [['spirit', 'soldier'], ['spirit', 'soldier']],
            $enemies->pluck('species_keys')->all()
        );
        $this->assertSame(['standard', 'standard'], $enemies->pluck('family_key')->all());
        $this->assertSame(['physical', 'magical'], $enemies->pluck('normal_attack_type')->all());
        $this->assertSame(
            ['images/enemy/enemy_723.webp', 'images/enemy/enemy_735.webp'],
            collect($profile['phases'])->pluck('image_path')->all()
        );
        $this->assertFileExists(public_path('images/enemy/enemy_735.webp'));
        $this->assertSame(['精霊', '人型'], $profileService->speciesLabels('dawn_hero_balanced'));
    }

    public function test_black_moon_trial_creates_a_fast_beast_with_configured_gimmick_actions(): void
    {
        $profileService = app(HeroTrialProfileService::class);
        $profile = $profileService->profile('black_moon_executor_balanced');
        $enemy = $profileService->enemies('black_moon_executor_balanced')->sole();

        $this->assertCount(1, $profile['phases']);
        $this->assertSame(81.4, $profile['benchmark']['pass_rate']);
        $this->assertSame(['beast', 'demon'], $profile['phases'][0]['species_keys']);
        $this->assertSame('月喰影獣ルナグリム', $enemy->name);
        $this->assertSame('高速妨害型', $enemy->type_name);
        $this->assertSame(7_500, $enemy->str);
        $this->assertSame(8_500, $enemy->agi);
        $this->assertSame(
            ['月影縛り', '蝕牙連舞', '月蝕深化'],
            $enemy->actions->pluck('name')->all(),
        );
        $this->assertSame(
            ['slow', 'multi_hit', 'self_buff'],
            $enemy->actions->pluck('action_type')->all(),
        );
        $this->assertSame(80, $enemy->actions->firstWhere('action_key', 'eclipse_fang_dance')->power_percent);
        $this->assertSame(15, $enemy->actions->firstWhere('action_key', 'deepening_eclipse')->effect_percent);
        $this->assertSame(['獣', '悪魔'], $profileService->speciesLabels('black_moon_executor_balanced'));
        $this->assertFileExists(public_path('images/enemy/enemy_724.webp'));
    }
}
