<?php

namespace App\Services;

final class JobArtV2FieldModifierResolver
{
    private const AXIS_LIMITS = [
        'activation_rate_delta' => 5.0,
        'accuracy_delta' => 8.0,
        'resource_gain_delta' => 1.0,
        'damage_multiplier' => 0.15,
        'heal_multiplier' => 0.15,
    ];

    public function __construct(
        private readonly JobArtV2FieldCatalog $catalog,
    ) {
    }

    public function resolve(
        FieldSnapshot $snapshot,
        string $actorKey,
        string $axis,
        string $scope = 'all',
    ): float {
        $values = [];
        foreach ([$snapshot->primary, $snapshot->overlay, ...$snapshot->echoes] as $field) {
            if ($field === null) {
                continue;
            }

            foreach (($this->catalog->field($field->key)['effects'] ?? []) as $effect) {
                if ((string) $effect['axis'] !== $axis
                    || !$this->scopeMatches((string) $effect['scope'], $scope)
                    || !$this->targetMatches((string) $effect['target'], $field->ownerActorKey, $actorKey)
                ) {
                    continue;
                }
                $values[] = (float) $effect['value'];
            }
        }

        return $this->resolveValues($values, $axis);
    }

    /**
     * @param array<int, int|float> $values
     */
    public function resolveValues(array $values, string $axis): float
    {
        $positive = max([0.0, ...array_filter($values, static fn (int|float $value): bool => $value > 0)]);
        $negative = min([0.0, ...array_filter($values, static fn (int|float $value): bool => $value < 0)]);
        $limit = self::AXIS_LIMITS[$axis] ?? INF;

        return max(-$limit, min($limit, $positive + $negative));
    }

    private function scopeMatches(string $effectScope, string $requestedScope): bool
    {
        return $effectScope === 'all' || $effectScope === $requestedScope;
    }

    private function targetMatches(string $target, string $ownerKey, string $actorKey): bool
    {
        return $target === 'owner' ? $ownerKey === $actorKey : $ownerKey !== $actorKey;
    }
}
