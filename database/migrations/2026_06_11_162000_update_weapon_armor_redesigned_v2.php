<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['docs/weapon_master.tsv', 'docs/armor_master.tsv'] as $relativePath) {
            $path = base_path($relativePath);
            if (!is_file($path)) {
                continue;
            }

            foreach ($this->readTsv($path) as $row) {
                $id = $this->intValue($row['id'] ?? null);
                if ($id <= 0) {
                    continue;
                }

                $type = trim((string)($row['type'] ?? ''));
                if (!in_array($type, ['weapon', 'armor'], true)) {
                    continue;
                }

                $payload = [
                    'name' => trim((string)($row['name'] ?? '')),
                    'type' => $type,
                    'sub_type' => $this->nullableText($row['sub_type'] ?? null),
                    'description' => $this->nullableText($row['description'] ?? null),
                    'element' => $this->nullableText($row['element'] ?? null),
                    'rarity' => trim((string)($row['rarity'] ?? 'normal')),
                    'price' => $this->intValue($row['price'] ?? null),
                    'sell_price' => $this->intValue($row['sell_price'] ?? null),
                    'hp_bonus' => $this->intValue($row['hp_bonus'] ?? null),
                    'mp_bonus' => $this->intValue($row['mp_bonus'] ?? null),
                    'str_bonus' => $this->intValue($row['str_bonus'] ?? null),
                    'def_bonus' => $this->intValue($row['def_bonus'] ?? null),
                    'agi_bonus' => $this->intValue($row['agi_bonus'] ?? null),
                    'mag_bonus' => $this->intValue($row['mag_bonus'] ?? null),
                    'spr_bonus' => $this->intValue($row['spr_bonus'] ?? null),
                    'luk_bonus' => $this->intValue($row['luk_bonus'] ?? null),
                    'required_level' => max(1, $this->intValue($row['required_level'] ?? 1)),
                    'unlock_city_id' => $this->nullableInt($row['unlock_city_id'] ?? null),
                    'is_shop_item' => $this->boolValue($row['is_shop_item'] ?? null),
                    'is_active' => $this->boolValue($row['is_active'] ?? 1),
                    'sort_order' => $this->intValue($row['sort_order'] ?? null),
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('items', 'weapon_category')) {
                    $payload['weapon_category'] = $type === 'weapon'
                        ? $this->weaponCategory($payload['sub_type'], $payload['name'])
                        : null;
                }
                if (Schema::hasColumn('items', 'weapon_hand_type')) {
                    $payload['weapon_hand_type'] = $type === 'weapon' ? 'one_hand' : null;
                }
                if (Schema::hasColumn('items', 'weapon_role')) {
                    $payload['weapon_role'] = $type === 'weapon' ? $this->weaponRole($payload) : null;
                }
                if (Schema::hasColumn('items', 'armor_category')) {
                    $payload['armor_category'] = $type === 'armor'
                        ? $this->armorCategory($payload['sub_type'], $payload['name'])
                        : null;
                }
                if (Schema::hasColumn('items', 'armor_weight')) {
                    $payload['armor_weight'] = $type === 'armor' ? $this->armorWeight($payload['sub_type'], $payload['name']) : null;
                }
                if (Schema::hasColumn('items', 'armor_role')) {
                    $payload['armor_role'] = $type === 'armor' ? $this->armorRole($payload) : null;
                }

                if (DB::table('items')->where('id', $id)->exists()) {
                    DB::table('items')->where('id', $id)->update($payload);
                } else {
                    DB::table('items')->insert(['id' => $id] + $payload + [
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Master data migration. No destructive rollback.
    }

    private function readTsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            $values = explode("\t", $line);
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }

    private function intValue(mixed $value): int
    {
        return (int)str_replace(',', '', trim((string)$value));
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '' || strtoupper($value) === 'NULL') {
            return null;
        }

        return $this->intValue($value);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strtoupper($value) === 'NULL') {
            return null;
        }

        return $value;
    }

    private function boolValue(mixed $value): bool
    {
        return in_array(trim((string)$value), ['1', 'true', 'TRUE'], true);
    }

    private function weaponCategory(?string $subType, string $name): ?string
    {
        $source = ($subType ?? '').' '.$name;

        return $this->match($source, [
            'katana' => ['刀'],
            'spear' => ['槍'],
            'staff' => ['杖', 'ロッド'],
            'bow' => ['弓'],
            'dagger' => ['短剣', 'ダガー'],
            'magic_device' => ['魔導具', '魔道具', '魔導器', 'オーブ'],
            'gun' => ['銃', '短銃', 'ライフル'],
            'axe' => ['斧', 'アックス'],
            'fist' => ['拳', '拳甲', 'ナックル', 'グローブ'],
            'sword' => ['剣', 'ブレード', 'サーベル'],
        ]);
    }

    private function weaponRole(array $item): string
    {
        if (($item['mag_bonus'] ?? 0) > ($item['str_bonus'] ?? 0)) {
            return 'magic';
        }

        return 'physical';
    }

    private function armorCategory(?string $subType, string $name): ?string
    {
        $source = ($subType ?? '').' '.$name;

        return $this->match($source, [
            'heavy_armor' => ['重鎧', '鎧', '甲冑', 'プレート', 'メイル'],
            'light_armor' => ['革鎧', '軽鎧', 'レザー', 'ジャケット'],
            'robe' => ['ローブ', '法衣', '聖衣'],
            'cloak' => ['外套', 'マント', 'クローク', '羽織'],
            'clothes' => ['服', '衣', '旅装', 'チュニック'],
        ]);
    }

    private function armorWeight(?string $subType, string $name): string
    {
        $source = ($subType ?? '').' '.$name;
        if (str_contains($source, '重') || str_contains($source, '鎧') || str_contains($source, '甲冑')) {
            return 'heavy';
        }
        if (str_contains($source, '軽') || str_contains($source, '革')) {
            return 'light';
        }

        return 'normal';
    }

    private function armorRole(array $item): string
    {
        if (($item['spr_bonus'] ?? 0) > ($item['def_bonus'] ?? 0)) {
            return 'magic';
        }

        return 'physical';
    }

    private function match(string $source, array $rules): ?string
    {
        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($source, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
};
