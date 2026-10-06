<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\ChatMessageDeletionLog;
use App\Models\PublicLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChatMessageDeletionService
{
    public function findOwnMessage(Character $character, int $logId): ?PublicLog
    {
        return $this->ownMessages($character)->whereKey($logId)->first();
    }

    public function deleteOwnMessage(Character $character, int $logId): bool
    {
        return DB::transaction(function () use ($character, $logId): bool {
            $log = $this->ownMessages($character)->whereKey($logId)->lockForUpdate()->first();
            if (! $log) {
                return false;
            }

            $sender = Character::query()->find($log->character_id, ['id', 'user_id', 'name']);
            $receiver = $log->receiver_id
                ? Character::query()->find($log->receiver_id, ['id', 'user_id', 'name'])
                : null;
            // 保存できなければ元の発言と通知も残す。同じ行ロック下で一度だけ保存する。
            ChatMessageDeletionLog::create([
                'public_log_id' => $log->id,
                'type' => $log->type,
                'character_id' => $log->character_id,
                'user_id' => $sender?->user_id ?? $character->user_id,
                'sender_name' => $sender?->name ?? $character->name,
                'receiver_id' => $log->receiver_id,
                'receiver_user_id' => $receiver?->user_id,
                'receiver_name' => $receiver?->name,
                'message' => $log->message,
                'sent_at' => $log->created_at,
                'deleted_at' => now(),
            ]);

            if ($log->type === 'private') {
                // 通知ベルに残った本文も、対象の手紙と一緒に取り除く。
                CharacterNotification::query()
                    ->where('character_id', $log->receiver_id)
                    ->where('type', 'private_message')
                    ->where('data->public_log_id', $log->id)
                    ->delete();
            }

            $log->delete();

            return true;
        });
    }

    private function ownMessages(Character $character): Builder
    {
        return PublicLog::query()
            ->where('character_id', $character->id)
            ->whereIn('type', ['chat', 'private']);
    }
}
