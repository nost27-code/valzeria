<?php

namespace App\Livewire\Admin;

use App\Models\JobClass;
use App\Models\JobRequirement;
use App\Support\JobRankCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class JobManager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $rankFilter = 'all';
    public ?int $editingJobId = null;
    public int $perPage = 30;

    public array $form = [];
    public array $requirementRows = [];

    private array $defaults = [
        'key' => '',
        'name' => '',
        'rank' => 'normal',
        'category' => '',
        'description' => '',
        'max_job_level' => 10,
        'hp_rate' => 100,
        'mp_rate' => 100,
        'atk_rate' => 100,
        'def_rate' => 100,
        'mag_rate' => 100,
        'spr_rate' => 100,
        'spd_rate' => 100,
        'luck_rate' => 100,
        'bonus_hp' => 0,
        'bonus_mp' => 0,
        'bonus_str' => 0,
        'bonus_def' => 0,
        'bonus_mag' => 0,
        'bonus_spr' => 0,
        'bonus_spd' => 0,
        'bonus_luk' => 0,
        'bonus_gold_rate' => 0,
        'bonus_drop_rate' => 0,
        'bonus_critical_rate' => 0,
        'special_skill_rate' => 0,
        'is_hidden' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function createNew(string $rank = 'normal'): void
    {
        $this->resetForm();
        $this->form['rank'] = in_array($rank, JobRankCatalog::keys(), true) ? $rank : 'normal';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRankFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function addRequirement(): void
    {
        $this->requirementRows[] = $this->emptyRequirementRow();
    }

    public function removeRequirement(int $index): void
    {
        unset($this->requirementRows[$index]);
        $this->requirementRows = array_values($this->requirementRows);
    }

    public function edit(int $jobId): void
    {
        $job = JobClass::with('requirements.requiredJob')->findOrFail($jobId);
        $this->editingJobId = $job->id;

        $this->form = array_merge($this->defaults, [
            'key' => $job->key,
            'name' => $job->name,
            'rank' => $job->rank,
            'category' => $job->category ?? '',
            'description' => $job->description ?? '',
            'max_job_level' => (int) $job->max_job_level,
            'hp_rate' => (int) $job->hp_rate,
            'mp_rate' => (int) $job->mp_rate,
            'atk_rate' => (int) $job->atk_rate,
            'def_rate' => (int) $job->def_rate,
            'mag_rate' => (int) $job->mag_rate,
            'spr_rate' => (int) $job->spr_rate,
            'spd_rate' => (int) $job->spd_rate,
            'luck_rate' => (int) $job->luck_rate,
            'bonus_hp' => (int) ($job->bonus_hp ?? 0),
            'bonus_mp' => (int) ($job->bonus_mp ?? 0),
            'bonus_str' => (int) ($job->bonus_str ?? 0),
            'bonus_def' => (int) ($job->bonus_def ?? 0),
            'bonus_mag' => (int) ($job->bonus_mag ?? 0),
            'bonus_spr' => (int) ($job->bonus_spr ?? 0),
            'bonus_spd' => (int) ($job->bonus_spd ?? 0),
            'bonus_luk' => (int) ($job->bonus_luk ?? 0),
            'bonus_gold_rate' => (int) ($job->bonus_gold_rate ?? 0),
            'bonus_drop_rate' => (int) ($job->bonus_drop_rate ?? 0),
            'bonus_critical_rate' => (int) ($job->bonus_critical_rate ?? 0),
            'special_skill_rate' => (int) ($job->special_skill_rate ?? 0),
            'is_hidden' => (bool) $job->is_hidden,
            'is_active' => (bool) $job->is_active,
            'sort_order' => (int) $job->sort_order,
        ]);

        $this->requirementRows = $job->requirements
            ->map(fn (JobRequirement $requirement) => [
                'requirement_type' => $requirement->requirement_type,
                'required_job_id' => $requirement->required_job_id ? (string) $requirement->required_job_id : '',
                'required_value' => $requirement->required_value !== null ? (string) $requirement->required_value : '',
                'required_key' => $requirement->required_key ?? '',
            ])
            ->values()
            ->toArray();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_\\-]+$/',
                Rule::unique('job_classes', 'key')->ignore($this->editingJobId),
            ],
            'form.name' => 'required|string|max:100',
            'form.rank' => ['required', Rule::in(JobRankCatalog::keys())],
            'form.category' => 'nullable|string|max:100',
            'form.description' => 'nullable|string|max:1000',
            'form.max_job_level' => 'required|integer|min:10|max:10',
            'form.hp_rate' => 'required|integer|min:0|max:9999',
            'form.mp_rate' => 'required|integer|min:0|max:9999',
            'form.atk_rate' => 'required|integer|min:0|max:9999',
            'form.def_rate' => 'required|integer|min:0|max:9999',
            'form.mag_rate' => 'required|integer|min:0|max:9999',
            'form.spr_rate' => 'required|integer|min:0|max:9999',
            'form.spd_rate' => 'required|integer|min:0|max:9999',
            'form.luck_rate' => 'required|integer|min:0|max:9999',
            'form.bonus_hp' => 'required|integer|min:0|max:999999',
            'form.bonus_mp' => 'required|integer|min:0|max:999999',
            'form.bonus_str' => 'required|integer|min:0|max:999999',
            'form.bonus_def' => 'required|integer|min:0|max:999999',
            'form.bonus_mag' => 'required|integer|min:0|max:999999',
            'form.bonus_spr' => 'required|integer|min:0|max:999999',
            'form.bonus_spd' => 'required|integer|min:0|max:999999',
            'form.bonus_luk' => 'required|integer|min:0|max:999999',
            'form.bonus_gold_rate' => 'required|integer|min:0|max:9999',
            'form.bonus_drop_rate' => 'required|integer|min:0|max:9999',
            'form.bonus_critical_rate' => 'required|integer|min:0|max:9999',
            'form.special_skill_rate' => 'required|integer|min:0|max:100',
            'form.is_hidden' => 'boolean',
            'form.is_active' => 'boolean',
            'form.sort_order' => 'required|integer|min:0|max:999999',
            'requirementRows' => 'array',
            'requirementRows.*.requirement_type' => 'required|in:master_job,character_level,title,item,quest,event_flag',
            'requirementRows.*.required_job_id' => 'nullable',
            'requirementRows.*.required_value' => 'nullable',
            'requirementRows.*.required_key' => 'nullable|string|max:100',
        ]);

        $jobData = $validated['form'];
        $jobData['category'] = $jobData['category'] !== '' ? $jobData['category'] : null;
        $jobData['description'] = $jobData['description'] !== '' ? $jobData['description'] : null;
        $jobData['is_hidden'] = (bool) $jobData['is_hidden'];
        $jobData['is_active'] = (bool) $jobData['is_active'];

        DB::transaction(function () use ($jobData, $validated) {
            $job = $this->editingJobId
                ? tap(JobClass::findOrFail($this->editingJobId))->update($jobData)
                : JobClass::create($jobData);

            $this->syncRequirements($job, $validated['requirementRows'] ?? []);

            $this->editingJobId = $job->id;
        });

        session()->flash('message', '職業設定を保存しました。');
        $this->edit($this->editingJobId);
    }

    public function toggleActive(int $jobId): void
    {
        $job = JobClass::findOrFail($jobId);
        $job->is_active = !$job->is_active;
        $job->save();

        session()->flash('message', "{$job->name} の公開状態を変更しました。");
    }

    public function resetForm(): void
    {
        $this->editingJobId = null;
        $this->form = $this->defaults;
        $this->requirementRows = [];
    }

    private function syncRequirements(JobClass $job, array $rows): void
    {
        $job->requirements()->delete();

        foreach ($rows as $row) {
            $type = $row['requirement_type'];
            $requiredJobId = $type === 'master_job' && $row['required_job_id'] !== ''
                ? (int) $row['required_job_id']
                : null;

            if ($requiredJobId === $job->id) {
                continue;
            }

            $requiredValue = in_array($type, ['character_level', 'item'], true) && $row['required_value'] !== ''
                ? (int) $row['required_value']
                : null;
            $requiredKey = in_array($type, ['title', 'quest', 'event_flag', 'item'], true) && $row['required_key'] !== ''
                ? $row['required_key']
                : null;

            if ($type === 'master_job' && !$requiredJobId) {
                continue;
            }
            if ($type === 'character_level' && !$requiredValue) {
                continue;
            }

            $job->requirements()->create([
                'requirement_type' => $type,
                'required_job_id' => $requiredJobId,
                'required_value' => $requiredValue,
                'required_key' => $requiredKey,
            ]);
        }
    }

    private function emptyRequirementRow(): array
    {
        return [
            'requirement_type' => 'master_job',
            'required_job_id' => '',
            'required_value' => '',
            'required_key' => '',
        ];
    }

    public function render()
    {
        $jobsQuery = JobClass::with('requirements.requiredJob')
            ->when($this->rankFilter !== 'all', fn ($q) => $q->where('rank', $this->rankFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('key', 'like', '%' . $this->search . '%');
            }));

        $jobs = JobRankCatalog::orderByRank($jobsQuery)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.admin.job-manager', [
            'jobs' => $jobs,
            'allJobs' => JobRankCatalog::orderByRank(JobClass::query())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'key', 'name', 'rank']),
            'rankLabels' => JobRankCatalog::rankOptions(),
        ])->layout('components.layouts.admin');
    }
}
