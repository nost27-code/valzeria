<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminViewerAccess
{
    public static function fingerprint(): ?string
    {
        $email = (string) config('admin_viewer.email');
        $hash = (string) config('admin_viewer.password_hash');

        return $email !== '' && password_get_info($hash)['algo'] !== null
            ? hash_hmac('sha256', $email.'|'.$hash, (string) config('app.key'))
            : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $expected = self::fingerprint();
        $actual = $request->session()->get('admin_viewer.fingerprint');
        if ($expected === null || !is_string($actual) || !hash_equals($expected, $actual)) {
            $request->session()->forget('admin_viewer');
            return redirect()->route('admin.viewer.login');
        }

        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 405);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
