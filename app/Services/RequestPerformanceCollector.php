<?php

namespace App\Services;

use App\Exceptions\ChampBattleStateChangedException;
use App\Support\DatabaseContention;
use App\Support\PerformanceQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

final class RequestPerformanceCollector
{
    private ?array $profile = null;

    private array $queries = [];

    private array $exactReads = [];

    private array $transactions = [];

    private array $seenErrors = [];

    private int $started = 0;

    public function begin(?bool $sampled = null): void
    {
        $this->reset();
        if (! config('request_performance.enabled')) {
            return;
        }
        $rate = min(1, max(0, (float) config('request_performance.detail_sample_rate')));
        $this->started = hrtime(true);
        $sha = @file_get_contents(base_path('.release-sha'));
        $this->profile = ['time' => time(), 'queries' => 0, 'read_queries' => 0, 'write_queries' => 0, 'db_ms' => 0.0, 'duplicate_reads' => 0,
            'categories' => [], 'errors' => [], 'phases' => [], 'transaction_ms' => 0.0,
            'max_transaction_ms' => 0.0, 'locking_ms' => 0.0, 'max_sql_ms' => 0.0,
            'detail_sampled' => $sampled ?? random_int(0, 999999) < $rate * 1000000,
            'detail_truncated' => false,
            'release' => is_string($sha) && preg_match('/\A[0-9a-f]{40}\s*\z/', $sha) ? trim($sha) : 'local'];
    }

    public function query(QueryExecuted $event): void
    {
        if ($this->profile === null) {
            return;
        }
        $description = PerformanceQuery::classify($event->sql);
        $ms = max(0, (float) $event->time);
        $this->profile['queries']++;
        $this->profile['read_queries'] += (int) $description['read'];
        $this->profile['write_queries'] += (int) in_array($description['type'], ['insert', 'update', 'delete', 'replace'], true);
        $this->profile['db_ms'] += $ms;
        $this->profile['max_sql_ms'] = max($this->profile['max_sql_ms'], $ms);
        $category = $description['category'];
        $this->profile['categories'][$category] = ($this->profile['categories'][$category] ?? 0) + 1;
        if ($description['locking']) {
            $this->profile['locking_ms'] += $ms;
        }
        if (! $this->profile['detail_sampled']) {
            return;
        }
        $duplicate = false;
        if ($description['read'] && count($this->exactReads) < 512) {
            // Binding hashes exist only in memory for this request and are never persisted.
            $key = hash('sha256', $event->sql."\0".json_encode($event->bindings, JSON_PARTIAL_OUTPUT_ON_ERROR));
            $duplicate = isset($this->exactReads[$key]);
            $this->exactReads[$key] = true;
            $this->profile['duplicate_reads'] += (int) $duplicate;
        } elseif ($description['read']) {
            $this->profile['detail_truncated'] = true;
        }
        $this->addQuery(PerformanceQuery::describe($event->sql), $ms, $duplicate);
    }

    private function addQuery(array $description, float $ms, bool $duplicate = false, bool $failed = false): void
    {
        $id = $description['id'];
        if (! isset($this->queries[$id])) {
            if (count($this->queries) >= (int) config('request_performance.max_query_shapes', 64)) {
                $this->profile['detail_truncated'] = true;

                return;
            }
            $this->queries[$id] = $description + ['count' => 0, 'ms' => 0.0, 'max_ms' => 0.0,
                'duplicates' => 0, 'failed' => 0, 'caller' => PerformanceQuery::caller()];
        }
        $query = &$this->queries[$id];
        $query['count'] += (int) ! $failed;
        $query['ms'] += $ms;
        $query['max_ms'] = max($query['max_ms'], $ms);
        $query['duplicates'] += (int) $duplicate;
        $query['failed'] += (int) $failed;
    }

    public function exception(Throwable $exception, ?string $phase = null): void
    {
        try {
            $this->captureException($exception, $phase);
        } catch (Throwable) {
            $this->reset();
        }
    }

    private function captureException(Throwable $exception, ?string $phase): void
    {
        if ($this->profile === null || isset($this->seenErrors[spl_object_id($exception)])) {
            return;
        }
        $this->seenErrors[spl_object_id($exception)] = $exception;
        $details = DatabaseContention::details($exception);
        if ($exception instanceof ChampBattleStateChangedException) {
            $details = ['reason' => 'state_changed'];
        }
        if ($details !== null) {
            $reason = $details['reason'];
            $this->profile['errors'][$reason] = ($this->profile['errors'][$reason] ?? 0) + 1;
            if ($phase !== null && preg_match('/\A[a-z_]{1,40}\z/', $phase)) {
                $this->profile['phases'][$phase] = ($this->profile['phases'][$phase] ?? 0) + 1;
            }
        }
        if ($exception instanceof QueryException) {
            $this->addQuery(PerformanceQuery::describe($exception->getSql()), 0, false, true);
        }
    }

    public function transaction(string $connection, int $level, bool $begin = true): void
    {
        if ($this->profile === null) {
            return;
        }
        if ($begin && $level === 1) {
            $this->transactions[$connection] ??= hrtime(true);
        } elseif ($level === 0 && isset($this->transactions[$connection])) {
            $ms = (hrtime(true) - $this->transactions[$connection]) / 1000000;
            $this->profile['transaction_ms'] += $ms;
            $this->profile['max_transaction_ms'] = max($this->profile['max_transaction_ms'], $ms);
            unset($this->transactions[$connection]);
        }
    }

    public function finish(Request $request, int $status): ?array
    {
        if ($this->profile === null) {
            return null;
        }
        $route = $request->route();
        if (str_ends_with((string) $route?->getName(), 'livewire.update') === false
            && str_contains((string) $route?->getActionName(), 'FrontendAssets')) {
            $this->reset();

            return null;
        }
        $operation = $request->method().' '.($route?->getName() ?? ($route ? $route->uri() : 'unmatched'));
        if ($request->is('livewire/update') || str_ends_with((string) $route?->getName(), 'livewire.update')) {
            $operations = [];
            foreach (array_slice((array) $request->input('components', []), 0, 10) as $component) {
                if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
                    continue;
                }
                $snapshot = json_decode($component['snapshot'], true);
                $name = data_get($snapshot, 'memo.name');
                if (is_string($name) && str_starts_with($name, 'admin.')) {
                    $this->reset();

                    return null;
                }
                $class = is_string($name) ? config('request_performance.components.'.$name) : null;
                if (! $class && is_string($name) && strlen($name) <= 100
                    && preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)*\z/', $name)) {
                    $candidate = 'App\\Livewire\\'.implode('\\', array_map(fn ($part) => Str::studly($part), explode('.', $name)));
                    $class = is_subclass_of($candidate, Component::class) ? $candidate : null;
                }
                $label = $class && class_exists($class) ? $name : 'other';
                $calls = [];
                foreach (array_slice((array) ($component['calls'] ?? []), 0, 4) as $call) {
                    $method = is_array($call) ? ($call['method'] ?? '') : '';
                    if ($class && is_string($method) && preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,60}\z/', $method)
                        && method_exists($class, $method) && (new \ReflectionMethod($class, $method))->isPublic()) {
                        $calls[] = $method;
                    }
                }
                $operations[] = 'livewire:'.$label.'.'.(implode('+', $calls) ?: 'render');
            }
            $operation = implode(' / ', array_unique($operations)) ?: 'livewire:other';
        }
        $profile = $this->profile + ['operation' => mb_substr($operation, 0, 240),
            'status' => $status, 'response_ms' => round((hrtime(true) - $this->started) / 1000000, 3),
            'open_transactions' => count($this->transactions)];
        // Counts come from the server result, never arbitrary request values or player data.
        if ($operation === 'POST battle.explore' && $request->attributes->get('exploration_processing_mode') === 'batch_reads') {
            $profile['exploration_processing_mode'] = 'batch_reads';
        }
        if ($operation === 'POST battle.explore' && $status >= 200 && $status < 400) {
            $result = data_get($request->attributes->get('committed_exploration_data'), 'result');
            if (is_array($result) && ! isset($result['error'])) {
                $batch = $result['batch_explore'] ?? null;
                $requested = is_array($batch) ? ($batch['requested'] ?? null) : 1;
                $completed = is_array($batch) ? ($batch['completed'] ?? null) : (isset($result['result']) ? 1 : null);
                if (is_int($requested) && is_int($completed) && $requested >= 1 && $requested <= 50
                    && $completed >= 0 && $completed <= $requested) {
                    $profile['exploration_count'] = ['requested' => $requested, 'completed' => $completed];
                    $profile['exploration_processing_mode'] = $request->attributes->get('exploration_processing_mode') === 'batch_reads'
                        ? 'batch_reads' : 'legacy';
                }
            }
        }
        $saved = [];
        foreach (['ms', 'count', 'duplicates', 'failed'] as $sort) {
            $queries = $this->queries;
            uasort($queries, fn ($a, $b) => $b[$sort] <=> $a[$sort]);
            foreach (array_slice($queries, 0, 3, true) as $id => $query) {
                $saved[$id] = $query;
            }
        }
        $profile['query_details'] = array_values(array_slice($saved, 0, (int) config('request_performance.max_saved_queries', 12), true));
        $profile['detail_truncated'] = $profile['detail_truncated'] || count($profile['query_details']) < count($this->queries);
        $this->reset();

        return $profile;
    }

    public function reset(): void
    {
        $this->profile = null;
        $this->queries = $this->exactReads = $this->transactions = $this->seenErrors = [];
    }
}
