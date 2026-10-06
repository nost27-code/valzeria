<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
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
