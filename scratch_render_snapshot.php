<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$character = \App\Models\Character::query()->orderBy('id')->first();
$service = $app->make(\App\Services\ItemBookService::class);
$book = $service->materialBookFor($character);

$html = view('item-book.index', compact('character', 'book'))->render();
@mkdir(__DIR__ . '/public/_preview', 0777, true);
file_put_contents(__DIR__ . '/public/_preview/item_book.html', $html);
echo "written\n";
