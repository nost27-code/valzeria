<?php

namespace App\Providers;

use App\Services\SchemaStateService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SchemaStateService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // DB・マスタ・公開リンクの検証と更新はデプロイ処理で行う。
    }
}
