<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use RuntimeException;

final class NationRaidSimulationArtifactWriter
{
    /** @return array{directory:string,snapshot:string,validation:string,population_report:string,simulation:?string,resolved_context_cache_metrics:?string,resolved_context_plan_candidate:?string} */
    public function write(array $snapshot, array $validation, ?array $simulation, ?string $runLabel = null): array
    {
        $label = $this->runLabel($runLabel);
        $directory = storage_path('app/private/nation-raid-simulation/'.$label);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create simulation artifact directory: {$directory}");
        }

        $snapshotPath = $directory.'/snapshot.json';
        $validationPath = $directory.'/validation.json';
        $reportPath = $directory.'/population-report.md';
        $simulationPath = $simulation !== null ? $directory.'/simulation.json' : null;
        $contextMetrics = is_array($simulation['resolved_context_cache_metrics'] ?? null)
            ? $simulation['resolved_context_cache_metrics']
            : null;
        $contextMetricsPath = $contextMetrics !== null
            ? $directory.'/resolved-context-cache-metrics.json'
            : null;
        $candidatePlan = is_array($contextMetrics['reachability']['review_candidate_plan'] ?? null)
            ? $contextMetrics['reachability']['review_candidate_plan']
            : null;
        $candidatePlanPath = $candidatePlan !== null
            ? $directory.'/resolved-context-plan-candidate.json'
            : null;
        $this->writeJson($snapshotPath, $snapshot);
        $this->writeJson($validationPath, $validation);
        $this->writeText($reportPath, $this->populationReport($snapshot, $validation));
        if ($simulationPath !== null) {
            $this->writeJson($simulationPath, $simulation);
        }
        if ($contextMetricsPath !== null) {
            $this->writeJson($contextMetricsPath, $contextMetrics);
        }
        if ($candidatePlanPath !== null) {
            $this->writeJson($candidatePlanPath, $candidatePlan);
        }

        return [
            'directory' => $directory,
            'snapshot' => $snapshotPath,
            'validation' => $validationPath,
            'population_report' => $reportPath,
            'simulation' => $simulationPath,
            'resolved_context_cache_metrics' => $contextMetricsPath,
            'resolved_context_plan_candidate' => $candidatePlanPath,
        ];
    }

    /**
     * 環境依存の時間・容量は、snapshot/simulationの決定的な成果物と分離して保存する。
     */
    public function writeOperationalMetrics(string $directory, array $metrics): string
    {
        $root = realpath(storage_path('app/private/nation-raid-simulation'));
        $resolvedDirectory = realpath($directory);
        if ($root === false || $resolvedDirectory === false) {
            throw new RuntimeException('Simulation artifact directory is not available for operational metrics.');
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedDirectory = rtrim(str_replace('\\', '/', $resolvedDirectory), '/').'/';
        if (! str_starts_with(strtolower($normalizedDirectory), strtolower($rootPrefix))) {
            throw new RuntimeException('Operational metrics must stay inside the private nation raid artifact directory.');
        }

        $path = $resolvedDirectory.'/operational-metrics.json';
        $this->writeJson($path, $metrics);

        return $path;
    }

    private function writeJson(string $path, array $payload): void
    {
        $json = NationRaidJson::encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )."\n";
        $this->writeText($path, $json);
    }

    private function writeText(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write simulation artifact: {$path}");
        }
    }

    private function runLabel(?string $requested): string
    {
        $requested = trim((string) $requested);
        if ($requested === '') {
            return now()->format('Ymd_His');
        }
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $requested)) {
            throw new RuntimeException('Run label may contain only letters, numbers, hyphen, and underscore.');
        }

        return $requested;
    }

    private function populationReport(array $snapshot, array $validation): string
    {
        $population = $snapshot['population_report'] ?? [];
        $lines = [
            '# 国家対抗レイド Phase 2 母集団レポート',
            '',
            '- 取得時刻: `'.($snapshot['extracted_at'] ?? 'unknown').'`',
            '- ruleset hash: `'.($snapshot['ruleset_hash'] ?? 'unknown').'`',
            '- boss species key: `'.($snapshot['boss_species_key'] ?? 'unknown').'`',
            '- raid killer contract hash: `'.($snapshot['raid_killer_contract_hash'] ?? 'unknown').'`',
            '- coordination timing model hash: `'.($snapshot['coordination_timing_model_hash'] ?? 'unknown').'`',
            '- integration hash: `'.($snapshot['integration_hash'] ?? 'unknown').'`',
            '- action profile model: `'.($snapshot['action_profile_model'] ?? 'unknown').'`',
            '- action profile cache hash: `'.($snapshot['action_profile_cache_hash'] ?? 'unknown').'`',
            '- resolved context profile model: `'.($snapshot['resolved_context_profile_model'] ?? 'unknown').'`',
            '- resolved context cache hash: `'.($snapshot['resolved_context_profile_cache_hash'] ?? 'unknown').'`',
            '- resolved context plan hash: `'.($snapshot['resolved_context_plan_hash'] ?? 'unknown').'`',
            '- resolved context plan complete: `'.(($snapshot['resolved_context_plan_coverage_complete'] ?? false) ? 'true' : 'false').'`',
            '- resolved context profile authoritative: `'.(($snapshot['resolved_context_profile_authoritative'] ?? false) ? 'true' : 'false').'`',
            '- schema ready: `'.(($validation['ready'] ?? false) ? 'true' : 'false').'`',
            '',
            '## 件数',
            '',
        ];
        foreach ($population as $key => $value) {
            if ($key === 'raid_killer_damage_rate_distribution') {
                continue;
            }
            $display = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            $lines[] = '- '.$key.': `'.$display.'`';
        }
        $lines[] = '';
        $lines[] = '## 竜特攻の実効rate分布';
        $lines[] = '';
        $lines[] = '| 実効特攻率 | 人数 |';
        $lines[] = '|---:|---:|';
        foreach (($population['raid_killer_damage_rate_distribution'] ?? []) as $row) {
            $rate = is_array($row) && is_numeric($row['damage_rate'] ?? null)
                ? rtrim(rtrim(number_format((float) $row['damage_rate'] * 100, 4, '.', ''), '0'), '.')
                : 'unknown';
            $characters = is_array($row) && is_numeric($row['characters'] ?? null)
                ? (string) (int) $row['characters']
                : 'unknown';
            $lines[] = "| +{$rate}% | {$characters} |";
        }
        $lines[] = '';
        $lines[] = '## Validation';
        $lines[] = '';
        foreach (($validation['counts'] ?? []) as $key => $value) {
            $lines[] = '- '.$key.': `'.$value.'`';
        }
        foreach (($validation['warnings'] ?? []) as $warning) {
            $lines[] = '- warning: `'.$warning.'`';
        }
        foreach (($validation['errors'] ?? []) as $error) {
            $character = $error['character_key'] ?? 'root';
            $lines[] = '- error (`'.$character.'`): `'.($error['reason'] ?? 'unknown').'`';
        }
        $lines[] = '';
        $lines[] = 'このレポートとJSON成果物にはplayer名、account名、email、DB IDを含めない。';

        return implode("\n", $lines)."\n";
    }
}
