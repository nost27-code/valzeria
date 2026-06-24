<?php

// update_enemy_drops.php
// execute-update の中から require されるため、Laravelブートストラップは不要ですが、
// 直接実行（ローカルテストなど）のときだけブートストラップするように条件分岐します。
if (!defined('LARAVEL_START') && file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    $app = require_once __DIR__ . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

use App\Models\Enemy;
use App\Models\Item;
use App\Models\EnemyDrop;
use Illuminate\Support\Facades\DB;

$mapping = [
    'ロックワームの岩鎧' => [
        ['古洞ワーム', '古洞ロックワームの岩鎧'],
        ['小鬼の森の親分', '古洞ロックワームの岩鎧'],
        ['訓練教官バルド', '古洞ロックワームの岩鎧'],
        ['機械ネズミ', '古洞ロックワームの岩鎧'],
        ['海蝕ワーム', '海蝕ロックワームの岩鎧'],
        ['氷結ワーム', '氷結ロックワームの岩鎧'],
    ],
    'ストーンゴーレムの岩鎧' => [
        ['氷結ゴーレム', '氷結ストーンゴーレムの岩鎧'],
        ['シーラット', '氷結ストーンゴーレムの岩鎧'],
        ['上層世界樹精', '氷結ストーンゴーレムの岩鎧'],
        ['量産機械ゴーレム', '氷結ストーンゴーレムの岩鎧'],
        ['海蝕ゴーレム', '氷結ストーンゴーレムの岩鎧'],
    ],
    '量産ゴーレムの岩鎧' => [
        ['量産機械ゴーレム', '兵工量産ゴーレムの岩鎧'],
        ['蒸気ゴーレム', '兵工量産ゴーレムの岩鎧'],
        ['月光光蝶', '兵工量産ゴーレムの岩鎧'],
        ['鉄殻虫', '兵工量産ゴーレムの岩鎧'],
    ],
    '星の守護者の鎧' => [
        ['終焉星守護者', '終焉星守護者の鎧'],
        ['神域星守護者', '終焉星守護者の鎧'],
        ['魔神神殿兵', '終焉星守護者の鎧'],
        ['天空剣士セラフ', '終焉星守護者の鎧'],
    ],
    '封印の守護者の鎧' => [
        ['精霊封印守', '精霊封印守護者の鎧'],
        ['氷封封印守', '凍結封印守護者の鎧'],
        ['砂嵐封印守', '砂嵐封印守護者の鎧'],
        ['太陽封印守', '太陽封印守護者の鎧'],
        ['魔神封印守', '悪魔封印守護者の鎧'],
        ['雷鳴封印守', '雷鳴封印守護者の鎧'],
        ['海賊下っ端', '精霊封印守護者の鎧'],
        ['ルートワーム', '精霊封印守護者の鎧'],
        ['雷動コア・ヴォルト', '精霊封印守護者の鎧'],
        ['氷封神殿主', '凍結封印守護者の鎧'],
        ['氷巨人', '凍結封印守護者の鎧'],
        ['黒インクの魔物', '精霊封印守護者の鎧'],
        ['次元空間裂け目', '精霊封印守護者の鎧'],
        ['深海封印守', '精霊封印守護者の鎧'],
    ]
];

DB::beginTransaction();
try {
    $updatedCount = 0;
    foreach ($mapping as $oldArmorName => $targets) {
        $oldItem = Item::where('name', $oldArmorName)->where('type', 'armor')->first();
        if (!$oldItem) {
            echo "Warning: Old armor '$oldArmorName' not found in DB. Skipping.\n";
            continue;
        }

        foreach ($targets as $target) {
            $enemyName = $target[0];
            $newArmorName = $target[1];

            $enemy = Enemy::where('name', $enemyName)->first();
            $newItem = Item::where('name', $newArmorName)->where('type', 'armor')->first();

            if (!$enemy) {
                echo "Warning: Enemy '$enemyName' not found. Skipping.\n";
                continue;
            }
            if (!$newItem) {
                echo "Warning: New armor '$newArmorName' not found. Skipping.\n";
                continue;
            }

            $drop = EnemyDrop::where('enemy_id', $enemy->id)
                ->where('item_id', $oldItem->id)
                ->first();

            if ($drop) {
                $drop->item_id = $newItem->id;
                $drop->save();
                echo "Updated: Enemy '{$enemy->name}' drop '{$oldArmorName}' -> '{$newArmorName}' (ID: {$newItem->id})\n";
                $updatedCount++;
            } else {
                $exists = EnemyDrop::where('enemy_id', $enemy->id)
                    ->where('item_id', $newItem->id)
                    ->exists();
                if (!$exists) {
                    EnemyDrop::create([
                        'enemy_id' => $enemy->id,
                        'item_id' => $newItem->id,
                        'drop_rate' => 2.0,
                        'is_active' => true
                    ]);
                    echo "Created: Enemy '{$enemy->name}' drop '{$newArmorName}' (ID: {$newItem->id})\n";
                    $updatedCount++;
                }
            }
        }

        $oldItem->is_active = false;
        $oldItem->save();
        echo "Deactivated old armor: '{$oldArmorName}' (ID: {$oldItem->id})\n";
    }

    DB::commit();
    echo "Successfully updated $updatedCount enemy drop records.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
