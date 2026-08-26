<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationGoal;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationResourceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NationGoalService
{
    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationLevelBenefitSettingsService $settings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(NationMembership $actor, array $attributes): NationGoal
    {
        $this->settings->assertEnabled();
        $normalized = $this->normalize($attributes);

        return DB::transaction(function () use ($actor, $normalized): NationGoal {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = $this->lockedActor($actor, $nation);
            $this->roles->authorize($lockedActor, 'manage_nation_goals');
            $this->expireDueGoals($nation);
            $slotLimit = $this->levels->benefitsForLevel(
                $this->levels->levelFor((int) $nation->development_exp),
            )['goal_slots'];
            throw_if(
                NationGoal::where('nation_id', $nation->id)->where('status', NationGoal::STATUS_ACTIVE)->count() >= $slotLimit,
                \DomainException::class,
                "現在の国家Lvでは共同目標を同時に{$slotLimit}件まで設定できます。",
            );

            if ($normalized['metric_type'] === 'material_quantity') {
                throw_unless(
                    NationMaterialConversionRate::where('material_id', $normalized['material_id'])
                        ->where('is_active', true)
                        ->sharedLock()
                        ->exists(),
                    \DomainException::class,
                    '募集対象として利用できない素材です。',
                );
            }

            $goal = NationGoal::create([
                'nation_id' => $nation->id,
                'created_by_character_id' => $lockedActor->character_id,
                ...$normalized,
                'starts_at' => now(),
                'status' => NationGoal::STATUS_ACTIVE,
            ]);
            $this->activityLogs->record($nation, 'nation_goal_created', $lockedActor->character, null, [
                'goal_id' => $goal->id,
                'title' => $goal->title,
            ]);

            return $goal->load(['material', 'creator']);
        }, 3);
    }

    public function completeManual(NationMembership $actor, NationGoal $goal): NationGoal
    {
        $this->settings->assertEnabled();

        return $this->close($actor, $goal, NationGoal::STATUS_COMPLETED);
    }

    public function cancel(NationMembership $actor, NationGoal $goal): NationGoal
    {
        $this->settings->assertEnabled();

        return $this->close($actor, $goal, NationGoal::STATUS_CANCELED);
    }

    /** @return Collection<int, array{goal:NationGoal,current:?int,target:?int,progress_bps:?int}> */
    public function activeWithProgress(Nation $nation): Collection
    {
        $this->sync($nation);

        return NationGoal::query()
            ->with(['material', 'creator'])
            ->where('nation_id', $nation->id)
            ->where('status', NationGoal::STATUS_ACTIVE)
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->get()
            ->map(function (NationGoal $goal): array {
                $current = $this->currentValue($goal);
                $target = $goal->target_value === null ? null : (int) $goal->target_value;

                return [
                    'goal' => $goal,
                    'current' => $current,
                    'target' => $target,
                    'progress_bps' => $current === null || $target === null
                        ? null
                        : min(10000, intdiv($current * 10000, max(1, $target))),
                ];
            });
    }

    public function historyQuery(Nation $nation)
    {
        return NationGoal::query()
            ->with(['material', 'creator'])
            ->where('nation_id', $nation->id)
            ->where('status', '<>', NationGoal::STATUS_ACTIVE)
            ->latest('id');
    }

    public function sync(Nation $nation): void
    {
        DB::transaction(function () use ($nation): void {
            $lockedNation = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $this->expireDueGoals($lockedNation);
            $goals = NationGoal::where('nation_id', $lockedNation->id)
                ->where('status', NationGoal::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($goals as $goal) {
                $current = $this->currentValue($goal);
                if ($current === null || $goal->target_value === null || $current < (int) $goal->target_value) {
                    continue;
                }

                $goal->update([
                    'status' => NationGoal::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'closed_at' => now(),
                ]);
                $this->activityLogs->record($lockedNation, 'nation_goal_completed', null, null, [
                    'goal_id' => $goal->id,
                    'title' => $goal->title,
                ]);
            }
        }, 3);
    }

    public function currentValue(NationGoal $goal): ?int
    {
        $end = $goal->deadline_at?->isPast() ? $goal->deadline_at : now();
        $ledger = NationResourceTransaction::query()
            ->where('nation_id', $goal->nation_id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', $goal->starts_at)
            ->where('created_at', '<=', $end);

        return match ($goal->metric_type) {
            'material_quantity' => (int) $ledger->where('material_id', $goal->material_id)->sum('quantity'),
            'development_exp' => (int) $ledger->sum('development_exp_delta'),
            'donation_points' => (int) $ledger->sum('points_delta'),
            'member_count' => NationMembership::where('nation_id', $goal->nation_id)->count(),
            'facility_level' => $this->facilityLevelValue($goal),
            'manual' => null,
            default => null,
        };
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalize(array $attributes): array
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        $description = trim((string) ($attributes['description'] ?? ''));
        $metric = (string) ($attributes['metric_type'] ?? 'manual');
        throw_if($title === '' || mb_strlen($title) > 60, \DomainException::class, '共同目標の名称は1〜60文字で入力してください。');
        throw_if(mb_strlen($description) > 200, \DomainException::class, '共同目標の説明は200文字以内で入力してください。');
        throw_unless(in_array($metric, NationGoal::METRICS, true), \DomainException::class, '共同目標の集計方法が不正です。');

        $materialId = $metric === 'material_quantity' ? (int) ($attributes['material_id'] ?? 0) : null;
        throw_if($metric === 'material_quantity' && $materialId < 1, \DomainException::class, '目標とする素材を選択してください。');
        $facilityType = $metric === 'facility_level' ? trim((string) ($attributes['facility_type'] ?? '')) : null;
        $facilityType = $facilityType === '' ? null : $facilityType;
        throw_if($facilityType !== null && ! in_array($facilityType, NationFacility::TYPES, true), \DomainException::class, '対象施設が不正です。');

        $target = $metric === 'manual' ? null : (int) ($attributes['target_value'] ?? 0);
        throw_if($metric !== 'manual' && $target < 1, \DomainException::class, '目標値は1以上で入力してください。');
        $deadline = trim((string) ($attributes['deadline_at'] ?? ''));
        try {
            $deadlineAt = $deadline === '' ? null : CarbonImmutable::parse($deadline);
        } catch (\Throwable) {
            throw new \DomainException('期限の日時が不正です。');
        }
        throw_if($deadlineAt?->isPast(), \DomainException::class, '期限は現在より後を指定してください。');

        return [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'metric_type' => $metric,
            'material_id' => $materialId,
            'facility_type' => $facilityType,
            'target_value' => $target,
            'deadline_at' => $deadlineAt,
        ];
    }

    private function lockedActor(NationMembership $actor, Nation $nation): NationMembership
    {
        $locked = NationMembership::whereKey($actor->id)
            ->where('nation_id', $nation->id)
            ->lockForUpdate()
            ->first();
        throw_unless($locked, \DomainException::class, '国家の所属情報が変更されました。');

        return $locked;
    }

    private function expireDueGoals(Nation $nation): void
    {
        NationGoal::where('nation_id', $nation->id)
            ->where('status', NationGoal::STATUS_ACTIVE)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->update([
                'status' => NationGoal::STATUS_EXPIRED,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function facilityLevelValue(NationGoal $goal): int
    {
        $query = NationFacility::where('nation_id', $goal->nation_id);
        if ($goal->facility_type !== null) {
            return (int) $query->where('facility_type', $goal->facility_type)->value('level');
        }

        return (int) $query->min('level');
    }

    private function close(NationMembership $actor, NationGoal $goal, string $status): NationGoal
    {
        return DB::transaction(function () use ($actor, $goal, $status): NationGoal {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = $this->lockedActor($actor, $nation);
            $this->roles->authorize($lockedActor, 'manage_nation_goals');
            $lockedGoal = NationGoal::whereKey($goal->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->firstOrFail();
            throw_unless($lockedGoal->status === NationGoal::STATUS_ACTIVE, \DomainException::class, 'この共同目標はすでに終了しています。');
            throw_if($status === NationGoal::STATUS_COMPLETED && $lockedGoal->metric_type !== 'manual', \DomainException::class, '自動集計の共同目標は手動で達成にできません。');
            $lockedGoal->update([
                'status' => $status,
                'completed_at' => $status === NationGoal::STATUS_COMPLETED ? now() : null,
                'closed_at' => now(),
            ]);
            $this->activityLogs->record(
                $nation,
                $status === NationGoal::STATUS_COMPLETED ? 'nation_goal_completed' : 'nation_goal_canceled',
                $lockedActor->character,
                null,
                ['goal_id' => $lockedGoal->id, 'title' => $lockedGoal->title],
            );

            return $lockedGoal->fresh(['material', 'creator']);
        }, 3);
    }
}
