<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Nation\Raid\NationRaidAstragiaAdoptionService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AdoptAstragiaForNextNationRaid extends Command
{
    protected $signature = 'nation-raid:adopt-astragia
        {--event-id= : 対象イベントID}
        {--event-key= : 対象イベントkey}
        {--admin-id= : 採用裁定を記録する管理者ID}
        {--approval-reference= : ボス変更の人間裁定記録}
        {--expected-old-ruleset-hash= : 変更前rulesetのSHA-256}
        {--new-ruleset-hash= : 配備候補rulesetのSHA-256}
        {--confirm-astragia-adoption : 開戦前の次回ボス変更を明示実行}';

    protected $description = '兵站準備前の次回国家対抗レイドをアストラギアへ変更する';

    public function handle(
        NationRaidAstragiaAdoptionService $service,
        NationRaidRules $rules,
    ): int {
        try {
            throw_unless($this->option('confirm-astragia-adoption'), \DomainException::class,
                '--confirm-astragia-adoption が必要です。');
            throw_unless(ctype_digit((string) $this->option('event-id'))
                && ctype_digit((string) $this->option('admin-id')),
                \DomainException::class, 'イベントIDと管理者IDが必要です。');
            throw_unless(hash_equals($rules->rulesetHash(), (string) $this->option('new-ruleset-hash')),
                \DomainException::class, '配備候補ruleset hashが一致しません。');
            $admin = User::query()->whereKey((int) $this->option('admin-id'))->where('role', 'admin')->first();
            throw_unless($admin, \DomainException::class, '管理者を確認できません。');

            $result = $service->adopt(
                eventId: (int) $this->option('event-id'),
                expectedEventKey: trim((string) $this->option('event-key')),
                expectedOldRulesetHash: trim((string) $this->option('expected-old-ruleset-hash')),
                admin: $admin,
                approvalReference: trim((string) $this->option('approval-reference')),
            );
            Log::notice('Nation raid Astragia adoption applied', $result);
            $this->line(NationRaidJson::encode($result));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
