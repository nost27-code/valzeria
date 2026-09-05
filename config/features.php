<?php

$defaultsEnabled = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    // Preview and official raid publication are independent; both default OFF.
    'nation_competitive_raid_preview_enabled' => filter_var(
        env('NATION_COMPETITIVE_RAID_PREVIEW_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    'nation_competitive_raid_enabled' => filter_var(env('NATION_COMPETITIVE_RAID_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Compatibility key only; event state is controlled by the lifecycle service.
    'nation_competitive_raid_active' => false,

    // 管理者専用。全環境で既定OFFとし、運用が明示的に有効化した時だけ公開する。
    'valzeria_lab_enabled' => filter_var(
        env('VALZERIA_LAB_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

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

    // Player-facing nation dashboard preview. All buttons remain non-mutating
    // while the nation-war feature and its operational gates stay disabled.
    'nation_screen_enabled' => filter_var(
        env('NATION_SCREEN_ENABLED', true),
        FILTER_VALIDATE_BOOL,
    ),

    // Nation community is released independently from nation-war operations.
    // Local/testing may use the default; production requires an explicit env flag.
    'nation_community_enabled' => filter_var(
        env('NATION_COMMUNITY_ENABLED', $defaultsEnabled),
        FILTER_VALIDATE_BOOL,
    ),

    // Nation donations and development levels are released separately from war.
    // Local/testing may use the default; production requires an explicit env flag.
    'nation_development_enabled' => filter_var(
        env('NATION_DEVELOPMENT_ENABLED', $defaultsEnabled),
        FILTER_VALIDATE_BOOL,
    ),

    // Nation level benefits other than the level-based member capacity remain
    // hidden until operations explicitly enables them in production.
    'nation_level_benefits_enabled' => filter_var(
        env('NATION_LEVEL_BENEFITS_ENABLED', $defaultsEnabled),
        FILTER_VALIDATE_BOOL,
    ),

    // Keep nation gameplay disabled until operations explicitly enables it.
    'nation_war_enabled' => filter_var(
        env('NATION_WAR_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),
];
