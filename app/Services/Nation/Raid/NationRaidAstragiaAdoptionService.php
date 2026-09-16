<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** 開戦前の2026-09-25開催だけを、ヴァルグレイドからアストラギアへ安全に差し替える。 */
final readonly class NationRaidAstragiaAdoptionService
{
    public const APPROVED_EVENT_KEY = 'valgreid-2026-09-25';

    public function __construct(
        private CompetitionEventCoordinatorService $coordinator,
        private NationRaidRules $rules,
    ) {}

    public function adopt(
        int $eventId,
        string $expectedEventKey,
        string $expectedOldRulesetHash,
        User $admin,
        string $approvalReference,
    ): array {
        throw_unless(app()->runningInConsole(), \DomainException::class, 'CLI以外から次回レイドボスを変更できません。');
        throw_unless($expectedEventKey === self::APPROVED_EVENT_KEY, \DomainException::class, '承認済みの次回イベントではありません。');
        throw_unless($admin->role === 'admin', \DomainException::class, '管理者を確認できません。');
        throw_if($approvalReference === '' || mb_strlen($approvalReference) > 255, \DomainException::class,
            '承認根拠を1〜255文字で指定してください。');
        throw_unless(hash_equals($this->rules->previousNextCycleRulesetHash(), $expectedOldRulesetHash),
            \DomainException::class, '変更前ruleset hashが予約済みヴァルグレイド版と一致しません。');

        return DB::transaction(function () use (
            $eventId,
            $expectedEventKey,
            $expectedOldRulesetHash,
            $admin,
            $approvalReference,
        ): array {
            $this->coordinator->lock();
            $event = NationRaidEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            $this->assertScheduledBeforePreparation($event, $expectedEventKey);

            $newHash = $this->rules->rulesetHash();
            if (hash_equals((string) $event->ruleset_hash, $newHash)) {
                $this->assertAdoptedState($event);

                return [
                    'changed' => false,
                    'event_id' => $event->id,
                    'event_key' => $event->event_key,
                    'boss_name' => $event->boss_name,
                    'ruleset_hash' => $newHash,
                ];
            }

            throw_unless(
                hash_equals((string) $event->ruleset_hash, $expectedOldRulesetHash)
                    && hash_equals((string) $event->ruleset_hash, hash('sha256', NationRaidJson::encode(
                        $event->ruleset_snapshot,
                        JSON_UNESCAPED_UNICODE,
                    )))
                    && ($event->ruleset_snapshot['version'] ?? null) === NationRaidRules::PREVIOUS_NEXT_CYCLE_RULESET_VERSION
                    && $event->name === '国家対抗レイド 黒天竜ヴァルグレイド'
                    && $event->boss_name === '十系喰らいの黒天竜 ヴァルグレイド'
                    && (int) $event->cycle_max_hp === $this->rules->stageMaxHp(1)
                    && (int) $event->total_target_hp === $this->rules->totalTargetHp(),
                \DomainException::class,
                '予約済みイベントの変更前snapshot・名称・HPが一致しません。',
            );

            $preservedKeys = [
                'event_key', 'status', 'announced_at', 'starts_at', 'ends_at',
                'stage_count', 'cycle_max_hp', 'total_target_hp',
                'reward_policy_snapshot', 'reward_policy_hash',
            ];
            $preserved = array_intersect_key($event->getRawOriginal(), array_flip($preservedKeys));
            $directory = storage_path('app/private/nation-raid-astragia-adoption');
            File::ensureDirectoryExists($directory, 0700);
            $path = $directory.'/event-'.$event->id.'-'.substr($expectedOldRulesetHash, 0, 12)
                .'-to-'.substr($newHash, 0, 12).'-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(8)).'.json';
            $json = NationRaidJson::encode([
                'reason' => $approvalReference,
                'event' => $event->getRawOriginal(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $backupHash = hash('sha256', $json);
            throw_unless(File::put($path, $json, true) === strlen($json) && hash_file('sha256', $path) === $backupHash,
                \RuntimeException::class, '変更前snapshotの保存を確認できません。');

            $snapshot = $this->rules->rulesetSnapshot();
            $event->fill([
                'name' => NationRaidRules::EVENT_NAME,
                'boss_name' => NationRaidRules::BOSS_NAME,
                'ruleset_version' => NationRaidRules::RULESET_VERSION,
                'ruleset_snapshot' => $snapshot,
                'ruleset_hash' => $newHash,
                'balance_approved_at' => now(),
                'balance_approved_by_user_id' => $admin->id,
                'balance_approval_reference' => $approvalReference,
                'state_version' => (int) $event->state_version + 1,
            ])->save();

            $event->refresh();
            throw_unless(
                array_intersect_key($event->getRawOriginal(), array_flip($preservedKeys)) === $preserved,
                \LogicException::class,
                '開催日時・HP・報酬条件の保持を確認できません。');
            $this->assertAdoptedState($event);

            return [
                'changed' => true,
                'event_id' => $event->id,
                'event_key' => $event->event_key,
                'boss_name' => $event->boss_name,
                'old_ruleset_hash' => $expectedOldRulesetHash,
                'new_ruleset_hash' => $newHash,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'total_target_hp' => $event->total_target_hp,
                'reward_policy_hash' => $event->reward_policy_hash,
                'backup_path' => $path,
                'backup_sha256' => $backupHash,
            ];
        }, 3);
    }

    private function assertScheduledBeforePreparation(NationRaidEvent $event, string $expectedEventKey): void
    {
        $preparationHours = (int) data_get(
            $event->ruleset_snapshot,
            'raid_cycle.logistics_preparation.duration_hours',
            NationRaidRules::LOGISTICS_PREPARATION_HOURS,
        );
        throw_unless(
            $event->event_key === $expectedEventKey
                && $event->status === NationRaidEvent::STATUS_SCHEDULED
                && $event->announced_at !== null
                && $event->activated_at === null
                && $event->preparation_frozen_at === null
                && (int) $event->current_cycle_no === 0
                && now()->lt($event->starts_at->copy()->subHours($preparationHours)),
            \DomainException::class,
            '兵站準備前の開催予約済みイベントだけ変更できます。',
        );
        throw_unless(
            ! $event->cycles()->exists()
                && ! $event->participations()->exists()
                && ! $event->battleResults()->exists()
                && ! $event->nationPreparations()->exists()
                && ! DB::table('nation_raid_daily_usages')->where('event_id', $event->id)->exists()
                && ! DB::table('nation_raid_preparation_members')->where('event_id', $event->id)->exists()
                && ! DB::table('nation_raid_preparation_contributions')->where('event_id', $event->id)->exists()
                && ! DB::table('nation_raid_personal_rewards')->where('event_id', $event->id)->exists()
                && ! DB::table('nation_raid_nation_rewards')->where('event_id', $event->id)->exists(),
            \DomainException::class,
            '参加・出撃・兵站・報酬の記録があるイベントは変更できません。',
        );
    }

    private function assertAdoptedState(NationRaidEvent $event): void
    {
        throw_unless(
            $event->name === NationRaidRules::EVENT_NAME
                && $event->boss_name === NationRaidRules::BOSS_NAME
                && $event->ruleset_version === NationRaidRules::RULESET_VERSION
                && hash_equals((string) $event->ruleset_hash, $this->rules->rulesetHash())
                && hash_equals((string) $event->ruleset_hash, hash('sha256', NationRaidJson::encode(
                    $event->ruleset_snapshot,
                    JSON_UNESCAPED_UNICODE,
                )))
                && data_get($event->ruleset_snapshot, 'fixed.boss_species_key') === NationRaidRules::BOSS_SPECIES_KEY,
            \DomainException::class,
            '適用済みアストラギア情報の保存状態が不整合です。',
        );
    }
}
