<?php

use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRewardIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET => '黒天竜穿ちの剛撃',
            NationRaidRewardIdentity::ASTRAGIA_MAX_ACTION_TOP_THREE_TITLE_TARGET => '天墜機神砕きの剛撃',
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
                        '国家対抗レイド最大ダメージ2〜3位称号マスタが一致しません。',
                    );

                    continue;
                }

                throw_if(
                    DB::table('titles')->where('name', $name)->exists(),
                    RuntimeException::class,
                    '同名の国家対抗レイド最大ダメージ2〜3位称号が別条件で存在します。',
                );
                DB::table('titles')->insert([
                    'category' => 'battle',
                    'rarity' => 'rare',
                    'name' => $name,
                    'description' => '国家対抗レイドの1行動最大ダメージ2〜3位を称える、能力補正のない称号。',
                    'hint' => '国家対抗レイドで1行動最大ダメージ2〜3位になる。',
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

            $event = DB::table('nation_raid_events')
                ->where('event_key', 'valgreid-inaugural')
                ->where('status', 'completed')
                ->first([
                    'id', 'reward_policy_hash', 'final_standings_snapshot', 'final_standings_hash',
                    'completed_at', 'finalized_at', 'updated_at',
                ]);
            if (! $event) {
                return;
            }

            $snapshot = json_decode((string) $event->final_standings_snapshot, true, flags: JSON_THROW_ON_ERROR);
            throw_unless(
                is_array($snapshot)
                    && is_string($event->final_standings_hash)
                    && hash_equals(
                        $event->final_standings_hash,
                        hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE)),
                    )
                    && is_array($snapshot['max_action'] ?? null),
                RuntimeException::class,
                '初回国家対抗レイドの最大ダメージ最終順位を確認できません。',
            );

            $titleId = (int) DB::table('titles')
                ->where('unlock_type', 'nation_raid_honor')
                ->where('target_type', 'raid_reward')
                ->where('target_id', NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET)
                ->value('id');
            $acquiredAt = $event->finalized_at ?? $event->completed_at ?? $event->updated_at ?? now();

            foreach ($snapshot['max_action'] as $standing) {
                if (! is_array($standing)
                    || ! in_array($standing['rank'] ?? null, [2, 3], true)
                    || ($standing['qualified'] ?? false) !== true) {
                    continue;
                }

                $accountId = filter_var($standing['account_id'] ?? null, FILTER_VALIDATE_INT);
                $characterId = filter_var($standing['character_id'] ?? null, FILTER_VALIDATE_INT);
                $characterName = $standing['name'] ?? null;
                throw_unless(
                    $accountId !== false && $accountId > 0
                        && $characterId !== false && $characterId > 0
                        && is_string($characterName) && $characterName !== '',
                    RuntimeException::class,
                    '初回国家対抗レイドの最大ダメージ2〜3位受取人を確認できません。',
                );
                if (! DB::table('characters')->where('id', $characterId)->where('user_id', $accountId)->exists()) {
                    continue;
                }

                $rewardSnapshot = [
                    'label' => '黒天竜穿ちの剛撃',
                    'title' => '黒天竜穿ちの剛撃',
                    'title_target_id' => NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET,
                    'badge' => false,
                    'policy_hash' => $event->reward_policy_hash,
                    'character_name' => $characterName,
                    'rank' => $standing['rank'],
                ];
                $idempotencyKey = hash('sha256', "raid:{$event->id}:personal:{$characterId}:max_top3");
                DB::table('nation_raid_personal_rewards')->insertOrIgnore([
                    'event_id' => $event->id,
                    'account_id_snapshot' => $accountId,
                    'character_id_snapshot' => $characterId,
                    'character_id' => $characterId,
                    'reward_key' => 'max_top3',
                    'status' => 'claimed',
                    'reward_snapshot' => json_encode($rewardSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'idempotency_key' => $idempotencyKey,
                    'availability_type' => 'finalization',
                    'available_at' => $acquiredAt,
                    'claimed_at' => $acquiredAt,
                    'created_at' => $acquiredAt,
                    'updated_at' => $acquiredAt,
                ]);
                $reward = DB::table('nation_raid_personal_rewards')
                    ->where('event_id', $event->id)
                    ->where('character_id_snapshot', $characterId)
                    ->where('reward_key', 'max_top3')
                    ->sole(['id', 'account_id_snapshot', 'status', 'reward_snapshot', 'idempotency_key', 'availability_type']);
                throw_unless(
                    (int) $reward->account_id_snapshot === $accountId
                        && $reward->status === 'claimed'
                        && json_decode((string) $reward->reward_snapshot, true, flags: JSON_THROW_ON_ERROR) === $rewardSnapshot
                        && hash_equals((string) $reward->idempotency_key, $idempotencyKey)
                        && $reward->availability_type === 'finalization',
                    RuntimeException::class,
                    '初回国家対抗レイドの最大ダメージ2〜3位報酬が一致しません。',
                );

                DB::table('character_titles')->insertOrIgnore([
                    'character_id' => $characterId,
                    'title_id' => $titleId,
                    'is_equipped' => false,
                    'created_at' => $acquiredAt,
                    'updated_at' => $acquiredAt,
                ]);
                $characterTitleId = (int) DB::table('character_titles')
                    ->where('character_id', $characterId)
                    ->where('title_id', $titleId)
                    ->value('id');
                DB::table('nation_raid_personal_rewards')
                    ->where('id', $reward->id)
                    ->update([
                        'balance_after_snapshot' => json_encode(
                            ['character_title_id' => $characterTitleId],
                            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                        ),
                    ]);
            }
        }, 3);
    }

    public function down(): void
    {
        // 遡及付与済みの報酬・称号を守るため削除しない。修正は前進migrationで行う。
    }
};
