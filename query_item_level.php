<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;
$item = Item::where('name', '庭園の騎士の剣')->first();
if ($item) {
    echo "Level: {$item->required_level}\n";
}
