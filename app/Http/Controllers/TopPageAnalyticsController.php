<?php

namespace App\Http\Controllers;

use App\Services\TopPageAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TopPageAnalyticsController extends Controller
{
    public function event(Request $request, TopPageAnalyticsService $analytics): Response
    {
        $validated = $request->validate([
            'visit_uuid' => ['nullable', 'uuid'],
            'event_name' => ['required', 'string', 'max:80'],
            'label' => ['nullable', 'string', 'max:200'],
            'href' => ['nullable', 'string', 'max:500'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $analytics->recordEvent(
            $request,
            (string) ($validated['visit_uuid'] ?? ''),
            (string) $validated['event_name'],
            $validated
        );

        return response('', 204);
    }
}
