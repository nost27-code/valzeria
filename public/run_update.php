<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // キャッシュクリア
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    echo "Cache cleared.<br>";

    // Seeder実行
    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'JobSystemSeeder', '--force' => true]);
    echo "JobSystemSeeder executed.<br>";

    // ボスドロップの設定スクリプト相当の処理をここに書く
    $bosses = App\Models\Enemy::where('is_boss', true)->get();
    $items = App\Models\Item::where('name', 'like', '%刻印%')
                ->orWhere('name', 'like', '%王印%')
                ->orWhere('name', 'like', '%神印%')
                ->orWhere('name', 'like', '%の印%')
                ->get();

    $updated = 0;
    foreach ($bosses as $boss) {
        $matchedItem = null;
        $candidates = [
            $boss->name . 'の刻印',
            $boss->name . 'の王印',
            $boss->name . 'の神印',
            $boss->name . 'の印',
        ];
        
        foreach ($candidates as $cand) {
            $found = $items->firstWhere('name', $cand);
            if ($found) {
                $matchedItem = $found;
                break;
            }
        }
        
        if (!$matchedItem) {
            foreach ($items as $item) {
                if (strpos($item->name, $boss->name) !== false) {
                    $matchedItem = $item;
                    break;
                }
                $bossBaseName = str_replace(['の刻印', 'の王印', 'の神印', 'の印'], '', $item->name);
                if (strpos($boss->name, $bossBaseName) !== false && mb_strlen($bossBaseName) > 2) {
                    $matchedItem = $item;
                    break;
                }
            }
        }

        if ($matchedItem) {
            App\Models\EnemyDrop::updateOrCreate(
                ['enemy_id' => $boss->id, 'item_id' => $matchedItem->id],
                ['drop_rate' => 100]
            );
            $updated++;
        }
    }
    echo "Boss drops updated: {$updated}<br>";
    echo "All updates completed successfully.";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
