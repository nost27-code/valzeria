<?php

namespace App\Console\Commands;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class StartInitialNationRaid extends Command
{
    protected $signature = 'nation-raid:start-initial
        {--admin-id= : 承認を記録する管理者ID}
        {--approval-reference= : 初回の数値採用・予告短縮の人間裁定記録}
        {--ruleset-hash= : 検証済みルールのSHA-256}
        {--reward-policy-hash= : 検証済み報酬policyのSHA-256}
        {--confirm-initial-launch : 初回開催を明示実行}';

    protected $description = '承認済みの初回だけを現在時刻から開始する（公開flagは変更しない）';

    public function handle(NationRaidEventService $service, NationRaidRules $rules, NationRaidRewardPolicy $policy): int
    {
        try {
            throw_unless($this->option('confirm-initial-launch'), \DomainException::class, '--confirm-initial-launch が必要です。');
            throw_unless(ctype_digit((string) $this->option('admin-id')), \DomainException::class, '管理者IDが必要です。');
            $admin = User::query()->whereKey((int) $this->option('admin-id'))->where('role', 'admin')->first();
            throw_unless($admin, \DomainException::class, '管理者を確認できません。');
            $reference = trim((string) $this->option('approval-reference'));
            throw_if($reference === '' || mb_strlen($reference) > 255, \DomainException::class, '承認根拠を1〜255文字で指定してください。');
            throw_unless(hash_equals($rules->rulesetHash(), (string) $this->option('ruleset-hash')),
                \DomainException::class, '検証済みルールと一致しません。');
            throw_unless(hash_equals($policy->hash($policy->candidate()), (string) $this->option('reward-policy-hash')),
                \DomainException::class, '検証済み報酬policyと一致しません。');

            $event = DB::transaction(function () use ($service, $rules, $policy, $admin, $reference): NationRaidEvent {
                app(CompetitionEventCoordinatorService::class)->lock();
                $event = NationRaidEvent::query()->where('event_key', 'valgreid-inaugural')->lockForUpdate()->first();
                if ($event) {
                    throw_unless($event->balance_approval_reference === $reference
                        && (int) $event->balance_approved_by_user_id === (int) $admin->id
                        && $event->ruleset_hash === $rules->rulesetHash()
                        && $event->reward_policy_hash === $policy->hash($policy->candidate()),
                        \DomainException::class, '保存済み初回開催の承認・snapshotが一致しません。');
                    throw_unless($event->status === NationRaidEvent::STATUS_ACTIVE,
                        \DomainException::class, '初回開催は既に存在します。保存状態を確認してください。');
                    return $event;
                }
                $event = $service->createDraft('valgreid-inaugural', '国家対抗レイド 黒天竜ヴァルグレイド', now());
                $event = $service->approveBalance($event, $admin, $reference);
                $event = $service->scheduleInitialLaunch($event, $admin, $reference);
                return $service->activate($event);
            }, 3);

            $this->line(NationRaidJson::encode($event->only([
                'id', 'event_key', 'status', 'starts_at', 'ends_at', 'announced_at', 'activated_at',
                'ruleset_hash', 'reward_policy_hash', 'balance_approval_reference',
            ])));
            return self::SUCCESS;
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
