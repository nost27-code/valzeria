<?php

// Laravelのブートストラップを読み込み
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

try {
    echo "Running migrations...<br>";
    Artisan::call('migrate', ['--force' => true]);
    echo nl2br(Artisan::output()) . "<br>";
    
    echo "Running basic jobs fix...<br>";
    if (file_exists(base_path('fix_basic_jobs.php'))) {
        require_once base_path('fix_basic_jobs.php');
        echo "Basic jobs fix completed.<br>";
    } else {
        echo "fix_basic_jobs.php not found.<br>";
    }
    
    echo "Running enemies data update...<br>";
    if (file_exists(base_path('update_enemies_data.php'))) {
        require_once base_path('update_enemies_data.php');
        echo "Enemies data update completed.<br>";
    } else {
        echo "update_enemies_data.php not found.<br>";
    }

    echo "Update complete.<br>";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
