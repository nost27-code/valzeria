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

    // Keep disabled until operations explicitly replaces the legacy arena.
    'six_hero_ui_enabled' => filter_var(
        env('SIX_HERO_UI_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),
];
