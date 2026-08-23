<?php

namespace App\Services;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

final class JobArtV2SlotConditionCatalog
{
    public const ALWAYS = 'always';

    /** @var array<string, string> */
    private const LABELS = [
        self::ALWAYS => '条件なし',
        'self_hp_le_50' => 'HP50%以下',
        'target_hp_le_50' => '敵HP50%以下',
        'target_hp_le_30' => '敵HP30%以下',
        'main_resource_lt_4' => 'リソース4未満',
        'main_resource_ge_4' => 'リソース4以上',
        'target_def_gt_spr' => '敵防御＞精神',
        'target_spr_gt_def' => '敵精神＞防御',
        'field_present' => '場あり',
        'opponent_ultimate_preparing' => '相手が奥義予告中',
    ];

    public function __construct(
        private readonly JobArtV2ResourceCatalog $resources,
        private readonly ?JobArtV2UltimateCounterplayService $ultimateCounterplay = null,
    ) {}

    /** @return array<string, string> */
    public function labels(): array
    {
        return self::LABELS;
    }

    public function normalize(?string $condition): string
    {
        $condition = trim((string) $condition);

        return array_key_exists($condition, self::LABELS) ? $condition : self::ALWAYS;
    }

    public function isAllowed(string $condition): bool
    {
        return array_key_exists($condition, self::LABELS);
    }

    public function matches(string $condition, BattleActor $actor, BattleState $state): bool
    {
        $condition = $this->normalize($condition);
        if ($condition === self::ALWAYS) {
            return true;
        }

        $target = $actor === $state->player ? $state->enemy : $state->player;
        $resource = $this->resources->forActor($actor);
        $points = $resource !== null ? $actor->getResource((string) $resource['resource_key']) : null;

        return match ($condition) {
            'self_hp_le_50' => $actor->maxHp > 0 && ($actor->hp * 100) <= ($actor->maxHp * 50),
            'target_hp_le_50' => $target->maxHp > 0 && ($target->hp * 100) <= ($target->maxHp * 50),
            'target_hp_le_30' => $target->maxHp > 0 && ($target->hp * 100) <= ($target->maxHp * 30),
            'main_resource_lt_4' => $points !== null && $points < 4,
            'main_resource_ge_4' => $points !== null && $points >= 4,
            'target_def_gt_spr' => $target->effectiveDef() > $target->effectiveSpr(),
            'target_spr_gt_def' => $target->effectiveSpr() > $target->effectiveDef(),
            'field_present' => $state->primaryField() !== null,
            'opponent_ultimate_preparing' => ($this->ultimateCounterplay
                ?? app(JobArtV2UltimateCounterplayService::class))
                ->opponentIsPreparing($actor, $state),
            default => false,
        };
    }
}
