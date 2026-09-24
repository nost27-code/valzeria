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
        try {
            return DB::transaction(function () use ($character, $characterItem): array {
                // 市場・強化と同じく冒険者を先にロックし、同じ枠の変更も直列化する。
                $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
                $lockedItem = CharacterItem::query()->with('item')->lockForUpdate()->find($characterItem->id);
                if (!$lockedItem || (int) $lockedItem->character_id !== (int) $lockedCharacter->id) {
                    return ['success' => false, 'message' => 'この装備は所持していません。'];
                }
                if ($lockedItem->isMarketListed()) {
                    return ['success' => false, 'message' => 'この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。'];
                }

                $item = $lockedItem->item;
                if (!$item) {
                    return ['success' => false, 'message' => '指定された装備が見つかりません。'];
                }
                if ($this->isMark($item)) {
                    return ['success' => false, 'message' => '印は装備できません。印図鑑で集める永続効果になりました。'];
                }
                if ($message = $this->permissionService->restrictionMessage($lockedCharacter, $item)) {
                    return ['success' => false, 'message' => $message];
                }

                $slot = $item->type === 'accessory' ? self::ACCESSORY_SLOT : $item->type;
                CharacterItem::where('character_id', $lockedCharacter->id)
                    ->where('equipped_slot', $slot)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false, 'equipped_slot' => null]);

                // 同じ装備の再送でも、直前の一括解除後の状態から必ず再装備する。
                $lockedItem->refresh();
                $lockedItem->forceFill(['is_equipped' => true, 'is_stored' => false, 'equipped_slot' => $slot])->save();
                $this->clampCurrentResources($lockedCharacter);
                app(PlayerLifecycleEventService::class)->recordFirstEquipmentChange($lockedCharacter);

                $character->setRawAttributes($lockedCharacter->getAttributes(), true);
                $characterItem->setRawAttributes($lockedItem->getAttributes(), true);

                return ['success' => true, 'message' => "{$lockedItem->displayName()}を装備しました。"];
            }, 3);
        } catch (\Exception $e) {
            report($e);
            return ['success' => false, 'message' => '装備変更処理に失敗しました。'];
        } finally {
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    /**
     * 装備を解除する
     */
    public function unequip(Character $character, CharacterItem $characterItem): array
    {
        try {
            return DB::transaction(function () use ($character, $characterItem): array {
                $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
                $lockedItem = CharacterItem::query()->lockForUpdate()->find($characterItem->id);
                if (!$lockedItem || (int) $lockedItem->character_id !== (int) $lockedCharacter->id) {
                    return ['success' => false, 'message' => 'この装備は所持していません。'];
                }
                if ($lockedItem->isMarketListed()) {
                    return ['success' => false, 'message' => 'この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。'];
                }
                if (!$lockedItem->is_equipped) {
                    return ['success' => false, 'message' => 'このアイテムは装備していません。'];
                }

                $lockedItem->forceFill(['is_equipped' => false, 'equipped_slot' => null])->save();
                $this->clampCurrentResources($lockedCharacter);
                $character->setRawAttributes($lockedCharacter->getAttributes(), true);
                $characterItem->setRawAttributes($lockedItem->getAttributes(), true);

                return ['success' => true, 'message' => "{$lockedItem->displayName()}を外しました。"];
            }, 3);
        } catch (\Exception $e) {
            report($e);
            return ['success' => false, 'message' => '装備解除処理に失敗しました。'];
        } finally {
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    private function clampCurrentResources(Character $character): void
    {
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = $this->statusService->getFinalStats($character);
        $character->current_hp = min((int) $character->current_hp, $stats['max_hp']);
        $character->current_mp = min((int) $character->current_mp, $stats['max_mp']);
        if ($character->isDirty(['current_hp', 'current_mp'])) {
            $character->save();
        }
    }

    /**
     * 現在装備中のアイテム一覧を取得する（スロットキー）
     */
    public function getEquippedItems(Character $character, bool $reuseLoadedRelations = false): array
    {
        if ($reuseLoadedRelations && $character->relationLoaded('characterItems')) {
            $equipped = $character->characterItems
                ->where('is_equipped', true)
                ->values();
            $equipped->loadMissing('item');
        } else {
            $equipped = $character->characterItems()
                ->where('is_equipped', true)
                ->with('item')
                ->get();
        }

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
