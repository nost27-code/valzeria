<?php

namespace App\Livewire\Admin;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Services\Admin\SixHeroBattleSimulatorService;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Services\PvPBattleService;
use App\Support\SixHeroRoomUiCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

final class SixHeroBattleSimulator extends Component
{
    public string $attackerSearch = '';

    public string $defenderSearch = '';

    public ?int $selectedAttackerId = null;

    public ?int $selectedDefenderId = null;

    public string $selectedRoomKey = SixHeroRoomKey::SEAL_MAGIC->value;

    public int $simulationCount = 20;

    /** @var array<string, int|float> */
    public array $summary = [];

    /** @var list<array<string, int|string|float>> */
    public array $runs = [];

    /** @var list<string> */
    public array $sampleLogs = [];

    public function boot(): void
    {
        abort_unless(
            Auth::check() && Auth::user()?->role === 'admin',
            403,
        );
    }

    public function selectAttacker(int $characterId): void
    {
        $this->selectedAttackerId = $characterId;
        $this->clearResults();
    }

    public function selectDefender(int $characterId): void
    {
        $this->selectedDefenderId = $characterId;
        $this->clearResults();
    }

    public function selectRoom(string $roomKey): void
    {
        $room = SixHeroRoomKey::tryFrom($roomKey);
        abort_if($room === null, 422);

        $this->selectedRoomKey = $room->value;
        $this->clearResults();
    }

    public function swapCombatants(): void
    {
        [$this->selectedAttackerId, $this->selectedDefenderId] = [
            $this->selectedDefenderId,
            $this->selectedAttackerId,
        ];
        $this->clearResults();
    }

    public function runSimulation(SixHeroBattleSimulatorService $simulator): void
    {
        $validated = $this->validate([
            'selectedAttackerId' => ['required', 'integer', 'exists:characters,id'],
            'selectedDefenderId' => [
                'required',
                'integer',
                'different:selectedAttackerId',
                'exists:characters,id',
            ],
            'selectedRoomKey' => ['required', Rule::enum(SixHeroRoomKey::class)],
            'simulationCount' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'selectedDefenderId.different' => '挑戦側と防衛側には別のキャラクターを選択してください。',
        ]);

        $room = SixHeroRoomKey::from($validated['selectedRoomKey']);
        $attacker = $this->battleCharacter((int) $validated['selectedAttackerId']);
        $defender = $this->battleCharacter((int) $validated['selectedDefenderId']);
        $runs = [];
        $sampleLogs = [];

        try {
            for ($index = 1; $index <= $this->simulationCount; $index++) {
                $resolution = $simulator
                    ->simulate($room, $attacker, $defender)
                    ->resolution;
                $runs[] = [
                    'index' => $index,
                    'winner' => $resolution->attackerWon ? '挑戦側' : '防衛側',
                    'turns' => $resolution->turnCount,
                    'attacker_hp' => $resolution->attackerHp,
                    'attacker_hp_rate' => $this->percent(
                        $resolution->attackerHp,
                        $resolution->attackerMaxHp,
                    ),
                    'defender_hp' => $resolution->defenderHp,
                    'defender_hp_rate' => $this->percent(
                        $resolution->defenderHp,
                        $resolution->defenderMaxHp,
                    ),
                    'attacker_won' => $resolution->attackerWon ? 1 : 0,
                ];

                if ($sampleLogs === []) {
                    $sampleLogs = $this->cleanLogs($resolution->result->logs);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->clearResults();
            $this->addError(
                'simulation',
                'シミュレーションを完了できませんでした。ログを確認してください。',
            );

            return;
        }

        $this->runs = $runs;
        $this->sampleLogs = $sampleLogs;
        $this->summary = $this->summarizeRuns($runs);
    }

    public function render(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
    ) {
        $attacker = $this->selectedCharacter($this->selectedAttackerId);
        $defender = $this->selectedCharacter($this->selectedDefenderId);
        $room = SixHeroRoomKey::tryFrom($this->selectedRoomKey);
        abort_if($room === null, 422);

        return view('livewire.admin.six-hero-battle-simulator', [
            'attackerCandidates' => $this->characterCandidates($this->attackerSearch),
            'defenderCandidates' => $this->characterCandidates($this->defenderSearch),
            'selectedAttacker' => $attacker,
            'selectedDefender' => $defender,
            'attackerStats' => $attacker ? $statusService->getFinalStats($attacker) : null,
            'defenderStats' => $defender ? $statusService->getFinalStats($defender) : null,
            'rooms' => collect(SixHeroRoomKey::cases())->map(
                static fn (SixHeroRoomKey $candidate): array => [
                    'key' => $candidate->value,
                    'label' => $candidate->label(),
                    'description' => SixHeroRoomUiCatalog::description($candidate),
                ],
            ),
            'selectedRoom' => $room,
            'selectedRoomRule' => SixHeroRoomUiCatalog::ruleGuide($room),
            'formula' => $damageCalculator->rankBattleFormulaParameters(),
            'normalAttackPower' => PvPBattleService::PVP_NORMAL_POWER_MULTIPLIER,
        ]);
    }

    private function battleCharacter(int $characterId): Character
    {
        return Character::query()
            ->with([
                'currentJob.skill',
                'jobHistories',
                'jobArtSlots.skill',
                'characterItems.item',
                'characterItems.affixPrefix',
                'characterItems.affixSuffix',
            ])
            ->findOrFail($characterId);
    }

    private function selectedCharacter(?int $characterId): ?Character
    {
        if ($characterId === null) {
            return null;
        }

        return Character::query()
            ->with([
                'user',
                'currentJob',
                'characterItems.item',
                'characterItems.affixPrefix',
                'characterItems.affixSuffix',
            ])
            ->find($characterId);
    }

    /** @return Collection<int, Character> */
    private function characterCandidates(string $search): Collection
    {
        return Character::query()
            ->with(['user', 'currentJob'])
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $search = trim($search);
                $query->where(function (Builder $candidateQuery) use ($search): void {
                    $candidateQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('id', $search)
                        ->orWhere('user_id', $search)
                        ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                            $userQuery
                                ->where('email', 'like', '%'.$search.'%')
                                ->orWhere('id', $search);
                        });
                });
            })
            ->orderByDesc('level')
            ->orderByDesc('exp')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /**
     * @param  list<array<string, int|string|float>>  $runs
     * @return array<string, int|float>
     */
    private function summarizeRuns(array $runs): array
    {
        $collection = collect($runs);
        $total = $collection->count();
        $attackerWins = (int) $collection->sum('attacker_won');

        return [
            'total' => $total,
            'attacker_wins' => $attackerWins,
            'defender_wins' => $total - $attackerWins,
            'attacker_win_rate' => $this->percent($attackerWins, $total),
            'defender_win_rate' => $this->percent($total - $attackerWins, $total),
            'avg_turns' => round((float) $collection->avg('turns'), 1),
            'avg_attacker_hp_rate' => round((float) $collection->avg('attacker_hp_rate'), 1),
            'avg_defender_hp_rate' => round((float) $collection->avg('defender_hp_rate'), 1),
        ];
    }

    private function percent(int $value, int $total): float
    {
        return $total > 0 ? round($value / $total * 100, 1) : 0.0;
    }

    /** @param list<string> $logs @return list<string> */
    private function cleanLogs(array $logs): array
    {
        return collect($logs)
            ->map(static function (string $log): string {
                $plain = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $log);

                return trim(html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5));
            })
            ->filter()
            ->values()
            ->all();
    }

    private function clearResults(): void
    {
        $this->summary = [];
        $this->runs = [];
        $this->sampleLogs = [];
        $this->resetErrorBag('simulation');
    }
}
