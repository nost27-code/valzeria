<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

final class JobArtBattleLogPresenter
{
    /** @var array<string, string>|null */
    private ?array $resourceGainDescriptions = null;

    public function __construct(
        private readonly JobArtV2LoadoutPresenter $loadoutPresenter,
        private readonly JobArtV2CardDescriptionCatalog $cardDescriptionCatalog,
        private readonly JobArtV2LineageGuideCatalog $lineageGuideCatalog,
    ) {}

    public function activationTitle(BattleActor $actor, Skill $skill, string $titleClass): string
    {
        $classes = trim($titleClass.' battle-log-job-art-tooltip');
        $description = $this->description($actor, $skill);

        return '<span class="'.e($classes).'">'
            .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">'
            .'《'.e((string) $skill->name).'》が発動！'
            .'</button>'
            .'<span class="battle-log-job-art-tooltip-panel">'
            .'<span class="battle-log-job-art-tooltip-label">戦技の効果</span>'
            .'<span class="battle-log-job-art-tooltip-description">'.e($description).'</span>'
            .'</span>'
            .'</span>';
    }

    public function resourceChange(ResourceChangeResult $result): ?string
    {
        if (! $result->applied
            || $result->resourceKey === null
            || $result->resourceName === null
            || $result->delta === 0
        ) {
            return null;
        }

        $signed = $result->delta > 0 ? "+{$result->delta}" : (string) $result->delta;
        $description = $this->resourceGainDescription($result->resourceKey);
        if ($description === null) {
            return e("{$result->resourceName} {$signed}（{$result->after}/{$result->cap}）");
        }

        return '<span class="battle-log-job-art-tooltip">'
            .'<button type="button" class="battle-log-job-art-tooltip-trigger" aria-expanded="false">'
            .e($result->resourceName)
            .'</button>'
            .'<span class="battle-log-job-art-tooltip-panel">'
            .'<span class="battle-log-job-art-tooltip-label">'.e($result->resourceName).'の獲得方法</span>'
            .'<span class="battle-log-job-art-tooltip-description">'.e($description).'</span>'
            .'</span>'
            .'</span>'
            .' '.e("{$signed}（{$result->after}/{$result->cap}）");
    }

    private function description(BattleActor $actor, Skill $skill): string
    {
        $display = $this->loadoutPresenter->forArt(
            $actor->currentJobId,
            $skill,
            $actor->jobArts,
        );
        $description = trim((string) ($display['display_description'] ?? ''));

        if ($description === '') {
            $description = trim((string) ($this->cardDescriptionCatalog->defaultDescription($skill) ?? ''));
        }

        if ($description === '') {
            $description = trim((string) ($skill->memo ?: $skill->description));
        }

        return $description !== '' ? $description : '戦況に応じた効果を発動する。';
    }

    private function resourceGainDescription(string $resourceKey): ?string
    {
        if ($this->resourceGainDescriptions !== null) {
            return $this->resourceGainDescriptions[$resourceKey] ?? null;
        }

        $this->resourceGainDescriptions = [];
        foreach ($this->lineageGuideCatalog->all() as $guide) {
            $guideResourceKey = trim((string) ($guide['resource_key'] ?? ''));
            if ($guideResourceKey === '') {
                continue;
            }

            $parts = array_values(array_filter([
                trim((string) ($guide['direct_gain'] ?? '')),
                trim((string) ($guide['common_gain'] ?? '')),
            ]));

            if ($parts !== []) {
                $this->resourceGainDescriptions[$guideResourceKey] = implode('。', $parts).'。';
            }
        }

        return $this->resourceGainDescriptions[$resourceKey] ?? null;
    }
}
