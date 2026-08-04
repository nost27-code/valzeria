<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\JobClass;
use App\Services\Battle\BattleResult;
use DomainException;
use Illuminate\Support\Facades\DB;

class HeroTrialService
{
    public function __construct(
        private readonly HeroTrialProfileService $profileService,
        private readonly BattleService $battleService,
        private readonly CharacterStatusService $statusService,
    ) {}

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive('hero_trials');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function trial(string $trialKey): ?array
    {
        $trial = config("hero_trials.released_trials.{$trialKey}");

        return is_array($trial) ? $trial : null;
    }

    public function hasClearedForJob(Character $character, JobClass $job): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $trial = collect(config('hero_trials.released_trials', []))
            ->first(fn ($candidate): bool => is_array($candidate)
                && (string) ($candidate['hero_job_key'] ?? '') === (string) $job->key);

        if (! is_array($trial)) {
            return false;
        }

        return $this->hasClearedArea($character, (int) ($trial['area_id'] ?? 0));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function facilitiesFor(Character $character, ?int $cityId): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $facilities = [];
        foreach ((array) config('hero_trials.released_trials', []) as $trialKey => $trial) {
            if (! is_array($trial) || (int) ($trial['city_id'] ?? 0) !== (int) $cityId) {
                continue;
            }

            if (! $this->appearanceRequirementsMet($character, $trial)) {
                continue;
            }

            $this->ensureProgress($character, $trial);
            $facilities[] = $this->buildFacility($character, (string) $trialKey, $trial);
        }

        return $facilities;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function facilityFor(Character $character, ?int $cityId): ?array
    {
        return $this->facilitiesFor($character, $cityId)[0] ?? null;
    }

    /**
     * @return array{
     *     trial_key:string,
     *     trial:array<string, mixed>,
     *     passed:bool,
     *     newly_unlocked:bool,
     *     phase_results:list<array{phase:array<string, mixed>, result:BattleResult, display_logs:list<string>}>
     * }
     */
    public function challenge(Character $character, string $trialKey): array
    {
        if (! $this->isEnabled()) {
            throw new DomainException('英雄試練は現在公開されていません。');
        }

        $trial = $this->trial($trialKey);
        if (! $trial) {
            throw new DomainException('指定された英雄試練は存在しません。');
        }

        $profile = $this->profileService->profile((string) ($trial['profile_key'] ?? ''));
        $enemies = $this->profileService->enemies((string) ($trial['profile_key'] ?? ''));

        try {
            return DB::transaction(function () use ($character, $trialKey, $trial, $profile, $enemies): array {
                $lockedCharacter = Character::query()
                    ->whereKey($character->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertCanChallenge($lockedCharacter, $trial);

                $progress = CharacterAreaProgress::query()
                    ->firstOrCreate(
                        [
                            'character_id' => $lockedCharacter->id,
                            'area_id' => (int) $trial['area_id'],
                        ],
                        [
                            'is_unlocked' => true,
                            'unlocked_at' => now(),
                            'discovery_state' => 'discovered',
                            'discovered_at' => now(),
                        ]
                    );
                $progress = CharacterAreaProgress::query()
                    ->whereKey($progress->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((bool) $progress->boss_defeated) {
                    throw new DomainException('この英雄試練はすでに達成しています。神殿で「'.(string) $trial['hero_job_name'].'」を確認してください。');
                }

                $phaseResults = [];
                $turnOffset = 0;
                foreach ($enemies as $index => $enemy) {
                    if ($index > 0) {
                        $lockedCharacter->refresh();
                        CharacterStatusService::clearRequestCache((int) $lockedCharacter->id);
                    }

                    $result = $this->battleService->executeBattle(
                        $lockedCharacter,
                        $enemy,
                        0,
                        ['rewards_enabled' => false]
                    );
                    $phaseResults[] = [
                        'phase' => (array) $profile['phases'][$index],
                        'result' => $result,
                        'display_logs' => $this->continuousDisplayLogs(
                            $result,
                            (int) $index,
                            $turnOffset,
                            $enemy->name,
                            $index < $enemies->count() - 1,
                        ),
                    ];
                    $turnOffset += $result->turnCount;

                    if ($result->result !== 'victory') {
                        break;
                    }
                }

                $passed = count($phaseResults) === count($profile['phases'])
                    && collect($phaseResults)->every(
                        fn (array $phaseResult): bool => $phaseResult['result']->result === 'victory'
                    );

                if ($passed) {
                    $now = now();
                    $progress->forceFill([
                        'is_unlocked' => true,
                        'boss_defeated' => true,
                        'boss_defeated_at' => $now,
                        'discovery_state' => 'cleared',
                        'cleared_at' => $now,
                    ])->save();
                }

                return [
                    'trial_key' => $trialKey,
                    'trial' => $trial,
                    'passed' => $passed,
                    'newly_unlocked' => $passed,
                    'phase_results' => $phaseResults,
                ];
            }, 3);
        } finally {
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    /**
     * @param  array<string, mixed>  $trial
     */
    private function appearanceRequirementsMet(Character $character, array $trial): bool
    {
        $requiredAreaId = (int) ($trial['appearance_required_area_id'] ?? 0);
        if (! $this->hasClearedArea($character, $requiredAreaId)) {
            return false;
        }

        return $this->hasMasteredJob($character, (string) ($trial['required_job_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $trial
     */
    private function ensureProgress(Character $character, array $trial): void
    {
        $progress = CharacterAreaProgress::query()->firstOrCreate(
            [
                'character_id' => $character->id,
                'area_id' => (int) $trial['area_id'],
            ],
            [
                'is_unlocked' => true,
                'unlocked_at' => now(),
                'discovery_state' => 'discovered',
                'discovered_at' => now(),
            ]
        );

        if (! (bool) $progress->is_unlocked) {
            $now = now();
            $progress->forceFill([
                'is_unlocked' => true,
                'unlocked_at' => $progress->unlocked_at ?? $now,
                'discovery_state' => $progress->discovery_state === 'undiscovered'
                    ? 'discovered'
                    : $progress->discovery_state,
                'discovered_at' => $progress->discovered_at ?? $now,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $trial
     * @return array<string, mixed>
     */
    private function buildFacility(Character $character, string $trialKey, array $trial): array
    {
        $cleared = $this->hasClearedArea($character, (int) $trial['area_id']);
        $isFull = $this->isHpSpFull($character);
        $speciesLabels = $this->profileService->speciesLabels((string) ($trial['profile_key'] ?? ''));
        $details = [
            '挑戦職: すべての職業',
            '試練主の種族: '.implode(' / ', $speciesLabels),
        ];
        array_push($details, ...(array) ($trial['mechanic_details'] ?? []));

        if ($cleared) {
            $details[] = '試練達成済み';

            return [
                'name' => (string) $trial['label'],
                'symbol_image' => (string) ($trial['symbol_image'] ?? 'jobbadge/jobbadge_070.webp'),
                'desc' => (string) ($trial['cleared_desc'] ?? '試練を越えた者に、英雄の道が開かれる。'),
                'details' => $details,
                'bg_image' => (string) ($trial['bg_image'] ?? 'card_bg/dungeon_10_07.webp'),
                'status' => 'active',
                'action' => (string) $trial['hero_job_name'].'を確認',
                'route' => 'jobs.index',
                'is_post' => false,
                'badge' => '達成済み',
            ];
        }

        if (! $isFull) {
            $details[] = 'HP/SPを全快にすると挑戦可能';
        }

        return [
            'name' => (string) $trial['label'],
            'symbol_image' => (string) ($trial['symbol_image'] ?? 'jobbadge/jobbadge_070.webp'),
            'desc' => (string) ($trial['facility_desc'] ?? '試練主が挑戦者を待つ。'),
            'details' => $details,
            'bg_image' => (string) ($trial['bg_image'] ?? 'card_bg/dungeon_10_07.webp'),
            'status' => 'active',
            'action' => $isFull ? (string) ($trial['challenge_action'] ?? '英雄試練に挑む') : '挑戦条件を確認',
            'route' => 'hero-trials.challenge',
            'params' => ['trialKey' => $trialKey],
            'is_post' => true,
            'badge' => '英雄試練',
        ];
    }

    /**
     * @param  array<string, mixed>  $trial
     */
    private function assertCanChallenge(Character $character, array $trial): void
    {
        if ((bool) $character->is_frozen) {
            throw new DomainException('このアカウントは凍結されています。お問い合わせください。');
        }

        if (! $this->appearanceRequirementsMet($character, $trial)) {
            throw new DomainException(
                '終焉の祭壇を踏破し、'.(string) ($trial['required_job_name'] ?? '指定の冠位職').'をマスターすると試練への道が開きます。'
            );
        }

        if ((int) $character->current_city_id !== (int) ($trial['city_id'] ?? 0)) {
            throw new DomainException((string) ($trial['label'] ?? '英雄試練').'へ挑むには、魔王城ヴァルゼリアへ移動してください。');
        }

        if ((bool) ($trial['requires_full_hp_sp'] ?? false) && ! $this->isHpSpFull($character)) {
            throw new DomainException((string) ($trial['label'] ?? '英雄試練').'に備え、宿屋でHP/SPを全快にしてから挑んでください。');
        }
    }

    private function hasClearedArea(Character $character, int $areaId): bool
    {
        return $areaId > 0 && CharacterAreaProgress::query()
            ->where('character_id', $character->id)
            ->where('area_id', $areaId)
            ->where('boss_defeated', true)
            ->exists();
    }

    private function hasMasteredJob(Character $character, string $jobKey): bool
    {
        return $jobKey !== '' && $character->jobHistories()
            ->where('is_mastered', true)
            ->whereHas('jobClass', fn ($query) => $query->where('key', $jobKey))
            ->exists();
    }

    private function isHpSpFull(Character $character): bool
    {
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = $this->statusService->getFinalStats($character);

        return (int) $character->current_hp >= (int) ($stats['max_hp'] ?? 1)
            && (int) ($character->current_mp ?? 0) >= (int) ($stats['max_mp'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private function continuousDisplayLogs(
        BattleResult $result,
        int $phaseIndex,
        int $turnOffset,
        string $enemyName,
        bool $hasNextPhase,
    ): array {
        $isVictory = $result->result === 'victory';

        return collect($result->logs)
            ->reject(function (string $line) use ($phaseIndex, $hasNextPhase, $enemyName, $isVictory): bool {
                $plainText = strip_tags($line);

                if ($phaseIndex > 0 && str_contains($plainText, '【戦闘開始】')) {
                    return true;
                }

                return $hasNextPhase
                    && $isVictory
                    && str_contains($plainText, "{$enemyName}を倒した！");
            })
            ->map(function (string $line) use ($turnOffset): string {
                if ($turnOffset === 0) {
                    return $line;
                }

                return (string) preg_replace_callback(
                    '/(--- ターン )(\d+)( ---)/u',
                    static fn (array $matches): string => $matches[1].((int) $matches[2] + $turnOffset).$matches[3],
                    $line,
                );
            })
            ->values()
            ->all();
    }
}
