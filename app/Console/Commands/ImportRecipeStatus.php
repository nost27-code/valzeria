<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportRecipeStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ffa:import-recipe-status {file=docs/recipe_status_master.md}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import recipe status from Markdown TSV file';

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

            // _SYN を含むIDであることを確認
            if (count($data) < 32 || !str_contains($data[0], '_SYN')) {
                continue;
            }

            $itemIdStr = $data[0];
            $itemType = strtolower($data[1]); // WEAPON -> weapon
            $name = $data[3];
            $subType = $data[4] !== '無' ? $data[4] : null;
            $rarity = $data[5];
            $requiredLevel = (int)$data[6];
            $element = $data[12] !== '無' ? $data[12] : null;
            
            $hp = (int)$data[13];
            $mp = (int)$data[14];
            $atk = (int)$data[15];
            $def = (int)$data[16];
            $mag = (int)$data[17];
            $spr = (int)$data[18];
            $spd = (int)$data[19];
            $luk = (int)$data[20];
            
            $price = (int)$data[21];
            $sellPrice = (int)$data[22];
            $description = $data[31];

            $item = \App\Models\Item::updateOrCreate(
                ['name' => $name],
                [
                    'type' => $itemType,
                    'sub_type' => $subType,
                    'rarity' => $rarity,
                    'required_level' => $requiredLevel,
                    'element' => $element,
                    'hp_bonus' => $hp,
                    'mp_bonus' => $mp,
                    'str_bonus' => $atk,
                    'def_bonus' => $def,
                    'mag_bonus' => $mag,
                    'spr_bonus' => $spr,
                    'agi_bonus' => $spd,
                    'luk_bonus' => $luk,
                    'price' => $price,
                    'sell_price' => $sellPrice,
                    'description' => $description,
                    'is_shop_item' => false,
                ]
            );

            $count++;
        }

        $this->info("Imported {$count} item status successfully!");
    }
}
