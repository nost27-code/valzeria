<?php

namespace App\Livewire\Admin;

use App\Models\City;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\Skill;
use App\Services\Admin\SkillEffectPreviewService;
use App\Support\JobRankCatalog;
use Illuminate\Support\Collection;
use Livewire\Component;

class SkillEffectLab extends Component
{
    public ?int $selectedJobId = null;
    public ?int $selectedCityId = null;
    public ?int $selectedEnemyId = null;

    /** @var array<string, int> */
    public array $stats = [
        'max_hp' => 1200,
        'max_mp' => 120,
        'str' => 180,
        'def' => 140,
        'agi' => 120,
        'mag' => 160,
        'spr' => 130,
        'luk' => 80,
    ];

    /** @var array<int, string|null> */
    public array $selectedSkillIds = [null, null, null];

    /** @var array<string, mixed> */
    public array $result = [];

    public function mount(): void
    {
        $this->selectedJobId = JobClass::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        $this->selectedCityId = City::query()
            ->whereHas('areas.enemies')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        $this->selectedEnemyId = $this->enemyOptions()->first()?->id;
    }

    public function updated($name): void
    {
        if ($name === 'selectedCityId') {
            $this->selectedEnemyId = $this->enemyOptions()->first()?->id;
        }

        if ($name === 'selectedJobId') {
            $this->selectedSkillIds = [null, null, null];
        }

        $this->result = [];
    }

    public function runPreview(): void
    {
        $this->validate([
            'selectedJobId' => ['required', 'integer', 'exists:job_classes,id'],
            'selectedCityId' => ['required', 'integer', 'exists:cities,id'],
            'selectedEnemyId' => ['required', 'integer', 'exists:enemies,id'],
            'stats.max_hp' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.max_mp' => ['required', 'integer', 'min:0', 'max:9999999'],
            'stats.str' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.def' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.agi' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.mag' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.spr' => ['required', 'integer', 'min:1', 'max:9999999'],
            'stats.luk' => ['required', 'integer', 'min:1', 'max:9999999'],
            'selectedSkillIds.*' => ['nullable', 'integer', 'exists:skills,id'],
        ]);

        $skillIds = collect($this->selectedSkillIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($skillIds->duplicates()->isNotEmpty()) {
            $this->addError('selectedSkillIds', '同じ技は複数枠に指定できません。');
            return;
        }

        $job = JobClass::findOrFail($this->selectedJobId);
        $enemy = Enemy::with('area.city')->findOrFail($this->selectedEnemyId);
        $skills = Skill::with('jobClass')
            ->whereIn('id', $skillIds)
            ->get()
            ->sortBy(fn (Skill $skill): int => $skillIds->search((int) $skill->id))
            ->values();

        $this->result = app(SkillEffectPreviewService::class)->preview($this->stats, $job, $enemy, $skills);
    }

    public function render()
    {
        return view('livewire.admin.skill-effect-lab', [
            'jobs' => $this->jobs(),
            'cities' => $this->cities(),
            'enemyOptions' => $this->enemyOptions(),
            'skillOptions' => $this->skillOptions(),
            'selectedSkillDetails' => $this->selectedSkillDetails(),
            'selectedJob' => $this->selectedJob(),
            'selectedEnemy' => $this->selectedEnemy(),
        ])->layout('components.layouts.admin');
    }

    private function selectedJob(): ?JobClass
    {
        return $this->selectedJobId
            ? JobClass::find($this->selectedJobId)
            : null;
    }

    private function selectedEnemy(): ?Enemy
    {
        return $this->selectedEnemyId
            ? Enemy::with('area.city')->find($this->selectedEnemyId)
            : null;
    }

    private function jobs(): Collection
    {
        return JobClass::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function cities(): Collection
    {
        return City::query()
            ->whereHas('areas.enemies')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function enemyOptions(): Collection
    {
        if (! $this->selectedCityId) {
            return collect();
        }

        return Enemy::query()
            ->with('area.city')
            ->whereHas('area', fn ($query) => $query->where('city_id', $this->selectedCityId))
            ->where(function ($query) {
                $query->where('is_boss', false)
                    ->orWhereNull('is_boss');
            })
            ->orderBy('area_id')
            ->orderBy('level')
            ->orderBy('id')
            ->limit(120)
            ->get();
    }

    private function skillOptions(): Collection
    {
        $jobId = $this->selectedJobId;

        return Skill::query()
            ->with('jobClass')
            ->where(function ($query) use ($jobId) {
                $query->where(function ($specialQuery) use ($jobId) {
                    $specialQuery->where('skill_type', 'special')
                        ->when($jobId, fn ($q) => $q->where('job_id', $jobId));
                })->orWhere(function ($currentArtQuery) use ($jobId) {
                    $currentArtQuery->where('skill_type', 'job_art')
                        ->when($jobId, fn ($q) => $q->where('job_id', $jobId))
                        ->where('pve_enabled', true);
                })->orWhere(function ($artQuery) {
                    $artQuery->where('skill_type', 'job_art')
                        ->where('inherit_on_master', true)
                        ->where('pve_enabled', true);
                });
            })
            ->orderByRaw("case when skill_type = 'special' then 0 when job_id = ? then 1 else 2 end", [$jobId ?? 0])
            ->orderBy('job_id')
            ->orderBy('learn_rank')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function selectedSkillDetails(): Collection
    {
        $skillIds = collect($this->selectedSkillIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($skillIds->isEmpty()) {
            return collect();
        }

        return Skill::query()
            ->with('jobClass')
            ->whereIn('id', $skillIds)
            ->get()
            ->sortBy(fn (Skill $skill): int => $skillIds->search((int) $skill->id))
            ->values()
            ->map(fn (Skill $skill): array => [
                'skill' => $skill,
                'kindLabel' => $this->skillKindLabel($skill),
                'jobChangeIntro' => $this->jobChangeIntro($skill),
                'effectRows' => $this->effectRows($skill),
            ]);
    }

    private function skillKindLabel(Skill $skill): string
    {
        if ($skill->skill_type === 'special') {
            return '必殺技';
        }

        return (int) $skill->job_id === (int) $this->selectedJobId ? '職業奥義' : '継承奥義';
    }

    /**
     * @return array<string, string|null>
     */
    private function jobChangeIntro(Skill $skill): array
    {
        return [
            'body' => $skill->memo ?: ($skill->description ?: '効果説明なし'),
            'phrase' => $skill->activation_phrase ?: null,
            'description' => $skill->activation_description
                ? str_replace(['{user}', '{target}', '{skill}'], ['冒険者', '敵', $skill->name], $skill->activation_description)
                : null,
        ];
    }

    /**
     * @return array<int, array{label:string,value:string}>
     */
    private function effectRows(Skill $skill): array
    {
        $rows = [];

        $this->addEffectRow($rows, '種別', $this->skillKindLabel($skill));
        $this->addEffectRow($rows, '職業', $skill->jobClass ? $this->jobLabel($skill->jobClass) : null);
        $this->addEffectRow($rows, '効果テンプレート', $skill->effect_template);
        $this->addEffectRow($rows, '攻撃タイプ', $skill->damage_type);
        $this->addEffectRow($rows, '威力', $skill->isJobArt() ? (string) ($skill->power ?? '-') : ($skill->power_multiplier !== null ? number_format((float) $skill->power_multiplier, 2) . '倍' : null));
        $this->addEffectRow($rows, 'Hit数', $skill->hit_count !== null ? (string) $skill->hit_count : null);
        $this->addEffectRow($rows, '発動率', $skill->effectiveActivationRate() > 0 ? $skill->effectiveActivationRate() . '%' : null);
        $this->addEffectRow($rows, '継承倍率', $skill->isJobArt() && $skill->inherited_rate !== null ? number_format((float) $skill->inherited_rate, 2) . '倍' : null);
        $this->addEffectRow($rows, 'DEF/SPR無視', (int) $skill->def_ignore_percent > 0 ? $skill->def_ignore_percent . '%' : null);
        $this->addEffectRow($rows, '追加Hit確率', (int) $skill->extra_hit_chance_percent > 0 ? $skill->extra_hit_chance_percent . '%' : null);
        $this->addEffectRow($rows, 'LUK威力加算', (float) $skill->luk_power_rate > 0 ? (string) $skill->luk_power_rate : null);
        $this->addEffectRow($rows, '自己バフ', (int) $skill->self_buff_percent > 0 ? $skill->self_buff_percent . '%' : null);
        $this->addEffectRow($rows, '被ダメ軽減', (int) $skill->damage_reduction_percent > 0 ? $skill->damage_reduction_percent . '%' : null);
        $template = (string) $skill->effect_template;
        $templateHeal = in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)
            ? '精神依存' . ((int) $skill->power > 0 ? '（威力' . (int) $skill->power . '）' : '')
            : null;

        $this->addEffectRow($rows, 'HP回復', (int) $skill->heal_percent > 0 ? $skill->heal_percent . '%' : $templateHeal);
        $this->addEffectRow($rows, 'SP回復', (int) $skill->mp_recover_percent > 0 ? $skill->mp_recover_percent . '%' : null);
        $this->addEffectRow($rows, '反動', (int) $skill->self_damage_percent > 0 ? $skill->self_damage_percent . '%' : null);
        $this->addEffectRow($rows, '吸収', (float) $skill->drain_hp_rate > 0 ? number_format((float) $skill->drain_hp_rate * 100, 1) . '%' : null);
        $this->addEffectRow($rows, '敵ATK低下', (int) $skill->enemy_atk_down_percent > 0 ? $skill->enemy_atk_down_percent . '%' : null);
        $this->addEffectRow($rows, '敵MAG低下', (int) $skill->enemy_mag_down_percent > 0 ? $skill->enemy_mag_down_percent . '%' : null);
        $this->addEffectRow($rows, '敵DEF低下', (int) $skill->enemy_def_down_percent > 0 ? $skill->enemy_def_down_percent . '%' : null);
        $this->addEffectRow($rows, '敵SPR低下', (int) $skill->enemy_spr_down_percent > 0 ? $skill->enemy_spr_down_percent . '%' : null);
        $this->addEffectRow($rows, '敵SPD低下', (int) $skill->enemy_spd_down_percent > 0 ? $skill->enemy_spd_down_percent . '%' : null);
        $this->addEffectRow($rows, 'Gold補正', (int) $skill->gold_bonus_percent > 0 ? $skill->gold_bonus_percent . '%' : null);
        $this->addEffectRow($rows, '素材補正', (int) $skill->drop_bonus_percent > 0 ? $skill->drop_bonus_percent . '%' : null);
        $this->addEffectRow($rows, 'レア補正', (int) $skill->rare_bonus_percent > 0 ? $skill->rare_bonus_percent . '%' : null);

        return $rows;
    }

    /**
     * @param array<int, array{label:string,value:string}> $rows
     */
    private function addEffectRow(array &$rows, string $label, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $rows[] = [
            'label' => $label,
            'value' => (string) $value,
        ];
    }

    private function jobLabel(JobClass $job): string
    {
        return JobRankCatalog::label((string) $job->rank) . ' / ' . $job->name;
    }
}
