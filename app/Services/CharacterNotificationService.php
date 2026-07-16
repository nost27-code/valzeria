<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CharacterNotificationService
{
    public function create(
        Character $character,
        string $category,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
        array $payload = [],
        int $priority = 0,
        ?Carbon $expiresAt = null
    ): ?CharacterNotification {
        if (! Schema::hasTable('character_notifications')) {
            return null;
        }

        $attributes = [
            'character_id' => $character->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $actionUrl,
            'data' => $payload,
        ];

        if (Schema::hasColumn('character_notifications', 'category')) {
            $attributes['category'] = $category;
        }
        if (Schema::hasColumn('character_notifications', 'action_label')) {
            $attributes['action_label'] = $actionLabel;
        }
        if (Schema::hasColumn('character_notifications', 'priority')) {
            $attributes['priority'] = $priority;
        }
        if (Schema::hasColumn('character_notifications', 'expires_at')) {
            $attributes['expires_at'] = $expiresAt;
        }

        return CharacterNotification::create($attributes);
    }

    public function unreadCount(Character $character): int
    {
        if (! Schema::hasTable('character_notifications')) {
            return 0;
        }

        return (int) CharacterNotification::query()
            ->where('character_id', $character->id)
            ->active()
            ->unread()
            ->count();
    }

    public function unreadCountByCategory(Character $character, string $category): int
    {
        if (! Schema::hasTable('character_notifications')) {
            return 0;
        }

        $query = CharacterNotification::query()
            ->where('character_id', $character->id)
            ->active()
            ->unread();

        if (Schema::hasColumn('character_notifications', 'category')) {
            $query->where('category', $category);
        } elseif ($category === 'market') {
            $query->whereIn('type', ['market_sale', 'market_material_sold', 'market_listing_expired']);
        }

        return (int) $query->count();
    }

    public function latest(Character $character, int $limit = 5): Collection
    {
        if (! Schema::hasTable('character_notifications')) {
            return collect();
        }

        return CharacterNotification::query()
            ->where('character_id', $character->id)
            ->active()
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * 管理画面のユーザー個別調査用。通知の既読状態を変更せずに直近を取得する。
     */
    public function latestForAdmin(Character $character, int $limit = 50): Collection
    {
        if (! Schema::hasTable('character_notifications')) {
            return collect();
        }

        return CharacterNotification::query()
            ->where('character_id', $character->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function markAllAsRead(Character $character): int
    {
        if (! Schema::hasTable('character_notifications')) {
            return 0;
        }

        return CharacterNotification::query()
            ->where('character_id', $character->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markCategoryAsRead(Character $character, string $category): int
    {
        if (! Schema::hasTable('character_notifications')) {
            return 0;
        }

        $query = CharacterNotification::query()
            ->where('character_id', $character->id)
            ->whereNull('read_at');

        if (Schema::hasColumn('character_notifications', 'category')) {
            $query->where('category', $category);
        } else {
            $query->where('type', $category);
        }

        return $query->update(['read_at' => now()]);
    }
}
