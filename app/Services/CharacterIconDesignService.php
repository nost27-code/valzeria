<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterIconDesignMessage;
use App\Models\CharacterIconDesignRequest;
use App\Models\KisekiTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CharacterIconDesignService
{
    public const CONTENT_KEY = 'character_icon_design';

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive(self::CONTENT_KEY);
    }

    public function canAccess(?Character $character): bool
    {
        return $this->isEnabled() && $character !== null;
    }

    public function canSubmit(?Character $character): bool
    {
        if (! $this->canAccess($character)) {
            return false;
        }

        if ((bool) config('character_icon_design.public_access_enabled', false)) {
            return true;
        }

        $previewCharacterIds = array_map(
            'intval',
            (array) config('character_icon_design.preview_character_ids', [])
        );

        return in_array((int) $character->id, $previewCharacterIds, true);
    }

    public function preparingTitle(): string
    {
        return (string) config('character_icon_design.preparing_title', 'キャラアイコン作成');
    }

    public function preparingMessage(): string
    {
        return (string) config(
            'character_icon_design.preparing_message',
            '現在準備中です。もうしばらくお待ちください。下書き保存しました。'
        );
    }

    public function draftFor(?Character $character): ?CharacterIconDesignRequest
    {
        if (! $character) {
            return null;
        }

        return CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->whereIn('status', ['eligible', 'draft'])
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, CharacterIconDesignRequest>
     */
    public function submittedRequestsFor(?Character $character): Collection
    {
        if (! $character) {
            return new Collection;
        }

        return CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function saveForm(
        Character $character,
        array $formData,
        bool $submit,
        ?int $designRequestId = null,
    ): array {
        if ($submit && ! $this->canSubmit($character)) {
            return [
                'success' => false,
                'message' => $this->preparingMessage(),
            ];
        }

        return DB::transaction(function () use (
            $character,
            $formData,
            $submit,
            $designRequestId,
        ): array {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();
            $designRequestQuery = CharacterIconDesignRequest::query()
                ->where('character_id', $character->id);
            if ($designRequestId !== null) {
                $designRequestQuery->whereKey($designRequestId);
            } else {
                $designRequestQuery
                    ->whereIn('status', ['eligible', 'draft'])
                    ->latest('id');
            }
            $designRequest = $designRequestQuery->lockForUpdate()->first();

            if (! $designRequest) {
                if ($submit) {
                    return [
                        'success' => false,
                        'message' => '提出できるヒアリングシートがありません。',
                    ];
                }

                $designRequest = CharacterIconDesignRequest::query()->create([
                    'character_id' => $lockedCharacter->id,
                    'status' => 'draft',
                    'price_kiseki' => (int) config('character_icon_design.submission_price_kiseki', 40),
                ]);
            }

            if ($submit && $designRequest->purchased_at && $designRequest->submitted_at) {
                return [
                    'success' => true,
                    'message' => 'ヒアリングシートは提出済みです。',
                ];
            }

            $updates = [
                'status' => 'draft',
                'form_data' => $formData,
            ];

            if ($submit) {
                if (! $designRequest->purchased_at) {
                    $price = (int) $designRequest->price_kiseki;
                    $totalKiseki = (int) ($lockedCharacter->free_kiseki ?? 0)
                        + (int) ($lockedCharacter->paid_kiseki ?? 0);

                    if ($totalKiseki < $price) {
                        $designRequest->forceFill($updates)->save();

                        return [
                            'success' => false,
                            'message' => '輝石が不足しているため提出できませんでした。入力内容は下書きに保存しました。',
                        ];
                    }

                    $spent = app(KisekiBalanceService::class)->spendLocked($lockedCharacter, $price);
                    $updates['free_kiseki_spent'] = $spent['free_spent'];
                    $updates['paid_kiseki_spent'] = $spent['paid_spent'];
                    $updates['purchased_at'] = now();

                    KisekiTransaction::query()->create([
                        'character_id' => $lockedCharacter->id,
                        'kiseki_type' => $spent['free_spent'] > 0 && $spent['paid_spent'] > 0
                            ? 'mixed'
                            : ($spent['free_spent'] > 0 ? 'free' : 'paid'),
                        'amount' => -$price,
                        'transaction_type' => 'service_purchase',
                        'source_type' => 'character_icon_design',
                        'source_id' => $designRequest->id,
                        'description' => 'キャラアイコン制作 ヒアリングシート提出',
                    ]);

                    $character->setRawAttributes($lockedCharacter->getAttributes(), true);
                }

                $updates['status'] = 'submitted';
                $updates['submitted_at'] = now();
            }

            $designRequest->forceFill($updates)->save();

            return [
                'success' => true,
                'message' => $submit
                    ? 'ヒアリングシートを提出しました。管理人との専用チャットが開きました。'
                    : '下書きを保存しました。',
            ];
        });
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateStatus(CharacterIconDesignRequest $designRequest, string $status): array
    {
        if (! in_array($status, config('character_icon_design.admin_editable_statuses', []), true)) {
            return ['success' => false, 'message' => '変更できない進行状態です。'];
        }

        return DB::transaction(function () use ($designRequest, $status): array {
            $lockedRequest = CharacterIconDesignRequest::query()
                ->whereKey($designRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->submitted_at) {
                return ['success' => false, 'message' => 'ヒアリングシートが提出されるまで進行状態は変更できません。'];
            }

            $previousStatus = $lockedRequest->status;
            $lockedRequest->status = $status;
            $lockedRequest->completed_at = $status === 'completed' ? now() : null;
            $lockedRequest->save();

            if ($previousStatus !== $status && $lockedRequest->character) {
                app(CharacterNotificationService::class)->create(
                    character: $lockedRequest->character,
                    category: 'message',
                    type: 'character_icon_design_status',
                    title: 'キャラアイコン制作の進行状況が更新されました',
                    body: '現在の状態: '.$lockedRequest->statusLabel(),
                    actionLabel: '制作状況を見る',
                    actionUrl: route('character-icon-design.show', ['request' => $lockedRequest->id]),
                    payload: [
                        'character_icon_design_request_id' => $lockedRequest->id,
                        'status' => $status,
                    ],
                    priority: 80,
                );
            }

            return [
                'success' => true,
                'message' => '進行状態を「'.$lockedRequest->statusLabel().'」へ変更しました。',
            ];
        });
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{success: bool, message: string}
     */
    public function addMessage(
        CharacterIconDesignRequest $designRequest,
        string $senderType,
        ?string $body,
        array $files = [],
        ?User $admin = null,
    ): array {
        if (! in_array($senderType, ['player', 'admin'], true)) {
            return ['success' => false, 'message' => '送信者を確認できませんでした。'];
        }

        $storedFiles = [];

        try {
            $message = DB::transaction(function () use (
                $designRequest,
                $senderType,
                $body,
                $files,
                $admin,
                &$storedFiles,
            ): CharacterIconDesignMessage {
                $lockedRequest = CharacterIconDesignRequest::query()
                    ->whereKey($designRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedRequest->isChatOpen() || $lockedRequest->status === 'completed') {
                    throw new \DomainException('この専用チャットは現在送信できません。');
                }

                $message = CharacterIconDesignMessage::query()->create([
                    'character_icon_design_request_id' => $lockedRequest->id,
                    'sender_type' => $senderType,
                    'admin_user_id' => $senderType === 'admin' ? $admin?->id : null,
                    'body' => filled($body) ? trim((string) $body) : null,
                    'read_by_player_at' => $senderType === 'player' ? now() : null,
                    'read_by_admin_at' => $senderType === 'admin' ? now() : null,
                ]);

                foreach (array_values($files) as $position => $file) {
                    $path = $file->store(
                        "character-icon-design/{$lockedRequest->id}/{$message->id}",
                        'local'
                    );
                    if (! $path) {
                        throw new \RuntimeException('画像を保存できませんでした。');
                    }
                    $storedFiles[] = $path;

                    $message->attachments()->create([
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'position' => $position,
                    ]);
                }

                return $message;
            });
        } catch (\Throwable $exception) {
            foreach ($storedFiles as $path) {
                Storage::disk('local')->delete($path);
            }

            if ($exception instanceof \DomainException) {
                return ['success' => false, 'message' => $exception->getMessage()];
            }

            throw $exception;
        }

        if ($senderType === 'admin') {
            $character = $designRequest->character()->first();
            if ($character) {
                app(CharacterNotificationService::class)->create(
                    character: $character,
                    category: 'message',
                    type: 'character_icon_design_message',
                    title: 'キャラアイコン制作の連絡が届きました',
                    body: filled($message->body)
                        ? Str::limit((string) $message->body, 70)
                        : '候補画像が届きました。',
                    actionLabel: '専用チャットを見る',
                    actionUrl: route('character-icon-design.show', ['request' => $designRequest->id]),
                    payload: [
                        'character_icon_design_request_id' => $designRequest->id,
                        'character_icon_design_message_id' => $message->id,
                    ],
                    priority: 90,
                );
            }
        }

        return ['success' => true, 'message' => 'メッセージを送信しました。'];
    }

    public function markAdminMessagesRead(CharacterIconDesignRequest $designRequest): void
    {
        $designRequest->messages()
            ->where('sender_type', 'admin')
            ->whereNull('read_by_player_at')
            ->update(['read_by_player_at' => now()]);
    }

    public function markPlayerMessagesRead(CharacterIconDesignRequest $designRequest): void
    {
        $designRequest->messages()
            ->where('sender_type', 'player')
            ->whereNull('read_by_admin_at')
            ->update(['read_by_admin_at' => now()]);
    }
}
