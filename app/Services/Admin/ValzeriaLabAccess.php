<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Auth;

final class ValzeriaLabAccess
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging', 'production'];

    public static function enabled(): bool
    {
        return app()->environment(self::ALLOWED_ENVIRONMENTS)
            && (bool) config('features.valzeria_lab_enabled', false);
    }

    public static function ensureEnabled(): void
    {
        abort_unless(self::enabled(), 404);
    }

    public static function ensureAuthorized(): void
    {
        self::ensureEnabled();

        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }
}
