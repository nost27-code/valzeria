<?php

namespace App\Http\Middleware;

use App\Support\DatabaseConnectionCooldown;
use App\Support\DatabaseContention;
use Closure;
use Illuminate\Http\Request;

class RejectDuringDatabaseConnectionCooldown
{
    public function __construct(private DatabaseConnectionCooldown $cooldown) {}

    public function handle(Request $request, Closure $next)
    {
        // Keep payment-provider webhook handling on its existing path.
        if ($request->is('stripe/webhook')) {
            return $next($request);
        }

        $remaining = $this->cooldown->remainingSeconds();
        if ($remaining === 0) {
            return $next($request);
        }

        return DatabaseContention::response($request, $remaining);
    }
}
