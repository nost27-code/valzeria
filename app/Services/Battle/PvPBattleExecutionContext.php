<?php

namespace App\Services\Battle;

final readonly class PvPBattleExecutionContext
{
    public function __construct(
        public string $displayLabel = '闘技場',
        public string $jobArtContext = 'champ',
        public ?PvPRoomRuleInterface $roomRule = null,
        public bool $rankBattleMinimumDamageGuaranteeEnabled = true,
        public bool $rankBattleDamageCapEnabled = true,
    ) {}

    public static function arena(): self
    {
        return new self(
            displayLabel: '闘技場',
            jobArtContext: 'champ',
            roomRule: null,
            rankBattleMinimumDamageGuaranteeEnabled: true,
            rankBattleDamageCapEnabled: true,
        );
    }

    public static function trainingGround(): self
    {
        return new self(
            displayLabel: '対人模擬戦',
            jobArtContext: 'champ',
            roomRule: null,
            rankBattleMinimumDamageGuaranteeEnabled: true,
            rankBattleDamageCapEnabled: true,
        );
    }
}
