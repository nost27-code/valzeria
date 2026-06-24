<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Models\ShopItem;
use App\Models\City;
use App\Models\Item;

$city = City::find(3); // 精霊都市エルフィア
$shop = Shop::where('city_id', 3)->where('type', 'weapon')->first();
if ($shop) {
    echo "Weapon shop found for city 3\n";
    $items = $shop->items()->where('type', 'weapon')->get();
    foreach($items as $i) {
        echo "Lv {$i->required_level} - {$i->name}: STR+{$i->str_bonus} (Price: {$i->price})\n";
    }
} else {
    // maybe there's no ShopItem model? Let's check how shops work.
    echo "No shop found.\n";
}
