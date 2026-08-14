<?php

namespace App\Services;

use App\Models\Skill;

/**
 * C-design B1 effects which are unlocked only for a formal main/secondary
 * lineage. Tech cards must never receive this metadata.
 *
 * Power and hit count stay in the master. This catalog only owns the extra
 * lineage grammar which the 165-art audit found to be missing.
 */
final class JobArtV2CDesignEffectCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const ARTS = [
        // The L-column workbook is the player-facing and runtime authority.
        // Earlier B1 prototype bonuses which are absent from that wording are
        // intentionally not retained here. Shared effects granted by another
        // card (for example counter_focus) remain explicit below.
        '50:5:聖剣烈破' => [
            'accepts_prepared_effects' => ['counter_focus'],
            'effect_texts' => ['counter_focusの共通倍率対象'],
        ],
        '51:1:黒炎纏い' => [],
        // 黒炎斬のHP支払いと倍率は、実支払額を奥義履歴へ残すため
        // ProgressionServiceの単一経路で処理する。
        '51:5:黒炎斬' => [],
    ];

    /** @return array<string, mixed>|null */
    public function forArt(Skill $skill): ?array
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        return self::ARTS[JobArtV2DeckRoleResolution::artKey($skill)] ?? null;
    }

    /** @return list<string> */
    public function effectTexts(Skill $skill): array
    {
        return array_values(array_map('strval', $this->forArt($skill)['effect_texts'] ?? []));
    }
}
