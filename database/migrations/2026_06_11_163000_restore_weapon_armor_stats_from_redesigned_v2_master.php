<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['docs/weapon_master.tsv', 'docs/armor_master.tsv'] as $relativePath) {
            $path = base_path($relativePath);
            if (!is_file($path)) {
                continue;
            }

            foreach ($this->readTsv($path) as $row) {
                $id = $this->intValue($row['id'] ?? null);
                $type = trim((string)($row['type'] ?? ''));
                if ($id <= 0 || !in_array($type, ['weapon', 'armor'], true)) {
                    continue;
                }

                DB::table('items')->where('id', $id)->update([
                    'name' => trim((string)($row['name'] ?? '')),
                    'type' => $type,
                    'description' => $this->nullableText($row['description'] ?? null),
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
                    'is_shop_item' => $this->boolValue($row['is_shop_item'] ?? null),
                    'is_active' => $this->boolValue($row['is_active'] ?? 1),
                    'sort_order' => $this->intValue($row['sort_order'] ?? null),
                    'sub_type' => $this->nullableText($row['sub_type'] ?? null),
                    'element' => $this->nullableText($row['element'] ?? null),
                    'unlock_city_id' => $this->nullableInt($row['unlock_city_id'] ?? null),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Master data restoration. No destructive rollback.
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
};
