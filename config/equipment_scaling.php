<?php

return [
    'weapon' => [
        // ランク別「固定値倍率」。武器のSTR/MAG主能力(items.str_bonus / mag_bonus)は
        // 基準値(str_bonus_base / mag_bonus_base) × この倍率 で再計算される。
        // 反映には database/seeders/WeaponStatRescaleSeeder.php の実行が必要（冪等・複数回実行可）。
        //
        // 固定値の変更だけを切り戻したい場合は、この倍率をすべて1.0へ戻してから
        // 同Seederを再実行する（items.str_bonus/mag_bonusが基準値まで戻る）。
        'fixed_multiplier' => [
            'G' => 1.0,
            'F' => 1.0,
            'E' => 1.0,
            'D' => 1.0,
            'C' => 1.0,
            'B' => 1.0,
            'A' => 1.8,
            'S' => 2.5,
            'SS' => 2.5,
            'SSS' => 2.5,
            'EPIC' => 2.5,
        ],

        // ランク別「比例補正率」。装備前主能力（職業・転職蓄積・モンスターマークのみで、
        // 装備を一切含まない状態のATK/MAG）に、この率を掛けた値を武器補正へ加算する。
        'proportional_rate' => [
            'G' => 0.0,
            'F' => 0.0,
            'E' => 0.0,
            'D' => 0.0,
            'C' => 0.0,
            'B' => 0.01,
            'A' => 0.02,
            'S' => 0.04,
            'SS' => 0.07,
            'SSS' => 0.11,
            'EPIC' => 0.16,
        ],

        // 比例補正だけを即時に無効化するスイッチ。固定値(itemsテーブル)には触れず、
        // CharacterStatusServiceが加算する比例補正のみを0にする。
        // デフォルトfalse: 本番有効化は別途の確認完了後に .env で true にする。
        // （テスト環境は phpunit.xml でtrueに固定し、新仕様の動作を検証する）
        'proportional_enabled' => env('EQUIPMENT_WEAPON_PROPORTIONAL_ENABLED', false),
    ],
];
