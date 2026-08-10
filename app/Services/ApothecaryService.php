<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerExplorationSupportItemState;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApothecaryService
{
    private const RECIPES = [
        'apothecary_charm' => ['item' => '薬屋のお守り', 'unlock_area_id' => 1001, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['王都の織布' => 1, '精霊樹の繊維' => 2, '青命草の葉' => 3]],
        'guard_incense' => ['item' => '守りの香', 'unlock_area_id' => 1006, 'output_quantity' => 1, 'fee_rate' => .10, 'materials' => ['精霊樹の繊維' => 2, '黒鉄の装甲片' => 1, '守樹の樹脂' => 3]],
        'first_aid_kit' => ['item' => '冒険者の救急包', 'unlock_area_id' => 1004, 'output_quantity' => 1, 'fee_rate' => .10, 'materials' => ['潮風の布片' => 2, '王都の織布' => 1, '止血苔' => 3, '毒抜きの胆' => 1]],
        'special_herbal_clearstream' => ['item' => '薬屋の特製漢方', 'family' => 'special_herbal', 'unlock_area_id' => 1011, 'output_quantity' => 1, 'fee_rate' => .12, 'materials' => ['青命草の葉' => 4, '命脈根' => 1, '清流の雫' => 2]],
        // 現行マスタに「世界樹の雫」はなく、既存の世界樹由来素材「世界樹の葉片」を再利用する。
        'special_herbal_worldtree' => ['item' => '薬屋の特製漢方', 'family' => 'special_herbal', 'unlock_area_id' => 1011, 'output_quantity' => 1, 'fee_rate' => .12, 'materials' => ['青命草の葉' => 4, '命脈根' => 1, '世界樹の葉片' => 6]],
        'lure_beast' => ['item' => '誘魔香〈獣〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '獣牙' => 3]],
        'lure_undead' => ['item' => '誘魔香〈不死〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '古びた骨片' => 3]],
        'lure_dragon' => ['item' => '誘魔香〈竜〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '魔物の外殻' => 3]],
        'lure_demon' => ['item' => '誘魔香〈悪魔〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '黒結晶' => 2]],
        'lure_aquatic' => ['item' => '誘魔香〈水棲〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '清流の雫' => 2]],
        'lure_flying' => ['item' => '誘魔香〈飛行〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '薄い翼膜' => 3]],
        'lure_insect' => ['item' => '誘魔香〈虫〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '守樹の樹脂' => 2]],
        'lure_machine' => ['item' => '誘魔香〈機械〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '魔鉱片' => 3]],
        'lure_slime' => ['item' => '誘魔香〈スライム〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, 'スライムの粘液' => 5]],
        'lure_soldier' => ['item' => '誘魔香〈人型〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '古びた徽章' => 3]],
        'lure_mage' => ['item' => '誘魔香〈魔法型〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '魔物の魔核' => 2]],
        'lure_spirit' => ['item' => '誘魔香〈精霊〉', 'unlock_area_id' => 1001, 'available_from_start' => true, 'output_quantity' => 1, 'fee_rate' => .08, 'materials' => ['魔物の欠片' => 2, '妖精粉' => 3]],
    ];

    public function recipesFor(Character $character): array
    {
        $materialNames = array_merge(...array_values(array_map(
            fn (array $recipe): array => array_keys($recipe['materials']),
            self::RECIPES,
        )));
        $materials = Material::whereIn('name', array_values(array_unique($materialNames)))->get()->keyBy('name');
        $owned = CharacterMaterial::where('character_id', $character->id)->with('material')->get()->mapWithKeys(fn ($row) => [$row->material?->name => (int) $row->quantity]);
        $items = Item::whereIn('name', array_column(self::RECIPES, 'item'))->get()->keyBy('name');
        $ownedItemCounts = CharacterItem::where('character_id', $character->id)
            ->where('is_equipped', false)
            ->whereIn('item_id', $items->pluck('id'))
            ->selectRaw('item_id, count(*) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');
        $remainingByItem = PlayerExplorationSupportItemState::where('character_id', $character->id)
            ->whereIn('item_id', $items->pluck('id'))
            ->pluck('battles_remaining', 'item_id');

        return collect(self::RECIPES)->map(function (array $definition, string $code) use ($character, $materials, $owned, $items, $ownedItemCounts, $remainingByItem) {
            $requirements = [];
            $max = PHP_INT_MAX;
            $materialValue = 0;
            foreach ($definition['materials'] as $name => $quantity) {
                $material = $materials->get($name);
                $available = (int) ($owned[$name] ?? 0);
                $requirements[] = [
                    'name' => $name,
                    'quantity' => $quantity,
                    'owned' => $available,
                    'available' => $material !== null,
                    'icon_image' => $material?->iconImagePath(),
                ];
                $max = min($max, intdiv($available, $quantity));
                $materialValue += $quantity * max(0, (int) ($material?->npc_sale_price ?? 0));
            }
            $fee = (int) (ceil(($materialValue * $definition['fee_rate']) / 10) * 10);
            $totalGold = app(BankService::class)->summary($character)['total_gold'];
            $max = min($max === PHP_INT_MAX ? 0 : $max, intdiv($totalGold, max(1, $fee)));
            $unlocked = $this->isRecipeUnlocked($character, $definition);
            $outputQuantity = $this->outputQuantity($definition);
            $item = $items->get($definition['item']);
            return [
                'code' => $code, 'name' => $definition['item'], 'description' => ExplorationSupportService::ITEMS[$this->supportKeyForItem($definition['item'])]['description'],
                'requirements' => $requirements, 'gold_fee' => $fee, 'output_quantity' => $outputQuantity, 'max_craft_count' => max(0, $max),
                'unlocked' => $unlocked, 'unlock_text' => $this->unlockText((int) $definition['unlock_area_id']),
                'variant_label' => $code === 'special_herbal_clearstream' ? '清流の雫を使う' : ($code === 'special_herbal_worldtree' ? '世界樹の葉片を使う' : null),
                'owned_item_count' => (int) ($ownedItemCounts[$item?->id] ?? 0),
                'remaining_battles' => (int) ($remainingByItem[$item?->id] ?? 0),
                'support_key' => $this->supportKeyForItem($definition['item']),
            ];
        })->values()->all();
    }

    public function craft(Character $character, string $code, int $count, bool $bankConfirmed = false): array
    {
        $definition = self::RECIPES[$code] ?? null;
        if (!$definition) throw new RuntimeException('調合できない品です。');
        $count = max(1, min(99, $count));
        if (!$this->isRecipeUnlocked($character, $definition)) throw new RuntimeException('この調合はまだ解放されていません。');
        $outputQuantity = $this->outputQuantity($definition);

        return DB::transaction(function () use ($character, $definition, $count, $code, $outputQuantity, $bankConfirmed): array {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();
            $materials = Material::whereIn('name', array_keys($definition['materials']))->lockForUpdate()->get()->keyBy('name');
            if ($materials->count() !== count($definition['materials'])) throw new RuntimeException('調合素材のマスタが不足しています。');
            $rows = CharacterMaterial::where('character_id', $lockedCharacter->id)->whereIn('material_id', $materials->pluck('id'))->lockForUpdate()->get()->keyBy('material_id');
            $value = 0;
            foreach ($definition['materials'] as $name => $quantity) {
                $material = $materials[$name]; $required = $quantity * $count; $row = $rows->get($material->id);
                if (!$row || (int) $row->quantity < $required) throw new RuntimeException("{$name}が足りません。");
                $value += $quantity * max(0, (int) $material->npc_sale_price);
            }
            $fee = (int) (ceil(($value * $definition['fee_rate']) / 10) * 10) * $count;
            foreach ($definition['materials'] as $name => $quantity) {
                $row = $rows[$materials[$name]->id]; $rest = (int) $row->quantity - ($quantity * $count);
                $rest > 0 ? $row->forceFill(['quantity' => $rest])->save() : $row->delete();
            }
            $payment = $fee > 0
                ? app(BankService::class)->spendForPayment($lockedCharacter, $fee, $bankConfirmed, 'apothecary_craft', '薬屋で探索補助品を調合', Recipe::class, null, ['recipe_code' => $code, 'count' => $count])
                : null;
            $item = Item::where('name', $definition['item'])->where('type', 'consumable')->firstOrFail();
            $craftedQuantity = $count * $outputQuantity;
            for ($i = 0; $i < $craftedQuantity; $i++) CharacterItem::create(['character_id' => $lockedCharacter->id, 'item_id' => $item->id, 'is_equipped' => false, 'is_locked' => false]);
            return ['name' => $item->name, 'quantity' => $craftedQuantity, 'fee' => $fee, 'payment' => $payment];
        });
    }

    private function isUnlocked(Character $character, int $areaId): bool
    {
        if ($areaId === 1001 && (int) ($character->highest_city_id ?? 0) >= 101) return true;
        $area = Area::find($areaId);
        $progress = CharacterAreaProgress::where('character_id', $character->id)->where('area_id', $areaId)->first();
        return $progress && ((bool) $progress->boss_defeated || (int) $progress->development_point >= (int) ($area?->development_required_point ?? 100));
    }

    private function isRecipeUnlocked(Character $character, array $definition): bool
    {
        return (bool) ($definition['available_from_start'] ?? false)
            || $this->isUnlocked($character, (int) $definition['unlock_area_id']);
    }

    private function outputQuantity(array $definition): int
    {
        return max(1, (int) ($definition['output_quantity'] ?? 3));
    }

    private function unlockText(int $areaId): string
    {
        return (string) (Area::find($areaId)?->name ?? 'フェルディアの探索地') . 'を踏破すると解放';
    }

    private function supportKeyForItem(string $itemName): string
    {
        foreach (ExplorationSupportService::ITEMS as $key => $item) if ($item['name'] === $itemName) return $key;
        throw new RuntimeException('探索補助品の定義がありません。');
    }
}
