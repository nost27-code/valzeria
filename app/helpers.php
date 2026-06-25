<?php

if (!function_exists('game_text')) {
    function game_text(string $key, string $default = ''): string
    {
        return app(\App\Services\GameTextService::class)->get($key, $default);
    }
}
