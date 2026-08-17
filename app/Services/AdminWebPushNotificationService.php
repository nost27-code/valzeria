<?php

namespace App\Services;

use App\Models\BugReport;
use App\Models\Character;
use App\Models\CharacterIconDesignRequest;
use App\Models\CharacterNotification;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminWebPushNotificationService
{
    public const TYPE_BUG_REPORT = 'admin_bug_report_received';

    public const TYPE_CONTACT_MESSAGE = 'admin_contact_message_received';

    public const TYPE_CHARACTER_ICON_DESIGN = 'admin_character_icon_design_submitted';

    public function __construct(
        private readonly CharacterNotificationService $notifications,
    ) {}

    /** @return array<int, string> */
    public static function types(): array
    {
        return [
            self::TYPE_BUG_REPORT,
            self::TYPE_CONTACT_MESSAGE,
            self::TYPE_CHARACTER_ICON_DESIGN,
        ];
    }

    public static function isType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    public function isRecipient(Character $character): bool
    {
        $recipientId = (int) config('web_push.admin_recipient_character_id', 0);

        if ($recipientId <= 0 || (int) $character->getKey() !== $recipientId) {
            return false;
        }

        $character->loadMissing('user');

        return $character->user?->role === 'admin';
    }

    public function notifyBugReport(BugReport $report): ?CharacterNotification
    {
        return $this->notify(
            type: self::TYPE_BUG_REPORT,
            title: '新しい不具合報告があります',
            actionRoute: 'admin.bug-reports',
            sourceType: 'bug_report',
            sourceId: (int) $report->getKey(),
        );
    }

    public function notifyContactMessage(ContactMessage $message): ?CharacterNotification
    {
        return $this->notify(
            type: self::TYPE_CONTACT_MESSAGE,
            title: '新着メールがあります',
            actionRoute: 'admin.contact-messages',
            sourceType: 'contact_message',
            sourceId: (int) $message->getKey(),
        );
    }

    public function notifyCharacterIconDesignRequest(
        CharacterIconDesignRequest $designRequest,
    ): ?CharacterNotification {
        return $this->notify(
            type: self::TYPE_CHARACTER_ICON_DESIGN,
            title: 'キャラ画像作成依頼が届きました',
            actionRoute: 'admin.character-icon-design.index',
            sourceType: 'character_icon_design_request',
            sourceId: (int) $designRequest->getKey(),
        );
    }

    private function notify(
        string $type,
        string $title,
        string $actionRoute,
        string $sourceType,
        int $sourceId,
    ): ?CharacterNotification {
        try {
            $recipient = $this->recipient();

            if (! $recipient) {
                return null;
            }

            return $this->notifications->create(
                character: $recipient,
                category: 'admin',
                type: $type,
                title: $title,
                body: '管理画面を確認してください。',
                actionLabel: '管理画面を開く',
                actionUrl: route($actionRoute),
                payload: [
                    'admin_source_type' => $sourceType,
                    'admin_source_id' => $sourceId,
                ],
                priority: 100,
            );
        } catch (\Throwable $exception) {
            Log::warning('Admin Web Push notification creation failed.', [
                'type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function recipient(): ?Character
    {
        $recipientId = (int) config('web_push.admin_recipient_character_id', 0);

        if ($recipientId <= 0
            || ! Schema::hasTable('characters')
            || ! Schema::hasTable('character_notifications')) {
            return null;
        }

        $character = Character::query()->with('user')->find($recipientId);

        return $character instanceof Character && $this->isRecipient($character)
            ? $character
            : null;
    }
}
