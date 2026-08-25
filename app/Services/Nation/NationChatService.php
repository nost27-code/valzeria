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

    public const READ_STATE_COLUMN = 'last_read_nation_chat_message_id';

    public function canUse(Character $character): bool
    {
        return $this->canUseCharacterId((int) $character->id);
    }

    public function canUseCharacterId(int $characterId): bool
    {
        return NationMembership::query()
            ->where('character_id', $characterId)
            ->whereHas('nation', fn ($query) => $query->whereIn('status', [Nation::STATUS_ACTIVE, Nation::STATUS_DISBAND_PENDING]))
            ->exists();
    }

    public function hasUnread(Character $character): bool
    {
        return $this->hasUnreadForCharacterId((int) $character->id);
    }

    public function hasUnreadForCharacterId(int $characterId): bool
    {
        $membership = NationMembership::query()
            ->where('character_id', $characterId)
            ->first(['id', 'nation_id', self::READ_STATE_COLUMN]);
        if (! $membership) {
            return false;
        }

        return NationChatMessage::query()
            ->where('nation_id', $membership->nation_id)
            ->when(
                $membership->{self::READ_STATE_COLUMN} !== null,
                fn ($query) => $query->where('id', '>', $membership->{self::READ_STATE_COLUMN}),
            )
            ->where(function ($query) use ($characterId): void {
                $query->whereNull('character_id')
                    ->orWhere('character_id', '<>', $characterId);
            })
            ->exists();
    }

    public function markRead(Character $character): void
    {
        $this->markReadForCharacterId((int) $character->id);
    }

    public function markReadForCharacterId(int $characterId): void
    {
        $membership = NationMembership::query()
            ->where('character_id', $characterId)
            ->first(['id', 'nation_id']);
        if (! $membership) {
            return;
        }

        $latestMessageId = $this->latestMessageIdForNation((int) $membership->nation_id);
        if ($latestMessageId === null) {
            return;
        }

        NationMembership::query()
            ->whereKey($membership->id)
            ->where('nation_id', $membership->nation_id)
            ->where('character_id', $characterId)
            ->where(function ($query) use ($latestMessageId): void {
                $query->whereNull(self::READ_STATE_COLUMN)
                    ->orWhere(self::READ_STATE_COLUMN, '<', $latestMessageId);
            })
            ->update([self::READ_STATE_COLUMN => $latestMessageId]);
    }

    public function latestMessageIdForNation(int $nationId): ?int
    {
        $latestMessageId = NationChatMessage::query()
            ->where('nation_id', $nationId)
            ->max('id');

        return $latestMessageId === null ? null : (int) $latestMessageId;
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
