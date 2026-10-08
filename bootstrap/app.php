<?php

use App\Http\Middleware\IsAdmin;
use App\Support\DatabaseContention;
use App\Support\DatabaseConnectionCooldown;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/');
        $middleware->prepend(App\Http\Middleware\RecordRequestPerformance::class);
        $middleware->web(prepend: [App\Http\Middleware\RejectDuringDatabaseConnectionCooldown::class]);
        $middleware->web(replace: [
            StartSession::class => App\Http\Middleware\StartSession::class,
        ]);
        $middleware->alias([
            'admin' => IsAdmin::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 混雑だけを簡潔な記録と503へ変換。他のSQLエラーは通常どおり報告する。
        $exceptions->report(function (Throwable $exception) {
            app(App\Services\RequestPerformanceCollector::class)->exception($exception);
            if ($details = DatabaseContention::details($exception)) {
                if ($details['reason'] === 'connection_limit') {
                    app(DatabaseConnectionCooldown::class)->recordConnectionLimit();
                }
                Log::warning('Database contention handled.', $details + [
                    'route' => app()->runningInConsole() ? null : request()->route()?->getName(),
                ]);

                return false;
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (DatabaseContention::details($exception) === null) {
                return null;
            }

            return DatabaseContention::response($request);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
