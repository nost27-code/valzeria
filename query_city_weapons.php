<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\City;

$city = City::find(3); // 精霊都市エルフィア
echo "City: " . $city->name . "\n";
$weapons = $city->weapons;
if ($weapons) {
    foreach($weapons as $w) {
        echo "Lv {$w->required_level} - {$w->name}: STR+{$w->str_bonus} (Price: {$w->price})\n";
    }
} else {
    echo "No weapons found via relation.\n";
}
