<?php

namespace App\Services\Nation;

use App\Models\Nation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class NationShowcaseService
{
    public const SHOWCASE_LIMIT = 3;

    private const ROTATION_EPOCH = '2026-01-01';

    /** @return array{nation_ids: list<int>, total: int} */
    public function dailySelection(?CarbonInterface $day = null): array
    {
        $nationIds = Nation::active()
            ->publiclyVisible()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'nation_ids' => $this->rotateForDay($nationIds, $day ?? now()),
            'total' => count($nationIds),
        ];
    }

    /**
     * The selection stays stable during a day while the active nation set is unchanged.
     *
     * @param  list<int>  $nationIds
     * @return list<int>
     */
    public function rotateForDay(
        array $nationIds,
        CarbonInterface $day,
        int $limit = self::SHOWCASE_LIMIT,
    ): array {
        $nationIds = array_values(array_unique(array_map('intval', $nationIds)));
        sort($nationIds, SORT_NUMERIC);

        $nationCount = count($nationIds);
        if ($nationCount === 0 || $limit < 1) {
            return [];
        }

        $rotationDay = CarbonImmutable::instance($day)->startOfDay();
        $epoch = CarbonImmutable::parse(self::ROTATION_EPOCH, $rotationDay->getTimezone())->startOfDay();
        $elapsedDays = (int) $epoch->diffInDays($rotationDay, false);
        $offset = (($elapsedDays % $nationCount) + $nationCount) % $nationCount;
        $selectionSize = min($limit, $nationCount);
        $selection = [];

        for ($index = 0; $index < $selectionSize; $index++) {
            $selection[] = $nationIds[($offset + $index) % $nationCount];
        }

        return $selection;
    }
}
