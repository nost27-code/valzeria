<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Database\Eloquent\Builder;

final class RecentAdventurerService
{
    public const ACTIVE_WINDOW_MINUTES = 5;

    /** 本番確認用。直近5分の全冒険者一覧だけから除外するテストアカウント */
    private const HIDDEN_ONLINE_TEST_USER_ID = 1;

    private const HIDDEN_ONLINE_TEST_CHARACTER_ID = 5;

    public function query(): Builder
    {
        return Character::query()
            ->visibleToPublic()
            // visibleToPublic() の全公開面除外とは分け、直近5分の全冒険者だけから運営テスト用を隠す。
            ->where(function (Builder $query): void {
                $query->where('id', '!=', self::HIDDEN_ONLINE_TEST_CHARACTER_ID)
                    ->orWhere('user_id', '!=', self::HIDDEN_ONLINE_TEST_USER_ID);
            })
            ->where('last_seen_at', '>=', now()->subMinutes(self::ACTIVE_WINDOW_MINUTES))
            ->orderByDesc('last_seen_at');
    }
}
