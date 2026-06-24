<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;

$shopWeapons = Item::where('type', 'weapon')
    ->where('is_shop_item', true)
    ->whereIn('required_level', range(40, 70))
    ->orderBy('required_level')
    ->get(['name', 'required_level', 'str_bonus', 'price']);

echo "Level 40-70 Shop Weapons:\n";
foreach($shopWeapons as $w) {
    echo "Lv {$w->required_level} - {$w->name}: STR+{$w->str_bonus} (Price: {$w->price})\n";
}

$shopWeapons2 = Item::where('type', 'weapon')
    ->where('is_shop_item', true)
    ->whereIn('required_level', range(110, 150))
    ->orderBy('required_level')
    ->get(['name', 'required_level', 'str_bonus', 'price']);

echo "\nLevel 110-150 Shop Weapons:\n";
foreach($shopWeapons2 as $w) {
    echo "Lv {$w->required_level} - {$w->name}: STR+{$w->str_bonus} (Price: {$w->price})\n";
}
