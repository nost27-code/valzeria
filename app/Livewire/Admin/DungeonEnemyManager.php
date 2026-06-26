<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Models\Enemy;
use App\Services\Enemy\EnemyStatGenerationService;
use App\Services\Enemy\EnemyStatPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class DungeonEnemyManager extends Component
{
    public ?int $selectedAreaId = null;

    public ?int $editingEnemyId = null;

    public array $form = [];

    public array $bulkForm = [
        'hp_rate' => 100,
        'str_rate' => 100,
        'def_rate' => 100,
        'agi_rate' => 100,
        'mag_rate' => 100,
        'spr_rate' => 100,
        'luk_rate' => 100,
        'reward_rate' => 100,
        'include_boss' => false,
    ];

    private array $defaults = [
        'name' => '',
        'level' => 1,
        'max_hp' => 10,
        'str' => 5,
        'def' => 5,
        'agi' => 5,
        'mag' => 5,
        'spr' => 5,
        'luk' => 5,
        'exp_reward' => 1,
        'gold_reward' => 1,
        'job_exp_reward' => 0,
        'appearance_weight' => 10,
        'is_boss' => false,
        'role' => '',
        'type_name' => '',
        'element' => '',
        'action_pattern' => '',
        'drop_type' => '',
        'sort_order' => 0,
        'enemy_level' => null,
        'family_key' => 'standard',
        'variant_key' => 'none',
        'role_key' => 'normal',
        'is_stat_locked' => true,
        'manual_adjustment_note' => '',
    ];

    public function mount(): void
    {
        $this->selectedAreaId = Area::orderBy('sort_order')->orderBy('id')->value('id');
        $this->resetForm();
    }

    public function updatedSelectedAreaId(): void
    {
        $this->resetForm();
    }

    public function edit(int $enemyId): void
    {
        $enemy = Enemy::where('area_id', $this->selectedAreaId)->findOrFail($enemyId);
        $this->editingEnemyId = $enemy->id;
        $this->form = array_merge($this->defaults, [
            'name' => $enemy->name,
            'level' => (int) $enemy->level,
            'max_hp' => (int) $enemy->max_hp,
            'str' => (int) $enemy->str,
            'def' => (int) $enemy->def,
            'agi' => (int) $enemy->agi,
            'mag' => (int) $enemy->mag,
            'spr' => (int) ($enemy->spr ?? 0),
            'luk' => (int) $enemy->luk,
            'exp_reward' => (int) $enemy->exp_reward,
            'gold_reward' => (int) $enemy->gold_reward,
            'job_exp_reward' => (int) ($enemy->job_exp_reward ?? 0),
            'appearance_weight' => (int) $enemy->appearance_weight,
            'is_boss' => (bool) $enemy->is_boss,
            'role' => $enemy->role ?? '',
            'type_name' => $enemy->type_name ?? '',
            'element' => $enemy->element ?? '',
            'action_pattern' => $enemy->action_pattern ?? '',
            'drop_type' => $enemy->drop_type ?? '',
            'sort_order' => (int) $enemy->sort_order,
            'enemy_level' => $enemy->enemy_level ? (int) $enemy->enemy_level : null,
            'family_key' => $enemy->family_key ?? 'standard',
            'variant_key' => $enemy->variant_key ?? 'none',
            'role_key' => $enemy->role_key ?? 'normal',
            'is_stat_locked' => (bool) ($enemy->is_stat_locked ?? true),
            'manual_adjustment_note' => $enemy->manual_adjustment_note ?? '',
        ]);
    }

    public function createNew(): void
    {
        $this->resetForm();
        $this->form['sort_order'] = (int) Enemy::where('area_id', $this->selectedAreaId)->max('sort_order') + 10;
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules())['form'];
        $data = $this->normalizeEnemyData($validated);

        if ($this->editingEnemyId) {
            Enemy::where('area_id', $this->selectedAreaId)->findOrFail($this->editingEnemyId)->update($data);
            session()->flash('message', '敵データを更新しました。');
        } else {
            $data['area_id'] = $this->selectedAreaId;
            Enemy::create($data);
            session()->flash('message', '敵データを追加しました。');
        }

        $this->resetForm();
    }

    public function applyAreaScale(): void
    {
        $validated = $this->validate([
            'bulkForm.hp_rate' => 'required|integer|min:10|max:300',
            'bulkForm.str_rate' => 'required|integer|min:10|max:300',
            'bulkForm.def_rate' => 'required|integer|min:10|max:300',
            'bulkForm.agi_rate' => 'required|integer|min:10|max:300',
            'bulkForm.mag_rate' => 'required|integer|min:10|max:300',
            'bulkForm.spr_rate' => 'required|integer|min:10|max:300',
            'bulkForm.luk_rate' => 'required|integer|min:10|max:300',
            'bulkForm.reward_rate' => 'required|integer|min:10|max:300',
            'bulkForm.include_boss' => 'boolean',
        ])['bulkForm'];

        $query = Enemy::where('area_id', $this->selectedAreaId);
        if (!$validated['include_boss']) {
            $query->where('is_boss', false);
        }

        $updated = 0;
        $query->get()->each(function (Enemy $enemy) use ($validated, &$updated) {
            $enemy->fill([
                'max_hp' => $this->scaled($enemy->max_hp, $validated['hp_rate']),
                'str' => $this->scaled($enemy->str, $validated['str_rate']),
                'def' => $this->scaled($enemy->def, $validated['def_rate']),
                'agi' => $this->scaled($enemy->agi, $validated['agi_rate']),
                'mag' => $this->scaled($enemy->mag, $validated['mag_rate']),
                'spr' => $this->scaled((int) ($enemy->spr ?? 0), $validated['spr_rate']),
                'luk' => $this->scaled($enemy->luk, $validated['luk_rate']),
                'exp_reward' => $this->scaled($enemy->exp_reward, $validated['reward_rate']),
                'gold_reward' => $this->scaled($enemy->gold_reward, $validated['reward_rate']),
                'job_exp_reward' => max(0, (int) floor(((int) ($enemy->job_exp_reward ?? 0)) * $validated['reward_rate'] / 100)),
            ])->save();

            $updated++;
        });

        session()->flash('message', "{$updated}体の敵データに一括倍率を適用しました。");
        $this->resetForm();
    }

    public function applyGeneratedStats(int $enemyId): void
    {
        $enemy = Enemy::where('area_id', $this->selectedAreaId)->findOrFail($enemyId);
        $result = app(EnemyStatPreviewService::class)->apply($enemy);

        if ($result['applied']) {
            session()->flash('message', "{$enemy->name} に生成ステータスを反映しました。");
        } else {
            session()->flash('message', "{$enemy->name} はロック中のため反映しませんでした。");
        }
    }

    public function toggleStatLock(int $enemyId): void
    {
        $enemy = Enemy::where('area_id', $this->selectedAreaId)->findOrFail($enemyId);
        $enemy->is_stat_locked = ! (bool) ($enemy->is_stat_locked ?? true);
        $enemy->save();

        session()->flash('message', $enemy->name . ' の自動生成ロックを' . ($enemy->is_stat_locked ? '有効' : '解除') . 'にしました。');
    }

    public function resetForm(): void
    {
        $this->editingEnemyId = null;
        $this->form = $this->defaults;
    }

    public function render()
    {
        $selectedArea = $this->selectedAreaId
            ? Area::with('city')->find($this->selectedAreaId)
            : null;

        return view('livewire.admin.dungeon-enemy-manager', [
            'areas' => $this->areasWithMetrics(),
            'selectedAreaId' => $this->selectedAreaId,
            'editingEnemyId' => $this->editingEnemyId,
            'selectedArea' => $selectedArea,
            'enemies' => $this->enemies(),
            'areaSummary' => $this->areaSummary(),
            'enemyMetrics' => $this->enemyMetrics(),
            'statPreviews' => $this->statPreviews(),
            'statGenerationOptions' => $this->statGenerationOptions(),
        ])->layout('components.layouts.admin');
    }

    private function rules(): array
    {
        return [
            'form.name' => 'required|string|max:100',
            'form.level' => 'required|integer|min:1|max:9999',
            'form.max_hp' => 'required|integer|min:1|max:99999999',
            'form.str' => 'required|integer|min:0|max:999999',
            'form.def' => 'required|integer|min:0|max:999999',
            'form.agi' => 'required|integer|min:0|max:999999',
            'form.mag' => 'required|integer|min:0|max:999999',
            'form.spr' => 'required|integer|min:0|max:999999',
            'form.luk' => 'required|integer|min:0|max:999999',
            'form.exp_reward' => 'required|integer|min:0|max:999999999',
            'form.gold_reward' => 'required|integer|min:0|max:999999999',
            'form.job_exp_reward' => 'required|integer|min:0|max:999999999',
            'form.appearance_weight' => 'required|integer|min:0|max:999999',
            'form.is_boss' => 'boolean',
            'form.role' => 'nullable|string|max:100',
            'form.type_name' => 'nullable|string|max:100',
            'form.element' => 'nullable|string|max:100',
            'form.action_pattern' => 'nullable|string|max:100',
            'form.drop_type' => 'nullable|string|max:100',
            'form.sort_order' => 'required|integer|min:0|max:999999',
            'form.enemy_level' => 'nullable|integer|min:1|max:255',
            'form.family_key' => 'required|string|max:50',
            'form.variant_key' => 'required|string|max:50',
            'form.role_key' => 'required|string|max:50',
            'form.is_stat_locked' => 'boolean',
            'form.manual_adjustment_note' => 'nullable|string|max:2000',
        ];
    }

    private function normalizeEnemyData(array $data): array
    {
        foreach (['role', 'type_name', 'element', 'action_pattern', 'drop_type', 'manual_adjustment_note'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }

        $data['is_boss'] = (bool) $data['is_boss'];
        $data['is_stat_locked'] = (bool) ($data['is_stat_locked'] ?? true);
        $data['enemy_level'] = filled($data['enemy_level'] ?? null) ? (int) $data['enemy_level'] : null;
        if (Schema::hasColumn('enemies', 'species_key')) {
            $data['species_key'] = (string) ($data['family_key'] ?? '');
        }

        return $data;
    }

    private function areasWithMetrics()
    {
        $metrics = $this->battleMetricsByArea(now()->subDays(30));

        return Area::with('city')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (Area $area) use ($metrics) {
                $row = $metrics[$area->id] ?? ['total' => 0, 'losses' => 0];
                $row['loss_rate'] = $this->percent((int) $row['losses'], (int) $row['total']);

                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'city' => $area->city?->name ?? '街未設定',
                    'recommended' => "Lv{$area->recommended_level_min}-{$area->recommended_level_max}",
                    'enemy_count' => $area->enemies()->count(),
                    'total' => (int) $row['total'],
                    'losses' => (int) $row['losses'],
                    'loss_rate' => $row['loss_rate'],
                ];
            });
    }

    private function enemies()
    {
        if (!$this->selectedAreaId) {
            return collect();
        }

        return Enemy::where('area_id', $this->selectedAreaId)
            ->orderBy('is_boss')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function areaSummary(): array
    {
        if (!$this->selectedAreaId || !Schema::hasTable('battle_logs')) {
            return ['total_7d' => 0, 'losses_7d' => 0, 'loss_rate_7d' => 0, 'total_30d' => 0, 'losses_30d' => 0, 'loss_rate_30d' => 0];
        }

        $seven = $this->battleMetricsForArea(now()->subDays(7));
        $thirty = $this->battleMetricsForArea(now()->subDays(30));

        return [
            'total_7d' => $seven['total'],
            'losses_7d' => $seven['losses'],
            'loss_rate_7d' => $this->percent($seven['losses'], $seven['total']),
            'total_30d' => $thirty['total'],
            'losses_30d' => $thirty['losses'],
            'loss_rate_30d' => $this->percent($thirty['losses'], $thirty['total']),
        ];
    }

    private function battleMetricsForArea($since): array
    {
        $row = DB::table('battle_logs')
            ->where('area_id', $this->selectedAreaId)
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN result = ? THEN 0 ELSE 1 END) as losses', ['win'])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'losses' => (int) ($row->losses ?? 0),
        ];
    }

    private function battleMetricsByArea($since): array
    {
        if (!Schema::hasTable('battle_logs')) {
            return [];
        }

        return DB::table('battle_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('area_id, COUNT(*) as total, SUM(CASE WHEN result = ? THEN 0 ELSE 1 END) as losses', ['win'])
            ->groupBy('area_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->area_id => ['total' => (int) $row->total, 'losses' => (int) $row->losses]])
            ->all();
    }

    private function enemyMetrics(): array
    {
        if (!$this->selectedAreaId || !Schema::hasTable('battle_logs')) {
            return [];
        }

        return DB::table('battle_logs')
            ->where('area_id', $this->selectedAreaId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('enemy_id, COUNT(*) as total, SUM(CASE WHEN result = ? THEN 0 ELSE 1 END) as losses, MAX(created_at) as last_battle_at', ['win'])
            ->groupBy('enemy_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->enemy_id => [
                'total' => (int) $row->total,
                'losses' => (int) $row->losses,
                'loss_rate' => $this->percent((int) $row->losses, (int) $row->total),
                'last_battle_at' => $row->last_battle_at,
            ]])
            ->all();
    }

    private function statPreviews(): array
    {
        if (!$this->selectedAreaId || !Schema::hasColumn('enemies', 'family_key')) {
            return [];
        }

        $service = app(EnemyStatPreviewService::class);

        return $this->enemies()
            ->mapWithKeys(fn (Enemy $enemy) => [$enemy->id => $service->preview($enemy)])
            ->all();
    }

    private function statGenerationOptions(): array
    {
        $generator = app(EnemyStatGenerationService::class);

        return [
            'families' => $generator->keys('family_multipliers'),
            'variants' => $generator->keys('variant_multipliers'),
            'roles' => $generator->keys('role_multipliers'),
        ];
    }

    private function scaled(int $value, int $rate): int
    {
        return max(1, (int) round($value * $rate / 100));
    }

    private function percent(int $value, int $total): float
    {
        return $total > 0 ? round($value / $total * 100, 1) : 0.0;
    }
}
