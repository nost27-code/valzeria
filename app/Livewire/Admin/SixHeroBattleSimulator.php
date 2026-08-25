<?php

namespace App\Livewire\Admin;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Services\Admin\SixHeroBattleSimulatorService;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Support\SixHeroCompetitionRules;
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
        $metricSamples = $this->emptyMetricSamples();

        try {
            foreach ([
                ['key' => 'a_to_b', 'label' => 'A→B', 'attacker' => $attacker, 'defender' => $defender, 'a_is_attacker' => true],
                ['key' => 'b_to_a', 'label' => 'B→A', 'attacker' => $defender, 'defender' => $attacker, 'a_is_attacker' => false],
            ] as $direction) {
                for ($index = 1; $index <= $this->simulationCount; $index++) {
                    $resolution = $simulator
                        ->simulate($room, $direction['attacker'], $direction['defender'])
                        ->resolution;
                    $aWon = $direction['a_is_attacker']
                        ? $resolution->attackerWon
                        : ! $resolution->attackerWon;
                    $aHp = $direction['a_is_attacker'] ? $resolution->attackerHp : $resolution->defenderHp;
                    $aMaxHp = $direction['a_is_attacker'] ? $resolution->attackerMaxHp : $resolution->defenderMaxHp;
                    $bHp = $direction['a_is_attacker'] ? $resolution->defenderHp : $resolution->attackerHp;
                    $bMaxHp = $direction['a_is_attacker'] ? $resolution->defenderMaxHp : $resolution->attackerMaxHp;
                    $aMetrics = $direction['a_is_attacker'] ? $resolution->attackerMetrics : $resolution->defenderMetrics;
                    $bMetrics = $direction['a_is_attacker'] ? $resolution->defenderMetrics : $resolution->attackerMetrics;
                    $this->mergeMetricSamples($metricSamples['a'], $aMetrics);
                    $this->mergeMetricSamples($metricSamples['b'], $bMetrics);

                    $runs[] = [
                        'index' => $index,
                        'direction' => $direction['label'],
                        'direction_key' => $direction['key'],
                        'winner' => $aWon ? 'A' : 'B',
                        'turns' => $resolution->turnCount,
                        'a_hp' => $aHp,
                        'a_max_hp' => $aMaxHp,
                        'a_hp_rate' => $this->percent($aHp, $aMaxHp),
                        'b_hp' => $bHp,
                        'b_max_hp' => $bMaxHp,
                        'b_hp_rate' => $this->percent($bHp, $bMaxHp),
                        'a_won' => $aWon ? 1 : 0,
                    ];

                    if ($sampleLogs === []) {
                        $sampleLogs = $this->cleanLogs($resolution->result->logs);
                    }
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
        $this->summary = $this->summarizeRuns($runs, $metricSamples);
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
            'baseDamageMultiplier' => SixHeroCompetitionRules::BASE_DAMAGE_MULTIPLIER,
            'normalAttackPower' => SixHeroCompetitionRules::NORMAL_ATTACK_POWER,
            'speedBreakthroughEnabled' => config('battle.speed_breakthrough.enabled') === true,
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
     * @param  array{a: array<string, mixed>, b: array<string, mixed>}  $metricSamples
     * @return array<string, int|float>
     */
    private function summarizeRuns(array $runs, array $metricSamples): array
    {
        $collection = collect($runs);
        $total = $collection->count();
        $aWins = (int) $collection->sum('a_won');
        $aToB = $collection->where('direction_key', 'a_to_b');
        $bToA = $collection->where('direction_key', 'b_to_a');
        $aToBRate = $this->percent((int) $aToB->sum('a_won'), $aToB->count());
        $bToARate = $this->percent((int) $bToA->sum('a_won'), $bToA->count());
        $aSummary = $this->summarizeCombatantMetrics($metricSamples['a'], $runs, 'a');
        $bSummary = $this->summarizeCombatantMetrics($metricSamples['b'], $runs, 'b');
        $aOutput = $aSummary['damage_per_action'] * $aSummary['avg_actions'];
        $bOutput = $bSummary['damage_per_action'] * $bSummary['avg_actions'];
        $hpRatio = $bSummary['avg_max_hp'] > 0
            ? $aSummary['avg_max_hp'] / $bSummary['avg_max_hp']
            : 0.0;
        $aBalance = $bOutput > 0 && $hpRatio > 0
            ? ($aOutput / $bOutput) / $hpRatio
            : 0.0;

        return array_merge([
            'total' => $total,
            'per_direction' => $aToB->count(),
            'a_wins' => $aWins,
            'b_wins' => $total - $aWins,
            'a_to_b_a_win_rate' => $aToBRate,
            'b_to_a_a_win_rate' => $bToARate,
            'bidirectional_a_win_rate' => round(($aToBRate + $bToARate) / 2, 1),
            'avg_turns' => round((float) $collection->avg('turns'), 1),
            'a_balance' => round($aBalance, 3),
            'b_balance' => $aBalance > 0 ? round(1 / $aBalance, 3) : 0.0,
        ], $this->prefixSummary('a', $aSummary), $this->prefixSummary('b', $bSummary));
    }

    /** @return array{a: array<string, mixed>, b: array<string, mixed>} */
    private function emptyMetricSamples(): array
    {
        $side = [
            'action_count' => 0,
            'extra_action_count' => 0,
            'normal_damage' => [],
            'skill_damage' => [],
            'nominal_rate' => [],
            'existing_ignore_rate' => [],
            'combined_ignore_rate' => [],
            'additional_ignore_rate' => [],
        ];

        return ['a' => $side, 'b' => $side];
    }

    /** @param array<string, mixed> $samples @param array<string, mixed> $metrics */
    private function mergeMetricSamples(array &$samples, array $metrics): void
    {
        $samples['action_count'] += (int) ($metrics['action_count'] ?? 0);
        $samples['extra_action_count'] += (int) ($metrics['extra_action_count'] ?? 0);
        $samples['normal_damage'] = array_merge($samples['normal_damage'], (array) ($metrics['normal_damage'] ?? []));
        $samples['skill_damage'] = array_merge($samples['skill_damage'], (array) ($metrics['skill_damage'] ?? []));
        foreach ((array) ($metrics['speed_rates'] ?? []) as $rates) {
            foreach (['nominal_rate', 'existing_ignore_rate', 'combined_ignore_rate', 'additional_ignore_rate'] as $key) {
                $samples[$key][] = (float) ($rates[$key] ?? 0.0);
            }
        }
    }

    /**
     * @param array<string, mixed> $samples
     * @param list<array<string, int|string|float>> $runs
     * @return array<string, int|float>
     */
    private function summarizeCombatantMetrics(array $samples, array $runs, string $side): array
    {
        $battleCount = count($runs);
        $normalDamage = (array) $samples['normal_damage'];
        $skillDamage = (array) $samples['skill_damage'];
        $totalDamage = array_sum($normalDamage) + array_sum($skillDamage);
        $actionCount = (int) $samples['action_count'];

        return [
            'avg_actions' => $battleCount > 0 ? round($actionCount / $battleCount, 2) : 0.0,
            'avg_extra_actions' => $battleCount > 0 ? round((int) $samples['extra_action_count'] / $battleCount, 2) : 0.0,
            'nominal_rate' => round($this->average((array) $samples['nominal_rate']) * 100, 1),
            'existing_ignore_rate' => round($this->average((array) $samples['existing_ignore_rate']) * 100, 1),
            'combined_ignore_rate' => round($this->average((array) $samples['combined_ignore_rate']) * 100, 1),
            'additional_ignore_rate' => round($this->average((array) $samples['additional_ignore_rate']) * 100, 1),
            'normal_damage_avg' => round($this->average($normalDamage), 1),
            'normal_damage_median' => round($this->median($normalDamage), 1),
            'skill_damage_avg' => round($this->average($skillDamage), 1),
            'skill_damage_median' => round($this->median($skillDamage), 1),
            'final_hp_avg' => round($this->average(array_column($runs, $side.'_hp')), 1),
            'final_hp_median' => round($this->median(array_column($runs, $side.'_hp')), 1),
            'final_hp_rate_avg' => round($this->average(array_column($runs, $side.'_hp_rate')), 1),
            'avg_max_hp' => $this->average(array_column($runs, $side.'_max_hp')),
            'damage_per_action' => $actionCount > 0 ? $totalDamage / $actionCount : 0.0,
        ];
    }

    /** @param array<string, int|float> $summary @return array<string, int|float> */
    private function prefixSummary(string $prefix, array $summary): array
    {
        $prefixed = [];
        foreach ($summary as $key => $value) {
            $prefixed[$prefix.'_'.$key] = $value;
        }

        return $prefixed;
    }

    /** @param list<int|float> $values */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param list<int|float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
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
