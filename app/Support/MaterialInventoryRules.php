<?php

namespace App\Support;

/** 倉庫容量・表示・売却で共用する、既存の大事なもの判定。 */
class MaterialInventoryRules
{
    public static function isKeyMaterial(?object $material): bool
    {
        if (! $material) {
            return false;
        }

        $name = (string) ($material->name ?? '');
        $category = (string) ($material->category ?? '');
        $mainUse = (string) ($material->main_use ?? '');

        return in_array((string) ($material->material_type ?? ''), ['boss_unique', 'key_item', 'weapon_unlock_key'], true)
            || (string) ($material->category_id ?? '') === 'boss_unique'
            || str_contains($category, '討伐証')
            || str_contains($category, 'ボス特異素材')
            || str_contains($category, '進化解放キー')
            || str_contains($mainUse, '解放キー')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }
}
