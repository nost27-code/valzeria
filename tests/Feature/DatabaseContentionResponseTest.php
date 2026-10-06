<?php

namespace Tests\Feature;

use App\Support\DatabaseContention;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class DatabaseContentionResponseTest extends TestCase
{
    public function test_connection_limit_renders_a_static_page_without_database_queries_or_automatic_resubmission(): void
    {
        DB::listen(fn () => $this->fail('Busy page must not query the database'));
        $exception = $this->databaseException(1203);
        $response = app(ExceptionHandler::class)->render(Request::create('/battle/explore', 'POST'), $exception);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('3', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('ただいま混み合っています', $response->getContent());
        $this->assertStringContainsString('結果や所持品を確認', $response->getContent());
        $this->assertStringNotContainsString('private-value', $response->getContent());
        $this->assertStringNotContainsString('location.reload', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_lock_wait_renders_json_for_item_requests(): void
    {
        $request = Request::create('/exploration/items/1/use', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = app(ExceptionHandler::class)->render($request, $this->databaseException(1205));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['success' => false, 'message' => DatabaseContention::MESSAGE], $response->getData(true));
    }

    public function test_contention_logging_omits_sql_and_bindings(): void
    {
        Log::shouldReceive('warning')->once()->with('Database contention handled.', [
            'reason' => 'connection_limit', 'database_error_code' => 1203, 'sql_state' => 'HY000', 'route' => null,
        ]);

        app(ExceptionHandler::class)->report($this->databaseException(1203));
    }

    public function test_unrelated_sql_errors_are_not_classified_as_contention(): void
    {
        foreach ([1062, 1064, 2006] as $code) {
            $this->assertNull(DatabaseContention::details($this->databaseException($code)));
        }
        $this->assertNull(DatabaseContention::details(new \RuntimeException('1205 is not a database error')));
    }

    public function test_nested_deadlock_and_connection_quota_are_classified(): void
    {
        $nested = new DeadlockException('outer transaction must roll back', 0, $this->databaseException(1213));
        $this->assertSame('database_lock', DatabaseContention::details($nested)['reason']);
        $this->assertSame('connection_limit', DatabaseContention::details($this->databaseException(1226, 'max_user_connections'))['reason']);
        $this->assertNull(DatabaseContention::details($this->databaseException(1226, 'max_queries')));
    }

    private function databaseException(int $code, string $message = 'database busy'): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = ['HY000', $code, $message];

        return new QueryException('mysql', 'select * from sessions where id = ?', ['private-value'], $previous);
    }
}
