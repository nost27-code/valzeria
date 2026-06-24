<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class EquipmentService
{
    public const ACCESSORY_SLOT = 'accessory';

    protected CharacterStatusService $statusService;
    protected EquipmentPermissionService $permissionService;

    public function __construct(CharacterStatusService $statusService, EquipmentPermissionService $permissionService)
    {
        $this->statusService = $statusService;
        $this->permissionService = $permissionService;
    }

    /**
     * 装備を変更する
     */
    public function equip(Character $character, CharacterItem $characterItem): array
    {
        if ($characterItem->character_id !== $character->id) {
            return ['success' => false, 'message' => 'この装備は所持していません。'];
        }

        $item = $characterItem->item;
        if (!$item) {
            return ['success' => false, 'message' => '指定された装備が見つかりません。'];
        }

        if ($this->isMark($item)) {
            return ['success' => false, 'message' => '印は装備できません。印図鑑で集める永続効果になりました。'];
        }

        $restrictionMessage = $this->permissionService->restrictionMessage($character, $item);
        if ($restrictionMessage) {
            return ['success' => false, 'message' => $restrictionMessage];
        }

        try {
            DB::beginTransaction();

            if ($item->type === 'accessory') {
                CharacterItem::where('character_id', $character->id)
                    ->where('equipped_slot', self::ACCESSORY_SLOT)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false, 'equipped_slot' => null]);

                $characterItem->is_equipped = true;
                $characterItem->is_stored = false;
                $characterItem->equipped_slot = self::ACCESSORY_SLOT;
                $characterItem->save();
            } else {
                // 同じカテゴリの装備を解除（武器・防具など）
                CharacterItem::where('character_id', $character->id)
                    ->where('equipped_slot', $item->type)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false, 'equipped_slot' => null]);

                // 対象のアイテムを装備状態にする
                $characterItem->is_equipped = true;
                $characterItem->is_stored = false;
                $characterItem->equipped_slot = $item->type;
                $characterItem->save();
            }

            // 最大HPの変動に合わせて現在HPを調整する
            // $statusServiceで新しい最大HPを取得する
            $finalStats = $this->statusService->getFinalStats($character);
            $newMaxHp = $finalStats['max_hp'];

            if ($character->current_hp > $newMaxHp) {
                $character->current_hp = $newMaxHp;
                $character->save();
            }

            DB::commit();

            return ['success' => true, 'message' => "{$characterItem->displayName()}を装備しました。"];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => '装備変更処理に失敗しました。'];
        }
    }

    /**
     * 装備を解除する
     */
    public function unequip(Character $character, CharacterItem $characterItem): array
    {
        if ($characterItem->character_id !== $character->id) {
            return ['success' => false, 'message' => 'この装備は所持していません。'];
        }

        if (!$characterItem->is_equipped) {
            return ['success' => false, 'message' => 'このアイテムは装備していません。'];
        }

        try {
            DB::beginTransaction();

            $characterItem->is_equipped = false;
            $characterItem->equipped_slot = null;
            $characterItem->save();

            // 最大HPの変動に合わせて現在HPを調整する
            $finalStats = $this->statusService->getFinalStats($character);
            $newMaxHp = $finalStats['max_hp'];

            if ($character->current_hp > $newMaxHp) {
                $character->current_hp = $newMaxHp;
                $character->save();
            }

            DB::commit();

            return ['success' => true, 'message' => "{$characterItem->displayName()}を外しました。"];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => '装備解除処理に失敗しました。'];
        }
    }

    /**
     * 現在装備中のアイテム一覧を取得する（スロットキー）
     */
    public function getEquippedItems(Character $character): array
    {
        $equipped = $character->characterItems()
            ->where('is_equipped', true)
            ->with('item')
            ->get();

        $result = [
            'weapon' => null,
            'armor' => null,
            'accessory' => null,
        ];

        foreach ($equipped as $charItem) {
            if (!$charItem->equipped_slot || !$charItem->item) {
                continue;
            }

            $slot = $charItem->equipped_slot;

            if (array_key_exists($slot, $result) && !$result[$slot]) {
                $result[$slot] = $charItem;
            }
        }

        return $result;
    }

    public function getAccessoryTab(Item $item): string
    {
        return $item->type;
    }

    public function isMark(Item $item): bool
    {
        if ($item->type !== 'accessory') {
            return false;
        }

        $markSubTypes = ['印', '刻印', '王印', '神印'];
        if ($item->sub_type && in_array($item->sub_type, $markSubTypes, true)) {
            return true;
        }

        $name = $item->name ?? '';
        return str_ends_with($name, 'の印')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

}
