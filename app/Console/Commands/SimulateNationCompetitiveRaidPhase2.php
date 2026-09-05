<?php

namespace App\Console\Commands;

use App\Services\Nation\Raid\Simulation\NationRaidReadOnlyDatabaseGuard;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedContextPlan;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationArtifactWriter;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationOperationalMetricsBuilder;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationRunner;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationSnapshotBuilder;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationSnapshotValidator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class SimulateNationCompetitiveRaidPhase2 extends Command
{
    protected $signature = 'nation-raid:simulate-phase2
        {--snapshot= : 既存の匿名snapshot.jsonを再利用する}
        {--snapshot-only : 匿名snapshotと母集団reportだけを生成する}
        {--active-days=7 : Phase 2固定の抽出日数（7以外は拒否）}
        {--profiles=7 : Characterごとの20ターンaction profile数}
        {--resolved-context-plan= : context別の解決済みprofileを生成するreview済みplan JSON}
        {--seeds=1000 : simulation seed数}
        {--seed-start=1 : 開始seed}
        {--strategy=boss_set : boss_set（作戦OFF） / mixed_equal（旧3作戦の比較） / assault / intercept / fortify}
        {--allow-reference-profile : 非正本action profileで参考sweepを明示実行する}
        {--run-label= : storage配下の成果物directory名}';

    protected $description = '国家対抗レイドPhase 2の匿名snapshotを作り、read-only balance simulationを実行する';

    public function handle(
        NationRaidSimulationSnapshotBuilder $builder,
        NationRaidSimulationSnapshotValidator $validator,
        NationRaidSimulationRunner $runner,
        NationRaidReadOnlyDatabaseGuard $readOnly,
        NationRaidResolvedContextPlan $resolvedContextPlan,
        NationRaidSimulationArtifactWriter $writer,
        NationRaidSimulationOperationalMetricsBuilder $operationalMetricsBuilder,
    ): int {
        $paths = null;
        $snapshot = null;
        $validation = null;
        $simulation = null;
        $commandStarted = hrtime(true);
        $snapshotOnly = (bool) $this->option('snapshot-only');
        $seedCount = null;
        $seedStart = null;
        $strategyMode = $this->optionString('strategy') ?: 'boss_set';
        $execution = [
            'outcome' => 'started',
            'snapshot_source' => $this->optionString('snapshot') !== '' ? 'reused_snapshot' : 'database_read_only',
            'resolved_context_plan_provided' => $this->optionString('resolved-context-plan') !== '',
            'snapshot_only' => $snapshotOnly,
            'seed_count' => $seedCount,
            'seed_start' => $seedStart,
            'strategy_mode' => $strategyMode,
            'reference_profile_allowed' => (bool) $this->option('allow-reference-profile'),
        ];
        $timings = [
            'snapshot_load_or_build' => null,
            'snapshot_validation' => null,
            'initial_artifact_write' => null,
            'simulation' => null,
            'final_artifact_write' => null,
            'measured_total_through_artifact_write' => null,
        ];

        try {
            if (! $snapshotOnly) {
                $seedCount = $this->positiveIntOption('seeds');
                $seedStart = $this->positiveIntOption('seed-start');
                $execution['seed_count'] = $seedCount;
                $execution['seed_start'] = $seedStart;
            }
            $snapshot = $this->measure(
                fn (): array => $this->loadOrBuildSnapshot($builder, $readOnly, $resolvedContextPlan),
                $timings,
                'snapshot_load_or_build',
            );
            $execution['balance_gate_authoritative'] = $runner->authoritativeForBalanceGate($snapshot);
            $validation = $this->measure(
                fn (): array => $validator->validate($snapshot),
                $timings,
                'snapshot_validation',
            );
            $runLabel = $this->optionString('run-label');

            if (! $validation['ready']) {
                $paths = $this->measure(
                    fn (): array => $writer->write($snapshot, $validation, null, $runLabel),
                    $timings,
                    'initial_artifact_write',
                );
                $execution['outcome'] = 'snapshot_validation_failed';
                $paths = $this->appendOperationalMetrics(
                    $operationalMetricsBuilder,
                    $writer,
                    $snapshot,
                    $paths,
                    $timings,
                    $execution,
                    $commandStarted,
                );
                $this->error('Snapshot validation failed. Simulation was not started.');
                $this->line('Artifacts: '.$paths['directory']);

                return self::FAILURE;
            }

            if (! $snapshotOnly
                && ! $runner->authoritativeForBalanceGate($snapshot)
                && ! (bool) $this->option('allow-reference-profile')) {
                $paths = $this->measure(
                    fn (): array => $writer->write($snapshot, $validation, null, $runLabel),
                    $timings,
                    'initial_artifact_write',
                );
                $execution['outcome'] = 'reference_profile_opt_in_required';
                $paths = $this->appendOperationalMetrics(
                    $operationalMetricsBuilder,
                    $writer,
                    $snapshot,
                    $paths,
                    $timings,
                    $execution,
                    $commandStarted,
                );
                $this->error('Simulation inputs are reference-only. Simulation was not started without --allow-reference-profile.');
                $this->line('Artifacts: '.$paths['directory']);

                return self::FAILURE;
            }

            // context不足等でsweepが停止しても、高価な正本cacheを回収できるよう先に固定する。
            $paths = $this->measure(
                fn (): array => $writer->write($snapshot, $validation, null, $runLabel),
                $timings,
                'initial_artifact_write',
            );
            $runLabel = basename($paths['directory']);

            if (! $snapshotOnly) {
                $simulation = $this->measure(
                    fn (): array => $runner->run(
                        snapshot: $snapshot,
                        seeds: $seedCount,
                        seedStart: $seedStart,
                        strategyMode: $strategyMode,
                        allowReferenceProfile: (bool) $this->option('allow-reference-profile'),
                    ),
                    $timings,
                    'simulation',
                );
                $simulation['minimum_1000_seed_gate_met'] = $seedCount >= 1_000;
            }

            if ($simulation !== null) {
                $paths = $this->measure(
                    fn (): array => $writer->write($snapshot, $validation, $simulation, $runLabel),
                    $timings,
                    'final_artifact_write',
                );
            }
            $execution['outcome'] = $snapshotOnly ? 'snapshot_only_completed' : 'completed';
            $paths = $this->appendOperationalMetrics(
                $operationalMetricsBuilder,
                $writer,
                $snapshot,
                $paths,
                $timings,
                $execution,
                $commandStarted,
            );
            $this->info('Phase 2 artifacts were generated without DB writes.');
            $this->line('Population: '.($snapshot['population_report']['included_characters'] ?? 0));
            $this->line('Ruleset: '.$snapshot['ruleset_hash']);
            $this->line('Artifacts: '.$paths['directory']);
            $this->line('Operational metrics: '.$paths['operational_metrics']);
            if (! $runner->authoritativeForBalanceGate($snapshot)) {
                $this->warn('Simulation inputs are reference-only. Do not use this run to approve balance.');
            }
            if ($simulation !== null && ($simulation['minimum_1000_seed_gate_met'] ?? false) !== true) {
                $this->warn('This was a smoke run with fewer than 1000 seeds.');
            }
            if ($simulation !== null) {
                $contextMetrics = is_array($simulation['resolved_context_cache_metrics'] ?? null)
                    ? $simulation['resolved_context_cache_metrics']
                    : [];
                $this->line('Context cache generation: '.$this->percentage($contextMetrics['cache_generation_completion_rate'] ?? null));
                $this->line('Context cache hit rate: '.$this->percentage($contextMetrics['runtime_cache_hit_rate'] ?? null));
                $this->line('Context plan utilization: '.$this->percentage($contextMetrics['plan_utilization_rate'] ?? null));
                if (($contextMetrics['cache_operational_gate_met'] ?? false) !== true) {
                    $this->warn('The authoritative context-cache operational gate is not met.');
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            if (is_array($snapshot) && is_array($validation) && is_array($paths)) {
                try {
                    $execution['outcome'] = 'failed_after_artifact_preservation';
                    $paths = $this->appendOperationalMetrics(
                        $operationalMetricsBuilder,
                        $writer,
                        $snapshot,
                        $paths,
                        $timings,
                        $execution,
                        $commandStarted,
                    );
                } catch (Throwable $metricsException) {
                    $this->warn('Operational metrics could not be preserved: '.$metricsException->getMessage());
                }
            }
            if (is_array($paths)) {
                $this->line('Preserved artifacts: '.$paths['directory']);
            }

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function loadOrBuildSnapshot(
        NationRaidSimulationSnapshotBuilder $builder,
        NationRaidReadOnlyDatabaseGuard $readOnly,
        NationRaidResolvedContextPlan $resolvedContextPlan,
    ): array {
        $path = $this->optionString('snapshot');
        if ($path !== '') {
            if ($this->optionString('resolved-context-plan') !== '') {
                throw new RuntimeException('--resolved-context-plan cannot be combined with --snapshot.');
            }
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Snapshot file is not readable.');
            }
            try {
                $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Snapshot file is not valid JSON.', previous: $exception);
            }
            if (! is_array($decoded)) {
                throw new RuntimeException('Snapshot root must be an object.');
            }

            return $decoded;
        }

        $planPath = $this->optionString('resolved-context-plan');
        $plan = $planPath === ''
            ? ['contexts' => [], 'coverage_complete' => false]
            : $resolvedContextPlan->load($planPath);

        return $readOnly->run(fn (): array => $builder->build(
            extractedAt: CarbonImmutable::now(),
            activeDays: $this->positiveIntOption('active-days'),
            profileCount: $this->positiveIntOption('profiles'),
            resolvedContexts: $plan['contexts'],
            resolvedContextCoverageComplete: $plan['coverage_complete'],
        ));
    }

    private function positiveIntOption(string $name): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new RuntimeException("--{$name} must be a positive integer.");
        }

        return $value;
    }

    private function optionString(string $name): string
    {
        return trim((string) ($this->option($name) ?? ''));
    }

    private function percentage(mixed $rate): string
    {
        if (! is_int($rate) && ! is_float($rate)) {
            return 'n/a';
        }

        return rtrim(rtrim(number_format((float) $rate * 100, 4, '.', ''), '0'), '.').'%';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  array<string, int|float|null>  $timings
     * @return T
     */
    private function measure(callable $operation, array &$timings, string $key): mixed
    {
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $timings[$key] = $this->elapsedMilliseconds($started);
        }
    }

    /**
     * @param  array<string, string|null>  $paths
     * @param  array<string, int|float|null>  $timings
     * @param  array<string, mixed>  $execution
     * @return array<string, string|null>
     */
    private function appendOperationalMetrics(
        NationRaidSimulationOperationalMetricsBuilder $builder,
        NationRaidSimulationArtifactWriter $writer,
        array $snapshot,
        array $paths,
        array &$timings,
        array $execution,
        int $commandStarted,
    ): array {
        $timings['measured_total_through_artifact_write'] = $this->elapsedMilliseconds($commandStarted);
        $metrics = $builder->build($snapshot, $paths, $timings, $execution);
        $paths['operational_metrics'] = $writer->writeOperationalMetrics($paths['directory'], $metrics);

        return $paths;
    }

    private function elapsedMilliseconds(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }
}
