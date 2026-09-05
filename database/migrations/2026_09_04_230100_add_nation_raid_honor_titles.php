<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 既存IDは変更しない。通常の自動解放条件と分離した、能力なしの称号マスタ。
        foreach (['damage2m' => '黒天竜を穿つ者', 'personal_first' => '万軍の先鋒',
            'personal_top3' => '黒天竜討滅の功臣', 'max_first' => '天穿の一撃'] as $key => $name) {
            $query = DB::table('titles')->where('unlock_type', 'nation_raid_honor')->where('target_type', 'raid_reward')->where('target_id', $key);
            if ($query->exists()) {
                throw_unless($query->count() === 1 && $query->value('name') === $name, RuntimeException::class, 'レイド称号マスタが一致しません。');

                continue;
            }
            DB::table('titles')->insert(['category' => 'battle', 'rarity' => 'rare', 'name' => $name,
                'description' => '国家対抗レイドの功績を称える、能力補正のない称号。', 'hint' => '国家対抗レイドで功績を残す。',
                'unlock_type' => 'nation_raid_honor', 'target_type' => 'raid_reward', 'target_id' => $key,
                'source_master' => 'nation_raid', 'display_order' => 0, 'is_hidden' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // 取得済み称号と参照IDを保全する。公開OFFで停止し、修正は前進migrationで行う。
    }
};
