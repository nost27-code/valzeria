<?php

// Bootstrap removed to allow require from within Laravel routes

use App\Models\Enemy;
use Illuminate\Support\Facades\DB;

$lines = file(base_path('enemies_data.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(array_shift($lines), "\t");

DB::beginTransaction();
try {
    $count = 0;
    foreach ($lines as $line) {
        $cols = str_getcsv($line, "\t");
        if (count($cols) < count($header)) {
            // パディング
            $cols = array_pad($cols, count($header), '');
        }

        $enemyId = $cols[0];
        $name = $cols[1];
        $isBoss = strtoupper(trim($cols[2])) === 'TRUE';
        $areaId = $cols[9]; // dungeon_id
        $role = $cols[12];
        $level = $cols[13];
        $element = $cols[14]; // attribute
        $type_name = $cols[15]; // type
        $hp = $cols[16];
        $str = $cols[17];
        $def = $cols[18];
        $agi = $cols[19];
        $mag = $cols[20];
        $spr = $cols[21];
        $luk = $cols[22];
        $exp = $cols[23];
        $jobExp = $cols[24];
        $gold = $cols[25];
        $appWeightRaw = $cols[26];
        $dropTypeRaw = $cols[28]; // rare_drop_category
        $behavior = $cols[31];

        // weight
        if ($appWeightRaw === '') {
            $appWeight = match ($role) {
                '雑魚' => 100,
                'やや強い' => 70,
                'レア敵' => 20,
                '最深部候補' => 40,
                'ボス候補' => 40,
                'ボス' => 0,
                default => 50,
            };
        } else {
            $appWeight = (int)$appWeightRaw;
        }

        // is_boss が true の場合、appWeight は基本的に 0 (通常エンカウントしない)
        if ($isBoss) {
            $appWeight = 0;
        }

        // drop_type
        $dropType = $dropTypeRaw !== '' ? $dropTypeRaw : '通常';

        // behavior
        $actionPattern = $behavior !== '' ? $behavior : '標準';

        Enemy::updateOrCreate(
            ['id' => $enemyId],
            [
                'area_id' => $areaId,
                'name' => $name,
                'level' => $level,
                'max_hp' => $hp,
                'str' => $str,
                'def' => $def,
                'agi' => $agi,
                'mag' => $mag,
                'spr' => $spr,
                'luk' => $luk,
                'exp_reward' => $exp,
                'job_exp_reward' => $jobExp,
                'gold_reward' => $gold,
                'appearance_weight' => $appWeight,
                'is_boss' => $isBoss,
                'role' => $role,
                'type_name' => $type_name,
                'element' => $element,
                'action_pattern' => $actionPattern,
                'drop_type' => $dropType,
                'sort_order' => 0, // 必要に応じて
            ]
        );
        $count++;
    }
    DB::commit();
    echo "Successfully updated {$count} enemies.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
