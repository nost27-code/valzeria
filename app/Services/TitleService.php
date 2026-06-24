<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Title;
use App\Models\CharacterTitle;

class TitleService
{
    /**
     * キャラクターに特定の称号を付与する。
     * 既に所持している場合は何もしない。
     */
    public function unlockTitle(Character $character, int $titleId)
    {
        $characterTitle = CharacterTitle::firstOrCreate([
            'character_id' => $character->id,
            'title_id' => $titleId,
        ]);

        return $characterTitle;
    }

    /**
     * 装備中の称号を取得する。なければ「駆け出し冒険者(1)」または null を返す。
     */
    public function getEquippedTitle(Character $character)
    {
        $equipped = $character->titles()->where('is_equipped', true)->with('title')->first();
        if ($equipped) {
            return $equipped->title;
        }

        // 装備していなくて初期称号も持っていなければ付与して装備させる
        $initialTitleId = 1; // 駆け出し冒険者
        $hasInitial = $character->titles()->where('title_id', $initialTitleId)->exists();
        if (!$hasInitial) {
            $this->unlockTitle($character, $initialTitleId);
            $character->titles()->where('title_id', $initialTitleId)->update(['is_equipped' => true]);
            return Title::find($initialTitleId);
        }

        return null;
    }

    /**
     * 特定の称号を装備する
     */
    public function equipTitle(Character $character, int $titleId)
    {
        // 自分が持っている称号か確認
        $hasTitle = $character->titles()->where('title_id', $titleId)->exists();
        if (!$hasTitle) {
            return false;
        }

        // 全ての装備フラグを外す
        $character->titles()->update(['is_equipped' => false]);

        // 対象を装備
        $character->titles()->where('title_id', $titleId)->update(['is_equipped' => true]);

        return true;
    }
}
