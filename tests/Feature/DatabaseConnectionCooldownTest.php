<?php

namespace Tests\Feature;

use App\Http\Middleware\RejectDuringDatabaseConnectionCooldown;
use App\Support\DatabaseConnectionCooldown;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseConnectionCooldownTest extends TestCase
{
    public function test_connection_limit_stops_following_requests_before_database_sessions_and_actions(): void
    {
        $this->freezeTime();
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        DB::listen(fn () => $this->fail('Cooldown must run before any database query'));
        Log::spy();
        app(ExceptionHandler::class)->report($this->databaseError(1226, 'max_user_connections'));
        $this->assertSame(3, app(DatabaseConnectionCooldown::class)->remainingSeconds());
        $this->assertTrue(Route::has('home'), 'Home route missing at '.app()->basePath().' ('.Route::getRoutes()->count().' routes)');

        $this->get('/home')->assertStatus(503)->assertHeader('Retry-After', '3')
            ->assertSee('ただいま混み合っています')->assertDontSee('location.reload');
        $this->postJson(route('default-livewire.update'), [])->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_expired_cooldown_passes_requests_through_without_replaying_actions(): void
    {
        $this->freezeTime();
        $actions = 0;
        Route::middleware('web')->get('/cooldown-probe', function () use (&$actions) {
            $actions++;

            return response('', 204);
        });
        app(DatabaseConnectionCooldown::class)->recordConnectionLimit();
        $this->get('/cooldown-probe')->assertStatus(503);
        $this->assertSame(0, $actions);
        $this->travel(3)->seconds();
        $this->get('/cooldown-probe')->assertNoContent();
        $this->assertSame(1, $actions);
    }

    public function test_row_lock_errors_do_not_pause_unrelated_requests(): void
    {
        Log::spy();
        app(ExceptionHandler::class)->report($this->databaseError(1205, 'Lock wait timeout'));
        $this->assertSame(0, app(DatabaseConnectionCooldown::class)->remainingSeconds());
    }

    public function test_missing_corrupt_or_unwritable_marker_does_not_break_requests(): void
    {
        $path = sys_get_temp_dir().'/missing-cooldown-'.bin2hex(random_bytes(8)).'/marker';
        $cooldown = new DatabaseConnectionCooldown($path);
        $cooldown->recordConnectionLimit();
        $this->assertSame(0, $cooldown->remainingSeconds());
        $file = tempnam(sys_get_temp_dir(), 'cooldown-probe-');
        try {
            $cooldown = new DatabaseConnectionCooldown($file);
            file_put_contents($file, 'invalid');
            $this->assertSame(0, $cooldown->remainingSeconds());
            file_put_contents($file, (string) (now()->getTimestamp() + 3600));
            $this->assertSame(0, $cooldown->remainingSeconds());
        } finally {
            unlink($file);
        }
    }

    public function test_payment_webhook_keeps_its_existing_handling_path(): void
    {
        app(DatabaseConnectionCooldown::class)->recordConnectionLimit();
        $response = app(RejectDuringDatabaseConnectionCooldown::class)->handle(
            Request::create('/stripe/webhook', 'POST'),
            fn () => response('', 204),
        );
        $this->assertSame(204, $response->getStatusCode());
    }

    private function databaseError(int $code, string $message): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = ['HY000', $code, $message];

        return new QueryException('mysql', 'select * from sessions where id = ?', ['private'], $previous);
    }
}
