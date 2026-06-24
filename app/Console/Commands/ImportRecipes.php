<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportRecipes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ffa:import-recipes {file=docs/recipe_master}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import recipe master data from TSV file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = base_path($this->argument('file'));

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return;
        }

        // 文字化け対策: ファイル内容を取得し、UTF-8に変換（必要であれば）して行ごとに処理
        $content = file_get_contents($filePath);
        $encoding = mb_detect_encoding($content, 'UTF-8, SJIS-win, SJIS, EUC-JP, auto');
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $content));

        $count = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = explode("\t", $line);

            // データが足りない場合はスキップ（REC...で始まる想定）
            if (count($data) < 14 || !str_starts_with($data[0], 'REC')) {
                continue;
            }

            $recipeCode = $data[0];
            $name = $data[1];
            $itemType = $data[3];
            $resultItemName = $data[5];
            $requiredLevel = (int)$data[6];
            $cityName = $data[7] ?? null;
            $areaId = !empty($data[8]) ? (int)$data[8] : null;
            $areaName = $data[9] ?? null;
            $element = $data[11] ?? null;
            $cost = isset($data[12]) ? (int)$data[12] : 0;
            $successRate = isset($data[13]) ? (int)$data[13] : 100;

            $unlockType = $data[14] ?? null;
            $unlockValue = $data[15] ?? null;
            $unlockDesc = $data[16] ?? null;

            // 素材 (17~28) 最大4種類
            $materials = [];
            for ($i = 0; $i < 4; $i++) {
                $baseIdx = 17 + ($i * 3);
                if (isset($data[$baseIdx]) && $data[$baseIdx] !== '') {
                    $materials[] = [
                        'material_code' => $data[$baseIdx],
                        'name' => $data[$baseIdx + 1] ?? '',
                        'quantity' => (int)($data[$baseIdx + 2] ?? 0),
                        'is_key' => false,
                        'consume' => true,
                    ];
                }
            }

            // キー素材 (29~31)
            $keyMaterialCode = $data[29] ?? null;
            $keyMaterialName = $data[30] ?? null;
            $consumeKey = isset($data[31]) && strtoupper($data[31]) === 'TRUE';
            
            if ($keyMaterialCode) {
                $materials[] = [
                    'material_code' => $keyMaterialCode,
                    'name' => $keyMaterialName,
                    'quantity' => 1,
                    'is_key' => true,
                    'consume' => $consumeKey,
                ];
            }

            $isActive = isset($data[32]) ? (strtoupper($data[32]) === 'TRUE') : true;
            $notes = $data[33] ?? null;

            // アイテムとの紐付けを試みる。存在しない場合は新規作成する
            $resultItemId = null;
            $item = \App\Models\Item::where('name', $resultItemName)->first();
            if (!$item) {
                $mappedType = strtolower($itemType);
                $item = \App\Models\Item::create([
                    'name' => $resultItemName,
                    'type' => $mappedType,
                    'required_level' => $requiredLevel,
                    'description' => "合成によって作られた装備",
                    'is_shop_item' => false,
                ]);
            }
            $resultItemId = $item->id;

            \App\Models\Recipe::updateOrCreate(
                ['recipe_code' => $recipeCode],
                [
                    'name' => $name,
                    'item_type' => $itemType,
                    'result_item_name' => $resultItemName,
                    'result_item_id' => $resultItemId,
                    'required_level' => $requiredLevel,
                    'area_id' => $areaId,
                    'area_name' => $areaName,
                    'city_name' => $cityName,
                    'element' => $element,
                    'cost' => $cost,
                    'success_rate' => $successRate,
                    'unlock_condition_type' => $unlockType,
                    'unlock_condition_value' => $unlockValue,
                    'unlock_condition_desc' => $unlockDesc,
                    'materials' => $materials,
                    'key_material_code' => $keyMaterialCode,
                    'key_material_name' => $keyMaterialName,
                    'consume_key_material' => $consumeKey,
                    'is_active' => $isActive,
                    'notes' => $notes,
                ]
            );

            $count++;
        }

        $this->info("Imported {$count} recipes successfully!");
    }
}
