<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** 2026-09-06に人間承認された初回開催中の後半HP増加だけを適用する。 */
final readonly class NationRaidApprovedHpCurveUpgradeService
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
        throw_unless(app()->runningInConsole(), \DomainException::class, 'CLI以外から開催中HPを変更できません。');
        throw_unless($expectedEventKey === self::APPROVED_EVENT_KEY, \DomainException::class, '承認済み初回イベントではありません。');
        throw_unless($admin->role === 'admin', \DomainException::class, '管理者を確認できません。');
        throw_if($approvalReference === '' || mb_strlen($approvalReference) > 255, \DomainException::class,
            '承認根拠を1〜255文字で指定してください。');
        throw_unless(hash_equals($this->rules->previousStagedHpRulesetHash(), $expectedOldRulesetHash),
            \DomainException::class, '開始時ruleset hashが承認済みの旧HP曲線と一致しません。');
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
            throw_if($cycles->contains(fn (NationRaidBossCycle $cycle): bool => $cycle->stage_no !== null
                && $cycle->stage_no >= 9 && $cycle->defeated_at !== null),
                \DomainException::class, '第9再臨以降が討伐済みのため、この承認曲線はそのまま適用できません。');

            $newHash = $this->rules->rulesetHash();
            if (hash_equals($event->ruleset_hash, $newHash)) {
                throw_unless(hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode($event->ruleset_snapshot, JSON_UNESCAPED_UNICODE)))
                    && $event->total_target_hp === self::APPROVED_TOTAL_TARGET_HP
                    && $current->max_hp === $this->rules->stageMaxHp((int) $current->stage_no)
                    && $this->snapshotsMatch($current->parameter_snapshot,
                        $this->events->cycleParameterSnapshot((int) $current->stage_no, $event)),
                    \DomainException::class, '適用済みHP曲線の保存状態が不整合です。');

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
                && $event->total_target_hp === 600_000_000
                && $event->cycle_max_hp === 10_000_000
                && $this->snapshotsMatch($current->parameter_snapshot,
                    $this->events->cycleParameterSnapshot((int) $current->stage_no, $event)),
                \DomainException::class, '開催中イベントの旧HP snapshotが一致しません。');

            $oldMaxHp = (int) $current->max_hp;
            $appliedDamage = $oldMaxHp - (int) $current->current_hp;
            $newMaxHp = $this->rules->stageMaxHp((int) $current->stage_no);
            throw_unless($newMaxHp >= $oldMaxHp && $newMaxHp > $appliedDamage,
                \DomainException::class, '現在個体のHPを安全に拡張できません。');

            $directory = storage_path('app/private/nation-raid-live-hp-upgrade');
            File::ensureDirectoryExists($directory, 0700);
            $path = $directory.'/event-'.$event->id.'-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(8)).'.json';
            $json = NationRaidJson::encode([
                'reason' => $approvalReference,
                'event' => $event->getRawOriginal(),
                'cycles' => $cycles->map->getRawOriginal()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $backupHash = hash('sha256', $json);
            throw_unless(File::put($path, $json, true) === strlen($json) && hash_file('sha256', $path) === $backupHash,
                \RuntimeException::class, '変更前snapshotの保存を確認できません。');

            $event->fill([
                'ruleset_version' => NationRaidRules::RULESET_VERSION,
                'ruleset_snapshot' => $this->rules->rulesetSnapshot(),
                'ruleset_hash' => $newHash,
                'cycle_max_hp' => $this->rules->stageMaxHp(1),
                'total_target_hp' => $this->rules->totalTargetHp(),
                'balance_approved_at' => now(),
                'balance_approved_by_user_id' => $admin->id,
                'balance_approval_reference' => $approvalReference,
                'state_version' => (int) $event->state_version + 1,
            ])->save();

            $newCurrentHp = $newMaxHp - $appliedDamage;
            $current->fill([
                'max_hp' => $newMaxHp,
                'current_hp' => $newCurrentHp,
                'current_form' => $this->rules->formForHp($newCurrentHp, $newMaxHp),
                'parameter_snapshot' => $this->events->cycleParameterSnapshot((int) $current->stage_no, $event),
            ])->save();

            return [
                'changed' => true,
                'event_id' => $event->id,
                'current_stage' => $current->stage_no,
                'old_ruleset_hash' => $expectedOldRulesetHash,
                'new_ruleset_hash' => $newHash,
                'old_total_target_hp' => 600_000_000,
                'new_total_target_hp' => $event->total_target_hp,
                'old_max_hp' => $oldMaxHp,
                'new_max_hp' => $newMaxHp,
                'applied_damage_preserved' => $appliedDamage,
                'current_hp' => $newCurrentHp,
                'backup_path' => $path,
                'backup_sha256' => $backupHash,
            ];
        }, 3);
    }

    private function assertApprovedCurve(): void
    {
        $expected = [
            1 => 10_000_000,
            5 => 20_000_000,
            9 => 200_000_000,
            13 => 500_000_000,
            17 => 1_000_000_000,
        ];
        foreach ($expected as $stage => $hp) {
            throw_unless($this->rules->stageMaxHp($stage) === $hp, \DomainException::class,
                'コード上のHP曲線が承認値と一致しません。');
        }
        throw_unless($this->rules->totalTargetHp() === self::APPROVED_TOTAL_TARGET_HP,
            \DomainException::class, 'コード上の総HPが承認値と一致しません。');
    }

    private function snapshotsMatch(array $actual, array $expected): bool
    {
        return hash_equals(
            hash('sha256', NationRaidJson::encode($actual, JSON_UNESCAPED_UNICODE)),
            hash('sha256', NationRaidJson::encode($expected, JSON_UNESCAPED_UNICODE)),
        );
    }
}
