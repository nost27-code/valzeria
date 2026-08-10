<?php

namespace App\Services;

use App\Models\WebPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushSender
{
    private ?WebPush $webPush = null;

    /**
     * @return array{success: bool, expired: bool}
     */
    public function send(WebPushSubscription $storedSubscription, array $payload): array
    {
        $subscription = Subscription::create([
            'endpoint' => $storedSubscription->endpoint,
            'publicKey' => $storedSubscription->public_key,
            'authToken' => $storedSubscription->auth_token,
            'contentEncoding' => $storedSubscription->content_encoding,
        ]);

        $report = $this->webPush()->sendOneNotification(
            $subscription,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return [
            'success' => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
        ];
    }

    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush(
            [
                'VAPID' => [
                    'subject' => (string) config('web_push.vapid.subject'),
                    'publicKey' => (string) config('web_push.vapid.public_key'),
                    'privateKey' => (string) config('web_push.vapid.private_key'),
                ],
            ],
            [
                'TTL' => (int) config('web_push.ttl', 3600),
            ]
        );
    }
}
