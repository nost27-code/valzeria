<?php

namespace App\Services;

use App\Models\CharacterItem;

class OwnedConsumableService
{
    /** 呼び出し元でCharacterを先にロックし、所持品の変更を直列化する。 */
    public function lockFirst(int $characterId, int $itemId, string $orderColumn = 'id'): ?CharacterItem
    {
        // 条件検索のFOR UPDATEはindex_mergeで別の冒険者の在庫まで待つことがある。
        // 候補の検索はロックせず、消費する所有個体だけを主キーでロックする。
        $id = CharacterItem::query()
            ->useWritePdo()
            ->where('character_id', $characterId)
            ->where('item_id', $itemId)
            ->where('is_equipped', false)
            ->oldest($orderColumn)
            ->value('id');

        if ($id === null) {
            return null;
        }

        $owned = CharacterItem::query()->whereKey($id)->lockForUpdate()->first();

        return $owned && (int) $owned->character_id === $characterId
            && (int) $owned->item_id === $itemId && ! $owned->is_equipped
                ? $owned : null;
    }
}
