<?php

namespace App\Services;

use App\Models\Character;
use Minishlink\WebPush\VAPID;

class WebPushEligibilityService
{
    public function mode(): string
    {
        return (string) config('web_push.mode', 'off');
    }

    public function isAllowed(Character $character): bool
    {
        if ($this->mode() !== 'allowlist') {
            return false;
        }

        return in_array(
            (int) $character->getKey(),
            array_map('intval', (array) config('web_push.allowed_character_ids', [])),
            true
        );
    }

    public function isConfigured(): bool
    {
        $subject = trim((string) config('web_push.vapid.subject', ''));
        $publicKey = trim((string) config('web_push.vapid.public_key', ''));
        $privateKey = trim((string) config('web_push.vapid.private_key', ''));

        $isMailto = str_starts_with($subject, 'mailto:')
            && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        $scheme = strtolower((string) parse_url($subject, PHP_URL_SCHEME));
        $isUrl = in_array($scheme, ['http', 'https'], true)
            && filter_var($subject, FILTER_VALIDATE_URL) !== false;

        if (! $isMailto && ! $isUrl) {
            return false;
        }

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function canSubscribe(Character $character): bool
    {
        return $this->isAllowed($character) && $this->isConfigured();
    }
}
