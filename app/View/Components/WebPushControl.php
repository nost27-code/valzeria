<?php

namespace App\View\Components;

use App\Services\WebPushEligibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Component;

class WebPushControl extends Component
{
    public string $vapidPublicKey;

    public bool $eligible;

    public int $characterId;

    public string $unavailableMessage;

    public function __construct(
        WebPushEligibilityService $eligibility,
        public bool $detailed = false,
        public bool $showUnavailable = false
    ) {
        $character = Auth::user()?->currentCharacter();
        $hasSubscriptionTable = Schema::hasTable('web_push_subscriptions');

        $this->eligible = $character !== null
            && $hasSubscriptionTable
            && $eligibility->canSubscribe($character);
        $this->characterId = (int) ($character?->getKey() ?? 0);
        $this->vapidPublicKey = (string) config('web_push.vapid.public_key', '');
        $this->unavailableMessage = match (true) {
            $character === null => 'キャラクターを選ぶと通知を設定できます。',
            ! $hasSubscriptionTable => '現在、通知設定を準備しています。',
            ! $eligibility->isAllowed($character) => $eligibility->mode() === 'allowlist'
                ? '現在、このキャラクターは先行テストの対象外です。'
                : '現在、スマホ通知は準備中です。',
            ! $eligibility->isConfigured() => '現在、通知サーバーの設定を準備しています。',
            default => '',
        };
    }

    public function shouldRender(): bool
    {
        return $this->showUnavailable || $this->eligible;
    }

    public function render(): View
    {
        return view('components.web-push-control');
    }
}
