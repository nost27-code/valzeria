<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\WeaponTraitOperationLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class ActionLogManager extends Component
{
    public string $searchQuery = '';
    public string $eventType = 'all';
    public int $currentPage = 1;
    public int $perPage = 50;

    public function updatedSearchQuery(): void
    {
        $this->searchQuery = trim($this->searchQuery);
        $this->resetPage();
    }

    public function updatedEventType(): void
    {
        $this->resetPage();
    }

    public function previousPage(): void
    {
        $this->currentPage = max(1, $this->currentPage - 1);
    }

    public function nextPage(): void
    {
        $this->currentPage++;
    }

    public function render()
    {
        $logs = collect();

        foreach ($this->sources() as $key => $source) {
            if ($this->eventType !== 'all' && $this->eventType !== $key) {
                continue;
            }

            $logs = $logs->merge($source());
        }

        $sortedLogs = $logs
            ->sortByDesc(fn (array $row) => $row['occurred_at_sort'])
            ->values();

        $offset = ($this->currentPage - 1) * $this->perPage;
        $logs = $sortedLogs->slice($offset, $this->perPage)->values();

        return view('livewire.admin.action-log-manager', [
            'logs' => $logs,
            'eventTypes' => $this->eventTypes(),
            'currentPage' => $this->currentPage,
            'perPage' => $this->perPage,
            'hasMore' => $sortedLogs->count() > ($offset + $this->perPage),
        ])->layout('components.layouts.admin');
    }

    private function sources(): array
    {
        return [
            'battle' => fn () => $this->battleLogs(),
            'job_exp_alert' => fn () => $this->jobExpAlertLogs(),
            'job_change' => fn () => $this->jobChangeLogs(),
            'loot' => fn () => $this->lootLogs(),
            'kiseki' => fn () => $this->kisekiLogs(),
            'shop' => fn () => $this->shopLogs(),
            'evolution' => fn () => $this->evolutionLogs(),
            'weapon_trait' => fn () => $this->weaponTraitLogs(),
            'decomposition' => fn () => $this->decompositionLogs(),
            'valmon_feed' => fn () => $this->valmonFeedLogs(),
            'valmon_find' => fn () => $this->valmonFindLogs(),
            'public' => fn () => $this->publicLogs(),
        ];
    }

    private function eventTypes(): array
    {
        return [
            'all' => 'すべて',
            'battle' => '戦闘集約',
            'job_exp_alert' => '職業EXP超過',
            'job_change' => '転職',
            'loot' => '重要戦利品',
            'kiseki' => '輝石',
            'shop' => '課金支援',
            'evolution' => '進化',
            'weapon_trait' => '銘・特攻鍛錬',
            'decomposition' => '分解集約',
            'valmon_feed' => 'ヴァルモン餌',
            'valmon_find' => 'ヴァルモン探索',
            'public' => '公開ログ',
        ];
    }

    private function battleLogs()
    {
        if (!Schema::hasTable('battle_logs')) {
            return collect();
        }

        $hasJobExp = Schema::hasColumn('battle_logs', 'job_exp_gained');

        $battles = $this->characterScopedQuery('battle_logs')
            ->leftJoin('areas', 'battle_logs.area_id', '=', 'areas.id')
            ->select([
                'battle_logs.id',
                'battle_logs.character_id',
                'battle_logs.created_at',
                'battle_logs.battle_type',
                'battle_logs.result',
                'battle_logs.exp_gained',
                $hasJobExp ? 'battle_logs.job_exp_gained' : DB::raw('0 as job_exp_gained'),
                'battle_logs.gold_gained',
                'battle_logs.level_up_count',
                'characters.name as character_name',
                'areas.name as area_name',
            ])
            ->orderByDesc('battle_logs.created_at')
            ->limit($this->sourceLimit(15))
            ->get();

        $lootSummaries = $this->lootSummariesForBattleBuckets($battles);

        return $battles
            ->groupBy(fn ($row) => $this->bucketKey((int) $row->character_id, Carbon::parse($row->created_at)))
            ->map(function ($rows, string $bucketKey) use ($lootSummaries) {
                $first = $rows->sortByDesc('created_at')->first();
                $last = $rows->sortBy('created_at')->first();
                $latestAt = Carbon::parse($first->created_at);
                $earliestAt = Carbon::parse($last->created_at);
                $wins = $rows->where('result', 'win')->count();
                $losses = $rows->where('result', '!=', 'win')->count();
                $battleTypes = $rows->pluck('battle_type')->filter()->unique()->implode(' / ');
                $areas = $rows->pluck('area_name')->filter()->unique()->take(3)->implode('、') ?: '-';
                $loot = $lootSummaries[$bucketKey] ?? ['materials' => 0, 'equipment' => 0, 'penalized' => 0];
                $jobExpTotal = (int) $rows->sum('job_exp_gained');
                $jobExpMax = (int) $rows->max('job_exp_gained');
                $jobExpOverCap = $rows->filter(fn ($row): bool => (int) $row->job_exp_gained > 3)->count();

                $summary = sprintf(
                    '%s-%s / %d戦 %d勝 %d敗',
                    $earliestAt->format('H:i'),
                    $latestAt->format('H:i'),
                    $rows->count(),
                    $wins,
                    $losses
                );

                $detail = sprintf(
                    'EXP +%s / JobEXP +%s（最高+%s%s）/ Gold +%s / LvUp %d / 戦利品: 素材%d・装備%d%s / %s / %s',
                    number_format((int) $rows->sum('exp_gained')),
                    number_format($jobExpTotal),
                    number_format($jobExpMax),
                    $jobExpOverCap > 0 ? '・上限超過' . $jobExpOverCap . '戦' : '',
                    number_format((int) $rows->sum('gold_gained')),
                    (int) $rows->sum('level_up_count'),
                    (int) $loot['materials'],
                    (int) $loot['equipment'],
                    (int) $loot['penalized'] > 0 ? '・喪失対象' . (int) $loot['penalized'] : '',
                    $areas,
                    $battleTypes ?: 'normal'
                );

                return $this->row(
                    $latestAt,
                    '戦闘集約',
                    $first->character_name,
                    $summary,
                    $detail,
                    'bg-red-100 text-red-700'
                );
            })
            ->values();
    }

    private function jobExpAlertLogs()
    {
        if (!Schema::hasTable('battle_logs') || !Schema::hasColumn('battle_logs', 'job_exp_gained')) return collect();

        return $this->characterScopedQuery('battle_logs')->leftJoin('areas', 'battle_logs.area_id', '=', 'areas.id')
            ->where('battle_logs.job_exp_gained', '>', 3)
            ->select(['battle_logs.created_at', 'battle_logs.job_exp_gained', 'battle_logs.battle_type', 'characters.name as character_name', 'areas.name as area_name'])
            ->orderByDesc('battle_logs.created_at')->limit($this->sourceLimit())->get()
            ->map(fn ($row) => $this->row($row->created_at, '職業EXP超過', $row->character_name, 'JobEXP +' . number_format((int) $row->job_exp_gained), trim(($row->area_name ?? '-') . ' / ' . ($row->battle_type ?? 'normal') . ' / 通常上限3を超過'), 'bg-rose-100 text-rose-700'));
    }

    private function jobChangeLogs()
    {
        if (!Schema::hasTable('job_change_logs')) return collect();

        return $this->characterScopedQuery('job_change_logs')
            ->leftJoin('job_classes as from_jobs', 'job_change_logs.from_job_id', '=', 'from_jobs.id')
            ->leftJoin('job_classes as to_jobs', 'job_change_logs.to_job_id', '=', 'to_jobs.id')
            ->select(['job_change_logs.created_at', 'job_change_logs.before_level', 'job_change_logs.reincarnation_count_after', 'characters.name as character_name', 'from_jobs.name as from_job_name', 'to_jobs.name as to_job_name'])
            ->orderByDesc('job_change_logs.created_at')->limit($this->sourceLimit())->get()
            ->map(fn ($row) => $this->row($row->created_at, '転職', $row->character_name, ($row->from_job_name ?? '無職') . ' → ' . ($row->to_job_name ?? '職業'), '転職前Lv' . number_format((int) $row->before_level) . ' / 転職' . number_format((int) $row->reincarnation_count_after) . '回目', 'bg-indigo-100 text-indigo-700'));
    }

    private function lootLogs()
    {
        if (!Schema::hasTable('exploration_loot_logs')) {
            return collect();
        }

        $logs = $this->characterScopedQuery('exploration_loot_logs')
            ->leftJoin('areas', 'exploration_loot_logs.area_id', '=', 'areas.id')
            ->leftJoin('materials', 'exploration_loot_logs.material_id', '=', 'materials.id')
            ->leftJoin('character_items', 'exploration_loot_logs.character_item_id', '=', 'character_items.id')
            ->leftJoin('items', 'character_items.item_id', '=', 'items.id')
            ->select([
                'exploration_loot_logs.created_at',
                'exploration_loot_logs.character_id',
                'exploration_loot_logs.quantity',
                'exploration_loot_logs.penalized',
                'characters.name as character_name',
                'areas.name as area_name',
                'materials.material_type',
                'materials.rank_tier as material_rank_tier',
                'materials.name as material_name',
                'items.rarity as item_rarity',
                'items.weapon_rank',
                'items.armor_rank',
                'items.accessory_rank',
                'items.name as item_name',
            ])
            ->orderByDesc('exploration_loot_logs.created_at')
            ->limit($this->sourceLimit(10))
            ->get()
            ->filter(fn ($row) => $this->isImportantLoot($row))
            ->values();

        return $logs
            ->groupBy(fn ($row) => $this->bucketKey((int) $row->character_id, Carbon::parse($row->created_at)))
            ->map(function ($rows) {
                $first = $rows->sortByDesc('created_at')->first();
                $last = $rows->sortBy('created_at')->first();
                $latestAt = Carbon::parse($first->created_at);
                $earliestAt = Carbon::parse($last->created_at);
                $areas = $rows->pluck('area_name')->filter()->unique()->take(3)->implode('、') ?: '-';
                $lootText = $this->summarizeLootItems($rows);
                $penalizedCount = $rows->where('penalized', true)->count();

                return $this->row(
                    $latestAt,
                    '重要戦利品集約',
                    $first->character_name,
                    sprintf(
                        '%s-%s / %d件',
                        $earliestAt->format('H:i'),
                        $latestAt->format('H:i'),
                        $rows->count()
                    ),
                    trim(($lootText ?: '重要戦利品なし') . ' / ' . $areas . ($penalizedCount > 0 ? ' / 全滅ペナルティ対象' . $penalizedCount : '')),
                    'bg-emerald-100 text-emerald-700'
                );
            })
            ->values();
    }

    private function kisekiLogs()
    {
        if (!Schema::hasTable('kiseki_transactions')) {
            return collect();
        }

        return $this->characterScopedQuery('kiseki_transactions')
            ->leftJoin('areas', 'kiseki_transactions.area_id', '=', 'areas.id')
            ->leftJoin('enemies', 'kiseki_transactions.enemy_id', '=', 'enemies.id')
            ->select([
                'kiseki_transactions.created_at',
                'kiseki_transactions.kiseki_type',
                'kiseki_transactions.amount',
                'kiseki_transactions.transaction_type',
                'kiseki_transactions.description',
                'kiseki_transactions.daily_dropped_count',
                'characters.name as character_name',
                'areas.name as area_name',
                'enemies.name as enemy_name',
            ])
            ->orderByDesc('kiseki_transactions.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                '輝石',
                $row->character_name,
                ($row->amount >= 0 ? '+' : '') . number_format((int) $row->amount) . ' / ' . $row->kiseki_type,
                trim(($row->description ?? $row->transaction_type) . ' ' . ($row->area_name ? "/ {$row->area_name}" : '') . ($row->enemy_name ? " / {$row->enemy_name}" : '')),
                'bg-sky-100 text-sky-700'
            ));
    }

    private function shopLogs()
    {
        if (!Schema::hasTable('shop_purchase_logs')) {
            return collect();
        }

        return $this->characterScopedQuery('shop_purchase_logs')
            ->select([
                'shop_purchase_logs.created_at',
                'shop_purchase_logs.item_name',
                'shop_purchase_logs.quantity',
                'shop_purchase_logs.total_kiseki_cost',
                'shop_purchase_logs.free_kiseki_spent',
                'shop_purchase_logs.paid_kiseki_spent',
                'characters.name as character_name',
            ])
            ->orderByDesc('shop_purchase_logs.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                '課金支援',
                $row->character_name,
                $row->item_name . ' x' . (int) $row->quantity,
                '消費 ' . number_format((int) $row->total_kiseki_cost) . '輝石 / 無償' . (int) $row->free_kiseki_spent . ' 有償' . (int) $row->paid_kiseki_spent,
                'bg-violet-100 text-violet-700'
            ));
    }

    private function evolutionLogs()
    {
        if (!Schema::hasTable('equipment_evolution_logs')) {
            return collect();
        }

        return $this->characterScopedQuery('equipment_evolution_logs')
            ->leftJoin('items as before_items', 'equipment_evolution_logs.before_equipment_id', '=', 'before_items.id')
            ->leftJoin('items as after_items', 'equipment_evolution_logs.after_equipment_id', '=', 'after_items.id')
            ->select([
                'equipment_evolution_logs.created_at',
                'equipment_evolution_logs.recipe_type',
                'characters.name as character_name',
                'before_items.name as before_name',
                'after_items.name as after_name',
            ])
            ->orderByDesc('equipment_evolution_logs.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                '進化',
                $row->character_name,
                ($row->before_name ?? '装備') . ' → ' . ($row->after_name ?? '進化装備'),
                $row->recipe_type ?? '-',
                'bg-amber-100 text-amber-700'
            ));
    }

    private function weaponTraitLogs()
    {
        if (!Schema::hasTable('weapon_trait_operation_logs')) {
            return collect();
        }

        return WeaponTraitOperationLog::query()
            ->with('character:id,name,user_id')
            ->when($this->searchQuery !== '', function ($query): void {
                $search = '%' . $this->searchQuery . '%';
                $query->whereHas('character', function ($characterQuery) use ($search): void {
                    $characterQuery->where('name', 'like', $search)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('email', 'like', $search));
                });
            })
            ->orderByDesc('created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn (WeaponTraitOperationLog $log) => $this->row(
                $log->created_at,
                '銘・特攻鍛錬',
                $log->character?->name,
                $log->operationLabel() . '：' . $log->baseDisplayName() . ' + ' . $log->materialDisplayName(),
                '完成：' . $log->completedDisplayName() . ' / 消費Gold ' . number_format((int) $log->gold_cost) . 'G',
                'bg-fuchsia-100 text-fuchsia-700'
            ));
    }

    private function decompositionLogs()
    {
        if (!Schema::hasTable('equipment_decomposition_logs')) {
            return collect();
        }

        $logs = $this->characterScopedQuery('equipment_decomposition_logs')
            ->select([
                'equipment_decomposition_logs.created_at',
                'equipment_decomposition_logs.character_id',
                'equipment_decomposition_logs.equipment_name',
                'equipment_decomposition_logs.rank',
                'equipment_decomposition_logs.obtained_materials',
                'characters.name as character_name',
            ])
            ->orderByDesc('equipment_decomposition_logs.created_at')
            ->limit($this->sourceLimit(10))
            ->get();

        return $logs
            ->groupBy(fn ($row) => $this->bucketKey((int) $row->character_id, Carbon::parse($row->created_at)))
            ->map(function ($rows) {
                $first = $rows->sortByDesc('created_at')->first();
                $last = $rows->sortBy('created_at')->first();
                $latestAt = Carbon::parse($first->created_at);
                $earliestAt = Carbon::parse($last->created_at);
                $rankCounts = $rows
                    ->groupBy(fn ($row) => strtoupper((string) ($row->rank ?? '-')))
                    ->map(fn ($rankRows, $rank) => '[' . $rank . ']x' . $rankRows->count())
                    ->values()
                    ->implode(' ');
                $sampleEquipment = $rows
                    ->pluck('equipment_name')
                    ->filter()
                    ->unique()
                    ->take(4)
                    ->implode('、');
                $materialText = $this->summarizeDecompositionMaterials($rows);

                return $this->row(
                    $latestAt,
                    '分解集約',
                    $first->character_name,
                    sprintf(
                        '%s-%s / %d個分解 %s',
                        $earliestAt->format('H:i'),
                        $latestAt->format('H:i'),
                        $rows->count(),
                        $rankCounts
                    ),
                    trim(($sampleEquipment ? '装備: ' . $sampleEquipment . ' / ' : '') . ($materialText ?: '獲得素材なし')),
                    'bg-orange-100 text-orange-700'
                );
            })
            ->values();
    }

    private function valmonFeedLogs()
    {
        if (!Schema::hasTable('valmon_feed_logs')) {
            return collect();
        }

        return $this->characterScopedQuery('valmon_feed_logs')
            ->leftJoin('player_valmons', 'valmon_feed_logs.player_valmon_id', '=', 'player_valmons.id')
            ->leftJoin('valmon_masters', 'player_valmons.valmon_master_id', '=', 'valmon_masters.id')
            ->select([
                'valmon_feed_logs.created_at',
                'valmon_feed_logs.feed_type',
                'valmon_feed_logs.quantity',
                'valmon_feed_logs.gained_exp',
                'characters.name as character_name',
                'valmon_masters.name as valmon_name',
            ])
            ->orderByDesc('valmon_feed_logs.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                'ヴァルモン餌',
                $row->character_name,
                ($row->valmon_name ?? 'ヴァルモン') . 'に餌 x' . (int) $row->quantity,
                'EXP +' . number_format((int) $row->gained_exp) . ' / ' . $row->feed_type,
                'bg-lime-100 text-lime-700'
            ));
    }

    private function valmonFindLogs()
    {
        if (!Schema::hasTable('valmon_material_find_logs')) {
            return collect();
        }

        return $this->characterScopedQuery('valmon_material_find_logs')
            ->leftJoin('materials', 'valmon_material_find_logs.material_id', '=', 'materials.id')
            ->leftJoin('player_valmons', 'valmon_material_find_logs.player_valmon_id', '=', 'player_valmons.id')
            ->leftJoin('valmon_masters', 'player_valmons.valmon_master_id', '=', 'valmon_masters.id')
            ->select([
                'valmon_material_find_logs.created_at',
                'valmon_material_find_logs.quantity',
                'characters.name as character_name',
                'materials.name as material_name',
                'valmon_masters.name as valmon_name',
            ])
            ->orderByDesc('valmon_material_find_logs.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                'ヴァルモン探索',
                $row->character_name,
                ($row->valmon_name ?? 'ヴァルモン') . 'が発見',
                ($row->material_name ?? '素材') . ' x' . (int) $row->quantity,
                'bg-teal-100 text-teal-700'
            ));
    }

    private function publicLogs()
    {
        if (!Schema::hasTable('public_logs')) {
            return collect();
        }

        return $this->characterScopedQuery('public_logs')
            ->where('public_logs.type', '!=', 'private')
            ->select([
                'public_logs.created_at',
                'public_logs.type',
                'public_logs.message',
                'characters.name as character_name',
            ])
            ->orderByDesc('public_logs.created_at')
            ->limit($this->sourceLimit())
            ->get()
            ->map(fn ($row) => $this->row(
                $row->created_at,
                '公開ログ',
                $row->character_name ?? '-',
                Str::limit(strip_tags((string) $row->message), 80),
                $row->type ?? '-',
                'bg-slate-100 text-slate-700'
            ));
    }

    private function characterScopedQuery(string $table)
    {
        $query = DB::table($table)
            ->leftJoin('characters', "{$table}.character_id", '=', 'characters.id');

        if ($this->searchQuery !== '') {
            $search = '%' . $this->searchQuery . '%';
            $query->where(function ($q) use ($search) {
                $q->where('characters.name', 'like', $search)
                    ->orWhereExists(function ($userQuery) use ($search) {
                        $userQuery->selectRaw('1')
                            ->from('users')
                            ->whereColumn('users.id', 'characters.user_id')
                            ->where('users.email', 'like', $search);
                    });
            });
        }

        return $query;
    }

    private function lootSummariesForBattleBuckets($battles): array
    {
        if ($battles->isEmpty() || !Schema::hasTable('exploration_loot_logs')) {
            return [];
        }

        $from = Carbon::parse($battles->min('created_at'))->subMinute();
        $to = Carbon::parse($battles->max('created_at'))->addMinute();
        $characterIds = $battles->pluck('character_id')->filter()->unique()->values();

        return DB::table('exploration_loot_logs')
            ->whereBetween('exploration_loot_logs.created_at', [$from, $to])
            ->whereIn('exploration_loot_logs.character_id', $characterIds)
            ->leftJoin('character_items', 'exploration_loot_logs.character_item_id', '=', 'character_items.id')
            ->select([
                'exploration_loot_logs.character_id',
                'exploration_loot_logs.created_at',
                'exploration_loot_logs.material_id',
                'exploration_loot_logs.character_item_id',
                'exploration_loot_logs.penalized',
            ])
            ->get()
            ->groupBy(fn ($row) => $this->bucketKey((int) $row->character_id, Carbon::parse($row->created_at)))
            ->map(function ($rows) {
                return [
                    'materials' => $rows->whereNotNull('material_id')->count(),
                    'equipment' => $rows->whereNotNull('character_item_id')->count(),
                    'penalized' => $rows->where('penalized', true)->count(),
                ];
            })
            ->all();
    }

    private function bucketKey(int $characterId, Carbon $time): string
    {
        $bucketMinute = (int) floor(((int) $time->format('i')) / 10) * 10;
        $bucket = $time->copy()->minute($bucketMinute)->second(0);

        return $characterId . ':' . $bucket->format('YmdHi');
    }

    private function sourceLimit(int $multiplier = 1): int
    {
        return max($this->perPage, (($this->currentPage * $this->perPage) + 1) * $multiplier);
    }

    private function resetPage(): void
    {
        $this->currentPage = 1;
    }

    private function isImportantLoot($row): bool
    {
        if ($row->item_name) {
            $rank = strtoupper((string) ($row->weapon_rank ?? $row->armor_rank ?? $row->accessory_rank ?? $row->item_rarity ?? ''));
            $rankOrder = ['G' => 1, 'F' => 2, 'E' => 3, 'D' => 4, 'C' => 5, 'B' => 6, 'A' => 7, 'S' => 8, 'SS' => 9, 'SSS' => 10, 'EPIC' => 11];
            $rarity = strtolower((string) ($row->item_rarity ?? ''));

            return ($rankOrder[$rank] ?? 0) >= $rankOrder['A']
                || in_array($rarity, ['rare', 'epic', 'legend'], true);
        }

        $name = (string) ($row->material_name ?? '');
        $type = (string) ($row->material_type ?? '');
        $tier = (int) ($row->material_rank_tier ?? 1);

        if ((bool) $row->penalized) {
            return true;
        }

        return $tier >= 3
            || str_contains($type, 'branch_evolution')
            || str_contains($type, 'secret')
            || str_contains($name, '導石')
            || str_contains($name, '古代片')
            || str_contains($name, '秘境晶')
            || str_contains($name, '極印')
            || str_contains($name, '英雄の証')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function summarizeDecompositionMaterials($rows): string
    {
        return $rows
            ->flatMap(function ($row) {
                $materials = is_string($row->obtained_materials)
                    ? json_decode($row->obtained_materials, true)
                    : [];

                return collect($materials ?: [])
                    ->map(fn ($material) => [
                        'name' => $material['name'] ?? '素材',
                        'quantity' => (int) ($material['quantity'] ?? 1),
                    ]);
            })
            ->groupBy('name')
            ->map(fn ($materials, $name) => $name . ' x' . number_format((int) $materials->sum('quantity')))
            ->values()
            ->implode('、');
    }

    private function summarizeLootItems($rows): string
    {
        return $rows
            ->map(fn ($row) => [
                'name' => $row->material_name ?? $row->item_name ?? '不明な戦利品',
                'quantity' => (int) ($row->quantity ?? 1),
            ])
            ->groupBy('name')
            ->map(fn ($items, $name) => $name . ' x' . number_format((int) $items->sum('quantity')))
            ->values()
            ->implode('、');
    }

    private function row($occurredAt, string $type, ?string $characterName, string $summary, string $detail, string $badgeClass): array
    {
        $time = $occurredAt ? Carbon::parse($occurredAt) : now();

        return [
            'occurred_at' => $time,
            'occurred_at_sort' => $time->timestamp,
            'type' => $type,
            'character_name' => $characterName ?: '-',
            'summary' => $summary,
            'detail' => $detail,
            'badge_class' => $badgeClass,
        ];
    }
}
