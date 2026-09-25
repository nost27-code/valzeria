<?php

use App\Services\Nation\Raid\NationRaidRewardIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            NationRaidRewardIdentity::VALGREID_FIRST_TITLE_TARGET => '黒天竜討滅の覇者',
            NationRaidRewardIdentity::ASTRAGIA_FIRST_TITLE_TARGET => '天墜機神討滅の覇者',
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
                        '国家対抗レイド1位称号マスタが一致しません。',
                    );

                    continue;
                }

                throw_if(
                    DB::table('titles')->where('name', $name)->exists(),
                    RuntimeException::class,
                    '同名の国家対抗レイド1位称号が別条件で存在します。',
                );
                DB::table('titles')->insert([
                    'category' => 'battle',
                    'rarity' => 'rare',
                    'name' => $name,
                    'description' => '国家対抗レイドの個人累計ダメージ1位を称える、能力補正のない称号。',
                    'hint' => '国家対抗レイドで個人累計ダメージ1位になる。',
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

            $valgreidTitleId = DB::table('titles')
                ->where('unlock_type', 'nation_raid_honor')
                ->where('target_type', 'raid_reward')
                ->where('target_id', NationRaidRewardIdentity::VALGREID_FIRST_TITLE_TARGET)
                ->value('id');

            DB::table('nation_raid_personal_rewards as rewards')
                ->join('nation_raid_events as events', 'events.id', '=', 'rewards.event_id')
                ->join('characters', 'characters.id', '=', 'rewards.character_id_snapshot')
                ->where('events.event_key', 'valgreid-inaugural')
                ->where('rewards.reward_key', 'personal_first')
                ->where('rewards.status', 'claimed')
                ->orderBy('rewards.id')
                ->select(['rewards.character_id_snapshot', 'rewards.claimed_at', 'rewards.created_at'])
                ->get()
                ->each(function (object $reward) use ($valgreidTitleId): void {
                    $acquiredAt = $reward->claimed_at ?? $reward->created_at ?? now();
                    DB::table('character_titles')->insertOrIgnore([
                        'character_id' => (int) $reward->character_id_snapshot,
                        'title_id' => (int) $valgreidTitleId,
                        'is_equipped' => false,
                        'created_at' => $acquiredAt,
                        'updated_at' => $acquiredAt,
                    ]);
                });
        }, 3);
    }

    public function down(): void
    {
        // 取得済み称号と既存の「万軍の先鋒」を守るため削除しない。修正は前進migrationで行う。
    }
};
