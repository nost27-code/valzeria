<?php

namespace App\Services\Admin;

use App\Models\Character;
use App\Models\JobClass;
use App\Models\Skill;
use App\Services\JobArtService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class JobArtAnalyticsService
{
    private const TESTER_EMAIL_PATTERN = 'tester_%@valzeria.local';

    private const CONTEXT_LABELS = [
        'normal' => '通常戦',
        'boss' => 'ボス戦',
        'pvp' => 'PvP',
    ];

    private const ACTIVITY_WINDOWS = ['7', '30', '90', 'all'];

    private const LEVEL_BANDS = [
        'all' => null,
        '1-49' => [1, 49],
        '50-99' => [50, 99],
        '100-149' => [100, 149],
        '150-199' => [150, 199],
        '200-255' => [200, 255],
    ];

    private const ART_SORTS = ['popular', 'low', 'name'];

    public function __construct(private readonly JobArtService $jobArtService) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function analyze(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $tablesReady = $this->tablesReady();

        if (in_array(false, $tablesReady, true)) {
            return $this->emptyAnalysis($filters, $tablesReady);
        }

        [$characters, $arts, $playerRows] = $this->snapshot($filters);
        $configuredPlayerRows = array_values(array_filter(
            $playerRows,
            fn (array $player): bool => $player['slot_count'] > 0,
        ));
        $configuredPlayers = count($configuredPlayerRows);
        $eligibleCounts = array_fill_keys($arts->pluck('id')->map(fn ($id): int => (int) $id)->all(), 0);
        $selected = [];
        $loadouts = [];
        $pairs = [];
        $invalidSlotCount = 0;

        foreach ($characters as $character) {
            $histories = $character->jobHistories->keyBy('job_class_id');
            foreach ($arts as $art) {
                if ($this->isAvailableToCharacter($character, $art, $histories)) {
                    $eligibleCounts[(int) $art->id]++;
                }
            }
        }

        foreach ($playerRows as $player) {
            $invalidSlotCount += collect($player['slots'])->where('is_active', false)->count();
        }

        foreach ($configuredPlayerRows as $player) {
            $validSkillIds = [];

            foreach ($player['slots'] as $slot) {
                if (! $slot['is_active'] || $slot['skill_id'] === null || ! $arts->has($slot['skill_id'])) {
                    continue;
                }

                $skillId = (int) $slot['skill_id'];
                $validSkillIds[] = $skillId;
                $selected[$skillId] ??= [
                    'player_count' => 0,
                    'slot_counts' => array_fill(1, JobArtService::V2_MAX_SLOTS, 0),
                    'latest_set_at' => null,
                ];
                $selected[$skillId]['player_count']++;
                $selected[$skillId]['slot_counts'][(int) $slot['slot_no']]++;
                $selected[$skillId]['latest_set_at'] = $this->laterTimestamp(
                    $selected[$skillId]['latest_set_at'],
                    $slot['updated_at'],
                );
            }

            $activeSlots = collect($player['slots'])->where('is_active', true)->values()->all();
            if ($activeSlots !== []) {
                $signature = collect($activeSlots)
                    ->map(fn (array $slot): string => $slot['slot_no'].':'.($slot['skill_id'] ?? 'missing'))
                    ->implode('|');
                $loadouts[$signature] ??= [
                    'signature' => $signature,
                    'count' => 0,
                    'slots' => $activeSlots,
                    'jobs' => [],
                    'players' => [],
                    'latest_set_at' => null,
                ];
                $loadouts[$signature]['count']++;
                $jobName = (string) ($player['current_job_name'] ?: '職業未設定');
                $loadouts[$signature]['jobs'][$jobName] = ($loadouts[$signature]['jobs'][$jobName] ?? 0) + 1;
                if (count($loadouts[$signature]['players']) < 3) {
                    $loadouts[$signature]['players'][] = $player['name'];
                }
                $loadouts[$signature]['latest_set_at'] = $this->laterTimestamp(
                    $loadouts[$signature]['latest_set_at'],
                    $player['set_updated_at'],
                );
            }

            $validSkillIds = array_values(array_unique($validSkillIds));
            sort($validSkillIds);
            $skillIdCount = count($validSkillIds);
            for ($left = 0; $left < $skillIdCount; $left++) {
                for ($right = $left + 1; $right < $skillIdCount; $right++) {
                    $pairKey = $validSkillIds[$left].':'.$validSkillIds[$right];
                    $pairs[$pairKey] ??= [
                        'first_skill_id' => $validSkillIds[$left],
                        'second_skill_id' => $validSkillIds[$right],
                        'count' => 0,
                    ];
                    $pairs[$pairKey]['count']++;
                }
            }
        }

        $artRows = $this->artRows($arts, $eligibleCounts, $selected, $configuredPlayers, $filters);
        $loadoutRows = $this->loadoutRows($loadouts, $configuredPlayers);
        $pairRows = $this->pairRows($pairs, $arts, $configuredPlayers);
        $filteredPlayers = $this->filterPlayerRows($playerRows, $filters['player_search']);
        $pagination = $this->paginatePlayerRows(
            $filteredPlayers,
            $filters['player_page'],
            $filters['per_page'],
        );
        $completeSetCount = collect($configuredPlayerRows)
            ->where('slot_count', JobArtService::V2_MAX_SLOTS)
            ->count();

        return [
            'ready' => true,
            'tablesReady' => $tablesReady,
            'filters' => $filters,
            'generatedAt' => now(),
            'contextLabel' => self::CONTEXT_LABELS[$filters['battle_context']],
            'cards' => [
                'cohort_players' => $characters->count(),
                'configured_players' => $configuredPlayers,
                'configured_rate' => $characters->isNotEmpty()
                    ? round($configuredPlayers / $characters->count() * 100, 1)
                    : 0.0,
                'complete_sets' => $completeSetCount,
                'unique_loadouts' => count($loadouts),
            ],
            'artRows' => $artRows,
            'loadoutRows' => $loadoutRows,
            'pairRows' => $pairRows,
            'playerRows' => $pagination['rows'],
            'playerPagination' => $pagination,
            'invalidSlotCount' => $invalidSlotCount,
            'jobOptions' => JobClass::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportPlayerRows(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        if (in_array(false, $this->tablesReady(), true)) {
            return [];
        }

        [, , $playerRows] = $this->snapshot($filters);

        return $this->filterPlayerRows($playerRows, $filters['player_search']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Collection<int, Character>, 1: Collection<int, Skill>, 2: array<int, array<string, mixed>>}
     */
    private function snapshot(array $filters): array
    {
        $availabilityContext = $this->availabilityContext($filters['battle_context']);
        $allArts = Skill::query()
            ->where('skill_type', 'job_art')
            ->with('jobClass:id,name')
            ->orderBy('job_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $arts = $allArts
            ->filter(fn (Skill $art): bool => $this->jobArtService->contextAllows($art, $availabilityContext))
            ->keyBy(fn (Skill $art): int => (int) $art->id);

        $relations = [
            'currentJob:id,name',
            'jobHistories:id,character_id,job_class_id,job_level,is_mastered',
            'jobHistories.jobClass:id,max_job_level',
            'jobArtSlots' => fn ($query) => $query
                ->where('battle_context', $filters['battle_context'])
                ->orderBy('slot_no'),
        ];
        if (Schema::hasTable('character_job_art_context_settings')) {
            $relations['jobArtContextSettings'] = fn ($query) => $query
                ->where('battle_context', $filters['battle_context']);
        }

        $characters = $this->characterQuery($filters)
            ->with($relations)
            ->orderBy('characters.id')
            ->get();

        return [
            $characters,
            $arts,
            $this->buildPlayerRows($characters, $allArts->keyBy('id'), $arts, $filters['battle_context']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function characterQuery(array $filters): Builder
    {
        $query = Character::query()
            ->select('characters.*')
            ->join('users', 'characters.user_id', '=', 'users.id')
            ->where(function (Builder $query): void {
                $query->whereNull('users.role')
                    ->orWhere('users.role', '!=', 'admin');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('users.email')
                    ->orWhere('users.email', 'not like', self::TESTER_EMAIL_PATTERN);
            });

        if ($filters['activity_window'] !== 'all') {
            $cutoff = now()->subDays((int) $filters['activity_window']);
            $query->where(function (Builder $query) use ($cutoff): void {
                $query->where('characters.last_seen_at', '>=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('characters.last_seen_at')
                            ->where('characters.updated_at', '>=', $cutoff);
                    });
            });
        }

        if ($filters['current_job_id'] > 0) {
            $query->where('characters.current_job_id', $filters['current_job_id']);
        }

        $levelRange = self::LEVEL_BANDS[$filters['level_band']];
        if (is_array($levelRange)) {
            $query->whereBetween('characters.level', $levelRange);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @param  Collection<int, Skill>  $artsById
     * @param  Collection<int, Skill>  $contextArts
     * @return array<int, array<string, mixed>>
     */
    private function buildPlayerRows(Collection $characters, Collection $artsById, Collection $contextArts, string $context): array
    {
        return $characters
            ->map(function (Character $character) use ($artsById, $contextArts, $context): ?array {
                $histories = $character->jobHistories->keyBy('job_class_id');
                $slots = $character->jobArtSlots
                    ->sortBy('slot_no')
                    ->map(function ($slot) use ($artsById, $contextArts, $character, $histories): array {
                        $art = $artsById->get((int) $slot->skill_id);
                        $isActive = $art !== null
                            && $contextArts->has((int) $art->id)
                            && $this->isAvailableToCharacter($character, $art, $histories);

                        return [
                            'slot_no' => (int) $slot->slot_no,
                            'skill_id' => $art ? (int) $art->id : null,
                            'name' => $art?->name ?? '不明な戦技',
                            'source_job_name' => $art?->jobClass?->name ?? '職業不明',
                            'learn_rank' => $art ? (int) $art->learn_rank : null,
                            'stage_label' => $art ? $this->stageLabel((int) $art->learn_rank) : '不明',
                            'is_active' => $isActive,
                            'updated_at' => $slot->updated_at?->toIso8601String(),
                        ];
                    })
                    ->values()
                    ->all();

                if ($slots === []) {
                    return null;
                }

                $battleCount = (int) $character->wins + (int) $character->losses;
                $contextSetting = Schema::hasTable('character_job_art_context_settings')
                    ? $character->jobArtContextSettings->firstWhere('battle_context', $context)
                    : null;
                $spPolicy = $contextSetting?->sp_policy ?: 'aggressive';
                $setUpdatedAt = collect($slots)->pluck('updated_at')->filter()->max();
                $activeSlotCount = collect($slots)->where('is_active', true)->count();

                return [
                    'character_id' => (int) $character->id,
                    'name' => (string) $character->name,
                    'level' => (int) $character->level,
                    'current_job_id' => $character->current_job_id ? (int) $character->current_job_id : null,
                    'current_job_name' => $character->currentJob?->name ?? '職業未設定',
                    'last_seen_at' => $character->last_seen_at?->toIso8601String(),
                    'set_updated_at' => $setUpdatedAt,
                    'wins' => (int) $character->wins,
                    'losses' => (int) $character->losses,
                    'battle_count' => $battleCount,
                    'win_rate' => $battleCount > 0
                        ? round((int) $character->wins / $battleCount * 100, 1)
                        : null,
                    'sp_policy' => $spPolicy,
                    'sp_policy_label' => $this->spPolicyLabel($spPolicy),
                    'slot_count' => $activeSlotCount,
                    'saved_slot_count' => count($slots),
                    'slots' => $slots,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Skill>  $arts
     * @param  array<int, int>  $eligibleCounts
     * @param  array<int, array<string, mixed>>  $selected
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function artRows(Collection $arts, array $eligibleCounts, array $selected, int $configuredPlayers, array $filters): array
    {
        $rows = $arts->map(function (Skill $art) use ($eligibleCounts, $selected, $configuredPlayers): array {
            $skillId = (int) $art->id;
            $selection = $selected[$skillId] ?? [
                'player_count' => 0,
                'slot_counts' => array_fill(1, JobArtService::V2_MAX_SLOTS, 0),
                'latest_set_at' => null,
            ];
            $selectedCount = (int) $selection['player_count'];
            $eligibleCount = (int) ($eligibleCounts[$skillId] ?? 0);
            $weightedSlots = 0;
            foreach ($selection['slot_counts'] as $slotNo => $count) {
                $weightedSlots += (int) $slotNo * (int) $count;
            }

            return [
                'skill_id' => $skillId,
                'name' => (string) $art->name,
                'source_job_name' => $art->jobClass?->name ?? '職業不明',
                'learn_rank' => (int) $art->learn_rank,
                'stage_label' => $this->stageLabel((int) $art->learn_rank),
                'eligible_count' => $eligibleCount,
                'selected_count' => $selectedCount,
                'eligible_adoption_rate' => $eligibleCount > 0
                    ? round($selectedCount / $eligibleCount * 100, 1)
                    : 0.0,
                'configured_share' => $configuredPlayers > 0
                    ? round($selectedCount / $configuredPlayers * 100, 1)
                    : 0.0,
                'average_slot' => $selectedCount > 0
                    ? round($weightedSlots / $selectedCount, 2)
                    : null,
                'slot_counts' => $selection['slot_counts'],
                'latest_set_at' => $selection['latest_set_at'],
            ];
        });

        if ($filters['art_search'] !== '') {
            $needle = mb_strtolower($filters['art_search']);
            $rows = $rows->filter(fn (array $row): bool => str_contains(
                mb_strtolower($row['name'].' '.$row['source_job_name'].' '.$row['stage_label']),
                $needle,
            ));
        }

        if ($filters['art_sort'] === 'low') {
            $rows = $rows
                ->where('eligible_count', '>', 0)
                ->sort(function (array $left, array $right): int {
                    return [$left['eligible_adoption_rate'], -$left['eligible_count'], $left['name']]
                        <=> [$right['eligible_adoption_rate'], -$right['eligible_count'], $right['name']];
                });
        } elseif ($filters['art_sort'] === 'name') {
            $rows = $rows->sortBy(fn (array $row): string => $row['source_job_name'].' '.$row['learn_rank'].' '.$row['name']);
        } else {
            $rows = $rows->sort(function (array $left, array $right): int {
                return [-$left['eligible_adoption_rate'], -$left['selected_count'], -$left['eligible_count'], $left['name']]
                    <=> [-$right['eligible_adoption_rate'], -$right['selected_count'], -$right['eligible_count'], $right['name']];
            });
        }

        return $rows->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $loadouts
     * @return array<int, array<string, mixed>>
     */
    private function loadoutRows(array $loadouts, int $configuredPlayers): array
    {
        return collect($loadouts)
            ->map(function (array $loadout) use ($configuredPlayers): array {
                arsort($loadout['jobs']);
                $loadout['share'] = $configuredPlayers > 0
                    ? round($loadout['count'] / $configuredPlayers * 100, 1)
                    : 0.0;
                $loadout['jobs'] = collect($loadout['jobs'])
                    ->take(3)
                    ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
                    ->values()
                    ->all();

                return $loadout;
            })
            ->sort(fn (array $left, array $right): int => [-$left['count'], $left['signature']] <=> [-$right['count'], $right['signature']])
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $pairs
     * @param  Collection<int, Skill>  $arts
     * @return array<int, array<string, mixed>>
     */
    private function pairRows(array $pairs, Collection $arts, int $configuredPlayers): array
    {
        return collect($pairs)
            ->map(function (array $pair) use ($arts, $configuredPlayers): array {
                $first = $arts->get($pair['first_skill_id']);
                $second = $arts->get($pair['second_skill_id']);

                return [
                    ...$pair,
                    'first_name' => $first?->name ?? '不明な戦技',
                    'second_name' => $second?->name ?? '不明な戦技',
                    'share' => $configuredPlayers > 0
                        ? round($pair['count'] / $configuredPlayers * 100, 1)
                        : 0.0,
                ];
            })
            ->sort(fn (array $left, array $right): int => [-$left['count'], $left['first_name'], $left['second_name']]
                <=> [-$right['count'], $right['first_name'], $right['second_name']])
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $histories
     */
    private function isAvailableToCharacter(Character $character, Skill $art, Collection $histories): bool
    {
        $currentJobId = (int) $character->current_job_id;
        if ((int) $art->job_id === $currentJobId) {
            $currentRank = (int) ($histories->get($currentJobId)?->job_level ?? 1);

            return $currentRank >= (int) $art->learn_rank;
        }

        $history = $histories->get((int) $art->job_id);
        $maxRank = (int) ($history?->jobClass?->max_job_level ?? 10);
        $mastered = (bool) ($history?->is_mastered ?? false)
            || (int) ($history?->job_level ?? 0) >= $maxRank;

        return $mastered && (bool) $art->inherit_on_master && ! $art->isTimeLimited();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterPlayerRows(array $rows, string $search): array
    {
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return collect($rows)
            ->filter(function (array $row) use ($needle): bool {
                $haystack = $row['name'].' '.$row['current_job_name'].' '.collect($row['slots'])->pluck('name')->implode(' ');

                return str_contains(mb_strtolower($haystack), $needle);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, last_page: int, from: int, to: int}
     */
    private function paginatePlayerRows(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $battleContext = (string) ($filters['battle_context'] ?? 'normal');
        $activityWindow = (string) ($filters['activity_window'] ?? '30');
        $levelBand = (string) ($filters['level_band'] ?? 'all');
        $artSort = (string) ($filters['art_sort'] ?? 'popular');

        return [
            'battle_context' => array_key_exists($battleContext, self::CONTEXT_LABELS) ? $battleContext : 'normal',
            'activity_window' => in_array($activityWindow, self::ACTIVITY_WINDOWS, true) ? $activityWindow : '30',
            'current_job_id' => max(0, (int) ($filters['current_job_id'] ?? 0)),
            'level_band' => array_key_exists($levelBand, self::LEVEL_BANDS) ? $levelBand : 'all',
            'art_sort' => in_array($artSort, self::ART_SORTS, true) ? $artSort : 'popular',
            'art_search' => mb_substr(trim((string) ($filters['art_search'] ?? '')), 0, 100),
            'player_search' => mb_substr(trim((string) ($filters['player_search'] ?? '')), 0, 100),
            'player_page' => max(1, (int) ($filters['player_page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($filters['per_page'] ?? 25))),
        ];
    }

    /** @return array<string, bool> */
    private function tablesReady(): array
    {
        return [
            'users' => Schema::hasTable('users'),
            'characters' => Schema::hasTable('characters'),
            'character_jobs' => Schema::hasTable('character_jobs'),
            'character_job_art_slots' => Schema::hasTable('character_job_art_slots'),
            'skills' => Schema::hasTable('skills'),
            'job_classes' => Schema::hasTable('job_classes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, bool>  $tablesReady
     * @return array<string, mixed>
     */
    private function emptyAnalysis(array $filters, array $tablesReady): array
    {
        return [
            'ready' => false,
            'tablesReady' => $tablesReady,
            'filters' => $filters,
            'generatedAt' => now(),
            'contextLabel' => self::CONTEXT_LABELS[$filters['battle_context']],
            'cards' => [
                'cohort_players' => 0,
                'configured_players' => 0,
                'configured_rate' => 0.0,
                'complete_sets' => 0,
                'unique_loadouts' => 0,
            ],
            'artRows' => [],
            'loadoutRows' => [],
            'pairRows' => [],
            'playerRows' => [],
            'playerPagination' => [
                'rows' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => $filters['per_page'],
                'last_page' => 1,
                'from' => 0,
                'to' => 0,
            ],
            'invalidSlotCount' => 0,
            'jobOptions' => collect(),
        ];
    }

    private function availabilityContext(string $battleContext): string
    {
        return match ($battleContext) {
            'boss' => 'boss',
            'pvp' => 'champ',
            default => 'pve',
        };
    }

    private function stageLabel(int $learnRank): string
    {
        return match ($learnRank) {
            1 => '始動',
            5 => '連携',
            9 => '奥義',
            default => 'Rank'.$learnRank,
        };
    }

    private function spPolicyLabel(string $policy): string
    {
        return match ($policy) {
            'aggressive' => '積極',
            'conserve' => '温存',
            default => '通常',
        };
    }

    private function laterTimestamp(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }
}
