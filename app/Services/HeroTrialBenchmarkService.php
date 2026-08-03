<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Services\Battle\BattleResult;
use App\Support\JobRankCatalog;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HeroTrialBenchmarkService
{
    public function __construct(
        private readonly CharacterStatusService $statusService,
        private readonly BattleService $battleService,
    ) {}

    /**
     * @return Collection<int, JobClass>
     */
    public function masteredCrownJobs(Character $character): Collection
    {
        $jobIds = $character->jobHistories()
            ->where('is_mastered', true)
            ->pluck('job_class_id');

        if ($jobIds->isEmpty()) {
            return collect();
        }

        return JobClass::query()
            ->whereIn('id', $jobIds)
            ->where('rank', JobRankCatalog::CROWN)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 現在のLv・基礎能力・装備を維持し、現在職だけを仮想的に差し替えた最終能力を返す。
     * 実際の転職で発生するLv1化・基礎能力圧縮は適用しない。
     *
     * @return array<string, mixed>
     */
    public function previewFinalStats(Character $character, ?int $virtualJobId = null): array
    {
        $virtualCharacter = clone $character;

        if ($virtualJobId !== null) {
            $job = $this->masteredCrownJob($character, $virtualJobId);
            $virtualCharacter->current_job_id = $job->id;
            $virtualCharacter->unsetRelation('jobClass');
            $virtualCharacter->unsetRelation('currentJob');
        }

        CharacterStatusService::clearRequestCache((int) $character->id);

        try {
            return $this->statusService->getFinalStats($virtualCharacter);
        } finally {
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    public function simulate(
        Character $character,
        Enemy $enemy,
        ?int $virtualJobId,
        bool $startWithFullHp,
    ): BattleResult {
        DB::beginTransaction();

        try {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($virtualJobId !== null) {
                $job = $this->masteredCrownJob($lockedCharacter, $virtualJobId);
                $lockedCharacter->current_job_id = $job->id;
                $lockedCharacter->save();
            }

            CharacterStatusService::clearRequestCache((int) $lockedCharacter->id);

            if ($startWithFullHp) {
                $stats = $this->statusService->getFinalStats($lockedCharacter);
                $lockedCharacter->current_hp = (int) ($stats['max_hp'] ?? $lockedCharacter->current_hp);
                $lockedCharacter->current_mp = (int) ($stats['max_mp'] ?? $lockedCharacter->current_mp ?? 0);
                $lockedCharacter->save();
            }

            CharacterStatusService::clearRequestCache((int) $lockedCharacter->id);

            return $this->battleService->executeBattle($lockedCharacter, $enemy);
        } finally {
            DB::rollBack();
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    private function masteredCrownJob(Character $character, int $jobId): JobClass
    {
        $job = JobClass::query()
            ->whereKey($jobId)
            ->where('rank', JobRankCatalog::CROWN)
            ->first();

        $isMastered = $job && $character->jobHistories()
            ->where('job_class_id', $job->id)
            ->where('is_mastered', true)
            ->exists();

        if (! $job || ! $isMastered) {
            throw new DomainException('仮想職業には、この冒険者がマスター済みの冠位職だけを指定できます。');
        }

        return $job;
    }
}
