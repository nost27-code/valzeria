<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

/** ローカル試遊で、同一国家の直近3時間ユニーク出撃者だけを期限付きで共有する。 */
final readonly class NationRaidTrialCoordinationService
{
    private const CACHE_SCHEMA = 'nation-raid-trial-coordination-v1';

    public function __construct(
        private CacheFactory $cache,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(Character $character): array
    {
        return $this->resolve($character, false);
    }

    /** 成功した試遊出撃だけを国家連携へ登録する。 @return array<string, mixed> */
    public function register(Character $character): array
    {
        return $this->resolve($character, true);
    }

    /** @return array<string, mixed> */
    private function resolve(Character $character, bool $register): array
    {
        $membership = NationMembership::query()
            ->with('nation')
            ->where('character_id', $character->getKey())
            ->first();
        $nation = $membership?->nation;
        if (! $nation instanceof Nation || $nation->status !== Nation::STATUS_ACTIVE) {
            return $this->unaffiliatedState();
        }

        $store = $this->cache->store((string) config('nation_raid.trial_coordination_cache_store', 'file'));
        $cacheKey = $this->cacheKey((int) $nation->id);
        $now = now()->getTimestamp();

        try {
            return $store->lock($cacheKey.':lock', 5)->block(2, function () use (
                $store,
                $cacheKey,
                $nation,
                $character,
                $register,
                $now,
            ): array {
                $stored = $store->get($cacheKey, []);
                $participants = is_array($stored) && ($stored['schema_version'] ?? null) === self::CACHE_SCHEMA
                    ? ($stored['participants'] ?? [])
                    : [];
                $participants = is_array($participants) ? $participants : [];
                $participants = $this->activeParticipants((int) $nation->id, $participants, $now);

                $characterKey = (string) $character->getKey();
                $newlyRegistered = false;
                if ($register && ! array_key_exists($characterKey, $participants)) {
                    $participants[$characterKey] = $now;
                    $newlyRegistered = true;
                }

                uasort($participants, static fn (int $left, int $right): int => $left <=> $right);
                if ($participants === []) {
                    $store->forget($cacheKey);
                } else {
                    $latestParticipation = max($participants);
                    $ttlSeconds = max(1, $latestParticipation + (NationRaidRules::COORDINATION_WINDOW_MINUTES * 60) - $now);
                    $store->put($cacheKey, [
                        'schema_version' => self::CACHE_SCHEMA,
                        'nation_id' => (int) $nation->id,
                        'participants' => $participants,
                    ], $ttlSeconds);
                }

                $participantIds = array_map('intval', array_keys($participants));
                $uniqueCount = count($participantIds);

                return [
                    'eligible' => true,
                    'nation_id' => (int) $nation->id,
                    'nation_name' => (string) $nation->display_name,
                    'window_minutes' => NationRaidRules::COORDINATION_WINDOW_MINUTES,
                    'unique_count' => $uniqueCount,
                    'bonus_rate' => NationRaidRules::coordinationDamageRate($uniqueCount),
                    'participant_ids' => $participantIds,
                    'participated_at' => array_map('intval', array_values($participants)),
                    'newly_registered' => $newlyRegistered,
                ];
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('国家連携の確認が混み合っています。少し待ってからもう一度お試しください。', previous: $e);
        }
    }

    /** @param array<int|string, mixed> $participants @return array<string, int> */
    private function activeParticipants(int $nationId, array $participants, int $now): array
    {
        $threshold = $now - (NationRaidRules::COORDINATION_WINDOW_MINUTES * 60);
        $candidates = [];
        foreach ($participants as $characterId => $participatedAt) {
            $id = filter_var($characterId, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || ! is_int($participatedAt) || $participatedAt <= $threshold) {
                continue;
            }
            $candidates[(string) $id] = $participatedAt;
        }
        if ($candidates === []) {
            return [];
        }

        $currentMemberIds = NationMembership::query()
            ->where('nation_id', $nationId)
            ->whereIn('character_id', array_map('intval', array_keys($candidates)))
            ->pluck('character_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_intersect_key($candidates, array_fill_keys($currentMemberIds, true));
    }

    /** @return array<string, mixed> */
    private function unaffiliatedState(): array
    {
        return [
            'eligible' => false,
            'nation_id' => null,
            'nation_name' => '無所属',
            'window_minutes' => NationRaidRules::COORDINATION_WINDOW_MINUTES,
            'unique_count' => 0,
            'bonus_rate' => 0.0,
            'participant_ids' => [],
            'participated_at' => [],
            'newly_registered' => false,
        ];
    }

    private function cacheKey(int $nationId): string
    {
        return "nation-raid:trial:coordination:v1:nation:{$nationId}";
    }
}
