<?php

namespace App\Http\Controllers;

use App\Services\GameHealthCheckService;
use Illuminate\Http\JsonResponse;

class GameHealthController extends Controller
{
    public function show(GameHealthCheckService $healthCheck): JsonResponse
    {
        $result = $healthCheck->check();

        return response()
            ->json($result, $result['ok'] ? 200 : 503, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
    }
}
