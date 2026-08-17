<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WebPushDispatchService
{
    public function __construct(
        private readonly WebPushEligibilityService $eligibility,
        private readonly WebPushPreferenceService $preferences,
        private readonly WebPushSender $sender,
        private readonly AdminWebPushNotificationService $adminNotifications,
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

        $characterRelation = Schema::hasTable('character_web_push_preferences')
            ? 'character.webPushPreference'
            : 'character';

        WebPushSubscription::query()
            ->with($characterRelation)
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

                    $latestId = $this->latestNotificationId($character);
                    $notificationQuery = CharacterNotification::query()
                        ->where('character_id', $character->getKey())
                        ->where('id', '>', $subscription->last_notification_id)
                        ->active();
                    $adminNotificationCount = 0;
                    $regularNotificationCount = 0;
                    $notification = null;
                    $regularNotificationQuery = $this->preferences
                        ->applyNotificationFilter(
                            (clone $notificationQuery)->whereNotIn(
                                'type',
                                AdminWebPushNotificationService::types(),
                            ),
                            $character,
                        );

                    if ($this->adminNotifications->isRecipient($character)) {
                        $adminNotificationQuery = (clone $notificationQuery)
                            ->whereIn('type', AdminWebPushNotificationService::types());
                        $adminNotificationCount = (clone $adminNotificationQuery)->count();
                        $notification = $adminNotificationQuery
                            ->latest('id')
                            ->first(['id', 'type', 'title', 'url']);

                        if ($notification !== null) {
                            $regularNotificationCount = (clone $regularNotificationQuery)->count();
                        }
                    }

                    if ($notification === null) {
                        $notification = $regularNotificationQuery
                            ->latest('id')
                            ->first(['id', 'type', 'title', 'url']);
                    }

                    if ($notification === null) {
                        $this->advanceToLatest($subscription, $latestId);

                        continue;
                    }

                    try {
                        $delivery = $this->sender->send($subscription, [
                            'title' => 'ヴァルゼリアの冒険者',
                            'body' => $this->notificationBody(
                                $notification,
                                $adminNotificationCount,
                                $regularNotificationCount,
                            ),
                            'tag' => $this->notificationTag($notification),
                            'data' => [
                                'url' => $this->notificationUrl($notification, $adminNotificationCount),
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
                        'last_notification_id' => max($latestId, (int) $notification->id),
                    ])->save();
                    $result['sent']++;
                }
            });

        return $result;
    }

    private function notificationBody(
        CharacterNotification $notification,
        int $adminNotificationCount,
        int $regularNotificationCount,
    ): string
    {
        $genericBody = '通知ベルに新着があります。';

        if (AdminWebPushNotificationService::isType((string) $notification->type)) {
            if ($regularNotificationCount > 0) {
                return '管理画面と通知ベルに新着があります。';
            }

            if ($adminNotificationCount > 1) {
                return "管理画面に新着が{$adminNotificationCount}件あります。";
            }

            return $this->sanitizedTitle($notification) ?: '管理画面に新着があります。';
        }

        if ((string) config('web_push.preview_mode', 'generic') !== 'title') {
            return $genericBody;
        }

        return $this->sanitizedTitle($notification) ?: $genericBody;
    }

    private function sanitizedTitle(CharacterNotification $notification): string
    {
        $plainTitle = html_entity_decode(
            strip_tags((string) $notification->title),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $normalizedTitle = preg_replace('/\s+/u', ' ', $plainTitle);
        $title = trim(is_string($normalizedTitle) ? $normalizedTitle : '');

        return Str::length($title) <= 60
            ? $title
            : Str::substr($title, 0, 59).'…';
    }

    private function notificationTag(CharacterNotification $notification): string
    {
        return AdminWebPushNotificationService::isType((string) $notification->type)
            ? 'valzeria-admin-'.(int) $notification->id
            : 'valzeria-bell';
    }

    private function notificationUrl(
        CharacterNotification $notification,
        int $adminNotificationCount,
    ): string {
        if (! AdminWebPushNotificationService::isType((string) $notification->type)) {
            return '/home';
        }

        if ($adminNotificationCount > 1) {
            return '/admin';
        }

        $path = parse_url((string) $notification->url, PHP_URL_PATH);
        if (! is_string($path)
            || ($path !== '/admin' && ! str_starts_with($path, '/admin/'))) {
            return '/admin';
        }

        $query = parse_url((string) $notification->url, PHP_URL_QUERY);

        return $path.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    private function latestNotificationId(Character $character): int
    {
        return (int) (CharacterNotification::query()
            ->where('character_id', $character->getKey())
            ->max('id') ?? 0);
    }

    private function advanceToLatest(WebPushSubscription $subscription, Character|int $characterOrLatestId): void
    {
        $latestId = $characterOrLatestId instanceof Character
            ? $this->latestNotificationId($characterOrLatestId)
            : $characterOrLatestId;

        if ($latestId > $subscription->last_notification_id) {
            $subscription->forceFill(['last_notification_id' => $latestId])->save();
        }
    }
}
