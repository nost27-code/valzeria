<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class TitleUnlockMessage
{
    /**
     * @param  iterable<int, object>  $titles
     */
    public static function forPastAchievements(iterable $titles): ?string
    {
        $titles = Collection::make($titles)->values();
        $count = $titles->count();

        if ($count === 0) {
            return null;
        }

        $name = trim((string) ($titles->first()->name ?? ''));
        if ($count === 1 && $name !== '') {
            return "過去の実績により、称号「{$name}」を獲得しました！";
        }

        return "過去の実績により、称号を{$count}個獲得しました！";
    }
}
