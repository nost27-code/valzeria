<?php

use App\Livewire\ChampCard;
use App\Livewire\ChatLog;
use App\Livewire\ColosseumRanking;
use App\Livewire\ColosseumScreen;
use App\Livewire\JobChange;
use App\Livewire\MainScreen;
use App\Livewire\MainScreenShell;
use App\Livewire\NationScreen;

return [
    'enabled' => env('REQUEST_PERFORMANCE_ENABLED', true),
    'path' => storage_path('app/private/request-performance'),
    'detail_sample_rate' => (float) env('REQUEST_PERFORMANCE_SAMPLE_RATE', 0.2),
    'max_query_shapes' => 64,
    'max_saved_queries' => 12,
    'max_bucket_bytes' => 131072,
    'retention_hours' => 48,
    'max_read_bytes' => 8388608,
    'max_read_records' => 20000,
    'slow_sql_ms' => 100,
    'slow_transaction_ms' => 500,
    'components' => [
        'main-screen' => MainScreen::class,
        'main-screen-shell' => MainScreenShell::class,
        'colosseum-screen' => ColosseumScreen::class,
        'colosseum-ranking' => ColosseumRanking::class,
        'champ-card' => ChampCard::class,
        'chat-log' => ChatLog::class,
        'job-change' => JobChange::class,
        'nation-screen' => NationScreen::class,
    ],
];
