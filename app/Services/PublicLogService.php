<?php

namespace App\Services;

use App\Models\PublicLog;
use App\Models\Character;
use Illuminate\Support\Str;

class PublicLogService
{
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
    public function addAdminPrivateMessage(string $message, Character $receiver): void
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
            body: '不具合フォームへの返答: ' . Str::limit($message, 70),
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
        PublicLog::create([
            'type' => 'admin_private_reply',
            'message' => $message,
            'character_id' => $character->id,
            'receiver_id' => $character->id,
            'importance' => 1,
        ]);
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

        // 個人チャットと管理人スレッドは下部チャットに出さない
        $query->where(function ($q) use ($currentCharacterId) {
            $q->whereNotIn('type', ['private', 'admin_private', 'admin_private_reply']);
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
