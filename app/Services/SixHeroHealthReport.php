<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final readonly class SixHeroHealthReport
{
    /**
     * @param  array<int, SixHeroHealthCheckItem>  $items
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public array $items,
    ) {}

    /** @return array{pass:int,warning:int,fail:int} */
    public function statusCounts(): array
    {
        $counts = [
            SixHeroHealthCheckItem::STATUS_PASS => 0,
            SixHeroHealthCheckItem::STATUS_WARNING => 0,
            SixHeroHealthCheckItem::STATUS_FAIL => 0,
        ];

        foreach ($this->items as $item) {
            $counts[$item->status]++;
        }

        return $counts;
    }

    public function overallStatus(): string
    {
        $counts = $this->statusCounts();
        if ($counts[SixHeroHealthCheckItem::STATUS_FAIL] > 0) {
            return SixHeroHealthCheckItem::STATUS_FAIL;
        }

        if ($counts[SixHeroHealthCheckItem::STATUS_WARNING] > 0) {
            return SixHeroHealthCheckItem::STATUS_WARNING;
        }

        return SixHeroHealthCheckItem::STATUS_PASS;
    }

    public function hasFailures(): bool
    {
        return $this->statusCounts()[SixHeroHealthCheckItem::STATUS_FAIL] > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->statusCounts()[SixHeroHealthCheckItem::STATUS_WARNING] > 0;
    }

    public function item(string $key): ?SixHeroHealthCheckItem
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     checked_at:string,
     *     overall_status:string,
     *     status_counts:array{pass:int,warning:int,fail:int},
     *     items:array<int, array{key:string,label:string,status:string,message:string,metadata:array<string, bool|float|int|string|null>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'checked_at' => $this->checkedAt->toIso8601String(),
            'overall_status' => $this->overallStatus(),
            'status_counts' => $this->statusCounts(),
            'items' => array_map(
                static fn (SixHeroHealthCheckItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
