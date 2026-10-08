<?php

namespace App\Providers;

use App\Services\RequestPerformanceCollector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class RequestPerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestPerformanceCollector::class);
    }

    public function boot(): void
    {
        Event::listen(QueryExecuted::class, function ($event): void {
            try {
                app(RequestPerformanceCollector::class)->query($event);
            } catch (Throwable) {
                app(RequestPerformanceCollector::class)->reset();
            }
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            Event::listen($event, function ($event): void {
                try {
                    app(RequestPerformanceCollector::class)->transaction(
                        $event->connectionName ?? 'unnamed-'.spl_object_id($event->connection),
                        $event->connection->transactionLevel(), $event instanceof TransactionBeginning,
                    );
                } catch (Throwable) {
                    app(RequestPerformanceCollector::class)->reset();
                }
            });
        }
    }
}
