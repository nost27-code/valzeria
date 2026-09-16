<?php

use App\Services\Nation\Raid\NationRaidRewardIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            NationRaidRewardIdentity::ASTRAGIA_DAMAGE_TITLE_TARGET => '天墜機神を穿つ者',
            NationRaidRewardIdentity::ASTRAGIA_TOP_THREE_TITLE_TARGET => '天墜機神討滅の功臣',
        ];

        DB::transaction(function () use ($definitions): void {
            foreach ($definitions as $targetId => $name) {
                $query = DB::table('titles')
                    ->where('unlock_type', 'nation_raid_honor')
                    ->where('target_type', 'raid_reward')
                    ->where('target_id', $targetId);
                if ($query->exists()) {
                    $row = $query->sole(['name', 'category', 'rarity', 'source_master', 'is_hidden']);
                    throw_unless(
                        $row->name === $name
                            && $row->category === 'battle'
                            && $row->rarity === 'rare'
                            && $row->source_master === 'nation_raid'
                            && (bool) $row->is_hidden,
                        RuntimeException::class,
                        'アストラギア報酬称号マスタが一致しません。',
                    );

                    continue;
                }

                throw_if(
                    DB::table('titles')->where('name', $name)->exists(),
                    RuntimeException::class,
                    '同名のアストラギア報酬称号が別条件で存在します。',
                );
                DB::table('titles')->insert([
                    'category' => 'battle',
                    'rarity' => 'rare',
                    'name' => $name,
                    'description' => '国家対抗レイドの功績を称える、能力補正のない称号。',
                    'hint' => '国家対抗レイドで功績を残す。',
                    'unlock_type' => 'nation_raid_honor',
                    'target_type' => 'raid_reward',
                    'target_id' => $targetId,
                    'source_master' => 'nation_raid',
                    'display_order' => 0,
                    'is_hidden' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    public function down(): void
    {
        // 取得済み称号とcharacter_titles参照を守るため削除しない。修正は前進migrationで行う。
    }
};
