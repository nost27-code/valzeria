<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Character;
use App\Models\Item;
use App\Models\CharacterItem;

class GrantInitialEquipment extends Command
{
    protected $signature = 'valzeria:grant-initial-equipment';

    protected $description = '全キャラクターに初期装備（木の剣、布の服、魔除けの護符）を付与し、装備状態にします';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $characters = Character::all();
        $this->info("全{$characters->count()}人のキャラクターに初期装備を配布します。");

        $initialItems = [
            'weapon' => Item::where('name', '木の剣')->first(),
            'armor' => Item::where('name', '布の服')->first(),
            'accessory' => Item::where('name', '魔除けの護符')->first(),
        ];

        foreach ($characters as $character) {
            foreach ($initialItems as $slot => $item) {
                if (!$item) continue;

                // 既に持っているか確認
                $exists = CharacterItem::where('character_id', $character->id)
                    ->where('item_id', $item->id)
                    ->exists();

                if (!$exists) {
                    // 持っていなければ付与して装備
                    CharacterItem::create([
                        'character_id' => $character->id,
                        'item_id' => $item->id,
                        'is_equipped' => true,
                        'equipped_slot' => $slot,
                        'acquired_from' => 'initial'
                    ]);
                    $this->info("{$character->name} に {$item->name} を付与しました。");
                }
            }
        }

        $this->info('初期装備の配布が完了しました。');
    }
}
