<?php

$defaultsEnabled = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    /*
    |--------------------------------------------------------------------------
    | 準備中機能
    |--------------------------------------------------------------------------
    |
    | ローカル環境では実装確認を続けられるよう既定でONにし、それ以外の
    | 環境では明示的に有効化するまでOFFにする。
    |
    */
    'player_shops_enabled' => filter_var(
        env('PLAYER_SHOPS_ENABLED', $defaultsEnabled),
        FILTER_VALIDATE_BOOL,
    ),

    'duplicate_valmon_egg_discovery_enabled' => filter_var(
        env('DUPLICATE_VALMON_EGG_DISCOVERY_ENABLED', $defaultsEnabled),
        FILTER_VALIDATE_BOOL,
    ),
];
