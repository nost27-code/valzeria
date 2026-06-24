<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class WeaponsSeeder extends Seeder
{
    public function run(): void
    {
        if (file_exists(database_path('data/weapon_evolution_master.json'))) {
            $this->command?->info('Weapon evolution master is active. Legacy weapons_data.tsv import skipped.');
            return;
        }

        $tsvPath = base_path('weapons_data.tsv');
        if (!file_exists($tsvPath)) {
            $this->command?->error("weapons_data.tsv not found.");
            return;
        }

        $lines = file($tsvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $headerLine = array_shift($lines);
        $headers = explode("\t", $headerLine);

        Item::where('type', 'weapon')->update(['is_shop_item' => false]);

        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            
            $row = explode("\t", $line);
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), null);
            }
            $row = array_slice($row, 0, count($headers));
            
            $data = array_combine($headers, $row);

            $name = trim($data['name'] ?? '');
            if (!$name) continue;

            $unlockCityId = (isset($data['unlock_city_id']) && trim($data['unlock_city_id']) !== '' && trim($data['unlock_city_id']) !== 'NULL') ? (int)trim($data['unlock_city_id']) : null;
            $element = (isset($data['element']) && trim($data['element']) !== '' && trim($data['element']) !== 'NULL') ? trim($data['element']) : null;
            $description = (isset($data['description']) && trim($data['description']) !== 'NULL') ? trim($data['description']) : '';

            Item::updateOrCreate(
                ['name' => $name, 'type' => 'weapon'],
                [
                    'description' => $description,
                    'rarity' => trim($data['rarity'] ?? 'normal'),
                    'price' => (int)str_replace(',', '', $data['price'] ?? 0),
                    'sell_price' => (int)str_replace(',', '', $data['sell_price'] ?? 0),
                    'hp_bonus' => (int)($data['hp_bonus'] ?? 0),
                    'mp_bonus' => (int)($data['mp_bonus'] ?? 0),
                    'str_bonus' => (int)($data['str_bonus'] ?? 0),
                    'def_bonus' => (int)($data['def_bonus'] ?? 0),
                    'mag_bonus' => (int)($data['mag_bonus'] ?? 0),
                    'spr_bonus' => (int)($data['spr_bonus'] ?? 0),
                    'agi_bonus' => (int)($data['agi_bonus'] ?? 0),
                    'luk_bonus' => (int)($data['luk_bonus'] ?? 0),
                    'required_level' => (int)($data['required_level'] ?? 1),
                    'is_shop_item' => (bool)($data['is_shop_item'] ?? 0),
                    'is_active' => (bool)($data['is_active'] ?? 1),
                    'sort_order' => (int)($data['sort_order'] ?? 100),
                    'sub_type' => trim($data['sub_type'] ?? ''),
                    'element' => $element,
                    'unlock_city_id' => $unlockCityId,
                ]
            );
        }

        $this->command?->info("Weapons data imported successfully.");
    }
}
