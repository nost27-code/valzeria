<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \App\Models\Item::query()
    ->where('type', 'weapon')
    ->whereIn('weapon_rank', ['S', 'SS', 'SSS', 'EPIC'])
    ->get(['name', 'weapon_category', 'weapon_rank', 'str_bonus', 'mag_bonus', 'def_bonus', 'spr_bonus', 'agi_bonus'])
    ->groupBy('weapon_category');

foreach ($rows as $category => $items) {
    echo "=== {$category} ===\n";
    foreach ($items->sortBy('weapon_rank') as $item) {
        echo "{$item->weapon_rank}\t{$item->name}\tSTR={$item->str_bonus}\tMAG={$item->mag_bonus}\tDEF={$item->def_bonus}\tSPR={$item->spr_bonus}\tAGI={$item->agi_bonus}\n";
    }
}
