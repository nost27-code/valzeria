<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseReadinessService
{
    /** @var array<int, int> */
    private const REQUIRED_JOB_EXP_LEVELS = [2, 3, 4, 5, 6, 7, 8, 9, 10];

    /** @var array<string, string> */
    private const RANK5_V6_REQUIRED_FLAGS = [
        'dynamic_single' => 'BATTLE_JOB_ART_DYNAMIC_SINGLE',
        'hit_resolution' => 'BATTLE_JOB_ART_HIT_RESOLUTION',
        'damage_application' => 'BATTLE_JOB_ART_DAMAGE_APPLICATION',
        'resources' => 'BATTLE_JOB_ART_RESOURCES',
    ];

    /** @return array<int, string> */
    public function issues(bool $includeDisabled = false): array
    {
        $issues = $this->databaseReferenceIssues();

        foreach (config('extra_content.contents', []) as $key => $content) {
            if (!$includeDisabled && !app(ExtraContentControlService::class)->isActive((string) $key, $content)) {
                continue;
            }

            $issues = array_merge($issues, $this->contentIssues((string) $key));
        }

        return array_values(array_unique($issues));
    }

    /** @return array<int, string> */
    public function contentIssues(string $contentKey): array
    {
        return match ($contentKey) {
            'star_tree_tower' => $this->starTreeTowerIssues(),
            'ferdia_unlocked' => $this->ferdiaIssues(),
            'exploration_support' => $this->explorationSupportIssues(),
            'equipment_book' => $this->equipmentBookIssues(),
            'character_icon_design' => $this->characterIconDesignIssues(),
            'hero_trials' => $this->heroTrialIssues(),
            'hero_trials_second_wave' => $this->heroTrialIssues('hero_trials_second_wave'),
            'hero_trials_final_wave' => $this->heroTrialIssues('hero_trials_final_wave'),
            default => ["未対応の追加コンテンツです: {$contentKey}"],
        };
    }

    /** @return array<int, string> */
    private function databaseReferenceIssues(): array
    {
        $issues = $this->coreGameDataIssues();
        array_push($issues, ...$this->rank5V6Issues());

        if (Schema::hasTable('items') && Schema::hasTable('cities') && Schema::hasColumn('items', 'unlock_city_id')) {
            $invalid = DB::table('items')
                ->leftJoin('cities', 'cities.id', '=', 'items.unlock_city_id')
                ->whereNotNull('items.unlock_city_id')
                ->whereNull('cities.id')
                ->count();
            if ($invalid > 0) {
                $issues[] = "items.unlock_city_id に存在しない都市参照が {$invalid} 件あります。";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function rank5V6Issues(): array
    {
        if (! (bool) config('battle.job_art_v2.rank5_v6', false)) {
            return [];
        }

        $issues = [];
        $missingFlags = [];
        foreach (self::RANK5_V6_REQUIRED_FLAGS as $configKey => $environmentKey) {
            if (! (bool) config("battle.job_art_v2.{$configKey}", false)) {
                $missingFlags[] = $environmentKey;
            }
        }
        if ($missingFlags !== []) {
            $issues[] = 'Rank5 v6.1 flagがONですが、依存flagがOFFです（' . implode('、', $missingFlags) . '）。';
        }

        if (! Schema::hasTable('skills')) {
            $issues[] = 'Rank5 v6.1 flagがONですが、skillsテーブルがありません。';

            return $issues;
        }

        try {
            $payload = json_decode(
                (string) file_get_contents(database_path('data/job_art_rank5_v6_1_migration.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            $issues[] = 'Rank5 v6.1のmaster検証データを読み込めません。';

            return $issues;
        }

        $expectedRows = $payload['new'] ?? null;
        if (! is_array($expectedRows) || count($expectedRows) !== 94) {
            $issues[] = 'Rank5 v6.1のmaster検証データが94件ではありません。';

            return $issues;
        }

        $jobIds = array_map(
            static fn (string $key): int => (int) explode(':', $key, 2)[0],
            array_keys($expectedRows),
        );
        $actualRows = DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->whereIn('job_id', $jobIds)
            ->get();
        $actualByKey = [];
        foreach ($actualRows as $row) {
            $key = ((int) $row->job_id) . ':' . ((int) $row->learn_rank);
            $actualByKey[$key][] = (array) $row;
        }

        $availableColumns = array_fill_keys(Schema::getColumnListing('skills'), true);
        $mismatchCount = 0;
        foreach ($expectedRows as $naturalKey => $expected) {
            $matches = $actualByKey[$naturalKey] ?? [];
            if (count($matches) !== 1) {
                $mismatchCount++;
                continue;
            }

            $actual = $matches[0];
            foreach ($expected as $column => $expectedValue) {
                if (! isset($availableColumns[$column])
                    || ! array_key_exists($column, $actual)
                    || ! $this->rank5V6ValueMatches($actual[$column], $expectedValue)
                ) {
                    $mismatchCount++;
                    break;
                }
            }
        }

        if ($mismatchCount > 0) {
            $issues[] = "Rank5 v6.1 flagがONですが、skillsのRank5 masterが新仕様と一致しません（不一致{$mismatchCount}件）。";
        }

        return $issues;
    }

    private function rank5V6ValueMatches(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if ($actual === null) {
            return false;
        }
        if (is_int($expected) || is_float($expected)) {
            return is_numeric($actual)
                && abs((float) $actual - (float) $expected) < 0.000001;
        }
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }

        return (string) $actual === (string) $expected;
    }

    /** @return array<int, string> */
    private function coreGameDataIssues(): array
    {
        $issues = $this->missingTables(['cities', 'job_exp_tables', 'job_classes', 'character_jobs']);
        if ($issues !== []) {
            return $issues;
        }

        if (DB::table('cities')->count() === 0) {
            $issues[] = '都市マスタがありません。';
        }

        $availableJobExpLevels = DB::table('job_exp_tables')
            ->whereIn('job_level', self::REQUIRED_JOB_EXP_LEVELS)
            ->pluck('job_level')
            ->map(fn ($level): int => (int) $level)
            ->all();
        $missingJobExpLevels = array_values(array_diff(self::REQUIRED_JOB_EXP_LEVELS, $availableJobExpLevels));
        if ($missingJobExpLevels !== []) {
            $issues[] = '職業経験値マスタが不足しています（不足ランク: '
                . implode(', ', $missingJobExpLevels)
                . '）。';
        }

        $missingColumns = collect(['is_mastered', 'mastered_at'])
            ->reject(fn (string $column): bool => Schema::hasColumn('character_jobs', $column))
            ->values()
            ->all();
        if ($missingColumns !== []) {
            $issues[] = 'character_jobs の必須カラムがありません（'
                . implode(', ', $missingColumns)
                . '）。';

            return $issues;
        }

        $invalidMastered = DB::table('character_jobs as character_jobs')
            ->join('job_classes as job_classes', 'job_classes.id', '=', 'character_jobs.job_class_id')
            ->where('character_jobs.is_mastered', true)
            ->whereColumn('character_jobs.job_level', '<', 'job_classes.max_job_level')
            ->count();
        if ($invalidMastered > 0) {
            $issues[] = "職業ランク未達のマスター済み履歴が {$invalidMastered} 件あります。";
        }

        $missingMastered = DB::table('character_jobs as character_jobs')
            ->join('job_classes as job_classes', 'job_classes.id', '=', 'character_jobs.job_class_id')
            ->where('character_jobs.is_mastered', false)
            ->whereColumn('character_jobs.job_level', '>=', 'job_classes.max_job_level')
            ->count();
        if ($missingMastered > 0) {
            $issues[] = "職業ランク到達済みの未マスター履歴が {$missingMastered} 件あります。";
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function starTreeTowerIssues(): array
    {
        $issues = $this->missingTables(['tower_floor_master', 'tower_runs', 'tower_reward_claims']);
        if ($issues !== []) {
            return $issues;
        }

        $towerKey = (string) config('star_tree_tower.star_tree.tower_key', 'star_tree_tower');
        $expectedFloors = (int) config('star_tree_tower.star_tree.seed_floor_count', 100);
        $floors = DB::table('tower_floor_master')->where('tower_key', $towerKey);
        $count = (int) $floors->count();
        $min = (int) $floors->min('floor');
        $max = (int) $floors->max('floor');
        if ($count !== $expectedFloors || $min !== 1 || $max !== $expectedFloors) {
            $issues[] = "星樹の塔の階層マスタが不足しています（期待 {$expectedFloors}階、実際 {$count}件・{$min}〜{$max}階）。";
        }

        if (!Schema::hasTable('items') || DB::table('items')->where('source_type', 'star_tree_tower_reward')->count() === 0) {
            $issues[] = '星樹の塔の初回到達報酬マスタがありません。';
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function ferdiaIssues(): array
    {
        $issues = $this->missingTables(['areas', 'cities', 'enemies', 'area_discovery_links']);
        if ($issues !== []) {
            return $issues;
        }

        $areaCount = DB::table('areas')->whereBetween('id', [1001, 1013])->count();
        if ($areaCount !== 13) {
            $issues[] = "フェルディア探索地マスタが不足しています（期待13件、実際 {$areaCount}件）。";
        }
        if (DB::table('enemies')->whereBetween('area_id', [1001, 1013])->count() === 0) {
            $issues[] = 'フェルディアの敵マスタがありません。';
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function explorationSupportIssues(): array
    {
        $issues = $this->missingTables([
            'player_exploration_support_effects',
            'player_exploration_support_item_states',
            'character_exploration_support_prefs',
            'items',
        ]);
        if ($issues !== []) {
            return $issues;
        }

        $expected = array_column(ExplorationSupportService::ITEMS, 'name');
        $actual = DB::table('items')->whereIn('name', $expected)->where('type', 'consumable')->count();
        if ($actual !== count($expected)) {
            $issues[] = "探索補助品マスタが不足しています（期待" . count($expected) . "件、実際 {$actual}件）。";
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function characterIconDesignIssues(): array
    {
        return $this->missingTables([
            'character_icon_design_requests',
            'character_icon_design_messages',
            'character_icon_design_message_attachments',
            'character_icon_entitlements',
        ]);
    }

    /** @return array<int, string> */
    private function equipmentBookIssues(): array
    {
        return $this->missingTables([
            'character_equipment_discoveries',
            'items',
            'weapon_evolution_recipes',
        ]);
    }

    /** @return array<int, string> */
    private function heroTrialIssues(?string $releaseGate = null): array
    {
        $issues = $this->missingTables(['areas', 'job_classes', 'job_requirements']);
        if ($issues !== []) {
            return $issues;
        }

        array_push($issues, ...$this->heroTrialHallConfigurationIssues());

        foreach (config('hero_trials.released_trials', []) as $trialKey => $trial) {
            if ($releaseGate !== null && (string) ($trial['release_gate'] ?? '') !== $releaseGate) {
                continue;
            }

            array_push($issues, ...$this->heroTrialDefinitionIssues((string) $trialKey, (array) $trial));

            $areaId = (int) ($trial['area_id'] ?? 0);
            if ($areaId <= 0 || !DB::table('areas')->where('id', $areaId)->exists()) {
                $issues[] = "英雄試練 {$trialKey} の試練場マスタがありません。";
            }

            $heroJobId = DB::table('job_classes')->where('key', $trial['hero_job_key'] ?? '')->value('id');
            $requiredJobId = DB::table('job_classes')->where('key', $trial['required_job_key'] ?? '')->value('id');
            if (!$heroJobId || !$requiredJobId) {
                $issues[] = "英雄試練 {$trialKey} の職業マスタが不足しています。";

                continue;
            }

            $hasRequirement = DB::table('job_requirements')
                ->where('job_id', $heroJobId)
                ->where('requirement_type', 'master_job')
                ->where('required_job_id', $requiredJobId)
                ->exists();
            if (!$hasRequirement) {
                $issues[] = "英雄試練 {$trialKey} の必須職マスター条件がありません。";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function heroTrialHallConfigurationIssues(): array
    {
        $issues = [];
        $tiers = config('hero_trials.hall_tiers', []);
        $cards = config('hero_trials.hall_cards', []);
        $releasedTrials = config('hero_trials.released_trials', []);

        if (! is_array($tiers) || $tiers === []) {
            $issues[] = '英雄試練殿の難易度区分がありません。';

            return $issues;
        }
        if (! is_array($cards) || $cards === []) {
            $issues[] = '英雄試練殿の試練カード設定がありません。';

            return $issues;
        }
        if (! is_array($releasedTrials)) {
            $issues[] = '英雄試練の実装済み試練設定を読み込めません。';

            return $issues;
        }

        $seenTrialKeys = [];
        foreach ($tiers as $tierKey => $tier) {
            if (! is_array($tier)) {
                $issues[] = "英雄試練殿の難易度区分 {$tierKey} が不正です。";

                continue;
            }

            $recommendedPower = (int) ($tier['recommended_power'] ?? 0);
            if ($recommendedPower <= 0) {
                $issues[] = "英雄試練殿の難易度区分 {$tierKey} に目安戦力がありません。";
            }
            if (! array_key_exists('is_planned', $tier) || ! is_bool($tier['is_planned'])) {
                $issues[] = "英雄試練殿の難易度区分 {$tierKey} の予定区分が不正です。";
            }

            $isPlanned = (bool) ($tier['is_planned'] ?? false);
            $trialKeys = $tier['trial_keys'] ?? null;
            if (! is_array($trialKeys) || $trialKeys === []) {
                $issues[] = "英雄試練殿の難易度区分 {$tierKey} に試練がありません。";

                continue;
            }

            foreach ($trialKeys as $trialKey) {
                $trialKey = trim((string) $trialKey);
                if ($trialKey === '') {
                    $issues[] = "英雄試練殿の難易度区分 {$tierKey} に空の試練キーがあります。";

                    continue;
                }
                if (isset($seenTrialKeys[$trialKey])) {
                    $issues[] = "英雄試練 {$trialKey} が複数の難易度区分に登録されています。";

                    continue;
                }

                $seenTrialKeys[$trialKey] = true;
                if (! isset($cards[$trialKey]) || ! is_array($cards[$trialKey])) {
                    $issues[] = "英雄試練 {$trialKey} の殿堂カード設定がありません。";
                }

                $isImplemented = isset($releasedTrials[$trialKey]) && is_array($releasedTrials[$trialKey]);
                if ($isPlanned && $isImplemented) {
                    $issues[] = "実装済み英雄試練 {$trialKey} が予定枠に残っています。";
                } elseif (! $isPlanned && ! $isImplemented) {
                    $issues[] = "英雄試練 {$trialKey} が実装済み枠ですが試練設定は未実装です。";
                }
            }
        }

        foreach ($cards as $trialKey => $card) {
            if (! isset($seenTrialKeys[$trialKey])) {
                $issues[] = "英雄試練 {$trialKey} が難易度区分に登録されていません。";
            }
            if (is_array($card)) {
                $symbolImage = (string) ($card['symbol_image'] ?? '');
                if (! $this->heroTrialAssetExists($symbolImage)) {
                    $issues[] = "英雄試練 {$trialKey} の殿堂シンボル画像がありません。";
                }
            }
        }

        foreach ($releasedTrials as $trialKey => $trial) {
            if (! isset($cards[$trialKey]) || ! is_array($trial)) {
                $issues[] = "実装済み英雄試練 {$trialKey} の殿堂カード設定がありません。";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $trial
     * @return array<int, string>
     */
    private function heroTrialDefinitionIssues(string $trialKey, array $trial): array
    {
        $issues = [];
        $releaseGate = trim((string) ($trial['release_gate'] ?? ''));
        if ($releaseGate !== '' && ! is_array(config("extra_content.contents.{$releaseGate}"))) {
            $issues[] = "英雄試練 {$trialKey} の公開設定 {$releaseGate} がありません。";
        }

        foreach (['symbol_image' => 'シンボル', 'job_badge_image' => '職業バッジ'] as $configKey => $label) {
            if (! $this->heroTrialAssetExists((string) ($trial[$configKey] ?? ''))) {
                $issues[] = "英雄試練 {$trialKey} の{$label}画像がありません。";
            }
        }

        $profileKey = trim((string) ($trial['profile_key'] ?? ''));
        $profile = config("hero_trials.profiles.{$profileKey}");
        if ($profileKey === '' || ! is_array($profile) || empty($profile['phases']) || ! is_array($profile['phases'])) {
            $issues[] = "英雄試練 {$trialKey} の戦闘プリセットがありません。";

            return $issues;
        }
        if ((string) ($profile['trial_key'] ?? '') !== $trialKey) {
            $issues[] = "英雄試練 {$trialKey} と戦闘プリセットの試練キーが一致しません。";
        }

        $benchmark = $profile['benchmark'] ?? null;
        $runs = is_array($benchmark) ? (int) ($benchmark['runs'] ?? 0) : 0;
        $passes = is_array($benchmark) ? (int) ($benchmark['passes'] ?? -1) : -1;
        $passRate = is_array($benchmark) && is_numeric($benchmark['pass_rate'] ?? null)
            ? (float) $benchmark['pass_rate']
            : -1.0;
        if (! is_array($benchmark)
            || trim((string) ($benchmark['measured_on'] ?? '')) === ''
            || trim((string) ($benchmark['basis'] ?? '')) === ''
            || trim((string) ($benchmark['note'] ?? '')) === ''
            || $runs <= 0
            || $passes < 0
            || $passes > $runs
            || $passRate < 0
            || $passRate > 100
        ) {
            $issues[] = "英雄試練 {$trialKey} の実戦闘ベンチマークが不完全です。";
        } elseif (abs(round(($passes / $runs) * 100, 1) - $passRate) > 0.05) {
            $issues[] = "英雄試練 {$trialKey} の実戦闘ベンチマーク勝率が試行結果と一致しません。";
        }

        foreach ($profile['phases'] as $phaseIndex => $phase) {
            if (! is_array($phase) || ! $this->heroTrialAssetExists((string) ($phase['image_path'] ?? ''))) {
                $phaseNumber = (int) $phaseIndex + 1;
                $issues[] = "英雄試練 {$trialKey} の第{$phaseNumber}形態画像がありません。";
            }
        }

        return $issues;
    }

    private function heroTrialAssetExists(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '') {
            return false;
        }
        if (! str_starts_with($path, 'images/')) {
            $path = 'images/'.$path;
        }

        return is_file(public_path($path));
    }

    /** @param array<int, string> $tables
     *  @return array<int, string> */
    private function missingTables(array $tables): array
    {
        return collect($tables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->map(fn (string $table): string => "必要テーブル {$table} がありません。")
            ->values()
            ->all();
    }
}
