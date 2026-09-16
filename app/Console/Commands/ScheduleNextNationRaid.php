<?php

namespace App\Console\Commands;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ScheduleNextNationRaid extends Command
{
    private const EVENT_NAME = '国家対抗レイド 黒天竜ヴァルグレイド';

    private const BOSS_NAME = '十系喰らいの黒天竜 ヴァルグレイド';

    protected $signature = 'nation-raid:schedule-next
        {--event-key= : 次回開催を識別するevent key}
        {--starts-at= : 開始日時（Y-m-d H:i、app timezone）}
        {--admin-id= : バランス承認を記録する管理者ID}
        {--approval-reference= : 開催日時・HPの人間裁定記録}
        {--ruleset-hash= : 検証済みルールのSHA-256}
        {--reward-policy-hash= : 検証済み報酬policyのSHA-256}
        {--confirm-next-cycle : 次回開催の作成・承認・予約を明示実行}';

    protected $description = '検証済みの次回国家対抗レイドを作成・承認・予約する（公開flagは変更しない）';

    public function handle(
        NationRaidEventService $service,
        NationRaidRules $rules,
        NationRaidRewardPolicy $policy,
    ): int {
        try {
            throw_unless($this->option('confirm-next-cycle'), \DomainException::class, '--confirm-next-cycle が必要です。');
            $eventKey = trim((string) $this->option('event-key'));
            throw_if($eventKey === 'valgreid-inaugural', \DomainException::class, '初回開催の識別子は再利用できません。');
            throw_unless(ctype_digit((string) $this->option('admin-id')), \DomainException::class, '管理者IDが必要です。');
            $admin = User::query()->whereKey((int) $this->option('admin-id'))->where('role', 'admin')->first();
            throw_unless($admin, \DomainException::class, '管理者を確認できません。');
            $reference = trim((string) $this->option('approval-reference'));
            throw_if($reference === '' || mb_strlen($reference) > 255, \DomainException::class, '承認根拠を1〜255文字で指定してください。');
            $startsAt = $this->parseStartsAt((string) $this->option('starts-at'));
            $snapshot = $rules->rulesetSnapshot();
            throw_unless($rules->supportsNextCycleSystems($snapshot), \DomainException::class, '次回開催用rulesetではありません。');
            throw_unless(hash_equals($rules->rulesetHash(), (string) $this->option('ruleset-hash')),
                \DomainException::class, '検証済みルールと一致しません。');
            $rewardPolicyHash = $policy->hash($policy->candidate());
            throw_unless(hash_equals($rewardPolicyHash, (string) $this->option('reward-policy-hash')),
                \DomainException::class, '検証済み報酬policyと一致しません。');

            $event = DB::transaction(function () use (
                $service, $rules, $policy, $admin, $reference, $eventKey, $startsAt, $rewardPolicyHash,
            ): NationRaidEvent {
                app(CompetitionEventCoordinatorService::class)->lock();
                $event = NationRaidEvent::query()->where('event_key', $eventKey)->lockForUpdate()->first();
                if ($event) {
                    $this->assertMatchingScheduledEvent(
                        $event, $rules, $policy, $admin, $reference, $startsAt, $rewardPolicyHash,
                    );

                    return $event;
                }

                $event = $service->createDraft($eventKey, self::EVENT_NAME, $startsAt, self::BOSS_NAME);
                $event = $service->approveBalance($event, $admin, $reference);

                return $service->schedule($event, now());
            }, 3);

            Log::notice('Nation raid next cycle scheduled', [
                'event_id' => $event->id,
                'event_key' => $event->event_key,
                'admin_user_id' => $admin->id,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'ruleset_hash' => $event->ruleset_hash,
                'reward_policy_hash' => $event->reward_policy_hash,
            ]);
            $this->line(NationRaidJson::encode([
                ...$event->only([
                    'id', 'event_key', 'status', 'starts_at', 'ends_at', 'announced_at',
                    'cycle_max_hp', 'total_target_hp', 'ruleset_hash', 'reward_policy_hash',
                ]),
                'preparation_starts_at' => $event->starts_at->copy()
                    ->subHours(NationRaidRules::LOGISTICS_PREPARATION_HOURS),
            ]));

            return self::SUCCESS;
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parseStartsAt(string $value): CarbonImmutable
    {
        $value = trim($value);
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, (string) config('app.timezone'));
        throw_unless($startsAt !== false && $startsAt->format('Y-m-d H:i') === $value,
            \InvalidArgumentException::class, '開始日時はY-m-d H:i形式で指定してください。');

        return $startsAt;
    }

    private function assertMatchingScheduledEvent(
        NationRaidEvent $event,
        NationRaidRules $rules,
        NationRaidRewardPolicy $policy,
        User $admin,
        string $reference,
        CarbonImmutable $startsAt,
        string $rewardPolicyHash,
    ): void {
        $durationHours = (int) config('nation_raid.event.duration_hours', 168);
        throw_unless(
            $event->status === NationRaidEvent::STATUS_SCHEDULED
                && $event->announced_at !== null
                && $event->balance_approved_at !== null
                && $event->name === self::EVENT_NAME
                && $event->boss_name === self::BOSS_NAME
                && $event->starts_at->eq($startsAt)
                && $event->ends_at->eq($startsAt->addHours($durationHours))
                && (int) $event->balance_approved_by_user_id === (int) $admin->id
                && $event->balance_approval_reference === $reference
                && $event->ruleset_hash === $rules->rulesetHash()
                && hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode(
                    $event->ruleset_snapshot, JSON_UNESCAPED_UNICODE,
                )))
                && $event->reward_policy_hash === $rewardPolicyHash
                && hash_equals($event->reward_policy_hash, $policy->hash($event->reward_policy_snapshot))
                && (int) $event->cycle_max_hp === $rules->stageMaxHp(1)
                && (int) $event->total_target_hp === $rules->totalTargetHp(),
            \DomainException::class,
            '保存済みの次回開催と承認・期間・snapshotが一致しません。',
        );
    }
}
