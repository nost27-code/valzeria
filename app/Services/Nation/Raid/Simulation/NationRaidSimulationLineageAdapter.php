<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use InvalidArgumentException;

/**
 * 現行戦技catalogの系譜keyを、Phase 1 raid rulesのkeyへ隔離して変換する。
 *
 * guard/guardian と eclipse/dark は同じ概念の命名差であり、通常戦技側の
 * 正本keyは変更しない。Phase 2成果物には両方を保存して追跡可能にする。
 */
final class NationRaidSimulationLineageAdapter
{
    /** @var array<string, string> */
    private const CANONICAL_TO_RAID = [
        'field' => 'field',
        'counter' => 'counter',
        'eclipse' => 'dark',
        'pierce' => 'pierce',
        'hunt' => 'hunt',
        'aim' => 'aim',
        'guard' => 'guardian',
        'transmute' => 'transmute',
        'break' => 'break',
        'command' => 'command',
    ];

    public function toRaid(string $canonicalLineage): string
    {
        $canonicalLineage = trim($canonicalLineage);
        if (! isset(self::CANONICAL_TO_RAID[$canonicalLineage])) {
            throw new InvalidArgumentException("Unknown canonical Job Art lineage: {$canonicalLineage}");
        }

        return self::CANONICAL_TO_RAID[$canonicalLineage];
    }

    /** @return array<string, string> */
    public function mappings(): array
    {
        return self::CANONICAL_TO_RAID;
    }

    public function contractHash(): string
    {
        return hash('sha256', NationRaidJson::encode([
            'version' => 'nation-raid-lineage-adapter-v1',
            'mappings' => self::CANONICAL_TO_RAID,
        ], JSON_UNESCAPED_UNICODE));
    }
}
