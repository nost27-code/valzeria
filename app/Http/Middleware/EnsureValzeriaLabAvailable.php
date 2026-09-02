<?php

namespace App\Http\Middleware;

use App\Services\Admin\ValzeriaLabAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureValzeriaLabAvailable
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        ValzeriaLabAccess::ensureEnabled();

        return $next($request);
    }
}
