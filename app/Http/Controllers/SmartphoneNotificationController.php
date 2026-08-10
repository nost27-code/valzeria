<?php

namespace App\Http\Controllers;

use App\Services\WebPushPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SmartphoneNotificationController extends Controller
{
    public function edit(
        Request $request,
        WebPushPreferenceService $preferences
    ): View {
        $character = $request->user()->currentCharacter();

        return view('smartphone-notifications', [
            'catalog' => $preferences->catalog(),
            'enabledTypes' => $preferences->enabledKeys($character),
        ]);
    }

    public function update(Request $request, WebPushPreferenceService $preferences): RedirectResponse
    {
        abort_unless(Schema::hasTable('character_web_push_preferences'), 503);

        $validated = $request->validate([
            'types' => ['nullable', 'array', 'max:'.count($preferences->allowedKeys())],
            'types.*' => ['string', 'distinct', Rule::in($preferences->allowedKeys())],
        ]);

        $preferences->save(
            $request->user()->currentCharacter(),
            array_values((array) ($validated['types'] ?? []))
        );

        return redirect()
            ->route('smartphone-notifications.edit')
            ->with('message', 'スマホ通知の種類を保存しました。');
    }
}
