<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$row = App\Models\CharacterMaterial::find(1);
echo $row ? "id=1 still exists, quantity={$row->quantity}" : "id=1 deleted (fully consumed)";
echo PHP_EOL;

$log = App\Models\ValmonFeedLog::latest('id')->first();
echo "last feed log: quantity={$log->quantity} gained_exp={$log->gained_exp} feed_type={$log->feed_type}" . PHP_EOL;
