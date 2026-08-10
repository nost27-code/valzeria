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

    private bool $eligible;

    public function __construct(WebPushEligibilityService $eligibility)
    {
        $character = Auth::user()?->currentCharacter();

        $this->eligible = $character !== null
            && Schema::hasTable('web_push_subscriptions')
            && $eligibility->canSubscribe($character);
        $this->vapidPublicKey = (string) config('web_push.vapid.public_key', '');
    }

    public function shouldRender(): bool
    {
        return $this->eligible;
    }

    public function render(): View
    {
        return view('components.web-push-control');
    }
}
