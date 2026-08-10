<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\WebPushSubscription;

class WebPushSubscriptionService
{
    public function subscribe(
        Character $character,
        string $endpoint,
        string $publicKey,
        string $authToken,
        string $contentEncoding = 'aes128gcm'
    ): WebPushSubscription {
        $endpointHash = hash('sha256', $endpoint);
        $subscription = WebPushSubscription::query()
            ->where('endpoint_hash', $endpointHash)
            ->first();

        $isNewOwner = $subscription === null
            || (int) $subscription->character_id !== (int) $character->getKey();

        if ($subscription === null) {
            $subscription = new WebPushSubscription([
                'endpoint_hash' => $endpointHash,
            ]);
        }

        $subscription->forceFill([
            'character_id' => $character->getKey(),
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => $contentEncoding,
        ]);

        if ($isNewOwner) {
            $subscription->last_notification_id = $this->latestNotificationId($character);
        }

        $subscription->save();

        return $subscription;
    }

    public function unsubscribe(Character $character, string $endpoint): bool
    {
        return WebPushSubscription::query()
            ->where('character_id', $character->getKey())
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete() > 0;
    }

    private function latestNotificationId(Character $character): int
    {
        return (int) (CharacterNotification::query()
            ->where('character_id', $character->getKey())
            ->max('id') ?? 0);
    }
}
