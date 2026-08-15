<?php

namespace App\Services;

use App\Models\Skill;

/**
 * 凍結済み資料とPR26の94職ID裁定を正本として扱う。
 * 職名から系譜を推測しない。
 */
final class JobArtLineageCatalog
{
    private const LINEAGE_NAMES = [
        'field' => '場術',
        'counter' => '反撃',
        'eclipse' => '冥蝕',
        'pierce' => '貫通',
        'hunt' => '封狩',
        'aim' => '照準',
        'guard' => '守護',
        'transmute' => '変成',
        'break' => '崩し',
        'command' => '指揮',
    ];

    private const LINEAGE_ICON_PATHS = [
        'counter' => 'images/icon/icon_281.webp',
        'eclipse' => 'images/icon/icon_282.webp',
        'pierce' => 'images/icon/icon_283.webp',
        'hunt' => 'images/icon/icon_284.webp',
        'aim' => 'images/icon/icon_285.webp',
        'guard' => 'images/icon/icon_286.webp',
        'transmute' => 'images/icon/icon_287.webp',
        'break' => 'images/icon/icon_288.webp',
        'command' => 'images/icon/icon_289.webp',
        'field' => 'images/icon/icon_290.webp',
    ];

    private const FIELD_SPEC = 'valzeria_job_art_field_v1_3_1.md';
    private const FOUR_LINEAGE_SPEC = 'valzeria_job_art_4lineage_v1_1.md';
    private const PR26_ASSIGNMENT = 'human-ruling:PR26-94-job-lineage-assignment';

    /** @var array<int, string> */
    private const JOB_LINEAGES = [
        1 => 'counter', 2 => 'pierce', 3 => 'hunt', 4 => 'aim',
        5 => 'break', 6 => 'field', 7 => 'guard', 8 => 'transmute',
        9 => 'eclipse', 10 => 'guard', 11 => 'counter', 12 => 'command',
        13 => 'counter', 14 => 'eclipse', 15 => 'guard', 16 => 'pierce',
        17 => 'hunt', 18 => 'aim', 19 => 'eclipse', 20 => 'transmute',
        21 => 'break', 22 => 'aim', 23 => 'field', 24 => 'field',
        25 => 'transmute', 26 => 'transmute',

        // 上級18職（PR26正式裁定）。
        27 => 'command', 28 => 'counter', 29 => 'field', 30 => 'eclipse',
        31 => 'transmute', 32 => 'pierce', 33 => 'break', 34 => 'hunt',
        35 => 'aim', 36 => 'guard', 37 => 'hunt', 38 => 'transmute',
        44 => 'guard', 45 => 'pierce', 46 => 'field', 47 => 'transmute',
        48 => 'command', 49 => 'transmute',

        // 超級10職（PR26正式裁定）。
        50 => 'counter', 51 => 'eclipse', 52 => 'pierce', 53 => 'field',
        54 => 'hunt', 55 => 'aim', 56 => 'guard', 57 => 'transmute',
        58 => 'break', 59 => 'command',

        // 冠位・英雄・伝説・神話。ID割当のみ。PR26では後3階層の効果を有効化しない。
        60 => 'counter', 61 => 'eclipse', 62 => 'pierce', 63 => 'field',
        64 => 'hunt', 65 => 'aim', 66 => 'guard', 67 => 'transmute',
        68 => 'break', 69 => 'command',
        70 => 'counter', 71 => 'eclipse', 72 => 'field', 73 => 'pierce',
        74 => 'aim', 75 => 'command', 76 => 'hunt', 77 => 'transmute',
        78 => 'break', 79 => 'guard',
        80 => 'command', 81 => 'aim', 82 => 'guard', 83 => 'hunt',
        84 => 'field', 85 => 'field', 86 => 'eclipse', 87 => 'command',
        88 => 'pierce', 89 => 'hunt', 90 => 'break', 91 => 'transmute',
        92 => 'guard', 93 => 'counter', 94 => 'aim', 95 => 'counter',
        96 => 'eclipse', 97 => 'transmute', 98 => 'pierce', 99 => 'break',
    ];

    /** @return array{lineage_key: string, lineage_name: string, source: string}|null */
    public function forJob(?int $jobId): ?array
    {
        $key = $jobId !== null ? (self::JOB_LINEAGES[$jobId] ?? null) : null;
        if ($key === null) {
            return null;
        }

        return [
            'lineage_key' => $key,
            'lineage_name' => self::LINEAGE_NAMES[$key],
            'source' => $this->sourceFor($jobId, $key),
        ];
    }

    /** @return array{lineage_key: string, lineage_name: string, source: string}|null */
    public function forArt(Skill $skill): ?array
    {
        return $this->forJob((int) $skill->job_id);
    }

    public function nameForKey(?string $lineageKey): ?string
    {
        return is_string($lineageKey) ? (self::LINEAGE_NAMES[$lineageKey] ?? null) : null;
    }

    public function iconPathForKey(?string $lineageKey): ?string
    {
        return is_string($lineageKey) ? (self::LINEAGE_ICON_PATHS[$lineageKey] ?? null) : null;
    }

    /** @return array<int, array{lineage_key: string, lineage_name: string, source: string}> */
    public function mappedJobs(): array
    {
        $mapped = [];
        foreach (array_keys(self::JOB_LINEAGES) as $jobId) {
            $metadata = $this->forJob($jobId);
            if ($metadata !== null) {
                $mapped[$jobId] = $metadata;
            }
        }

        ksort($mapped);

        return $mapped;
    }

    private function sourceFor(int $jobId, string $lineageKey): string
    {
        if ($lineageKey === 'field') {
            return self::FIELD_SPEC;
        }
        if (in_array($lineageKey, ['counter', 'pierce', 'aim', 'guard'], true)) {
            return self::FOUR_LINEAGE_SPEC;
        }

        return self::PR26_ASSIGNMENT;
    }
}
