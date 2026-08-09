<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use PHPUnit\Framework\TestCase;

class DamageApplicationServiceTest extends TestCase
{
    public function test_it_applies_the_existing_final_hp_decrement_and_records_the_result(): void
    {
        $target = $this->actor('target', 100);
        $result = (new DamageApplicationService())->apply(new DamageApplicationRequest(
            sourceActor: $this->actor('source', 100),
            targetActor: $target,
            resolvedDamage: 30,
            sourceType: DamageSourceType::JOB_ART,
            sourceId: 9001,
            battleType: 'pve',
            hitResult: HitResult::HIT,
            hitIndex: 2,
            hitCount: 3,
        ));

        $this->assertSame(70, $target->hp);
        $this->assertSame(30, $target->totalDamageTaken);
        $this->assertSame(30, $result->requestedDamage);
        $this->assertSame(100, $result->hpBefore);
        $this->assertSame(70, $result->hpAfter);
        $this->assertSame(30, $result->actualHpLoss);
        $this->assertSame(0, $result->overkillDamage);
        $this->assertFalse($result->wasLethal);
        $this->assertSame(DamageSourceType::JOB_ART, $result->sourceType);
        $this->assertSame(9001, $result->sourceId);
        $this->assertSame(HitResult::HIT, $result->hitResult);
        $this->assertSame(2, $result->hitIndex);
        $this->assertSame(3, $result->hitCount);
    }

    public function test_overkill_and_guts_follow_the_existing_battle_actor_rules(): void
    {
        $service = new DamageApplicationService();
        $lethal = $this->actor('lethal', 40);
        $lethalResult = $service->apply($this->request($lethal, 75));

        $this->assertSame(0, $lethal->hp);
        $this->assertSame(40, $lethalResult->actualHpLoss);
        $this->assertSame(35, $lethalResult->overkillDamage);
        $this->assertTrue($lethalResult->wasLethal);

        $guts = $this->actor('guts', 40);
        $guts->gutsReady = true;
        $gutsResult = $service->apply($this->request($guts, 75));

        $this->assertSame(1, $guts->hp);
        $this->assertSame(39, $gutsResult->actualHpLoss);
        $this->assertSame(35, $gutsResult->overkillDamage);
        $this->assertFalse($gutsResult->wasLethal);
        $this->assertTrue($guts->gutsJustTriggered);
        $this->assertFalse($guts->gutsReady);
    }

    public function test_fixed_seed_ten_thousand_applications_match_legacy_for_all_battle_contexts(): void
    {
        $service = new DamageApplicationService();

        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach ([DamageSourceType::NORMAL_ATTACK, DamageSourceType::JOB_SKILL, DamageSourceType::JOB_ART] as $sourceType) {
                mt_srand(7300);
                $damages = [];
                for ($i = 0; $i < 10_000; $i++) {
                    $damages[] = mt_rand(1, 100);
                }
                $expectedNextRoll = mt_rand();

                $legacy = $this->actor('legacy', 2_000_000);
                foreach ($damages as $damage) {
                    $legacy->takeDamage($damage);
                }

                mt_srand(7300);
                $actual = $this->actor('actual', 2_000_000);
                for ($i = 0; $i < 10_000; $i++) {
                    $damage = mt_rand(1, 100);
                    $service->apply(new DamageApplicationRequest(
                        sourceActor: null,
                        targetActor: $actual,
                        resolvedDamage: $damage,
                        sourceType: $sourceType,
                        sourceId: null,
                        battleType: $battleType,
                    ));
                }

                $label = "{$battleType}:{$sourceType->value}";
                $this->assertSame($legacy->hp, $actual->hp, $label);
                $this->assertSame($legacy->totalDamageTaken, $actual->totalDamageTaken, $label);
                $this->assertSame($expectedNextRoll, mt_rand(), "{$label} consumed RNG");
            }
        }
    }

    private function request(BattleActor $target, int $damage): DamageApplicationRequest
    {
        return new DamageApplicationRequest(
            sourceActor: null,
            targetActor: $target,
            resolvedDamage: $damage,
            sourceType: DamageSourceType::FIXED,
            sourceId: null,
            battleType: 'pve',
        );
    }

    private function actor(string $name, int $hp): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }
}
