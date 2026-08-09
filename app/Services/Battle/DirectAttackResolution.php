<?php

namespace App\Services\Battle;

final readonly class DirectAttackResolution
{
    public function __construct(
        public int $sourceActionId,
        public BattleActor $attacker,
        public BattleActor $target,
        public HitResult $hitResult,
        public string $damageCategory,
        public bool $direct,
        public BattleActionType $actionType,
    ) {}

    public static function fromDamageSource(
        int $sourceActionId,
        BattleActor $attacker,
        BattleActor $target,
        ?HitResult $hitResult,
        string $damageCategory,
        bool $direct,
        DamageSourceType $sourceType,
    ): self {
        return new self(
            sourceActionId: $sourceActionId,
            attacker: $attacker,
            target: $target,
            hitResult: $hitResult ?? HitResult::HIT,
            damageCategory: $damageCategory === 'magical' ? 'magical' : 'physical',
            direct: $direct,
            actionType: match ($sourceType) {
                DamageSourceType::JOB_ART => BattleActionType::JOB_ART,
                DamageSourceType::JOB_SKILL, DamageSourceType::OTHER => BattleActionType::CURRENT_JOB_SKILL,
                default => BattleActionType::NORMAL_ATTACK,
            },
        );
    }
}
