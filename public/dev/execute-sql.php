<?php
// 本番サーバー上でSQLファイルをDBに流し込むエンドポイント（使い捨て）
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$sqlFile = __DIR__ . '/update_stats_and_prices.sql';
if (!file_exists($sqlFile)) {
    echo "SQL file not found.\n";
    exit;
}

$sql = file_get_contents($sqlFile);
try {
    DB::unprepared($sql);
    echo "Successfully executed update_stats_and_prices.sql.\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
}
