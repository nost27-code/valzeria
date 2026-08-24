<?php

namespace Tests\Unit;

use App\Enums\SixHeroRoomKey;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\MiraclePvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\Battle\SixHeroRoomRuleResolver;
use App\Support\SixHeroRoomUiCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class SixHeroRoomRegistryTest extends TestCase
{
    public function test_room_keys_and_labels_are_exactly_the_six_defined_rooms(): void
    {
        $actual = [];
        foreach (SixHeroRoomKey::cases() as $room) {
            $actual[$room->value] = $room->label();
        }

        $this->assertSame([
            'seal_magic' => '封魔の間',
            'seal_blade' => '封刃の間',
            'burning_life' => '灼命の間',
            'divine_speed' => '神速の間',
            'reverse_time' => '逆刻の間',
            'miracle' => '奇跡の間',
        ], $actual);
    }

    public function test_resolver_maps_every_room_to_its_exact_rule(): void
    {
        $resolver = new SixHeroRoomRuleResolver;
        $expected = [
            [SixHeroRoomKey::SEAL_MAGIC, SealMagicPvPRoomRule::class],
            [SixHeroRoomKey::SEAL_BLADE, SealBladePvPRoomRule::class],
            [SixHeroRoomKey::BURNING_LIFE, BurningLifePvPRoomRule::class],
            [SixHeroRoomKey::DIVINE_SPEED, DivineSpeedPvPRoomRule::class],
            [SixHeroRoomKey::REVERSE_TIME, ReverseTimePvPRoomRule::class],
            [SixHeroRoomKey::MIRACLE, MiraclePvPRoomRule::class],
        ];

        foreach ($expected as [$room, $expectedClass]) {
            $rule = $resolver->resolve($room);

            $this->assertInstanceOf(PvPRoomRuleInterface::class, $rule, $room->value);
            $this->assertSame($expectedClass, $rule::class, $room->value);
        }

        $this->assertCount(count(SixHeroRoomKey::cases()), $expected);
    }

    public function test_room_visuals_use_the_assigned_crown_and_accent_colors(): void
    {
        $expected = [
            SixHeroRoomKey::SEAL_MAGIC->value => ['crown_001.webp', 'border-violet-300 bg-violet-50'],
            SixHeroRoomKey::SEAL_BLADE->value => ['crown_002.webp', 'border-red-300 bg-red-50'],
            SixHeroRoomKey::BURNING_LIFE->value => ['crown_003.webp', 'border-orange-300 bg-orange-50'],
            SixHeroRoomKey::DIVINE_SPEED->value => ['crown_004.webp', 'border-green-300 bg-green-50'],
            SixHeroRoomKey::REVERSE_TIME->value => ['crown_005.webp', 'border-blue-300 bg-blue-50'],
            SixHeroRoomKey::MIRACLE->value => ['crown_006.webp', 'border-yellow-300 bg-yellow-50'],
        ];

        foreach (SixHeroRoomKey::cases() as $room) {
            [$crownFile, $accentClasses] = $expected[$room->value];

            $this->assertStringEndsWith($crownFile, SixHeroRoomUiCatalog::crownImagePath($room));
            $this->assertSame($accentClasses, SixHeroRoomUiCatalog::accentClasses($room));
        }
    }

    public function test_resolver_returns_a_fresh_rule_instance_for_every_resolution(): void
    {
        $resolver = new SixHeroRoomRuleResolver;

        foreach (SixHeroRoomKey::cases() as $room) {
            $this->assertNotSame(
                $resolver->resolve($room),
                $resolver->resolve($room),
                $room->value,
            );
        }

        $this->assertNotSame(
            $resolver->resolve(SixHeroRoomKey::BURNING_LIFE),
            $resolver->resolve(SixHeroRoomKey::BURNING_LIFE),
            '灼命の戦闘中状態を戦闘間で共有してはいけません。',
        );
    }

    public function test_unknown_strings_cannot_be_resolved_or_fall_back_to_a_null_rule(): void
    {
        $this->assertNull(SixHeroRoomKey::tryFrom('unknown_room'));

        $parameterType = (new ReflectionMethod(SixHeroRoomRuleResolver::class, 'resolve'))
            ->getParameters()[0]
            ->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $parameterType);
        $this->assertSame(SixHeroRoomKey::class, $parameterType->getName());
    }

    public function test_six_hero_context_disables_rank_battle_minimum_guarantees_and_damage_caps(): void
    {
        $factory = new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver());

        foreach (SixHeroRoomKey::cases() as $room) {
            $context = $factory->make($room);

            $this->assertFalse($context->rankBattleMinimumDamageGuaranteeEnabled, $room->value);
            $this->assertFalse($context->rankBattleDamageCapEnabled, $room->value);
        }

        $this->assertTrue(PvPBattleExecutionContext::arena()->rankBattleMinimumDamageGuaranteeEnabled);
        $this->assertTrue(PvPBattleExecutionContext::arena()->rankBattleDamageCapEnabled);
    }
}
