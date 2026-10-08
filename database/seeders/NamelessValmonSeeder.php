<?php

namespace Database\Seeders;

use App\Models\ValmonMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NamelessValmonSeeder extends Seeder
{
    public function run(): void
    {
        $masters = (array) config('nameless_valmons.masters');
        foreach ($masters as $definition) {
            if (! config('nameless_ruins.'.$definition['zone'])
                || ! is_file(public_path(sprintf('images/valmon/new/valmon_%03d.webp', $definition['no'])))) {
                throw new RuntimeException('遺跡ヴァルモンの区画または画像が不足しています。');
            }
        }

        DB::transaction(function () use ($masters): void {
            // 承認された新規15種だけを登録。既存種・所有データは変更しない。
            foreach ($masters as $key => $definition) {
                ValmonMaster::updateOrCreate(['valmon_key' => $key], [
                    'name' => $definition['name'], 'description' => $definition['description'],
                    'image_path' => sprintf('images/valmon/new/valmon_%03d.webp', $definition['no']),
                    'silhouette_type' => $definition['type'], 'rarity' => $definition['rarity'],
                    'base_find_material_category' => null, 'is_starter' => false,
                    'is_active' => true, 'sort_order' => 1000 + $definition['no'],
                ]);
            }
        });
    }
}
