<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;

$items = Item::where('type', 'weapon')
    ->where('is_shop_item', true)
    ->where('unlock_city_id', 3)
    ->get(['name', 'required_level', 'str_bonus', 'price']);

foreach($items as $i) {
    echo "Lv {$i->required_level} - {$i->name}: STR+{$i->str_bonus} (Price: {$i->price})\n";
}
