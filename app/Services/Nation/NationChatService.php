<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationChatMessage;
use App\Models\NationMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NationChatService
{
    public const MAX_MESSAGE_LENGTH = 100;

    public const RECENT_MESSAGE_LIMIT = 50;

    public function canUse(Character $character): bool
    {
        return NationMembership::query()
            ->where('character_id', $character->id)
            ->whereHas('nation', fn ($query) => $query->whereIn('status', [Nation::STATUS_ACTIVE, Nation::STATUS_DISBAND_PENDING]))
            ->exists();
    }

    public function send(Character $character, string $message, string $requestId): NationChatMessage
    {
        $message = trim($message);
        throw_if($message === '', \DomainException::class, 'メッセージを入力してください。');
        throw_if(mb_strlen($message) > self::MAX_MESSAGE_LENGTH, \DomainException::class, 'メッセージは100文字以内で入力してください。');
        throw_unless(Str::isUuid($requestId), \DomainException::class, '送信情報を更新して、もう一度お試しください。');

        return DB::transaction(function () use ($character, $message, $requestId): NationChatMessage {
            $membership = NationMembership::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            throw_unless($membership, \DomainException::class, '国家へ所属していません。');

            $nationExists = Nation::query()
                ->whereKey($membership->nation_id)
                ->whereIn('status', [Nation::STATUS_ACTIVE, Nation::STATUS_DISBAND_PENDING])
                ->exists();
            throw_unless($nationExists, \DomainException::class, '所属国家では国家チャットを利用できません。');

            return NationChatMessage::query()->firstOrCreate(
                [
                    'nation_id' => $membership->nation_id,
                    'idempotency_key' => $requestId,
                ],
                [
                    'character_id' => $character->id,
                    'message' => $message,
                ],
            );
        }, 3);
    }

    /** @return Collection<int, NationChatMessage> */
    public function recentFor(Character $character, int $limit = self::RECENT_MESSAGE_LIMIT): Collection
    {
        $limit = max(1, min(self::RECENT_MESSAGE_LIMIT, $limit));

        return NationChatMessage::query()
            ->with('character')
            ->whereHas('nation', fn ($query) => $query->whereIn('status', [Nation::STATUS_ACTIVE, Nation::STATUS_DISBAND_PENDING]))
            ->whereExists(function ($query) use ($character): void {
                $query->selectRaw('1')
                    ->from('nation_memberships')
                    ->whereColumn('nation_memberships.nation_id', 'nation_chat_messages.nation_id')
                    ->where('nation_memberships.character_id', $character->id);
            })
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
