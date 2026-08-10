<?php

namespace App\Http\Controllers;

use App\Services\WebPushEligibilityService;
use App\Services\WebPushSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class WebPushSubscriptionController extends Controller
{
    public function store(
        Request $request,
        WebPushEligibilityService $eligibility,
        WebPushSubscriptionService $subscriptions
    ): JsonResponse {
        $character = $request->user()?->currentCharacter();

        abort_unless(
            $character !== null
                && Schema::hasTable('web_push_subscriptions')
                && $eligibility->canSubscribe($character),
            404
        );

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:512', 'regex:/^[A-Za-z0-9_-]+$/'],
            'keys.auth' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9_-]+$/'],
            'contentEncoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        $subscriptions->subscribe(
            $character,
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm'
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(
        Request $request,
        WebPushEligibilityService $eligibility,
        WebPushSubscriptionService $subscriptions
    ): JsonResponse {
        $character = $request->user()?->currentCharacter();

        abort_unless(
            $character !== null
                && Schema::hasTable('web_push_subscriptions')
                && $eligibility->canSubscribe($character),
            404
        );

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
        ]);

        $subscriptions->unsubscribe($character, $validated['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
