<?php

namespace App\Services\Nation\Raid;

use App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter;

/** DBを持たない選出契約。本開催とsimulationは同じイベント固定順を使う。 */
final readonly class NationRaidLineageVoteResolver
{
    public function __construct(private NationRaidSimulationLineageAdapter $lineages) {}

    public function resolve(array $votes, string $eventSeed): array
    {
        throw_unless((bool) preg_match('/\A[a-f0-9]{64}\z/', $eventSeed), \InvalidArgumentException::class, 'Invalid lineage event seed.');
        $keys = array_values($this->lineages->mappings());
        sort($keys, SORT_STRING);
        $counts = array_fill_keys($keys, 0);
        foreach ($votes as $key => $count) {
            throw_unless(array_key_exists($key, $counts) && is_int($count) && $count >= 0, \InvalidArgumentException::class, 'Invalid lineage vote count.');
            $counts[$key] = $count;
        }
        $order = $keys;
        usort($order, fn ($a, $b) => strcmp(hash_hmac('sha256', $a, $eventSeed), hash_hmac('sha256', $b, $eventSeed)) ?: strcmp($a, $b));
        $max = max($counts);
        return ['counts' => $counts, 'order' => $order,
            'selected' => $max > 0 ? collect($order)->first(fn ($key) => $counts[$key] === $max) : null];
    }

    public function contract(): array
    {
        return ['version' => 'nation-raid-lineage-voting-v1',
            'source' => 'previous_raid_day_first_resolved_set_per_account',
            'duplicate_slots' => 'one_vote_per_distinct_lineage', 'empty_votes' => 'no_counter_lineage',
            'tie' => 'ascending_hmac_sha256(lineage,event_seed),then_lineage_key;fixed_for_event',
            'lineage_adapter_hash' => $this->lineages->contractHash()];
    }

    public function contractHash(): string
    {
        return hash('sha256', NationRaidJson::encode($this->contract(), JSON_UNESCAPED_UNICODE));
    }
}
