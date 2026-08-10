<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WebPushDispatchService
{
    public function __construct(
        private readonly WebPushEligibilityService $eligibility,
        private readonly WebPushSender $sender
    ) {}

    /**
     * @return array{scanned: int, sent: int, expired: int, failed: int, skipped: int, misconfigured: int}
     */
    public function dispatch(): array
    {
        $result = [
            'scanned' => 0,
            'sent' => 0,
            'expired' => 0,
            'failed' => 0,
            'skipped' => 0,
            'misconfigured' => 0,
        ];

        if (! Schema::hasTable('web_push_subscriptions') || ! Schema::hasTable('character_notifications')) {
            return $result;
        }

        WebPushSubscription::query()
            ->with('character')
            ->orderBy('id')
            ->chunkById((int) config('web_push.batch_size', 20), function ($subscriptions) use (&$result): void {
                foreach ($subscriptions as $subscription) {
                    $result['scanned']++;
                    $character = $subscription->character;

                    if (! $character instanceof Character) {
                        $subscription->delete();
                        $result['expired']++;
                        continue;
                    }

                    if (! $this->eligibility->isAllowed($character)) {
                        $this->advanceToLatest($subscription, $character);
                        $result['skipped']++;
                        continue;
                    }

                    if (! $this->eligibility->isConfigured()) {
                        $result['misconfigured']++;
                        continue;
                    }

                    $notification = CharacterNotification::query()
                        ->where('character_id', $character->getKey())
                        ->where('id', '>', $subscription->last_notification_id)
                        ->active()
                        ->latest('id')
                        ->first(['id']);

                    if ($notification === null) {
                        $this->advanceToLatest($subscription, $character);
                        continue;
                    }

                    try {
                        $delivery = $this->sender->send($subscription, [
                            'title' => 'ヴァルゼリアの冒険者',
                            'body' => '通知ベルに新着があります。',
                            'tag' => 'valzeria-bell',
                            'data' => [
                                'url' => '/home',
                                'notificationId' => (int) $notification->id,
                            ],
                        ]);
                    } catch (\Throwable $exception) {
                        Log::warning('Web Push delivery failed.', [
                            'subscription_id' => $subscription->getKey(),
                            'character_id' => $character->getKey(),
                            'exception' => $exception::class,
                        ]);
                        $result['failed']++;
                        continue;
                    }

                    if ($delivery['expired']) {
                        $subscription->delete();
                        $result['expired']++;
                        continue;
                    }

                    if (! $delivery['success']) {
                        $result['failed']++;
                        continue;
                    }

                    $subscription->forceFill([
                        'last_notification_id' => (int) $notification->id,
                    ])->save();
                    $result['sent']++;
                }
            });

        return $result;
    }

    private function advanceToLatest(WebPushSubscription $subscription, Character $character): void
    {
        $latestId = (int) (CharacterNotification::query()
            ->where('character_id', $character->getKey())
            ->max('id') ?? 0);

        if ($latestId > $subscription->last_notification_id) {
            $subscription->forceFill(['last_notification_id' => $latestId])->save();
        }
    }
}
