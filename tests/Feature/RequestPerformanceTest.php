<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\RequestPerformanceReportService;
use App\Services\RequestPerformanceCollector;
use App\Services\RequestPerformanceStore;
use App\Support\PerformanceQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RequestPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = storage_path('framework/testing/performance-'.bin2hex(random_bytes(5)));
        config(['request_performance.path' => $this->directory, 'request_performance.enabled' => true,
            'request_performance.detail_sample_rate' => 1]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @unlink($this->directory.'/.prune');
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/example/private-value?token=private-token');
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('GET', 'example/{id}', fn () => null))->name('example.show'));

        return $request;
    }

    public function test_exploration_counts_use_server_results_and_do_not_record_private_payloads(): void
    {
        $request = Request::create('/battle/areas/1/explore', 'POST', ['batch_count' => 'private-input']);
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', 'battle/areas/{id}/explore', fn () => null))->name('battle.explore'));
        $request->attributes->set('committed_exploration_data', ['character_id' => 999, 'private' => 'private-value',
            'result' => ['result' => 'victory', 'batch_explore' => ['requested' => 50, 'completed' => 17]]]);
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(false);
        $profile = $collector->finish($request, 302);
        $this->assertSame(['requested' => 50, 'completed' => 17], $profile['exploration_count']);
        $this->assertSame('legacy', $profile['exploration_processing_mode']);
        $request->attributes->set('exploration_processing_mode', 'batch_reads');
        $collector->begin(false);
        $this->assertSame('batch_reads', $collector->finish($request, 302)['exploration_processing_mode']);
        $request->attributes->set('exploration_processing_mode', 'batch_discoveries');
        $collector->begin(false);
        $this->assertSame('batch_discoveries', $collector->finish($request, 302)['exploration_processing_mode']);
        $collector->begin(false);
        $failed = $collector->finish($request, 500);
        $this->assertSame('batch_discoveries', $failed['exploration_processing_mode']);
        $this->assertArrayNotHasKey('exploration_count', $failed);
        $request->attributes->remove('exploration_processing_mode');
        $this->assertStringNotContainsString('private-', json_encode($profile));
        $this->assertArrayNotHasKey('character_id', $profile);
        app(RequestPerformanceStore::class)->write($profile);
        $rows = app(RequestPerformanceStore::class)->read(time() - 60, time() + 1)['rows'];
        $this->assertSame($profile['exploration_count'], $rows[0]['exploration_count']);
        // Replay/early rejection has no fresh server result and is not a zero-cost 50-run sample.
        $request->attributes->remove('committed_exploration_data');
        $collector->begin(false);
        $replayed = $collector->finish($request, 302);
        $this->assertArrayNotHasKey('exploration_count', $replayed);
        $this->assertArrayNotHasKey('exploration_processing_mode', $replayed);
        $request->attributes->set('committed_exploration_data', ['result' => ['result' => 'victory']]);
        $collector->begin(false);
        $this->assertSame(['requested' => 1, 'completed' => 1], $collector->finish($request, 302)['exploration_count']);
        $request->attributes->set('committed_exploration_data', ['result' => ['batch_explore' => ['requested' => 5000, 'completed' => 50]]]);
        $collector->begin(false);
        $this->assertArrayNotHasKey('exploration_count', $collector->finish($request, 302));
        $request->attributes->set('committed_exploration_data', ['result' => ['result' => 'victory']]);
        $collector->begin(false);
        $this->assertArrayNotHasKey('exploration_count', $collector->finish($request, 500));
    }

    private function record(string $operation, int $time, float $ms, int $queries = 4): array
    {
        return ['time' => $time, 'operation' => $operation, 'status' => 200, 'queries' => $queries,
            'db_ms' => $ms, 'response_ms' => $ms + 10, 'detail_sampled' => true, 'detail_truncated' => false,
            'duplicate_reads' => 2, 'categories' => ['read' => $queries], 'errors' => [], 'phases' => [],
            'transaction_ms' => 0, 'max_transaction_ms' => 0, 'locking_ms' => 0, 'max_sql_ms' => $ms,
            'open_transactions' => 0, 'release' => 'local', 'query_details' => []];
    }

    public function test_sql_values_and_comments_are_removed_and_different_bindings_are_not_counted_as_duplicates(): void
    {
        $description = PerformanceQuery::describe("SELECT * FROM users WHERE email='secret@example.test' AND id=991 /* password */ AND token=\"hidden-token\"");
        $this->assertStringNotContainsString('secret', $description['sql']);
        $this->assertStringNotContainsString('991', $description['sql']);
        $this->assertStringNotContainsString('password', $description['sql']);
        $this->assertStringNotContainsString('hidden-token', $description['sql']);
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        foreach ([1, 2, 1] as $id) {
            $collector->query(new QueryExecuted('select * from users where id = ?', [$id], 4, DB::connection()));
        }
        $profile = $collector->finish($this->request(), 200);
        $this->assertSame(3, $profile['queries']);
        $this->assertSame(1, $profile['duplicate_reads']);
        $this->assertSame(3, $profile['query_details'][0]['count']);
        $this->assertSame('GET example.show', $profile['operation']);
        $this->assertStringNotContainsString('private', json_encode($profile));
        $this->assertArrayNotHasKey('bindings', $profile['query_details'][0]);
        $this->assertStringNotContainsString('unterminated-secret', PerformanceQuery::describe("select 'unterminated-secret")['sql']);
    }

    public function test_nested_transactions_and_retried_contention_are_measured_without_double_counting(): void
    {
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        $collector->transaction('mysql', 1);
        $collector->transaction('mysql', 2);
        $collector->transaction('mysql', 1);
        $exception = new QueryException('mysql', 'update characters set gold=? where id=?', [999, 1],
            new \PDOException('Lock wait timeout'));
        $exception->errorInfo = ['HY000', 1205, 'Lock wait timeout'];
        $collector->exception($exception, 'reward_save');
        $collector->exception($exception);
        $collector->transaction('mysql', 0);
        $profile = $collector->finish($this->request(), 503);
        $this->assertSame(1, $profile['errors']['database_lock']);
        $this->assertSame(1, $profile['phases']['reward_save']);
        $this->assertSame(0, $profile['open_transactions']);
        $this->assertGreaterThan(0, $profile['max_transaction_ms']);
        $this->assertSame(1, $profile['query_details'][0]['failed']);
        $this->assertSame(0.0, $profile['query_details'][0]['ms']);
        $collector->begin(false);
        $this->assertSame([], $collector->finish($this->request(), 200)['errors']);
    }

    public function test_summary_is_collected_without_sql_details_when_not_sampled_or_disabled(): void
    {
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(false);
        $collector->query(new QueryExecuted('select 1', [], 3, DB::connection()));
        $record = $collector->finish($this->request(), 200);
        $this->assertSame(1, $record['queries']);
        $this->assertSame([], $record['query_details']);
        config(['request_performance.enabled' => false]);
        $collector->begin();
        $this->assertNull($collector->finish($this->request(), 200));
    }

    public function test_cache_writes_are_counted_as_writes_and_preexisting_outer_transactions_are_not_invented(): void
    {
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        $collector->transaction('already-open', 2);
        $collector->transaction('already-open', 1, false);
        $collector->query(new QueryExecuted('update cache set value = ?', ['private-token'], 3, DB::connection()));
        $profile = $collector->finish($this->request(), 200);
        $this->assertSame(1, $profile['write_queries']);
        $this->assertSame(0, $profile['read_queries']);
        $this->assertSame(1, $profile['categories']['session_cache']);
        $this->assertSame(0, $profile['open_transactions']);
        $this->assertStringNotContainsString('private-token', json_encode($profile));
    }

    public function test_report_filters_exploration_modes_without_assuming_old_records_or_other_operations(): void
    {
        $now = time();
        $store = app(RequestPerformanceStore::class);
        $store->write($this->record('POST battle.explore', $now - 1, 10) + ['exploration_processing_mode' => 'legacy']);
        $store->write($this->record('POST battle.explore', $now - 2, 20) + ['exploration_processing_mode' => 'batch_reads']);
        $store->write($this->record('POST battle.explore', $now - 3, 30));
        $store->write($this->record('POST battle.explore', $now - 5, 50) + ['exploration_processing_mode' => 'batch_discoveries']);
        $store->write($this->record('GET home', $now - 4, 40));
        $reports = app(\App\Services\Admin\RequestPerformanceReportService::class);
        foreach (['legacy' => 10, 'batch_reads' => 20, 'unknown' => 30, 'batch_discoveries' => 50] as $mode => $expectedMs) {
            $report = $reports->read(15, $now + 1, 'db_ms', explorationMode: $mode);
            $this->assertSame(1, $report['totalRequests']);
            $this->assertSame((float) $expectedMs, $report['selected']['db_avg']);
            $this->assertSame('POST battle.explore', $report['selected']['operation']);
        }
    }

    public function test_busy_writer_is_nonblocking_and_expired_files_are_pruned(): void
    {
        mkdir($this->directory, 0700, true);
        $old = $this->directory.'/'.gmdate('YmdHi', time() - 50 * 3600).'.jsonl';
        file_put_contents($old, 'old fixture');
        $store = app(RequestPerformanceStore::class);
        $now = time();
        $store->write($this->record('GET home', $now, 1));
        $this->assertFileDoesNotExist($old);
        $stream = fopen($this->directory.'/'.gmdate('YmdHi', $now).'.jsonl', 'c+b');
        flock($stream, LOCK_EX);
        $start = hrtime(true);
        $store->write($this->record('GET home', $now, 5));
        $this->assertLessThan(100, (hrtime(true) - $start) / 1000000);
        fclose($stream);
        $this->assertCount(1, $store->read($now - 60, $now + 1)['rows']);
        $this->assertNotEmpty($store->read($now - 60, $now + 1)['warnings']);
    }

    public function test_livewire_parameters_and_unknown_component_names_are_not_retained(): void
    {
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'champ-card', 'path' => 'secret-path'], 'data' => ['token' => 'secret']]),
            'calls' => [['method' => 'render', 'params' => ['secret-argument']]],
        ]]]);
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        $profile = $collector->finish($request, 200);
        $this->assertSame('livewire:champ-card.render', $profile['operation']);
        $this->assertStringNotContainsString('secret', json_encode($profile));
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'admin.request-performance']]),
        ]]]);
        $collector->begin(true);
        $this->assertNull($collector->finish($request, 200));
    }

    public function test_livewire_four_versioned_endpoint_is_grouped_by_component_and_action(): void
    {
        $request = Request::create('/livewire-hashed/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'city-header']]),
            'calls' => [['method' => 'render', 'params' => ['never-store']]],
        ]]]);
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', 'livewire-hashed/update', fn () => null))->name('default-livewire.update'));
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        $this->assertSame('livewire:city-header.render', $collector->finish($request, 200)['operation']);
    }

    public function test_file_capacity_and_reader_limits_are_visible_and_corrupt_records_do_not_break_reports(): void
    {
        $store = app(RequestPerformanceStore::class);
        $now = time();
        $store->write($this->record('GET home', $now, 3));
        $this->assertCount(1, $store->read($now - 60, $now + 1)['rows']);
        config(['request_performance.max_bucket_bytes' => 1]);
        $store->write($this->record('GET home', $now, 5));
        $this->assertNotEmpty($store->read($now - 60, $now + 1)['warnings']);
        $this->assertCount(1, $store->read($now - 60, $now + 1)['rows']);
        file_put_contents($this->directory.'/'.gmdate('YmdHi', $now).'.jsonl', "not-json\n", FILE_APPEND);
        $this->assertNotEmpty($store->read($now - 60, $now + 1)['warnings']);
        config(['request_performance.max_read_bytes' => 1]);
        $result = $store->read($now - 60, $now + 1);
        $this->assertSame([], $result['rows']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_current_and_previous_periods_are_separate_and_each_strategy_has_evidence(): void
    {
        $now = time();
        $store = app(RequestPerformanceStore::class);
        $old = $this->record('GET home', $now - 1000, 200);
        $store->write($old);
        $current = $this->record('GET home', $now - 10, 150);
        $current['errors'] = ['database_lock' => 1];
        $current['phases'] = ['reward_save' => 1];
        $store->write($current);
        $report = app(RequestPerformanceReportService::class)->read(15, $now, 'db_ms');
        $this->assertSame(1, $report['totalRequests']);
        $this->assertSame(1, $report['previousRequests']);
        $this->assertSame(150.0, $report['selected']['db_avg']);
        $this->assertSame(200.0, $report['selected']['previous']['db_avg']);
        $this->assertCount(4, $report['selected']['hints']);
    }

    public function test_only_full_admins_can_open_page_and_page_does_not_write_game_data_or_profile_itself(): void
    {
        $this->get(route('admin.request-performance'))->assertRedirect('/');
        $player = User::factory()->create(['role' => 'player']);
        $this->actingAs($player)->get(route('admin.request-performance'))->assertRedirect('/admin/login');
        $admin = User::factory()->create(['role' => 'admin']);
        $writes = [];
        DB::listen(function ($event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $this->actingAs($admin)->get(route('admin.request-performance'))->assertOk()->assertSee('処理負荷・DB分析')
            ->assertSee('計測データはまだありません')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $writes);
        $this->assertFalse(is_dir($this->directory));
        $this->get(route('admin.request-performance', ['minutes' => 'bad']))->assertStatus(422);
        $this->get(route('admin.request-performance', ['op' => '../../secret']))->assertStatus(422);
        $this->get(route('admin.request-performance', ['exploration_mode' => 'invalid']))->assertStatus(422);
    }

    public function test_http_queries_are_profiled_and_recording_failures_do_not_change_the_response(): void
    {
        Route::get('/perf-fixture', function () {
            DB::select('select 1');

            return response('result');
        })->name('perf.fixture');
        $this->get('/perf-fixture')->assertOk()->assertSee('result');
        $rows = app(RequestPerformanceStore::class)->read(time() - 60, time() + 1)['rows'];
        $this->assertSame('GET perf.fixture', $rows[0]['operation']);
        $this->assertSame(1, $rows[0]['queries']);
        file_put_contents($this->directory.'/blocked', 'not a directory');
        config(['request_performance.path' => $this->directory.'/blocked']);
        $this->get('/perf-fixture')->assertOk()->assertSee('result');
    }
}
