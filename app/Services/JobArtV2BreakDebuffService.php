<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleStatChangeLogFormatter;
use App\Services\Battle\HitResult;

final class JobArtV2BreakDebuffService
{
    public const EVENT_APPLIED = 'applied';
    public const EVENT_REPLACED = 'replaced';
    public const EVENT_REFRESHED = 'refreshed';
    public const EVENT_KEPT_STRONGER = 'kept_stronger';
    public const EVENT_EXPIRED = 'expired';

    private const JOB_ID = 68;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {}

    public function applyOnHit(
        BattleActor $attacker,
        BattleActor $target,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult,
    ): ?JobArtV2BreakDebuffResult {
        $metadata = $this->metadata($attacker, $skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($metadata === null
            || $hitResult !== HitResult::HIT
            || $sourceActionId === null
            || ! $state->claimBreakDebuffEvent($target, $sourceActionId)
        ) {
            return null;
        }

        $rate = max(0.0, (float) ($metadata['break_rate'] ?? 0.0));
        if ($state->battleType === 'boss') {
            $rate *= 0.5;
        }
        $rounds = max(1, (int) ($metadata['break_rounds'] ?? 1));
        $previous = $target->breakDebuffState();
        $previousRate = $previous?->rate ?? 0.0;
        $previousRounds = $previous?->remainingRounds ?? 0;

        if ($previous === null) {
            $event = self::EVENT_APPLIED;
            $applied = true;
        } elseif ($rate > $previous->rate) {
            $event = self::EVENT_REPLACED;
            $applied = true;
        } elseif (abs($rate - $previous->rate) < 0.000001) {
            $event = self::EVENT_REFRESHED;
            $applied = true;
        } else {
            $event = self::EVENT_KEPT_STRONGER;
            $applied = false;
        }

        if ($applied) {
            $target->replaceBreakDebuffState(new JobArtV2BreakDebuffState(
                rate: $rate,
                remainingRounds: $rounds,
                appliedRound: $state->turnCount,
            ));
        }
        $current = $target->breakDebuffState();
        $result = new JobArtV2BreakDebuffResult(
            applied: $applied,
            event: $event,
            targetActorKey: $state->actorKey($target),
            previousRate: $previousRate,
            rate: $current?->rate ?? $previousRate,
            previousRemainingRounds: $previousRounds,
            remainingRounds: $current?->remainingRounds ?? $previousRounds,
            sourceActionId: $sourceActionId,
        );
        $state->recordBreakDebuffResult($result);

        if ($applied) {
            $state->addLog(BattleStatChangeLogFormatter::fromPercentages(
                $target->name,
                [
                    ['label' => 'def', 'percent' => $rate * 100],
                    ['label' => 'spr', 'percent' => $rate * 100],
                ],
                false,
                $rounds.'ラウンド',
            ));
        }

        return $result;
    }

    /** @return list<JobArtV2BreakDebuffResult> */
    public function endRound(BattleState $state): array
    {
        $results = [];
        foreach ([$state->player, $state->enemy] as $actor) {
            $debuff = $actor->breakDebuffState();
            if ($debuff === null || ! $debuff->advanceAtRoundEnd($state->turnCount) || ! $debuff->isExpired()) {
                continue;
            }

            $result = new JobArtV2BreakDebuffResult(
                applied: true,
                event: self::EVENT_EXPIRED,
                targetActorKey: $state->actorKey($actor),
                previousRate: $debuff->rate,
                rate: 0.0,
                previousRemainingRounds: 1,
                remainingRounds: 0,
                sourceActionId: 'round:'.$state->turnCount.':break:'.$state->actorKey($actor),
            );
            $actor->replaceBreakDebuffState(null);
            $state->recordBreakDebuffResult($result);
            $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の崩しが解けた。</span>');
            $results[] = $result;
        }

        return $results;
    }

    /** @return array<string, int|float|string|bool>|null */
    private function metadata(BattleActor $actor, Skill $skill): ?array
    {
        if (! $this->featureGate->usesResources($actor) || (int) $skill->job_id !== self::JOB_ID) {
            return null;
        }

        $resolution = $this->roles()->resolveActor($actor);
        $trusted = $resolution->active
            ? in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null
            : $actor->currentJobId === self::JOB_ID
                && ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
        if (! $trusted) {
            return null;
        }

        $metadata = $this->prototypeCatalog->artResourceMetadata($skill);

        return isset($metadata['break_rate'], $metadata['break_rounds']) ? $metadata : null;
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }
}
