<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** 明示承認済みのローカル検証回だけを移行するCLI用。Web/本番では使えない。 */
final readonly class NationRaidLocalEventUpgradeService
{
    public function __construct(
        private CompetitionEventCoordinatorService $coordinator,
        private NationRaidRules $rules,
        private NationRaidEventService $events,
    ) {}

    public function upgrade(int $eventId, string $expectedEventKey): array
    {
        throw_unless(app()->environment('local') && app()->runningInConsole(), \DomainException::class,
            'ローカルCLI専用の移行です。');

        return DB::transaction(function () use ($eventId, $expectedEventKey): array {
            $this->coordinator->lock();
            $event = NationRaidEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
            throw_unless($event->event_key === $expectedEventKey && str_starts_with($expectedEventKey, 'local-')
                && $event->status === NationRaidEvent::STATUS_ACTIVE
                && $event->starts_at->lte(now()) && $event->ends_at->gt(now()),
                \DomainException::class, '対象の開催中ローカル回を確認できません。');
            throw_unless($event->current_cycle_no === 1 && $event->stage_count === 20
                && $event->cycles()->count() === 1 && $event->completed_at === null,
                \DomainException::class, '第1再臨以外の進行移行は別途確認してください。');
            $cycle = $event->cycles()->where('cycle_no', 1)->lockForUpdate()->sole();
            throw_unless($cycle->cycle_kind === NationRaidBossCycle::KIND_MAIN && $cycle->stage_no === 1
                && $cycle->current_hp > 0 && $cycle->current_hp <= $cycle->max_hp,
                \DomainException::class, '現在個体のHPが不正です。');
            throw_unless($this->rules->matchesCombatRulesetHash($event->ruleset_hash)
                && hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode($event->ruleset_snapshot, JSON_UNESCAPED_UNICODE)))
                && $cycle->parameter_snapshot === $this->events->cycleParameterSnapshot(1, $event),
                \DomainException::class, 'HP以外も異なるルールは移行できません。');
            if ($event->ruleset_hash === $this->rules->rulesetHash()) {
                throw_unless($cycle->max_hp === $this->rules->stageMaxHp(1)
                    && $event->cycle_max_hp === $cycle->max_hp && $event->total_target_hp === $this->rules->totalTargetHp(),
                    \DomainException::class, '新形式のHPが不整合です。');
                return ['changed' => false, 'current_hp' => $cycle->current_hp, 'max_hp' => $cycle->max_hp];
            }
            throw_if(NationRaidBattleResult::where('event_id', $event->id)
                ->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED])->exists(),
                \DomainException::class, '未確定出撃が残っているため移行しません。');
            throw_unless($cycle->max_hp === 5_000_000 && $event->cycle_max_hp === 5_000_000
                && $event->total_target_hp === 100_000_000,
                \DomainException::class, '旧500万回以外の移行は別途確認してください。');
            $appliedDamage = $cycle->max_hp - $cycle->current_hp;

            // JSONはWeb公開外に保存。保存・読み戻しが成功するまでDBは変更しない。
            $directory = storage_path('app/private/nation-raid-local-upgrade');
            File::ensureDirectoryExists($directory, 0700);
            $path = $directory.'/event-'.$event->id.'-'.bin2hex(random_bytes(12)).'.json';
            $json = NationRaidJson::encode([
                'reason' => '2026-09-05 human-approved local staged HP upgrade; not a population balance PASS',
                'event' => $event->getRawOriginal(), 'cycle' => $cycle->getRawOriginal(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $json);
            throw_unless(File::put($path, $json, true) === strlen($json) && hash_file('sha256', $path) === $hash,
                \RuntimeException::class, '移行前の保存を確認できません。');

            $event->fill([
                'ruleset_version' => NationRaidRules::RULESET_VERSION,
                'ruleset_snapshot' => $this->rules->rulesetSnapshot(), 'ruleset_hash' => $this->rules->rulesetHash(),
                'cycle_max_hp' => $this->rules->stageMaxHp(1), 'total_target_hp' => $this->rules->totalTargetHp(),
                'state_version' => $event->state_version + 1,
                'balance_approval_reference' => 'Local rehearsal only: 2026-09-05 staged HP approved; unlimited sorties; population balance gate unverified.',
            ])->save();
            $newHp = $this->rules->stageMaxHp(1) - $appliedDamage;
            $cycle->fill([
                'max_hp' => $this->rules->stageMaxHp(1), 'current_hp' => $newHp,
                'current_form' => $this->rules->formForHp($newHp, $this->rules->stageMaxHp(1)),
                'parameter_snapshot' => $this->events->cycleParameterSnapshot(1, $event),
            ])->save();

            return ['changed' => true, 'applied_damage_preserved' => $appliedDamage,
                'current_hp' => $newHp, 'max_hp' => $cycle->max_hp, 'backup_path' => $path, 'backup_sha256' => $hash];
        }, 3);
    }
}
