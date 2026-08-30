<?php

namespace App\Services\Battle;

use App\Services\JobArtV2DefenseService;

class DamageApplicationService
{
    public function __construct(
        private readonly ?JobArtV2DefenseService $defenseService = null,
    ) {}

    public function apply(DamageApplicationRequest $request): DamageApplicationResult
    {
        $resolvedDamage = $request->resolvedDamage;
        $defenseService = $this->defenseService;
        if ($defenseService === null
            && $request->battleState !== null
            && $request->directAttackResolution !== null
        ) {
            // Keep the constructor backward-compatible for the existing small
            // unit tests while resolving the shared v2 pipeline in Laravel.
            $defenseService = app(JobArtV2DefenseService::class);
        }
        if ($defenseService !== null
            && $request->battleState !== null
            && $request->directAttackResolution !== null
        ) {
            $resolvedDamage = $defenseService->resolveDamage(
                $request->battleState,
                $request->directAttackResolution,
                $resolvedDamage,
            );
        }

        $hpBefore = $request->targetActor->hp;
        $gutsReadyBefore = $request->targetActor->gutsReady;
        $request->targetActor->takeDamage($resolvedDamage);
        $hpAfter = $request->targetActor->hp;

        $result = new DamageApplicationResult(
            requestedDamage: $resolvedDamage,
            hpBefore: $hpBefore,
            hpAfter: $hpAfter,
            actualHpLoss: max(0, $hpBefore - $hpAfter),
            overkillDamage: max(0, $resolvedDamage - $hpBefore),
            wasLethal: $request->targetActor->isDead(),
            sourceType: $request->sourceType,
            sourceId: $request->sourceId,
            hitResult: $request->hitResult,
            hitIndex: $request->hitIndex,
            hitCount: $request->hitCount,
        );

        if ($defenseService !== null
            && $request->battleState !== null
            && $request->directAttackResolution !== null
        ) {
            $defenseService->completeDamageApplication(
                $request->battleState,
                $request->directAttackResolution,
                $result,
                $gutsReadyBefore
                    && ! $request->targetActor->gutsReady
                    && $request->targetActor->gutsJustTriggered,
            );
        }

        return $result;
    }
}
