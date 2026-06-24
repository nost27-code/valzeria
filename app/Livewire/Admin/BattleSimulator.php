<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\Enemy;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class BattleSimulator extends Component
{
    public string $characterSearch = '';
    public string $enemySearch = '';
    public ?int $selectedCharacterId = null;
    public ?int $selectedEnemyId = null;
    public int $simulationCount = 20;
    public bool $startWithFullHp = true;

    public array $summary = [];
    public array $runs = [];
    public array $sampleLogs = [];

    public function selectCharacter(int $characterId): void
    {
        $this->selectedCharacterId = $characterId;
        $this->summary = [];
        $this->runs = [];
        $this->sampleLogs = [];
    }

    public function selectEnemy(int $enemyId): void
    {
        $this->selectedEnemyId = $enemyId;
        $this->summary = [];
        $this->runs = [];
        $this->sampleLogs = [];
    }

    public function runSimulation(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'selectedEnemyId' => ['required', 'integer', 'exists:enemies,id'],
            'simulationCount' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = [];
        $sampleLogs = [];

        for ($i = 1; $i <= $this->simulationCount; $i++) {
            $result = $this->simulateOnce();
            $runs[] = [
                'index' => $i,
                'result' => $result->result,
                'player_hp_after' => $result->playerHpAfter,
                'player_mp_after' => $result->playerMpAfter,
                'exp' => $result->exp,
                'gold' => $result->gold,
                'job_exp' => $result->jobExp,
                'turns' => $this->turnCount($result->logs),
            ];

            if ($sampleLogs === []) {
                $sampleLogs = $this->cleanLogs($result->logs);
            }
        }

        $this->runs = $runs;
        $this->sampleLogs = $sampleLogs;
        $this->summary = $this->summarizeRuns($runs);
    }

    public function render()
    {
        $statusService = app(CharacterStatusService::class);

        $character = $this->selectedCharacterId
            ? Character::with(['user', 'currentJob', 'currentCity', 'characterItems.item'])->find($this->selectedCharacterId)
            : null;
        $enemy = $this->selectedEnemyId
            ? Enemy::with('area.city')->find($this->selectedEnemyId)
            : null;

        return view('livewire.admin.battle-simulator', [
            'characterCandidates' => $this->characterCandidates(),
            'enemyCandidates' => $this->enemyCandidates(),
            'selectedCharacter' => $character,
            'selectedEnemy' => $enemy,
            'selectedCharacterStats' => $character ? $statusService->getFinalStats($character) : null,
        ])->layout('components.layouts.admin');
    }

    private function simulateOnce()
    {
        DB::beginTransaction();

        try {
            $character = Character::with(['currentJob.skill', 'jobHistories', 'characterItems.item'])
                ->lockForUpdate()
                ->findOrFail($this->selectedCharacterId);
            $enemy = Enemy::with('area')->findOrFail($this->selectedEnemyId);

            if ($this->startWithFullHp) {
                $stats = app(CharacterStatusService::class)->getFinalStats($character);
                $character->current_hp = $stats['max_hp'] ?? $character->current_hp;
                $character->current_mp = $stats['max_mp'] ?? $character->current_mp;
                $character->save();
            }

            $result = app(BattleService::class)->executeBattle($character, $enemy);
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    private function characterCandidates(): Collection
    {
        $query = Character::query()
            ->with(['user', 'currentJob', 'currentCity'])
            ->orderByDesc('level')
            ->orderByDesc('exp')
            ->limit(20);

        if ($this->characterSearch !== '') {
            $search = trim($this->characterSearch);
            $query->where(function ($characterQuery) use ($search) {
                $characterQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('id', $search)
                    ->orWhere('user_id', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', '%' . $search . '%')
                            ->orWhere('id', $search);
                    });
            });
        }

        return $query->get();
    }

    private function enemyCandidates(): Collection
    {
        $query = Enemy::query()
            ->with('area.city')
            ->orderBy('level')
            ->orderBy('id')
            ->limit(30);

        if ($this->enemySearch !== '') {
            $search = trim($this->enemySearch);
            $query->where(function ($enemyQuery) use ($search) {
                $enemyQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('id', $search)
                    ->orWhereHas('area', fn ($areaQuery) => $areaQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        return $query->get();
    }

    private function summarizeRuns(array $runs): array
    {
        $total = count($runs);
        $wins = collect($runs)->where('result', 'victory')->count();
        $defeats = collect($runs)->where('result', 'defeat')->count();
        $timeouts = collect($runs)->where('result', 'timeout')->count();

        return [
            'total' => $total,
            'wins' => $wins,
            'defeats' => $defeats,
            'timeouts' => $timeouts,
            'win_rate' => $this->percent($wins, $total),
            'defeat_rate' => $this->percent($defeats, $total),
            'avg_hp_after' => $total > 0 ? round(collect($runs)->avg('player_hp_after'), 1) : 0,
            'avg_mp_after' => $total > 0 ? round(collect($runs)->avg('player_mp_after'), 1) : 0,
            'avg_turns' => $total > 0 ? round(collect($runs)->avg('turns'), 1) : 0,
            'avg_exp' => $total > 0 ? round(collect($runs)->avg('exp'), 1) : 0,
            'avg_job_exp' => $total > 0 ? round(collect($runs)->avg('job_exp'), 1) : 0,
        ];
    }

    private function percent(int $value, int $total): float
    {
        return $total > 0 ? round($value / $total * 100, 1) : 0.0;
    }

    private function turnCount(array $logs): int
    {
        return collect($logs)
            ->filter(fn (string $log): bool => str_contains($log, '--- ターン'))
            ->count();
    }

    private function cleanLogs(array $logs): array
    {
        return collect($logs)
            ->map(fn (string $log): string => trim(strip_tags(str_replace('<br>', "\n", $log))))
            ->filter()
            ->values()
            ->all();
    }
}
