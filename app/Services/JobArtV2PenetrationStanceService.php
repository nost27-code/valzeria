<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

final class JobArtV2PenetrationStanceService
{
    public const EVENT_ACQUIRED = 'acquired';
    public const EVENT_CONSUMED = 'consumed';
    public const EVENT_REFORMED = 'reformed';
    public const EVENT_NO_STANCE = 'no_stance';

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $catalog,
        private readonly JobArtV2PenetrationService $penetrationService,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {}

    public function enabledFor(BattleActor $actor): bool
    {
        return $this->featureGate->usesPenetrationStance($actor);
    }

    public function beginCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $rank = $this->trustedStanceRank($actor, $skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($rank === null || $sourceActionId === null) {
            return;
        }

        $hadStance = $actor->hasPiercingStance();
        if (! $state->snapshotPiercingStance($actor, $sourceActionId, $hadStance)) {
            return;
        }

        if ($rank === 1) {
            $actor->setPiercingStance(true);
            $this->emit($actor, $state, $skill, self::EVENT_ACQUIRED, $sourceActionId, $hadStance);

            return;
        }

        if ($hadStance) {
            $actor->setPiercingStance(false);
            $this->emit($actor, $state, $skill, self::EVENT_CONSUMED, $sourceActionId, true);

            return;
        }

        $this->emit($actor, $state, $skill, self::EVENT_NO_STANCE, $sourceActionId, false);
    }

    public function completeCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        if ($this->trustedStanceRank($actor, $skill) !== 5) {
            return;
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || $state->piercingStanceSnapshot($actor, $sourceActionId) === null) {
            return;
        }
        if (! $state->claimPiercingStanceEvent($actor, self::EVENT_REFORMED, $sourceActionId)) {
            return;
        }

        $hadStance = (bool) $state->piercingStanceSnapshot($actor, $sourceActionId);
        $actor->setPiercingStance(true);
        $state->recordPiercingStanceEvent(
            $actor,
            self::EVENT_REFORMED,
            (int) $skill->id,
            $sourceActionId,
            $hadStance,
        );
        $this->appendLog($state, self::EVENT_REFORMED);
    }

    /** @return array{def: ?int, spr: ?int, penetration_rate: ?float} */
    public function defenseOverrides(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
    ): array {
        $rank = $this->trustedStanceRank($attacker, $skill);
        if (! in_array($rank, [5, 9], true)) {
            return $this->penetrationService->defenseOverrides($attacker, $defender, $skill);
        }

        $sourceActionId = $state->currentSourceActionId();
        $hadStance = $sourceActionId !== null
            ? $state->piercingStanceSnapshot($attacker, $sourceActionId)
            : null;
        if ($hadStance === true) {
            return $this->penetrationService->defenseOverrides($attacker, $defender, $skill);
        }

        return $this->legacyDefenseOverrides($defender, $skill);
    }

    private function trustedStanceRank(BattleActor $actor, Skill $skill): ?int
    {
        if (! $this->enabledFor($actor) || (int) $skill->job_id !== 62 || ! $skill->isJobArt()) {
            return null;
        }

        $resolution = $this->roles()->resolveActor($actor);
        $trusted = $resolution->active
            ? in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null
            : ($actor->jobArtOrigins[(int) $skill->id] ?? null) === 'current';
        if (! $trusted) {
            return null;
        }

        $rank = (int) $skill->learn_rank;
        if (! in_array($rank, [1, 5, 9], true)
            || $this->catalog->artResourceMetadata($skill) === null
        ) {
            return null;
        }

        return $rank;
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }

    /** @return array{def: ?int, spr: ?int, penetration_rate: ?float} */
    private function legacyDefenseOverrides(BattleActor $defender, Skill $skill): array
    {
        if ((int) $skill->def_ignore_percent <= 0) {
            return ['def' => null, 'spr' => null, 'penetration_rate' => null];
        }

        $legacyRate = 1 - ((int) $skill->def_ignore_percent / 100);

        return [
            'def' => (int) floor($defender->def * $legacyRate),
            'spr' => (int) floor($defender->spr * $legacyRate),
            'penetration_rate' => null,
        ];
    }

    private function emit(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        string $event,
        int $sourceActionId,
        bool $hadStance,
    ): void {
        if (! $state->claimPiercingStanceEvent($actor, $event, $sourceActionId)) {
            return;
        }

        $state->recordPiercingStanceEvent(
            $actor,
            $event,
            (int) $skill->id,
            $sourceActionId,
            $hadStance,
        );
        $this->appendLog($state, $event);
    }

    private function appendLog(BattleState $state, string $event): void
    {
        $message = match ($event) {
            self::EVENT_ACQUIRED => '貫通構えを取った',
            self::EVENT_CONSUMED => '貫通構えを消費した',
            self::EVENT_REFORMED => '貫通構えを再形成した',
            self::EVENT_NO_STANCE => '構えなしのため貫通なし',
            default => null,
        };
        if ($message !== null) {
            $state->addLog("<span class=\"text-cyan-700 font-bold\">{$message}</span>");
        }
    }
}
