<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;

$shopWeapons = Item::where('type', 'weapon')
    ->where('is_shop_item', true)
    ->whereIn('required_level', range(40, 130))
    ->orderBy('required_level')
    ->get(['name', 'required_level', 'str_bonus', 'price']);

foreach($shopWeapons as $w) {
    echo "Lv {$w->required_level} - {$w->name}: STR+{$w->str_bonus} (Price: {$w->price})\n";
}
