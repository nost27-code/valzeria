<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtService;
use App\Services\ValmonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValmonBondArtTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_valmon_defaults_to_balanced_and_trust_and_ranch_shows_all_choices(): void
    {
        [$user, $character, $valmon] = $this->createCharacterAndValmon(80);

        $spec = app(ValmonService::class)->assistAttackSpec($valmon->fresh('master'));

        $this->assertSame('balanced', $valmon->fresh()->bond_style);
        $this->assertSame('trust', $valmon->fresh()->bond_phrase_style);
        $this->assertSame(5.0, $spec['rate']);
        $this->assertSame(0.30, $spec['power_rate']);
        $this->assertSame(70, $spec['damage_variance_min_percent']);
        $this->assertSame(130, $spec['damage_variance_max_percent']);
        $this->assertSame('蒼天・狐月連牙', $spec['technique_name']);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))
            ->assertOk()
            ->assertSee('絆技の設定')
            ->assertSee('均衡')
            ->assertSee('速攻')
            ->assertSee('豪撃')
            ->assertSee('信頼')
            ->assertSee('熱血')
            ->assertSee('静か')
            ->assertSee('元気')
            ->assertSee('蒼天・狐月連牙')
            ->assertSee('蒼天・白狐連閃')
            ->assertSee('蒼天・天狐絶衝')
            ->assertSee('追撃ダメージは70〜130%で変動します。')
            ->assertSee('「任せたよ、ソラキツネ――一緒に決めよう！」');
    }

    public function test_bond_art_settings_are_saved_per_valmon_and_locked_styles_are_rejected(): void
    {
        [$user, $character, $valmon] = $this->createCharacterAndValmon(59);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('valmons.bond-art-settings', $valmon), [
                'bond_style' => 'quick',
                'bond_phrase_style' => 'hot_blooded',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'このスタイルはLv60で解放されます。');

        $this->assertDatabaseHas('player_valmons', [
            'id' => $valmon->id,
            'bond_style' => 'balanced',
            'bond_phrase_style' => 'trust',
        ]);

        $valmon->forceFill(['level' => 60])->save();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('valmons.bond-art-settings', $valmon), [
                'bond_style' => 'quick',
                'bond_phrase_style' => 'hot_blooded',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('player_valmons', [
            'id' => $valmon->id,
            'bond_style' => 'quick',
            'bond_phrase_style' => 'hot_blooded',
        ]);
        $quickSpec = app(ValmonService::class)->assistAttackSpec($valmon->fresh('master'));
        $this->assertSame(6.0, $quickSpec['rate']);
        $this->assertSame(0.25, $quickSpec['power_rate']);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('valmons.bond-art-settings', $valmon), [
                'bond_style' => 'heavy',
                'bond_phrase_style' => 'quiet',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'このスタイルはLv80で解放されます。');

        $this->assertDatabaseHas('player_valmons', [
            'id' => $valmon->id,
            'bond_style' => 'quick',
            'bond_phrase_style' => 'hot_blooded',
        ]);

        $valmon->forceFill(['level' => 80])->save();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('valmons.bond-art-settings', $valmon), [
                'bond_style' => 'heavy',
                'bond_phrase_style' => 'quiet',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $heavySpec = app(ValmonService::class)->assistAttackSpec($valmon->fresh('master'));
        $this->assertSame(3.0, $heavySpec['rate']);
        $this->assertSame(0.50, $heavySpec['power_rate']);
        $this->assertSame('quiet', $heavySpec['phrase_style_key']);
    }

    public function test_another_players_valmon_settings_cannot_be_changed(): void
    {
        [$owner, , $valmon] = $this->createCharacterAndValmon(80);
        [$otherUser, $otherCharacter] = $this->createCharacterAndValmon(80, 'other-rapil');
        $this->assertNotSame($owner->id, $otherUser->id);

        $this->actingAs($otherUser)
            ->withSession(['current_character_id' => $otherCharacter->id])
            ->post(route('valmons.bond-art-settings', $valmon), [
                'bond_style' => 'heavy',
                'bond_phrase_style' => 'quiet',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('player_valmons', [
            'id' => $valmon->id,
            'bond_style' => 'balanced',
            'bond_phrase_style' => 'trust',
        ]);
    }

    public function test_bond_art_can_activate_after_a_one_shot_in_a_boss_battle_and_only_rolls_once(): void
    {
        [, $character, $valmon] = $this->createCharacterAndValmon(80);
        $valmon->forceFill([
            'bond_style' => 'heavy',
            'bond_phrase_style' => 'hot_blooded',
        ])->save();
        config(['valmon_bond_arts.styles.heavy.rate' => 100.0]);

        $player = new BattleActor('試験冒険者', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 100,
            'def' => 20,
            'agi' => 20,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
        ], $character);
        $enemy = new BattleActor('試験ボス', false, [
            'hp' => 10,
            'max_hp' => 10,
            'str' => 10,
            'def' => 1,
            'agi' => 1,
            'mag' => 1,
            'spr' => 1,
            'luk' => 1,
        ]);
        $state = new BattleState($player, $enemy, 'boss');
        $service = new TestableValmonBondBattleService(
            app(CharacterStatusService::class),
            app(DamageCalculator::class),
            app(JobArtService::class),
        );

        $service->executePlayerAction($player, $enemy, $state);
        $log = implode("\n", $state->logs);

        $this->assertTrue($enemy->isDead());
        $this->assertTrue($state->valmonAssistRolled);
        $this->assertTrue($state->valmonAssistUsed);
        $this->assertStringContainsString('【絆技・豪撃】蒼天・天狐絶衝――ソラキツネによる追撃！', $log);
        $this->assertStringContainsString('「行くぞ、ソラキツネ――一気に決めるぞ！」', $log);
        $this->assertStringContainsString('光の尾を引くソラキツネが、空を蹴って試験ボスへ飛び込んだ！', $log);

        $logCount = count($state->logs);
        $service->executePlayerAction($player, $enemy, $state);
        $this->assertCount($logCount + 1, $state->logs);
        $this->assertSame(1, substr_count(implode("\n", $state->logs), '【絆技・豪撃】'));
    }

    public function test_bond_art_is_not_rolled_in_pvp(): void
    {
        [, $character] = $this->createCharacterAndValmon(80);
        config(['valmon_bond_arts.styles.balanced.rate' => 100.0]);

        $player = new BattleActor('試験冒険者', true, [
            'hp' => 100,
            'max_hp' => 100,
            'str' => 100,
            'def' => 20,
            'agi' => 20,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
        ], $character);
        $enemy = new BattleActor('対戦相手', false, [
            'hp' => 10,
            'max_hp' => 10,
            'str' => 10,
            'def' => 1,
            'agi' => 1,
            'mag' => 1,
            'spr' => 1,
            'luk' => 1,
        ]);
        $state = new BattleState($player, $enemy, 'pvp');
        $service = new TestableValmonBondBattleService(
            app(CharacterStatusService::class),
            app(DamageCalculator::class),
            app(JobArtService::class),
        );

        $service->executePlayerAction($player, $enemy, $state);

        $this->assertFalse($state->valmonAssistRolled);
        $this->assertFalse($state->valmonAssistUsed);
        $this->assertStringNotContainsString('【絆技', implode("\n", $state->logs));
    }

    public function test_bond_art_damage_uses_the_configured_variance_range(): void
    {
        $service = new TestableValmonBondBattleService(
            app(CharacterStatusService::class),
            app(DamageCalculator::class),
            app(JobArtService::class),
        );

        $this->assertSame(70, $service->applyTestValmonAssistDamageVariance(100, 70, 70));
        $this->assertSame(130, $service->applyTestValmonAssistDamageVariance(100, 130, 130));
    }

    private function createCharacterAndValmon(int $level, string $valmonKey = 'rapil'): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '絆技テスト',
        ]);
        $master = ValmonMaster::create([
            'valmon_key' => $valmonKey,
            'name' => $valmonKey === 'rapil' ? 'ソラキツネ' : '別のヴァルモン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        $valmon = PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'level' => $level,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character, $valmon];
    }
}

class TestableValmonBondBattleService extends BattleService
{
    public function executePlayerAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $this->executeAction($attacker, $defender, $state);
    }

    public function applyTestValmonAssistDamageVariance(int $baseDamage, int $min, int $max): int
    {
        return $this->applyValmonAssistDamageVariance($baseDamage, [
            'damage_variance_min_percent' => $min,
            'damage_variance_max_percent' => $max,
        ]);
    }

    protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        $damage = max(1, $defender->hp);
        $defender->takeDamage($damage);
        $state->addLog("{$attacker->name} の試験攻撃！ {$defender->name} に {$damage} のダメージ！");
    }
}
