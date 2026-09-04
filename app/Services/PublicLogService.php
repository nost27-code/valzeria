<?php

namespace App\Services;

use App\Models\PublicLog;
use App\Models\Character;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicLogService
{
    /** @param array<int, array<string, mixed>> $equipmentDrops */
    public function addEquipmentDropLogs(Character $character, array $equipmentDrops): void
    {
        foreach ($equipmentDrops as $equipmentDrop) {
            $rankText = strtoupper((string) ($equipmentDrop['rank'] ?? $equipmentDrop['rarity'] ?? ''));
            $rarity = strtolower((string) ($equipmentDrop['rarity'] ?? ''));

            if ($rankText === 'LEGEND' || $rarity === 'legend') {
                $rankText = 'EPIC';
            }

            if (($equipmentDrop['affix_quality'] ?? null) === 'excellent') {
                $this->addLog(
                    'drop',
                    "【逸品】{$character->name}さんが「{$equipmentDrop['item_name']}」を手に入れました！",
                    $character,
                    3
                );
                continue;
            }

            if (!in_array($rankText, ['SSS', 'EPIC'], true)
                && !in_array($rarity, ['rare', 'epic', 'legend'], true)) {
                continue;
            }

            $message = "【獲得】{$character->name}さんが{$rankText}ランク装備「{$equipmentDrop['item_name']}」を手に入れました！";
            $importance = in_array($rankText, ['SSS', 'EPIC'], true) ? 3 : 2;

            $this->addLog('drop', $message, $character, $importance);
        }
    }

    public function addMapPublishedLog(\App\Models\ExplorationMap $map, \App\Models\TownMapRegistration $registration): void
    {
        if (!in_array($map->map_grade, config('exploration_maps.public_log_grades', []), true)) {
            return;
        }

        $marker = \App\Models\MapPublicationLog::firstOrCreate(['map_id' => $map->id]);
        if (!$marker->wasRecentlyCreated) return;
        $grade = ['hero' => '英雄', 'legend' => '伝説'][$map->map_grade] ?? $map->map_grade;
        $log = PublicLog::create(['type' => 'system_map_published', 'message' => '🗺️【' . $grade . '地図】' . $map->owner->name . 'さんが「' . $map->name . '」を' . $registration->town->name . '地図院で公開しました！', 'character_id' => $map->owner_character_id, 'importance' => 1]);
        $marker->update(['public_log_id' => $log->id]);
    }

    /**
     * システムやバトル、エリア解放などの公開ログを記録する
     */
    public function addLog(string $type, string $message, ?Character $character = null, int $importance = 1, ?int $receiverId = null): void
    {
        // 黒炉深坑の到達記録は、管理者・テスト用アカウントも含めて
        // 挑戦結果として公開する。ほかの操作ログの除外方針は維持する。
        if ($character?->isExcludedFromPublicLogs() && !in_array($type, ['private', 'region_depth_dungeon'], true)) {
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
     * 不具合報告などへの運営からの個別連絡を記録する。
     */
    public function addAdminPrivateMessage(
        string $message,
        Character $receiver,
        string $notificationContext = '不具合フォームへの返答',
    ): void
    {
        $log = PublicLog::create([
            'type' => 'admin_private',
            'message' => $message,
            'character_id' => null,
            'receiver_id' => $receiver->id,
            'importance' => 4,
        ]);

        app(CharacterNotificationService::class)->create(
            character: $receiver,
            category: 'message',
            type: 'admin_private_message',
            title: '管理人からメッセージが届きました',
            body: $notificationContext . ': ' . Str::limit($message, 70),
            actionLabel: '会話へ',
            actionUrl: route('message.index'),
            payload: [
                'public_log_id' => $log->id,
                'sender_type' => 'admin',
            ],
            priority: 90,
            expiresAt: now()->addDays(30),
        );
    }

    /**
     * 冒険者から管理人スレッドへ送る返答を記録する。
     */
    public function addAdminPrivateReply(string $message, Character $character): void
    {
        DB::transaction(function () use ($message, $character): void {
            Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();

            PublicLog::create([
                'type' => 'admin_private_reply',
                'message' => $message,
                'character_id' => $character->id,
                'receiver_id' => $character->id,
                'importance' => 1,
            ]);
        });
    }

    /**
     * 冒険者返信の会話履歴を残したまま、管理画面の未対応通知だけを解除する。
     */
    public function resolvePendingAdminReply(PublicLog $reply): bool
    {
        if ($reply->type !== 'admin_private_reply' || !$reply->receiver_id) {
            return false;
        }

        return DB::transaction(function () use ($reply): bool {
            $receiverId = (int) $reply->receiver_id;

            $receiver = Character::query()
                ->whereKey($receiverId)
                ->lockForUpdate()
                ->first(['id']);

            if (!$receiver) {
                return false;
            }

            $lockedReply = PublicLog::query()
                ->whereKey($reply->id)
                ->lockForUpdate()
                ->first();

            if (
                !$lockedReply
                || $lockedReply->type !== 'admin_private_reply'
                || (int) $lockedReply->receiver_id !== $receiverId
            ) {
                return false;
            }

            $hasLaterThreadLog = PublicLog::query()
                ->where('receiver_id', $receiverId)
                ->whereIn('type', ['admin_private', 'admin_private_reply', 'admin_reply_resolved'])
                ->where('id', '>', $lockedReply->id)
                ->exists();

            if ($hasLaterThreadLog) {
                return false;
            }

            PublicLog::query()->create([
                'type' => 'admin_reply_resolved',
                'message' => "返信通知 #{$lockedReply->id} を管理画面で対応済みにしました。",
                'character_id' => null,
                'receiver_id' => $receiverId,
                'importance' => 0,
            ]);

            return true;
        });
    }

    /**
     * 管理人からの最終送信後に冒険者が返信したままのスレッド数。
     */
    public function pendingAdminReplyCount(): int
    {
        return $this->pendingAdminReplyQuery()->count();
    }

    /**
     * 管理人の返答待ちになっている冒険者返信を新しい順で取得する。
     *
     * @return Collection<int, PublicLog>
     */
    public function pendingAdminReplies(int $limit = 20): Collection
    {
        return $this->pendingAdminReplyQuery()
            ->with('character.user')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 最新の公開ログを取得する
     */
    public function getRecentLogs(
        int $limit = 20,
        ?int $currentCharacterId = null,
        ?array $types = null,
        array $excludedTypes = [],
        ?bool $newcomersVisible = null,
    )
    {
        return $this->recentLogsQuery(
            $currentCharacterId,
            $types,
            $excludedTypes,
            $newcomersVisible,
        )
            ->with(['character', 'receiver'])
            ->limit($limit)
            ->get();
    }

    /**
     * 表示対象ログのID・更新日時・本文だけから、軽量な変更判定値を作る。
     */
    public function getRecentLogsVersion(
        int $limit = 20,
        ?int $currentCharacterId = null,
        ?array $types = null,
        array $excludedTypes = [],
        ?bool $newcomersVisible = null,
    ): string
    {
        $rows = $this->recentLogsQuery(
            $currentCharacterId,
            $types,
            $excludedTypes,
            $newcomersVisible,
        )
            ->limit($limit)
            ->get(['id', 'updated_at', 'message']);

        return $this->logsVersion($rows);
    }

    public function logsVersion(iterable $logs): string
    {
        return hash('sha256', collect($logs)
            ->map(fn (PublicLog $log): string => implode(':', [
                $log->id,
                $log->updated_at?->format('Y-m-d H:i:s.u') ?? '',
                hash('sha256', (string) $log->message),
            ]))
            ->implode('|'));
    }

    private function recentLogsQuery(
        ?int $currentCharacterId,
        ?array $types,
        array $excludedTypes,
        ?bool $newcomersVisible,
    ): Builder
    {
        $query = PublicLog::query()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($types !== null) {
            $query->whereIn('type', $types);
        }

        $query->where(function ($q): void {
            $q->whereNull('character_id')
                ->orWhereDoesntHave('character', fn ($characterQuery) => $characterQuery->excludedFromPublicLogs());
        });

        // 個人チャットと管理人スレッドは下部チャットに出さない
        $query->where(function ($q) use ($currentCharacterId) {
            $q->whereNotIn('type', ['private', 'admin_private', 'admin_private_reply', 'admin_reply_resolved']);
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

        if ($excludedTypes !== []) {
            $query->where(function ($filterQuery) use ($excludedTypes, $newcomersVisible): void {
                $filterQuery->whereNotIn('type', $excludedTypes);

                if ($newcomersVisible === true) {
                    $filterQuery->orWhere(function ($newcomerQuery): void {
                        $newcomerQuery
                            ->whereNotIn('type', ['private', 'admin_private', 'admin_private_reply', 'admin_reply_resolved'])
                            ->where(function ($messageQuery): void {
                                $messageQuery->where('type', 'newcomer')
                                    ->orWhere('message', 'like', '新しい冒険者%ヴァルゼリアの地に降り立ちました。%');
                            });
                    });
                }
            });
        }

        if ($newcomersVisible === false) {
            $query->where('type', '!=', 'newcomer')
                ->where(function ($newcomerQuery): void {
                    $newcomerQuery->whereNull('message')
                        ->orWhere('message', 'not like', '新しい冒険者%ヴァルゼリアの地に降り立ちました。%');
                });
        }

        return $query;
    }

    private function pendingAdminReplyQuery(): Builder
    {
        return PublicLog::query()
            ->where('type', 'admin_private_reply')
            ->whereNotNull('receiver_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('public_logs as later_admin_thread_logs')
                    ->whereColumn('later_admin_thread_logs.receiver_id', 'public_logs.receiver_id')
                    ->whereIn('later_admin_thread_logs.type', ['admin_private', 'admin_private_reply', 'admin_reply_resolved'])
                    ->whereColumn('later_admin_thread_logs.id', '>', 'public_logs.id');
            });
    }
}
