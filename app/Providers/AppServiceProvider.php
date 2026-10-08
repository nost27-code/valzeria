<?php

namespace App\Providers;

use App\Services\GameSettingService;
use App\Services\SchemaStateService;
use App\Services\NamelessSchemaService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->make(\App\Support\WebDatabaseConnection::class)->apply($this->app->runningInConsole());
        $this->app->make(\App\Support\WorkerDatabaseConnection::class)->apply(
            $this->app->runningInConsole(),
            getenv('VALZERIA_DB_ROLE') ?: 'web',
            $_SERVER['argv'] ?? [],
        );
        $this->app->scoped(\App\Services\ExplorationStateService::class);
        $this->app->scoped(SchemaStateService::class);
        $this->app->scoped(NamelessSchemaService::class);
        $this->app->scoped(GameSettingService::class);
        $this->app->scoped(\App\Services\ExplorationBatchReadContext::class);
        $this->app->scoped(\App\Services\ExplorationBatchWriteContext::class);
        $this->app->scoped(\App\Services\ExplorationBatchStateContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class,
            fn ($event) => \App\Services\ExplorationBatchStateContext::wire($event->connection),
        );
        foreach (\Illuminate\Support\Facades\DB::getConnections() as $connection) {
            \App\Services\ExplorationBatchStateContext::wire($connection);
        }
        // Include query-builder writes as well as Eloquent writes; never retain state after a mutation.
        \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
            app(\App\Services\ExplorationBatchReadContext::class)->invalidateSql($query->sql, $query->connection->getTablePrefix());
            app(\App\Services\ExplorationBatchStateContext::class)->afterSql($query->sql, $query->connection);
            if (str_contains($query->sql, 'character_exploration_states')
                && preg_match('/^\\s*(insert|update|delete|replace)\\b/i', $query->sql)) {
                app(\App\Services\ExplorationStateService::class)->invalidate();
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class,
            fn () => app(\App\Services\ExplorationStateService::class)->invalidate(),
        );
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class,
            fn () => app(\App\Services\ExplorationBatchReadContext::class)->invalidate(),
        );
        foreach ([\Illuminate\Database\Events\TransactionBeginning::class => 'transactionBeginning',
            \Illuminate\Database\Events\TransactionCommitted::class => 'transactionCommitted',
            \Illuminate\Database\Events\TransactionRolledBack::class => 'transactionRolledBack'] as $event => $method) {
            \Illuminate\Support\Facades\Event::listen($event,
                fn ($transaction) => app(\App\Services\ExplorationBatchWriteContext::class)->{$method}($transaction->connection),
            );
            \Illuminate\Support\Facades\Event::listen($event,
                fn ($transaction) => app(\App\Services\ExplorationBatchStateContext::class)->{$method}($transaction->connection),
            );
        }

        // 同一IP全体とメールアドレス+IPの両方で制限。共有回線の他アカウントを恒久ロックしない。
        foreach (['auth-login' => 10, 'auth-admin' => 5, 'auth-admin-viewer' => 5] as $name => $attempts) {
            \Illuminate\Support\Facades\RateLimiter::for($name, function (\Illuminate\Http\Request $request) use ($name, $attempts) {
                $ip = hash('sha256', (string) $request->ip());
                $email = $request->input('email');
                $account = hash('sha256', mb_strtolower(trim(is_string($email) ? $email : '')).'|'.$ip);
                return [
                    \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($name.':ip:'.$ip),
                    \Illuminate\Cache\RateLimiting\Limit::perMinute($attempts)->by($name.':account:'.$account),
                ];
            });
        }
        \Illuminate\Support\Facades\RateLimiter::for('auth-create', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by(hash('sha256', (string) $request->ip())));
        // DB・マスタ・公開リンクの検証と更新はデプロイ処理で行う。
    }
}
