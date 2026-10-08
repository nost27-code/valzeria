<?php

namespace App\Services\Admin;

use App\Services\RequestPerformanceStore;

final class RequestPerformanceReportService
{
    public const PERIODS = [15 => '15分', 60 => '1時間', 360 => '6時間', 1440 => '24時間'];

    public const SORTS = ['db_ms' => 'DB合計時間', 'requests' => '実行回数', 'p95' => '応答時間の遅い側（p95）',
        'duplicate_avg' => '重複読み取り', 'contention' => 'DB競合'];

    public const EXPLORATION_MODES = ['legacy' => '通常処理', 'batch_reads' => 'まとめ読取',
        'batch_discoveries' => 'まとめ読取＋図鑑記録', 'batch_state' => '操作内状態管理', 'unknown' => '方式未記録'];

    public function __construct(private RequestPerformanceStore $store) {}

    public function read(int $minutes, int $until, string $sort, ?string $operationId = null, string $release = '', string $explorationMode = ''): array
    {
        $seconds = $minutes * 60;
        $current = $this->store->read($until - $seconds, $until);
        $previous = $this->store->read($until - 2 * $seconds, $until - $seconds);
        $releases = array_values(array_unique(array_column([...$current['rows'], ...$previous['rows']], 'release')));
        $filter = fn ($row) => ($release === '' || ($row['release'] ?? '') === $release)
            && ($explorationMode === '' || ($row['operation'] === 'POST battle.explore'
                && ($row['exploration_processing_mode'] ?? 'unknown') === $explorationMode));
        $currentRows = array_values(array_filter($current['rows'], $filter));
        $previousRows = array_values(array_filter($previous['rows'], $filter));
        $rows = $this->aggregate($currentRows);
        $before = $this->aggregate($previousRows);
        foreach ($rows as $id => &$row) {
            $row['previous'] = $before[$id] ?? null;
            $row['hints'] = $this->hints($row);
        }
        unset($row);
        uasort($rows, fn ($a, $b) => $b[$sort] <=> $a[$sort]);
        $selectedId = $operationId ?? array_key_first($rows);
        $selected = $rows[$selectedId] ?? null;
        $details = [];
        $detailsLimited = false;
        if ($selected !== null) {
            foreach ($currentRows as $record) {
                if (hash('sha256', $record['operation']) !== $selectedId) {
                    continue;
                }
                foreach ($record['query_details'] ?? [] as $query) {
                    $id = $query['id'];
                    if (! isset($details[$id])) {
                        if (count($details) >= 256) {
                            $detailsLimited = true;

                            continue;
                        }
                        $details[$id] = $query;
                        $details[$id]['callers'] = [$query['caller']];
                    } else {
                        foreach (['count', 'ms', 'duplicates', 'failed'] as $metric) {
                            $details[$id][$metric] += $query[$metric];
                        }
                        $details[$id]['max_ms'] = max($details[$id]['max_ms'], $query['max_ms']);
                        if (count($details[$id]['callers']) < 3 && ! in_array($query['caller'], $details[$id]['callers'])) {
                            $details[$id]['callers'][] = $query['caller'];
                        }
                    }
                }
            }
            uasort($details, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        }
        $warnings = array_unique([...$current['warnings'], ...array_map(fn ($message) => '前期間：'.$message, $previous['warnings'])]);
        if ($detailsLimited) {
            $warnings[] = 'SQL形式の集計上限に達しました。詳細は最新側の記録の一部です。期間を短くしてください。';
        }
        if (count($rows) > 100) {
            $warnings[] = '画面別一覧は上位100件です。';
        }
        if (count($details) > 30) {
            $warnings[] = 'SQL一覧は詳細記録内のDB時間上位30種類です。';
        }
        $dir = $this->store->directory();
        if (is_file($dir)) {
            $warnings[] = '計測保存先がディレクトリではありません。保存設定を確認してください。';
        }
        if (is_dir($dir) && (! is_readable($dir) || ! is_writable($dir))) {
            $warnings[] = '計測保存先の読み書き権限を確認してください。';
        }
        if (array_sum(array_column($rows, 'truncated')) > 0) {
            $warnings[] = 'SQL詳細の件数制限で一部の種類を省略しています。概要のSQL数・DB時間は省略前の値です。';
        }

        return ['rows' => array_slice($rows, 0, 100, true), 'selected' => $selected,
            'details' => array_slice($details, 0, 30, true), 'warnings' => array_values($warnings),
            'releases' => $releases, 'totalRequests' => count($currentRows), 'totalSamples' => count(array_filter($currentRows, fn ($row) => $row['detail_sampled'])),
            'totalDbMs' => array_sum(array_column($currentRows, 'db_ms')), 'previousRequests' => count($previousRows),
            'from' => $until - $seconds, 'until' => $until, 'previousFrom' => $until - 2 * $seconds];
    }

    private function aggregate(array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $id = hash('sha256', $record['operation']);
            $row = $rows[$id] ?? ['id' => $id, 'operation' => $record['operation'], 'requests' => 0, 'samples' => 0,
                'queries' => 0, 'read_queries' => 0, 'write_queries' => 0, 'db_ms' => 0.0, 'responses' => [], 'max_sql_ms' => 0.0, 'duplicates' => 0,
                'categories' => [], 'errors' => [], 'phases' => [], 'max_transaction_ms' => 0.0,
                'locking_ms' => 0.0, 'open_transactions' => 0, 'http_errors' => 0, 'truncated' => 0, 'releases' => []];
            $row['requests']++;
            $row['samples'] += (int) $record['detail_sampled'];
            $row['queries'] += $record['queries'];
            $row['read_queries'] += $record['read_queries'] ?? (($record['categories']['read'] ?? 0) + ($record['categories']['metadata'] ?? 0));
            $row['write_queries'] += $record['write_queries'] ?? ($record['categories']['write'] ?? 0);
            $row['db_ms'] += $record['db_ms'];
            $row['responses'][] = $record['response_ms'];
            $row['max_sql_ms'] = max($row['max_sql_ms'], $record['max_sql_ms']);
            $row['max_transaction_ms'] = max($row['max_transaction_ms'], $record['max_transaction_ms']);
            $row['locking_ms'] += $record['locking_ms'];
            $row['open_transactions'] += $record['open_transactions'];
            $row['duplicates'] += $record['duplicate_reads'];
            $row['http_errors'] += (int) ($record['status'] >= 500);
            $row['truncated'] += (int) $record['detail_truncated'];
            $row['releases'][$record['release']] = true;
            foreach (['categories', 'errors', 'phases'] as $field) {
                foreach ($record[$field] as $key => $count) {
                    $row[$field][$key] = ($row[$field][$key] ?? 0) + $count;
                }
            }
            $rows[$id] = $row;
        }
        foreach ($rows as &$row) {
            sort($row['responses']);
            $row['median'] = $this->percentile($row['responses'], 0.5);
            $row['p95'] = $this->percentile($row['responses'], 0.95);
            $row['db_avg'] = $row['db_ms'] / $row['requests'];
            $row['query_avg'] = $row['queries'] / $row['requests'];
            $row['duplicate_avg'] = $row['samples'] ? $row['duplicates'] / $row['samples'] : null;
            $row['contention'] = array_sum($row['errors']);
            $row['read_ratio'] = $row['queries'] ? 100 * $row['read_queries'] / $row['queries'] : 0;
            unset($row['responses']);
        }

        return $rows;
    }

    private function percentile(array $values, float $fraction): float
    {
        return (float) $values[max(0, (int) ceil(count($values) * $fraction) - 1)];
    }

    private function hints(array $row): array
    {
        $hints = [];
        if ($row['duplicates'] > 0) {
            $hints[] = '② 同一リクエスト内の同じ条件の読み取りを再利用';
        }
        if ($row['read_ratio'] >= 80) {
            $hints[] = '③ 表示・マスタの読み取りにキャッシュが使えるか調査';
        }
        if ($row['max_sql_ms'] >= (float) config('request_performance.slow_sql_ms')) {
            $hints[] = '④ 遅いSQLの実行計画・検索条件・索引を確認';
        }
        if (($row['errors']['database_lock'] ?? 0) > 0 || ($row['errors']['state_changed'] ?? 0) > 0 || $row['max_transaction_ms'] >= (float) config('request_performance.slow_transaction_ms')) {
            $hints[] = '⑤ 競合段階・トランザクション範囲・ロック順序を確認';
        }
        if (($row['errors']['connection_limit'] ?? 0) > 0) {
            $hints[] = '接続上限：同時接続と処理時間を別途確認';
        }

        return $hints;
    }
}
