<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Models\CharacterRegionDungeonRun;
use App\Models\City;
use App\Models\RegionDepthDungeon;
use App\Services\RegionDepthDungeonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class RegionDepthDungeonManager extends Component
{
    public ?int $editingId = null;

    public array $form = [];

    private array $defaults = [
        'name' => '', 'description' => '', 'city_id' => null, 'source_area_id' => null, 'baseline_area_id' => null,
        'is_enabled' => true, 'entry_gold' => 0, 'entry_materials_text' => '', 'danger_increase_percent' => 33,
        'base_job_exp' => 3, 'main_stat_per_danger' => 0.01, 'hp_per_danger' => 0.005, 'agi_luk_per_danger' => 0.005,
        'exp_per_danger' => 0.0005, 'exp_multiplier_cap' => 2, 'job_exp_cap' => 8,
        'danger_per_guaranteed_bonus' => 200, 'remainder_percent_divisor' => 2, 'public_log_minimum_danger' => 100,
    ];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function createNew(): void
    {
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $dungeon = RegionDepthDungeon::findOrFail($id);
        $this->editingId = $dungeon->id;
        $this->form = array_merge($this->defaults, [
            'name' => $dungeon->name,
            'description' => $dungeon->description ?? '',
            'city_id' => $dungeon->city_id,
            'source_area_id' => $dungeon->source_area_id,
            'baseline_area_id' => $dungeon->baseline_area_id,
            'is_enabled' => $dungeon->is_enabled,
            'entry_gold' => $dungeon->entry_gold,
            'entry_materials_text' => $this->materialsToText($dungeon->entry_materials ?? []),
            'danger_increase_percent' => $dungeon->danger_increase_percent,
            'base_job_exp' => $dungeon->base_job_exp,
            'main_stat_per_danger' => $dungeon->main_stat_per_danger,
            'hp_per_danger' => $dungeon->hp_per_danger,
            'agi_luk_per_danger' => $dungeon->agi_luk_per_danger,
            'exp_per_danger' => $dungeon->exp_per_danger,
            'exp_multiplier_cap' => $dungeon->exp_multiplier_cap,
            'job_exp_cap' => $dungeon->job_exp_cap,
            'danger_per_guaranteed_bonus' => $dungeon->danger_per_guaranteed_bonus,
            'remainder_percent_divisor' => $dungeon->remainder_percent_divisor,
            'public_log_minimum_danger' => $dungeon->public_log_minimum_danger,
        ]);
    }

    public function save(): void
    {
        $form = $this->validate($this->rules())['form'];
        $source = Area::findOrFail((int) $form['source_area_id']);
        $baseline = Area::findOrFail((int) $form['baseline_area_id']);
        try {
            $materials = $this->parseMaterials((string) $form['entry_materials_text']);
        } catch (\RuntimeException $exception) {
            $this->addError('form.entry_materials_text', $exception->getMessage());

            return;
        }
        $scaling = app(RegionDepthDungeonService::class)->baselineScaling($source, $baseline);

        DB::transaction(function () use ($form, $source, $baseline, $materials, $scaling) {
            $dungeon = $this->editingId ? RegionDepthDungeon::lockForUpdate()->findOrFail($this->editingId) : new RegionDepthDungeon();
            $area = $dungeon->exists ? $dungeon->area : $this->createArea($form, $source);
            $area->update([
                'name' => trim((string) $form['name']),
                'description' => trim((string) $form['description']),
                'city_id' => (int) $form['city_id'],
                'recommended_level_min' => (int) $baseline->recommended_level_min,
                'recommended_level_max' => (int) $baseline->recommended_level_max,
            ]);

            $dungeon->fill([
                'key' => $dungeon->key ?: $this->newKey((string) $form['name']),
                'name' => trim((string) $form['name']),
                'description' => trim((string) $form['description']) ?: null,
                'city_id' => (int) $form['city_id'],
                'area_id' => $area->id,
                'source_area_id' => $source->id,
                'baseline_area_id' => $baseline->id,
                'is_enabled' => (bool) $form['is_enabled'],
                'entry_gold' => (int) $form['entry_gold'],
                'entry_materials' => $materials,
                'danger_increase_percent' => (int) $form['danger_increase_percent'],
                'base_stat_multipliers' => $scaling['base_stat_multipliers'],
                'base_exp_multiplier' => $scaling['base_exp_multiplier'],
                'base_job_exp' => (int) $form['base_job_exp'],
                'main_stat_per_danger' => $form['main_stat_per_danger'],
                'hp_per_danger' => $form['hp_per_danger'],
                'agi_luk_per_danger' => $form['agi_luk_per_danger'],
                'exp_per_danger' => $form['exp_per_danger'],
                'exp_multiplier_cap' => $form['exp_multiplier_cap'],
                'job_exp_cap' => (int) $form['job_exp_cap'],
                'danger_per_guaranteed_bonus' => (int) $form['danger_per_guaranteed_bonus'],
                'remainder_percent_divisor' => (int) $form['remainder_percent_divisor'],
                'public_log_minimum_danger' => (int) $form['public_log_minimum_danger'],
            ])->save();
        });

        session()->flash('message', $this->editingId ? '追加ダンジョンを更新しました。' : '追加ダンジョンを作成しました。');
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->form = $this->defaults;
    }

    public function render()
    {
        $dungeons = RegionDepthDungeon::with(['city', 'sourceArea', 'baselineArea'])->orderBy('id')->get();

        return view('livewire.admin.region-depth-dungeon-manager', [
            'dungeons' => $dungeons,
            'dungeonMetrics' => [
                'summaries' => $this->analyticsForDungeons($dungeons->pluck('key')->all()),
                'run_history' => $this->recentRunsForDungeons($dungeons->pluck('key')->all())
                    ->map(fn (CharacterRegionDungeonRun $run) => [
                        'entered_at' => $run->entered_at?->format('m/d H:i'),
                        'character_name' => $run->character?->name ?? '削除済み冒険者',
                        'dungeon_key' => $run->dungeon_key,
                        'status' => $run->status,
                        'max_danger_rate' => (int) $run->max_danger_rate,
                        'max_chain_count' => (int) $run->max_chain_count,
                        'total_exp' => (int) $run->total_exp,
                        'total_job_exp' => (int) $run->total_job_exp,
                    ])->all(),
            ],
            'cities' => City::orderBy('sort_order')->get(),
            'areas' => Area::query()->with('city')->whereHas('enemies', fn ($query) => $query->where('is_boss', false))->orderBy('city_id')->orderBy('sort_order')->get(),
        ])->layout('components.layouts.admin');
    }

    private function analyticsForDungeons(array $dungeonKeys): array
    {
        $keys = array_values(array_filter($dungeonKeys));
        if ($keys === [] || !Schema::hasTable('character_region_dungeon_runs')) {
            return [];
        }

        $rows = DB::table('character_region_dungeon_runs')
            ->whereIn('dungeon_key', $keys)
            ->selectRaw(
                "dungeon_key,
                COUNT(*) as total_entries,
                COUNT(DISTINCT character_id) as unique_challengers,
                SUM(CASE WHEN entered_at >= ? THEN 1 ELSE 0 END) as entries_24h,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_runs,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_runs,
                SUM(CASE WHEN status = 'defeated' THEN 1 ELSE 0 END) as defeated_runs,
                AVG(CASE WHEN status <> 'active' THEN max_danger_rate END) as average_max_danger,
                AVG(CASE WHEN status <> 'active' THEN max_chain_count END) as average_chain_count,
                AVG(CASE WHEN status <> 'active' THEN total_exp END) as average_total_exp,
                AVG(CASE WHEN status <> 'active' THEN total_job_exp END) as average_total_job_exp",
                [now()->subDay()]
            )
            ->groupBy('dungeon_key')
            ->get();

        return $rows->mapWithKeys(function ($row) {
            $returned = (int) ($row->returned_runs ?? 0);
            $defeated = (int) ($row->defeated_runs ?? 0);
            $finished = $returned + $defeated;

            return [(string) $row->dungeon_key => [
                'total_entries' => (int) ($row->total_entries ?? 0),
                'unique_challengers' => (int) ($row->unique_challengers ?? 0),
                'entries_24h' => (int) ($row->entries_24h ?? 0),
                'active_runs' => (int) ($row->active_runs ?? 0),
                'returned_runs' => $returned,
                'defeated_runs' => $defeated,
                'defeat_rate' => $finished > 0 ? round($defeated / $finished * 100, 1) : null,
                'average_max_danger' => $finished > 0 ? round((float) ($row->average_max_danger ?? 0), 1) : null,
                'average_chain_count' => $finished > 0 ? round((float) ($row->average_chain_count ?? 0), 1) : null,
                'average_total_exp' => $finished > 0 ? round((float) ($row->average_total_exp ?? 0)) : null,
                'average_total_job_exp' => $finished > 0 ? round((float) ($row->average_total_job_exp ?? 0), 1) : null,
            ]];
        })->all();
    }

    private function recentRunsForDungeons(array $dungeonKeys)
    {
        $keys = array_values(array_filter($dungeonKeys));
        if ($keys === [] || !Schema::hasTable('character_region_dungeon_runs')) {
            return collect();
        }

        return CharacterRegionDungeonRun::query()
            ->with('character:id,name,icon_path')
            ->whereIn('dungeon_key', $keys)
            ->orderByDesc('entered_at')
            ->limit(15)
            ->get();
    }

    private function rules(): array
    {
        return [
            'form.name' => 'required|string|max:100', 'form.description' => 'nullable|string|max:2000', 'form.city_id' => 'required|exists:cities,id',
            'form.source_area_id' => 'required|exists:areas,id', 'form.baseline_area_id' => 'required|exists:areas,id',
            'form.is_enabled' => 'boolean', 'form.entry_gold' => 'required|integer|min:0|max:999999999', 'form.entry_materials_text' => 'nullable|string|max:2000',
            'form.danger_increase_percent' => 'required|integer|min:0|max:100', 'form.base_job_exp' => 'required|integer|min:0|max:99',
            'form.main_stat_per_danger' => 'required|numeric|min:0|max:1', 'form.hp_per_danger' => 'required|numeric|min:0|max:1', 'form.agi_luk_per_danger' => 'required|numeric|min:0|max:1',
            'form.exp_per_danger' => 'required|numeric|min:0|max:1', 'form.exp_multiplier_cap' => 'required|numeric|min:1|max:100', 'form.job_exp_cap' => 'required|integer|min:0|max:99',
            'form.danger_per_guaranteed_bonus' => 'required|integer|min:1|max:10000', 'form.remainder_percent_divisor' => 'required|integer|min:1|max:10000', 'form.public_log_minimum_danger' => 'required|integer|min:0|max:999999',
        ];
    }

    private function createArea(array $form, Area $source): Area
    {
        $key = $this->newKey((string) $form['name']);
        return Area::create([
            'name' => trim((string) $form['name']), 'slug' => $key, 'description' => trim((string) $form['description']), 'city_id' => (int) $form['city_id'],
            'recommended_level_min' => (int) $source->recommended_level_min, 'recommended_level_max' => (int) $source->recommended_level_max,
            'sort_order' => ((int) Area::where('city_id', (int) $form['city_id'])->max('sort_order')) + 10,
            'unlock_order' => ((int) Area::max('unlock_order')) + 1, 'is_route_area' => false, 'is_published' => true,
        ]);
    }

    private function newKey(string $name): string
    {
        $slug = Str::slug($name);
        return 'region-depth-' . ($slug !== '' ? $slug . '-' : '') . Str::lower(Str::random(8));
    }

    private function parseMaterials(string $text): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') continue;
            [$code, $quantity] = array_pad(preg_split('/[,\s]+/', trim($line), 2), 2, null);
            if (!$code || !ctype_digit((string) $quantity) || (int) $quantity < 1) throw new \RuntimeException('入場素材は「素材コード 数量」を1行ずつ入力してください。');
            $entries[] = ['code' => $code, 'quantity' => (int) $quantity];
        }

        return $entries;
    }

    private function materialsToText(array $materials): string
    {
        return collect($materials)->map(fn (array $entry) => ($entry['code'] ?? '') . ' ' . ($entry['quantity'] ?? 0))->implode("\n");
    }
}
