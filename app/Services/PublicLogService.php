<?php

namespace App\Services;

use App\Models\PublicLog;
use App\Models\Character;
use Illuminate\Support\Str;

class PublicLogService
{
    /**
     * システムやバトル、エリア解放などの公開ログを記録する
     */
    public function addLog(string $type, string $message, ?Character $character = null, int $importance = 1, ?int $receiverId = null): void
    {
        if ($character?->isExcludedFromPublicLogs() && $type !== 'private') {
            return;
        }

        $log = PublicLog::create([
            'type' => $type,
            'message' => $message,
            'character_id' => $character ? $character->id : null,
            'receiver_id' => $receiverId,
            'importance' => $importance,
        ]);

        if ($type === 'private' && $character && $receiverId && (int) $receiverId !== (int) $character->id) {
            $receiver = Character::find($receiverId);
            if ($receiver) {
                app(CharacterNotificationService::class)->create(
                    character: $receiver,
                    category: 'message',
                    type: 'private_message',
                    title: '新しいメッセージが届きました',
                    body: $character->name . 'さんから: ' . Str::limit($message, 70),
                    actionLabel: '会話へ',
                    actionUrl: route('message.index'),
                    payload: [
                        'public_log_id' => $log->id,
                        'sender_character_id' => $character->id,
                    ],
                    priority: 90,
                    expiresAt: now()->addDays(30),
                );
            }
        }
    }

    /**
     * 最新の公開ログを取得する
     */
    public function getRecentLogs(int $limit = 20, ?int $currentCharacterId = null)
    {
        $query = PublicLog::with(['character', 'receiver'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $query->where(function ($q): void {
            $q->whereNull('character_id')
                ->orWhereDoesntHave('character', fn ($characterQuery) => $characterQuery->excludedFromPublicLogs());
        });

        // 自分に関係のない個人チャット（private）は除外する
        $query->where(function ($q) use ($currentCharacterId) {
            $q->where('type', '!=', 'private');
            if ($currentCharacterId) {
                $q->orWhere(function ($q2) use ($currentCharacterId) {
                    $q2->where('type', 'private')
                       ->where(function ($q3) use ($currentCharacterId) {
                           $q3->where('character_id', $currentCharacterId)
                              ->orWhere('receiver_id', $currentCharacterId);
                       });
                });
            }
        });

        return $query->limit($limit)->get();
    }
}
