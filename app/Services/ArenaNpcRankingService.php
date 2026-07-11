<?php

namespace App\Services;

use App\Models\ArenaNpcRanking;
use App\Models\ArenaRanking;
use App\Models\Character;
use App\Models\NpcMaster;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArenaNpcRankingService
{
    public const PLAYER_TOP_PROTECTED_RANK = 10;
    public const NPC_LOWER_ENTRY_FLOOR_RANK = 50;
    private const HIDDEN_TESTER_RANK_BASE = 800000;

    public const EXCLUDED_NPC_IDS = [
        8, 12, 17, 26, 29, 37, 38, 39, 45, 48, 50, 57,
    ];

    public function ensureRankings(): void
    {
        if (! Schema::hasTable('arena_npc_rankings')) {
            return;
        }

        if (ArenaNpcRanking::query()->exists()) {
            $this->compactVisibleRanksIfNeeded();
            return;
        }

        $npcs = $this->eligibleNpcQuery()->get();

        if ($npcs->isEmpty()) {
            return;
        }

        $profiles = ['physical', 'guard', 'speed', 'magical', 'balanced'];

        DB::transaction(function () use ($npcs, $profiles): void {
            $startRank = $this->npcLowerEntryStartRank();

            foreach ($npcs->values() as $index => $npc) {
                ArenaNpcRanking::create([
                    'npc_id' => (int) $npc->npc_id,
                    'rank' => $startRank + $index,
                    'level' => $this->npcRecommendedLevel($npc),
                    'battle_profile' => $profiles[$index % count($profiles)],
                    'is_active' => true,
                ]);
            }
        });

        $this->compactVisibleRanksIfNeeded();
    }

    public function nextRank(): int
    {
        $playerNextRank = (int) (ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->max('rank') ?? 0) + 1;
        $firstNpcRank = Schema::hasTable('arena_npc_rankings')
            ? (int) (ArenaNpcRanking::where('is_active', true)->min('rank') ?? 0)
            : 0;

        if ($firstNpcRank <= 0 || $playerNextRank < $firstNpcRank) {
            return max(1, $playerNextRank);
        }

        return $this->maxCombinedRank() + 1;
    }

    public function ensurePlayerRanking(Character $character): ArenaRanking
    {
        return DB::transaction(function () use ($character): ArenaRanking {
            $character->loadMissing('user');
            if ($character->isAdminTester()) {
                return ArenaRanking::query()->firstOrCreate(
                    ['character_id' => $character->id],
                    [
                        'rank' => $this->nextHiddenTesterRank(),
                        'wins' => 0,
                        'losses' => 0,
                    ]
                );
            }

            $this->compactVisibleRanksIfNeeded();

            $existing = ArenaRanking::where('character_id', $character->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $rank = max(1, (int) (ArenaRanking::max('rank') ?? 0) + 1);
            $this->shiftActiveNpcsAtOrBelowRank($rank);

            return ArenaRanking::create([
                'character_id' => $character->id,
                'rank' => $rank,
                'wins' => 0,
                'losses' => 0,
            ]);
        });
    }

    public function topEntries(int $limit = 3): Collection
    {
        return $this->combinedEntries()
            ->filter(fn (array $entry): bool => $entry['rank'] <= $limit)
            ->sortBy('rank')
            ->values();
    }

    public function targetEntries(ArenaRanking $myRanking, int $range = 3): Collection
    {
        if ((int) $myRanking->rank <= 1) {
            return collect();
        }

        return $this->combinedEntries()
            ->filter(fn (array $entry): bool => $entry['rank'] < (int) $myRanking->rank)
            ->sortByDesc('rank')
            ->take($range)
            ->values();
    }

    public function rankingEntries(int $limit = 100): Collection
    {
        return $this->combinedEntries()
            ->sortBy('rank')
            ->take($limit)
            ->values();
    }

    private function compactVisibleRanksIfNeeded(): void
    {
        if (! Schema::hasTable('arena_npc_rankings')) {
            return;
        }

        $players = ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->orderBy('rank')
            ->orderBy('id')
            ->get(['id', 'rank']);

        $hiddenPlayers = ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->adminTesters())
            ->orderBy('id')
            ->get(['id', 'rank']);

        $activeNpcs = ArenaNpcRanking::query()
            ->where('is_active', true)
            ->orderBy('rank')
            ->orderBy('id')
            ->get(['id', 'rank']);

        $inactiveNpcs = ArenaNpcRanking::query()
            ->where('is_active', false)
            ->orderBy('id')
            ->get(['id', 'rank']);

        $npcStartRank = max($players->count(), self::PLAYER_TOP_PROTECTED_RANK, self::NPC_LOWER_ENTRY_FLOOR_RANK) + 1;
        $visibleMaxRank = $activeNpcs->isEmpty()
            ? $players->count()
            : $npcStartRank + $activeNpcs->count() - 1;

        $needsCompact = $players
            ->values()
            ->contains(fn (ArenaRanking $ranking, int $index): bool => (int) $ranking->rank !== $index + 1);

        if (! $needsCompact) {
            $needsCompact = $activeNpcs
                ->values()
                ->contains(fn (ArenaNpcRanking $ranking, int $index): bool => (int) $ranking->rank !== $npcStartRank + $index);
        }

        if (! $needsCompact && $visibleMaxRank > 0) {
            $needsCompact = $inactiveNpcs
                ->contains(fn (ArenaNpcRanking $ranking): bool => (int) $ranking->rank > 0 && (int) $ranking->rank <= $visibleMaxRank);
        }

        if (! $needsCompact) {
            $needsCompact = $hiddenPlayers
                ->contains(fn (ArenaRanking $ranking): bool => (int) $ranking->rank < self::HIDDEN_TESTER_RANK_BASE);
        }

        if (! $needsCompact) {
            return;
        }

        DB::transaction(function () use ($players, $hiddenPlayers, $activeNpcs, $inactiveNpcs, $npcStartRank): void {
            foreach ($players as $player) {
                ArenaRanking::query()
                    ->whereKey($player->id)
                    ->update(['rank' => -2000000 - (int) $player->id]);
            }

            foreach ($hiddenPlayers as $player) {
                ArenaRanking::query()
                    ->whereKey($player->id)
                    ->update(['rank' => -3000000 - (int) $player->id]);
            }

            foreach ($activeNpcs as $npc) {
                ArenaNpcRanking::query()
                    ->whereKey($npc->id)
                    ->update(['rank' => -1000000 - (int) $npc->id]);
            }

            foreach ($inactiveNpcs as $npc) {
                ArenaNpcRanking::query()
                    ->whereKey($npc->id)
                    ->update(['rank' => 900000 + (int) $npc->id]);
            }

            foreach ($players->values() as $index => $player) {
                ArenaRanking::query()
                    ->whereKey($player->id)
                    ->update(['rank' => $index + 1]);
            }

            foreach ($hiddenPlayers->values() as $index => $player) {
                ArenaRanking::query()
                    ->whereKey($player->id)
                    ->update(['rank' => self::HIDDEN_TESTER_RANK_BASE + $index + 1]);
            }

            foreach ($activeNpcs->values() as $index => $npc) {
                ArenaNpcRanking::query()
                    ->whereKey($npc->id)
                    ->update(['rank' => $npcStartRank + $index]);
            }
        });
    }

    public function shiftCombinedRanksDown(
        int $minRank,
        int $maxRank,
        ?int $exceptCharacterId = null,
        ?int $exceptNpcRankingId = null
    ): void {
        if ($maxRank < $minRank) {
            return;
        }

        ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->whereBetween('rank', [$minRank, $maxRank])
            ->when($exceptCharacterId, fn ($query) => $query->where('character_id', '!=', $exceptCharacterId))
            ->orderByDesc('rank')
            ->lockForUpdate()
            ->get()
            ->each(function (ArenaRanking $ranking): void {
                $ranking->rank = (int) $ranking->rank + 1;
                $ranking->save();
            });

        ArenaNpcRanking::query()
            ->where('is_active', true)
            ->whereBetween('rank', [$minRank, $maxRank])
            ->when($exceptNpcRankingId, fn ($query) => $query->where('id', '!=', $exceptNpcRankingId))
            ->orderByDesc('rank')
            ->lockForUpdate()
            ->get()
            ->each(function (ArenaNpcRanking $ranking): void {
                $ranking->rank = (int) $ranking->rank + 1;
                $ranking->save();
            });
    }

    private function shiftActiveNpcsAtOrBelowRank(int $rank): void
    {
        if (! Schema::hasTable('arena_npc_rankings')) {
            return;
        }

        ArenaNpcRanking::query()
            ->where('is_active', true)
            ->where('rank', '>=', $rank)
            ->orderByDesc('rank')
            ->lockForUpdate()
            ->get()
            ->each(function (ArenaNpcRanking $ranking): void {
                $ranking->rank = (int) $ranking->rank + 1;
                $ranking->save();
            });
    }

    public function maxCombinedRank(): int
    {
        $playerMax = (int) (ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->max('rank') ?? 0);
        $npcMax = Schema::hasTable('arena_npc_rankings')
            ? (int) (ArenaNpcRanking::where('is_active', true)->max('rank') ?? 0)
            : 0;

        return max($playerMax, $npcMax);
    }

    public function npcLowerEntryStartRank(): int
    {
        $playerMax = (int) (ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->max('rank') ?? 0);

        return max($playerMax, self::PLAYER_TOP_PROTECTED_RANK, self::NPC_LOWER_ENTRY_FLOOR_RANK) + 1;
    }

    private function nextHiddenTesterRank(): int
    {
        $currentMax = (int) (ArenaRanking::query()
            ->where('rank', '>=', self::HIDDEN_TESTER_RANK_BASE)
            ->max('rank') ?? self::HIDDEN_TESTER_RANK_BASE);

        return $currentMax + 1;
    }

    private function combinedEntries(): Collection
    {
        $this->ensureRankings();

        $players = ArenaRanking::with(['character.jobClass'])
            ->whereHas('character', fn ($query) => $query->visibleToPublic())
            ->get()
            ->map(function (ArenaRanking $ranking): array {
                $character = $ranking->character;
                $profileService = app(CharacterProfileService::class);
                $profileFrameTheme = $character
                    ? $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme)
                    : 'standard';

                return [
                    'type' => 'player',
                    'id' => (int) $ranking->id,
                    'rank' => (int) $ranking->rank,
                    'name' => $character?->name ?? '不明',
                    'level' => $character ? (int) $character->level : null,
                    'job' => $character?->jobClass?->name ?? '冒険者',
                    'power' => $this->playerPower($character),
                    'character' => $character,
                    'ranking' => $ranking,
                    'image_path' => CharacterIconCatalog::normalize($character?->icon_path),
                    'frame_image_path' => $profileService->frameImageForTheme($profileFrameTheme),
                ];
            });

        $npcs = Schema::hasTable('arena_npc_rankings')
            ? ArenaNpcRanking::with('npc')
                ->where('is_active', true)
                ->get()
                ->map(function (ArenaNpcRanking $ranking): array {
                    $npc = $ranking->npc;

                    return [
                        'type' => 'npc',
                        'id' => (int) $ranking->id,
                        'rank' => (int) $ranking->rank,
                        'name' => $this->npcDisplayName($npc),
                        'full_name' => $npc?->npc_name ?? ('放浪冒険者 #' . $ranking->npc_id),
                        'level' => (int) $ranking->level,
                        'job' => $this->npcJobLabel($npc),
                        'power' => $this->npcPower($ranking),
                        'npc' => $npc,
                        'ranking' => $ranking,
                        'image_path' => $npc?->image_path,
                    ];
                })
            : collect();

        return $players->concat($npcs);
    }

    private function playerPower(?Character $character): ?int
    {
        if (! $character) {
            return null;
        }

        $stats = app(CharacterStatusService::class)->getFinalStats($character);

        return app(CharacterPowerService::class)->fromFinalStats($stats);
    }

    public function npcPowerForDisplay(ArenaNpcRanking $ranking): int
    {
        return $this->npcPower($ranking);
    }

    private function npcPower(ArenaNpcRanking $ranking): int
    {
        $level = max(1, (int) $ranking->level);
        $range = app(CharacterPowerService::class)->recommendedRangeForLevels($level, $level);

        return (int) ($range['min'] ?? 1);
    }

    public function eligibleNpcQuery()
    {
        return NpcMaster::where('is_active', true)
            ->whereNotIn('npc_rank', ['legend', 'hero'])
            ->whereNotIn('npc_id', self::EXCLUDED_NPC_IDS)
            ->orderByRaw("CASE npc_rank WHEN 'skilled' THEN 1 WHEN 'common' THEN 2 ELSE 9 END")
            ->orderBy('sort_order')
            ->orderBy('npc_id');
    }

    public function npcDisplayName(?NpcMaster $npc): string
    {
        if (! $npc) {
            return '放浪冒険者';
        }

        $name = (string) $npc->npc_name;

        if (str_contains($name, 'の')) {
            return trim((string) str($name)->afterLast('の')) ?: $name;
        }

        $prefixes = [
            '王都騎士団長',
            '泣き虫魔法使い',
            '駆け出し剣士',
            '無口な傭兵',
            '海賊船長',
            '精霊騎士',
            '大賢者',
            '竜騎士',
            '暗黒騎士',
            '黄金商人',
            '機工王',
            '賢商王',
            '狙撃手',
            '剣聖',
            '武神',
            '幻影王',
            '聖女',
            '砂王',
            '司祭',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $displayName = trim(mb_substr($name, mb_strlen($prefix)));

                return $displayName !== '' ? $displayName : $name;
            }
        }

        return $name;
    }

    public function npcRecommendedLevel(?NpcMaster $npc): int
    {
        if (! $npc) {
            return 1;
        }

        $npcId = (int) $npc->npc_id;

        return match ((string) $npc->npc_rank) {
            'hero' => max(42, min(50, 50 - ($npcId - 41))),
            'skilled' => max(32, min(40, 32 + (40 - $npcId))),
            default => max(18, min(28, 18 + (20 - $npcId))),
        };
    }

    public function npcLevelCap(?NpcMaster $npc): int
    {
        return 50;
    }

    public function recordNpcWin(ArenaNpcRanking $ranking): void
    {
        $ranking->loadMissing('npc');
        $ranking->wins++;

        $levelCap = $this->npcLevelCap($ranking->npc);
        if ((int) $ranking->level < $levelCap) {
            $ranking->level = (int) $ranking->level + 1;
        }
    }

    public function npcJobLabel(?NpcMaster $npc): string
    {
        if (! $npc) {
            return '？？？';
        }

        if ((string) $npc->npc_rank === 'hero') {
            return '？？？';
        }

        $name = (string) $npc->npc_name;

        $keywordJobs = [
            '双剣' => '盗賊',
            '魔剣' => '魔法剣士',
            '剣士' => '剣士',
            '剣' => '剣士',
            '豪腕' => '戦士',
            '鉄槌' => '戦士',
            '盾' => '守護騎士',
            '片目' => '傭兵',
            '傭兵' => '傭兵',
            '黒猫' => '魔盗士',
            '狙撃手' => '狙撃手',
            '銀弓' => '弓使い',
            '弓' => '弓使い',
            '忍び' => '忍者',
            '紅蓮' => '魔法使い',
            '氷華' => '魔法使い',
            '魔法使い' => '魔法使い',
            '司祭' => '司祭',
            '僧侶' => '僧侶',
            '白衣' => '薬師',
            '狂牙' => '狂戦士',
            '小銭' => '盗賊',
            '森笛' => '吟遊詩人',
            '森歩き' => '弓使い',
            '疾風' => '盗賊',
            '駆け出し' => '剣士',
            '墓守り' => '戦士',
            '砂読み' => '軍師',
            '空渡り' => '盗賊',
            '火山見張り' => '戦士',
            '洞窟好き' => '戦士',
            '草原帰り' => '格闘家',
            '港風' => '商人',
            '潮騒' => '吟遊詩人',
            '雪見' => '僧侶',
            '放浪' => '剣士',
        ];

        foreach ($keywordJobs as $keyword => $job) {
            if (str_contains($name, $keyword)) {
                return $job;
            }
        }

        return (string) $npc->npc_rank === 'skilled' ? '傭兵' : '冒険者';
    }
}
