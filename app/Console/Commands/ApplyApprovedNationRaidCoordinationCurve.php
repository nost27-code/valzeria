<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Nation\Raid\NationRaidApprovedCoordinationCurveUpgradeService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Console\Command;

final class ApplyApprovedNationRaidCoordinationCurve extends Command
{
    protected $signature = 'nation-raid:apply-approved-coordination-curve
        {--event-id= : 対象イベントID}
        {--event-key= : 対象イベントkey}
        {--admin-id= : バランス裁定を記録する管理者ID}
        {--approval-reference= : 開催中共闘ボーナス変更の人間裁定記録}
        {--expected-old-ruleset-hash= : 変更前rulesetのSHA-256}
        {--new-ruleset-hash= : 配備候補rulesetのSHA-256}
        {--confirm-live-coordination-upgrade : 開催中の共闘bonus変更を明示実行}';

    protected $description = '承認済み初回レイドの共闘ボーナス段階を開催中に更新する';

    public function handle(
        NationRaidApprovedCoordinationCurveUpgradeService $service,
        NationRaidRules $rules,
    ): int {
        try {
            throw_unless($this->option('confirm-live-coordination-upgrade'), \DomainException::class,
                '--confirm-live-coordination-upgrade が必要です。');
            throw_unless(ctype_digit((string) $this->option('event-id'))
                && ctype_digit((string) $this->option('admin-id')),
                \DomainException::class, 'イベントIDと管理者IDが必要です。');
            throw_unless(hash_equals($rules->rulesetHash(), (string) $this->option('new-ruleset-hash')),
                \DomainException::class, '配備候補ruleset hashが一致しません。');
            $admin = User::query()->whereKey((int) $this->option('admin-id'))->where('role', 'admin')->first();
            throw_unless($admin, \DomainException::class, '管理者を確認できません。');

            $result = $service->upgrade(
                eventId: (int) $this->option('event-id'),
                expectedEventKey: trim((string) $this->option('event-key')),
                expectedOldRulesetHash: trim((string) $this->option('expected-old-ruleset-hash')),
                admin: $admin,
                approvalReference: trim((string) $this->option('approval-reference')),
            );
            $this->line(NationRaidJson::encode($result));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
