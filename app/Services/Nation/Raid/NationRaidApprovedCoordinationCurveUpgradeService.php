<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** 2026-09-06に人間承認された初回開催中の共闘ボーナス段階だけを適用する。 */
final readonly class NationRaidApprovedCoordinationCurveUpgradeService
{
    private const APPROVED_EVENT_KEY = 'valgreid-inaugural';

    private const APPROVED_TOTAL_TARGET_HP = 6_920_000_000;

    public function __construct(
        private CompetitionEventCoordinatorService $coordinator,
        private NationRaidRules $rules,
        private NationRaidEventService $events,
    ) {}

    public function upgrade(
        int $eventId,
        string $expectedEventKey,
        string $expectedOldRulesetHash,
        User $admin,
        string $approvalReference,
    ): array {
        throw_unless(app()->runningInConsole(), \DomainException::class, 'CLI以外から共闘ボーナスを変更できません。');
        throw_unless($expectedEventKey === self::APPROVED_EVENT_KEY, \DomainException::class, '承認済み初回イベントではありません。');
        throw_unless($admin->role === 'admin', \DomainException::class, '管理者を確認できません。');
        throw_if($approvalReference === '' || mb_strlen($approvalReference) > 255, \DomainException::class,
            '承認根拠を1〜255文字で指定してください。');
        throw_unless(hash_equals($this->rules->previousLiveHpRulesetHash(), $expectedOldRulesetHash),
            \DomainException::class, '変更前ruleset hashが承認済みの共闘調整前HP曲線と一致しません。');
        $this->assertApprovedCurve();

        return DB::transaction(function () use (
            $eventId,
            $expectedEventKey,
            $expectedOldRulesetHash,
            $admin,
            $approvalReference,
        ): array {
            $this->coordinator->lock();
            $event = NationRaidEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            throw_unless($event->event_key === $expectedEventKey
                && $event->status === NationRaidEvent::STATUS_ACTIVE
                && $event->starts_at->lte(now()) && $event->ends_at->gt(now())
                && $event->stage_count === NationRaidRules::MAX_STAGES
                && $event->completed_at === null,
                \DomainException::class, '対象の開催中初回イベントを確認できません。');

            $cycles = $event->cycles()->orderBy('cycle_no')->lockForUpdate()->get();
            $current = $cycles->firstWhere('cycle_no', $event->current_cycle_no);
            throw_unless($current instanceof NationRaidBossCycle
                && $current->cycle_kind === NationRaidBossCycle::KIND_MAIN
                && $current->stage_no !== null
                && $current->current_hp > 0 && $current->current_hp <= $current->max_hp,
                \DomainException::class, '現在の再臨個体を確認できません。');

            $newHash = $this->rules->rulesetHash();
            if (hash_equals($event->ruleset_hash, $newHash)) {
                $this->assertStoredState($event, $current);

                return [
                    'changed' => false,
                    'event_id' => $event->id,
                    'current_stage' => $current->stage_no,
                    'current_hp' => $current->current_hp,
                    'max_hp' => $current->max_hp,
                    'ruleset_hash' => $newHash,
                ];
            }

            throw_unless(hash_equals($event->ruleset_hash, $expectedOldRulesetHash)
                && hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode($event->ruleset_snapshot, JSON_UNESCAPED_UNICODE)))
                && $event->total_target_hp === self::APPROVED_TOTAL_TARGET_HP
                && $this->currentCycleMatchesEvent($event, $current),
                \DomainException::class, '開催中イベントの共闘調整前snapshotが一致しません。');

            $directory = storage_path('app/private/nation-raid-live-coordination-upgrade');
            File::ensureDirectoryExists($directory, 0700);
            $path = $directory.'/event-'.$event->id.'-'.substr($expectedOldRulesetHash, 0, 12).'-to-'.substr($newHash, 0, 12)
                .'-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(8)).'.json';
            $json = NationRaidJson::encode([
                'reason' => $approvalReference,
                'event' => $event->getRawOriginal(),
                'cycles' => $cycles->map->getRawOriginal()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $backupHash = hash('sha256', $json);
            throw_unless(File::put($path, $json, true) === strlen($json) && hash_file('sha256', $path) === $backupHash,
                \RuntimeException::class, '変更前snapshotの保存を確認できません。');

            $hpBefore = ['max_hp' => $current->max_hp, 'current_hp' => $current->current_hp];
            $event->fill([
                'ruleset_version' => NationRaidRules::RULESET_VERSION,
                'ruleset_snapshot' => $this->rules->rulesetSnapshot(),
                'ruleset_hash' => $newHash,
                'balance_approved_at' => now(),
                'balance_approved_by_user_id' => $admin->id,
                'balance_approval_reference' => $approvalReference,
                'state_version' => (int) $event->state_version + 1,
            ])->save();
            $current->update([
                'parameter_snapshot' => $this->events->cycleParameterSnapshot((int) $current->stage_no, $event),
            ]);

            return [
                'changed' => true,
                'event_id' => $event->id,
                'current_stage' => $current->stage_no,
                'old_ruleset_hash' => $expectedOldRulesetHash,
                'new_ruleset_hash' => $newHash,
                'total_target_hp' => $event->total_target_hp,
                'max_hp_before' => $hpBefore['max_hp'],
                'max_hp_after' => $current->fresh()->max_hp,
                'current_hp_before' => $hpBefore['current_hp'],
                'current_hp_after' => $current->fresh()->current_hp,
                'backup_path' => $path,
                'backup_sha256' => $backupHash,
            ];
        }, 3);
    }

    private function assertApprovedCurve(): void
    {
        $expected = [1 => 0.0, 2 => 0.03, 3 => 0.06, 4 => 0.09, 5 => 0.12, 7 => 0.12,
            8 => 0.15, 11 => 0.15, 12 => 0.17, 15 => 0.17, 16 => 0.19, 18 => 0.19,
            19 => 0.21, 21 => 0.21, 22 => 0.22, 25 => 0.22, 26 => 0.22];
        foreach ($expected as $participants => $rate) {
            throw_unless(NationRaidRules::coordinationDamageRate($participants) === $rate,
                \DomainException::class, 'コード上の共闘ボーナス段階が承認値と一致しません。');
        }
        throw_unless($this->rules->totalTargetHp() === self::APPROVED_TOTAL_TARGET_HP,
            \DomainException::class, 'コード上の総HPが承認済み値と一致しません。');
    }

    private function assertStoredState(NationRaidEvent $event, NationRaidBossCycle $current): void
    {
        throw_unless(hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode($event->ruleset_snapshot, JSON_UNESCAPED_UNICODE)))
            && $event->total_target_hp === self::APPROVED_TOTAL_TARGET_HP
            && $this->currentCycleMatchesEvent($event, $current),
            \DomainException::class, '適用済み共闘ボーナスの保存状態が不整合です。');
    }

    private function currentCycleMatchesEvent(NationRaidEvent $event, NationRaidBossCycle $current): bool
    {
        $expected = $this->events->cycleParameterSnapshot((int) $current->stage_no, $event);

        return $current->max_hp === (int) ($current->parameter_snapshot['boss']['max_hp'] ?? 0)
            && $current->current_form === $this->rules->formForHp($current->current_hp, $current->max_hp)
            && hash_equals(
                hash('sha256', NationRaidJson::encode($current->parameter_snapshot, JSON_UNESCAPED_UNICODE)),
                hash('sha256', NationRaidJson::encode($expected, JSON_UNESCAPED_UNICODE)),
            );
    }
}
