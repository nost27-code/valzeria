<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\PlayerNamelessEquipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NamelessEquipmentService
{
    public const MAX_FORGE_LEVEL = 99;
    private const MILESTONES = [10 => 1, 30 => 3, 50 => 5, 70 => 7, 90 => 9, 99 => 10];
    private const TYPES = [
        'weapon' => ['剣', '短剣', '槍', '斧', '弓', '杖', '魔導書', '銃', '拳具'],
        'armor' => ['鎧', '服', 'ローブ', '外套', '盾', '装束'],
    ];
    private const STAT_KEYS = [
        'weapon' => ['剣' => 'str', '短剣' => 'str', '槍' => 'str', '斧' => 'str', '弓' => 'str', '銃' => 'str', '拳具' => 'str', '杖' => 'mag', '魔導書' => 'mag'],
        'armor' => ['鎧' => 'def', '服' => 'def', '外套' => 'def', '盾' => 'def', '装束' => 'def', 'ローブ' => 'spr'],
    ];
    private const STAT_LABELS = ['str' => 'ATK', 'def' => 'DEF', 'mag' => 'MAG', 'spr' => 'SPR'];

    public static function powerFor(int $forgeLevel): int
    {
        return 5 + (max(0, min(self::MAX_FORGE_LEVEL, $forgeLevel)) * 5);
    }

    public static function goldCostForNextLevel(int $nextLevel): int
    {
        return max(0, min(self::MAX_FORGE_LEVEL, $nextLevel)) * 10000;
    }

    public static function totalGoldCostFor(int $forgeLevel): int
    {
        $level = max(0, min(self::MAX_FORGE_LEVEL, $forgeLevel));
        return 10000 * $level * ($level + 1) / 2;
    }

    public static function totalFineMaterialsFor(int $forgeLevel): int
    {
        $level = max(0, min(self::MAX_FORGE_LEVEL, $forgeLevel));
        if ($level === 0) return 0;
        return array_sum(array_map(fn (int $current) => (int) ceil($current / 5), range(1, $level)));
    }

    public static function milestoneMaterialTotalFor(int $forgeLevel): int
    {
        return array_sum(array_filter(self::MILESTONES, fn (int $quantity, int $level) => $level <= $forgeLevel, ARRAY_FILTER_USE_BOTH));
    }

    public static function forgeCapForCityOrder(int $cityOrder): int
    {
        if ($cityOrder >= 100) return 99;
        return max(10, min(90, intdiv(max(10, $cityOrder), 10) * 10));
    }

    /** @return array{key: string, label: string} */
    public static function statFor(string $kind, string $equipmentType): array
    {
        $key = self::STAT_KEYS[$kind][$equipmentType] ?? null;
        if (!$key) throw new RuntimeException('この武具種別は選択できません。');
        return ['key' => $key, 'label' => self::STAT_LABELS[$key]];
    }

    /** @return array<string, array{key: string, label: string}> */
    public static function statOptionsFor(string $kind): array
    {
        return collect(self::STAT_KEYS[$kind] ?? [])->map(fn (string $key) => ['key' => $key, 'label' => self::STAT_LABELS[$key]])->all();
    }

    public function rowsFor(Character $character): array
    {
        $owned = $character->namelessEquipments()->get()->keyBy('kind');
        return collect(['weapon', 'armor'])->mapWithKeys(function (string $kind) use ($character, $owned): array {
            $equipment = $owned->get($kind);
            return [$kind => $this->rowFor($character, $kind, $equipment)];
        })->all();
    }

    public function forge(Character $character, string $kind, ?string $newName, ?string $newType): array
    {
        return DB::transaction(function () use ($character, $kind, $newName, $newType): array {
            $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
            $equipment = PlayerNamelessEquipment::query()
                ->where('character_id', $lockedCharacter->id)->where('kind', $kind)->lockForUpdate()->first();
            if (!$equipment) throw new RuntimeException('名もなき武具をまだ所持していません。');

            $nextLevel = (int) $equipment->forge_level + 1;
            if ((int) $equipment->forge_level >= $this->forgeCapFor($lockedCharacter)) {
                throw new RuntimeException("現在の冒険進行では、これ以上鍛えることはできません。\n新たな地へ進むことで、さらなる鍛冶が解放されます。");
            }

            $normalizedName = $this->normalizeName($newName);
            $resolvedType = $this->validateType($kind, $newType ?: $equipment->equipment_type);
            $requirements = $this->requirementsFor($kind, $nextLevel);
            foreach ($requirements['materials'] as $requirement) {
                $owned = CharacterMaterial::query()->where('character_id', $lockedCharacter->id)
                    ->where('material_id', $requirement['material']->id)->lockForUpdate()->first();
                if ((int) ($owned?->quantity ?? 0) < $requirement['required']) throw new RuntimeException('素材が不足しています。');
            }
            if ((int) $lockedCharacter->money < $requirements['gold']) throw new RuntimeException('Goldが不足しています。');

            foreach ($requirements['materials'] as $requirement) {
                $owned = CharacterMaterial::query()->where('character_id', $lockedCharacter->id)
                    ->where('material_id', $requirement['material']->id)->lockForUpdate()->firstOrFail();
                $owned->quantity -= $requirement['required'];
                $owned->save();
            }
            app(GoldService::class)->spend($lockedCharacter, $requirements['gold'], 'nameless_equipment_forge', "{$equipment->displayName()} +{$nextLevel} 鍛冶");
            $equipment->update(['forge_level' => $nextLevel, 'custom_name' => $normalizedName, 'equipment_type' => $resolvedType]);
            CharacterStatusService::clearRequestCache($lockedCharacter->id);

            if (isset(self::MILESTONES[$nextLevel])) {
                app(PublicLogService::class)->addLog('system', "【鍛冶】{$lockedCharacter->name}さんの{$equipment->fresh()->displayName()}が +{$nextLevel} に到達しました。", $lockedCharacter, 1);
            }
            return ['message' => "{$equipment->fresh()->displayName()} を +{$nextLevel} に鍛えました。"];
        });
    }

    public function equip(Character $character, string $kind): array
    {
        return DB::transaction(function () use ($character, $kind): array {
            $equipment = PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', $kind)->lockForUpdate()->first();
            if (!$equipment) throw new RuntimeException('名もなき武具をまだ所持していません。');
            CharacterItem::query()->where('character_id', $character->id)
                ->where('is_equipped', true)->where('equipped_slot', $kind)
                ->update(['is_equipped' => false, 'equipped_slot' => null]);
            PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', $kind)->update(['is_equipped' => false]);
            $equipment->update(['is_equipped' => true]);
            CharacterStatusService::clearRequestCache($character->id);
            return ['message' => "{$equipment->displayName()}を装備しました。"];
        });
    }

    public function unequip(Character $character, string $kind): array
    {
        $equipment = PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', $kind)->first();
        if (!$equipment) throw new RuntimeException('名もなき武具をまだ所持していません。');
        $equipment->update(['is_equipped' => false]);
        CharacterStatusService::clearRequestCache($character->id);
        return ['message' => "{$equipment->displayName()}を外しました。"];
    }

    private function rowFor(Character $character, string $kind, ?PlayerNamelessEquipment $equipment): array
    {
        if (!$equipment) return ['kind' => $kind, 'owned' => false];
        $nextLevel = (int) $equipment->forge_level + 1;
        $cap = $this->forgeCapFor($character);
        $requirements = $nextLevel <= self::MAX_FORGE_LEVEL ? $this->requirementsFor($kind, $nextLevel) : ['gold' => 0, 'materials' => []];
        $canForge = $nextLevel <= $cap && (int) $character->money >= $requirements['gold'];
        $materials = collect($requirements['materials'])->map(function (array $requirement) use ($character, &$canForge): array {
            $owned = (int) (CharacterMaterial::query()->where('character_id', $character->id)->where('material_id', $requirement['material']->id)->value('quantity') ?? 0);
            if ($owned < $requirement['required']) $canForge = false;
            return ['name' => $requirement['material']->displayName(), 'owned' => $owned, 'required' => $requirement['required'], 'missing' => max(0, $requirement['required'] - $owned)];
        })->all();
        $stat = self::statFor($kind, (string) $equipment->equipment_type);
        $nextPower = (int) $equipment->base_power + ($nextLevel * (int) $equipment->power_per_level);
        return compact('kind', 'equipment', 'cap', 'nextLevel', 'requirements', 'materials', 'canForge', 'stat', 'nextPower') + [
            'owned' => true,
            'stat_options' => self::statOptionsFor($kind),
        ];
    }

    private function forgeCapFor(Character $character): int { return self::forgeCapForCityOrder((int) ($character->highestCity?->sort_order ?? 10)); }
    private function requirementsFor(string $kind, int $nextLevel): array
    {
        $tier = DB::table('nameless_equipment_material_tiers')->where('min_level', '<=', $nextLevel)->where('max_level', '>=', $nextLevel)->first();
        if (!$tier) throw new RuntimeException('名もなき武具の素材テーブルが見つかりません。');
        $codes = ['MAT_COMMON_MONSTER_FRAGMENT', $kind === 'weapon' ? $tier->weapon_main_material_code : $tier->armor_main_material_code, $kind === 'weapon' ? $tier->weapon_fine_material_code : $tier->armor_fine_material_code];
        $quantities = [$nextLevel, $nextLevel, (int) ceil($nextLevel / 5)];
        if (isset(self::MILESTONES[$nextLevel])) { $codes[] = $kind === 'weapon' ? 'MAT_ENHANCE_FRAGMENT' : '5007'; $quantities[] = self::MILESTONES[$nextLevel]; }
        return ['gold' => self::goldCostForNextLevel($nextLevel), 'materials' => collect($codes)->map(fn (string $code, int $i) => ['material' => Material::query()->where('material_code', $code)->firstOrFail(), 'required' => $quantities[$i]])->all()];
    }
    private function normalizeName(?string $name): ?string { $name = trim((string) $name); if ($name === '') return null; if (mb_strlen($name) > 32 || preg_match('/[<>]/u', $name)) throw new RuntimeException('この名前は使用できません。'); return $name; }
    private function validateType(string $kind, string $type): string { self::statFor($kind, $type); return $type; }
}
