<?php

namespace App\Services\Admin;

use App\Models\NationRaidBattleTelemetryLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** 国家対抗レイドの戦闘テレメトリを次回調整へ変換するread-only集計。 */
final class NationRaidAnalyticsService
{
    /** @var array<string, string> */
    private const LINEAGE_LABELS = [
        'field' => '場術',
        'counter' => '反撃',
        'eclipse' => '冥蝕',
        'pierce' => '貫通',
        'hunt' => '封狩',
        'aim' => '照準',
        'guard' => '守護',
        'transmute' => '変成',
        'break' => '崩し',
        'command' => '指揮',
    ];

    /** @var array<string, string> */
    private const PHASE_LABELS = [
        'sealed_scale' => '第一形態《封鱗》',
        'split_wing' => '第二形態《裂翼》',
        'ten_lineage_corrosion' => '第三形態《十系侵蝕》',
        'lineage_invasion' => '第三形態《十系侵蝕》',
        'exposed_core' => '最終形態《露核》',
        'unknown' => '不明',
    ];

    /** @var array<string, string> */
    private const DAMAGE_SOURCE_LABELS = [
        'normal' => '通常攻撃',
        'job_art_direct' => '戦技の直接ダメージ',
        'direct_unclassified' => '直接ダメージ（旧記録・技未計測）',
        'dot' => '継続ダメージ',
        'counter' => '反撃ダメージ',
        'eclipse_backlash' => '暗黒剣の予告後ダメージ',
        'percent' => '割合ダメージ',
        'companion' => '相棒・付随ダメージ',
        'other' => 'その他',
    ];

    /** @var array<string, string> */
    private const COUNTERPLAY_LABELS = [
        'telegraphs_seen' => '予告を見た回数',
        'guards_20' => '20%軽減',
        'guards_35' => '35%軽減',
        'guards_50' => '50%軽減',
        'hunt_delays' => '影縫い乱舞による遅延',
        'command_delays' => '王戦の号令による遅延',
        'sp_denials' => 'SP不足による大技阻止',
        'effect_suppressions' => '固有追加効果の抑止',
        'ultimate_casts' => '20ターン目大技の発動',
        'ultimate_fallbacks' => '20ターン目の代替行動',
        'adaptive_casts' => '対抗系譜技の発動',
        'adaptive_delays' => '対抗系譜技の遅延',
        'responses_selected' => '対抗戦技の選択',
        'responses_applied' => '対抗効果の成立',
        'preparations_destroyed' => 'レイド準備の破壊',
        'aim_sp_pressure' => '大技阻止：狙い撃ち',
        'transmute_resource_slow' => '大技阻止：大錬成爆装',
        'turn_18_delay' => '大技阻止：18ターン目遅延',
        'turn_20_delay' => '大技阻止：20ターン目遅延',
        'denial_overlap' => '大技阻止経路の重複',
    ];

    private ?bool $tableExists = null;

    /**
     * 収集項目、分かること、次回変更候補を同じ正本で画面とCodex出力へ渡す。
     *
     * @return list<array{category:string,collect:string,reveals:string,improves:string,guardrail:string}>
     */
    public function metricDefinitions(): array
    {
        return [
            [
                'category' => '討伐成立性',
                'collect' => '出撃数、参加者数、実適用ダメージ、個体番号、共有HP前後、日別参加',
                'reveals' => '開催回に固定した段階別HPへ届くか、上位依存か、早期討伐・未討伐の傾向',
                'improves' => '次回のボスHP、1日回数、開催日数、被damage軽減を調整する',
                'guardrail' => '平均だけで決めず、中央値・P25/P75/P90と上位不在条件を併記する',
            ],
            [
                'category' => '20ターン難度曲線',
                'collect' => '各ターン到達数、ボスダメージの上限適用前/後/最終値、回復、戦闘不能ターン',
                'reveals' => '何ターン目から脱落が増えるか、capが常時効いているか、終盤だけ急激すぎないか',
                'improves' => 'ターン倍率、最大HP割合cap、技威力、回復阻害、20ターン目大技を調整する',
                'guardrail' => '強者の生存だけでなく、戦力分位ごとの到達率を比較する',
            ],
            [
                'category' => '形態バランス',
                'collect' => '出撃開始時形態、形態別与ダメージ・被ダメージ・生存ターン、形態滞在出撃数',
                'reveals' => '特定形態が硬すぎる、短すぎる、最終形態で参加者が攻撃できない等の偏り',
                'improves' => '形態閾値、与damage倍率、被damage軽減、特徴技の重みを調整する',
                'guardrail' => '形態は出撃開始snapshotで比較し、途中のglobal HP変化を混ぜない',
            ],
            [
                'category' => '10系譜公平性',
                'collect' => '持込系譜、最多編成の対抗対象、対象時/非対象時の与ダメージ・生存・使用率',
                'reveals' => '対抗対象になった系譜だけ使用不能か、常に強い/弱い系譜、囮編成の影響',
                'improves' => '10対抗技の威力、低下率、持続、解除可否、日次投票規則を調整する',
                'guardrail' => '複数系譜setは各系譜へ1出撃ずつ帰属し、合計と同一視しない',
            ],
            [
                'category' => '予告対策',
                'collect' => '予告回数、20/35/50%軽減、遅延、SP阻止、追加効果抑止、大技/代替行動',
                'reveals' => '予告へ応答する価値があるか、特定の対策だけが必須か、阻止が簡単すぎるか',
                'improves' => 'ボスSP、大技必要SP、予告turn、軽減率、代替行動を調整する',
                'guardrail' => '対策の選択回数と成功回数を分け、複数効果の重複を成功率へ足さない',
            ],
            [
                'category' => 'damage構成',
                'collect' => '通常、戦技直接、継続、反撃、割合、付随ダメージと1行動最大ダメージ',
                'reveals' => '共有HP削りの大半を占めるダメージ源、割合基準HPの悪用、単発記録の偏り',
                'improves' => 'レイド効果仮想HP、固有軽減の適用範囲、ランキング定義を調整する',
                'guardrail' => '算出damageと共有HPへ実適用したdamageを分離する',
            ],
            [
                'category' => '戦力帯公平性',
                'collect' => '開始時能力・戦力snapshot、レベル、職業、与ダメージ、生存ターン、回復',
                'reveals' => '低中戦力が貢献を残せるか、上位10%だけで討伐が決まるか',
                'improves' => 'ボス防御/精神、ボス攻撃/魔力、割合cap、個人到達報酬を調整する',
                'guardrail' => '表示名やaccount情報をCodex出力へ含めず、分位集計だけを渡す',
            ],
            [
                'category' => '国家規模公平性',
                'collect' => '国家集計資格、活動人数、一人あたりダメージ、無所属/集計外の貢献',
                'reveals' => '小国・大国・無所属の参加差、1人国家farm、分母による不利益',
                'improves' => '国家資材の二重上限、資格人数、一人あたり判定、装飾資格を調整する',
                'guardrail' => '国家名・個人名をCodex出力せず、規模帯だけで比較する',
            ],
            [
                'category' => '報酬到達',
                'collect' => 'Character別出撃数・累計ダメージ、参加/各ダメージ閾値の到達人数',
                'reveals' => '参加賞が容易すぎるか、中央値層が素材報酬へ届くか、称号が希少か',
                'improves' => '15出撃、25万/100万/200万damage、報酬個数を次回調整する',
                'guardrail' => '閾値はevent snapshotから読み、管理画面で別の固定値を推測しない',
            ],
            [
                'category' => '運用・計測品質',
                'collect' => 'resolved/aborted/refunded、処理時間、欠損turn、ruleset/schema version、quality flag',
                'reveals' => '通信失敗、終了境界、計測欠損、ruleset混在で分析が歪んでいないか',
                'improves' => '10分猶予、再送・返却処理、index、計測hookを修正する',
                'guardrail' => '計測失敗は戦闘・共有HP・報酬へ影響させない',
            ],
        ];
    }

    /** @return list<array{event_key:string,last_recorded_at:string,records:int}> */
    public function eventOptions(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return NationRaidBattleTelemetryLog::query()
            ->selectRaw('event_key, MAX(created_at) AS last_recorded_at, COUNT(*) AS records')
            ->groupBy('event_key')
            ->orderByDesc('last_recorded_at')
            ->get()
            ->map(fn (NationRaidBattleTelemetryLog $row): array => [
                'event_key' => (string) $row->event_key,
                'last_recorded_at' => (string) ($row->getAttribute('last_recorded_at') ?? ''),
                'records' => (int) $row->getAttribute('records'),
            ])
            ->all();
    }

    public function latestEventKey(): string
    {
        return $this->eventOptions()[0]['event_key'] ?? '';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function analyze(array $filters): array
    {
        $eventOptions = $this->eventOptions();
        $eventKey = trim((string) ($filters['event_key'] ?? ''));
        if ($eventKey === '') {
            $eventKey = $eventOptions[0]['event_key'] ?? '';
        }

        $normalizedFilters = [
            'event_key' => $eventKey,
            'raid_day' => max(0, min(7, (int) ($filters['raid_day'] ?? 0))),
            'affiliation' => in_array(($filters['affiliation'] ?? 'all'), ['all', 'eligible', 'unaffiliated', 'ineligible'], true)
                ? (string) ($filters['affiliation'] ?? 'all')
                : 'all',
            'boss_phase' => trim((string) ($filters['boss_phase'] ?? 'all')) ?: 'all',
            'adaptive_lineage' => trim((string) ($filters['adaptive_lineage'] ?? 'all')) ?: 'all',
            'result_status' => in_array(($filters['result_status'] ?? 'all'), ['all', 'resolved', 'aborted', 'refunded'], true)
                ? (string) ($filters['result_status'] ?? 'all')
                : 'all',
        ];

        $empty = $this->emptyAnalysis($normalizedFilters, $eventOptions);
        if (! $this->tableExists() || $eventKey === '') {
            return $empty;
        }

        $eventRows = NationRaidBattleTelemetryLog::query()
            ->where('event_key', $eventKey)
            ->orderBy('id')
            ->get();
        if ($eventRows->isEmpty()) {
            return $empty;
        }

        $rows = $this->applyFilters(
            NationRaidBattleTelemetryLog::query()->where('event_key', $eventKey),
            $normalizedFilters,
        )->orderBy('id')->get();

        $eventSnapshot = $eventRows
            ->reverse()
            ->first(fn (NationRaidBattleTelemetryLog $row): bool => is_array($row->event_snapshot) && $row->event_snapshot !== [])
            ?->event_snapshot ?? [];
        $resolvedRows = $rows->where('result_status', 'resolved')->values();
        $analysis = [
            'table_available' => true,
            'has_records' => $rows->isNotEmpty(),
            'event_options' => $eventOptions,
            'filter_options' => [
                'phases' => $eventRows->pluck('boss_phase')->filter()->unique()->values()->map(fn ($key) => [
                    'key' => (string) $key,
                    'label' => $this->phaseLabel((string) $key),
                ])->all(),
                'lineages' => collect(self::LINEAGE_LABELS)->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                ])->values()->all(),
            ],
            'filters' => $normalizedFilters,
            'event_snapshot' => $eventSnapshot,
            'metric_definitions' => $this->metricDefinitions(),
            'summary' => $this->summary($rows, $resolvedRows, $eventSnapshot),
            'daily' => $this->dailyStats($resolvedRows),
            'phases' => $this->phaseStats($resolvedRows),
            'lineages' => $this->lineageStats($resolvedRows),
            'turns' => $this->turnStats($resolvedRows),
            'damage_sources' => $this->damageSourceStats($resolvedRows),
            'equipment_effects' => $this->equipmentEffectStats($resolvedRows),
            'counterplay' => $this->counterplayStats($resolvedRows),
            'nation_sizes' => $this->nationSizeStats($resolvedRows),
            'nation_competition' => $this->nationCompetitionStats($resolvedRows),
            'participant_distribution' => $this->participantDistribution($resolvedRows),
            'reward_reach' => $this->rewardReach($resolvedRows, $eventSnapshot),
            'power_quantiles' => $this->powerQuantiles($resolvedRows),
            'data_quality' => $this->dataQuality($rows),
        ];

        $analysis['codex_prompt'] = $this->codexPrompt($analysis);

        return $analysis;
    }

    /** @param array<string, mixed> $analysis */
    public function codexPrompt(array $analysis): string
    {
        $payload = [
            'export_schema' => 'nation_raid_analytics_export_v1',
            'generated_at' => now()->toIso8601String(),
            'filters' => $analysis['filters'] ?? [],
            'event_snapshot' => $analysis['event_snapshot'] ?? [],
            'sample_definition' => [
                'one_row' => '1出撃の終了結果。手番ごとのDB行ではない',
                'damage' => '共有HPへ実際に適用されたダメージ',
                'lineage_attribution' => '1出撃に複数系譜を含む場合、各系譜へ1件ずつ帰属するため系譜件数の合計は出撃数を超えうる',
                'privacy' => '表示名、account、個人・国家の識別子、戦闘tokenを除いた集計値',
            ],
            'metric_definitions' => $analysis['metric_definitions'] ?? $this->metricDefinitions(),
            'summary' => $analysis['summary'] ?? [],
            'daily' => $analysis['daily'] ?? [],
            'phases' => $analysis['phases'] ?? [],
            'lineages' => $analysis['lineages'] ?? [],
            'turns' => $analysis['turns'] ?? [],
            'damage_sources' => $analysis['damage_sources'] ?? [],
            'equipment_effects' => $analysis['equipment_effects'] ?? [],
            'counterplay' => $analysis['counterplay'] ?? [],
            'nation_sizes' => $analysis['nation_sizes'] ?? [],
            'nation_competition' => $analysis['nation_competition'] ?? [],
            'participant_distribution' => $analysis['participant_distribution'] ?? [],
            'reward_reach' => $analysis['reward_reach'] ?? [],
            'power_quantiles' => $analysis['power_quantiles'] ?? [],
            'data_quality' => $analysis['data_quality'] ?? [],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return implode("\n", [
            '# 国家対抗レイド改善分析依頼',
            '',
            '「ヴァルゼリアの冒険者」の国家対抗レイドについて、以下の匿名集計データを分析してください。',
            '次回レイドへ活かすため、観測事実・推測・データ不足を分けて日本語で報告してください。',
            '',
            '## 必須の分析',
            '',
            '1. 開催回に固定した段階別HP、開催期間、出撃条件、20ターンの討伐成立性。平均だけでなく中央値・分位・参加頻度を考慮する。',
            '2. ターン・形態・戦力分位ごとの生存とダメージの壁。',
            '3. 10系譜の対象時/非対象時格差と、予告対策の実効性。',
            '4. 国家ごとの総ダメージ・一人あたりダメージ、国家規模・無所属・個人報酬閾値の公平性。',
            '5. 次回変更候補を「数値を維持」「弱体化」「強化」「追加計測」に分け、根拠となるmetricを示す。',
            '6. sample不足、欠損、ruleset混在がある項目は断定せず、必要な追加データを示す。',
            '',
            '## 出力形式',
            '',
            '- Executive verdict',
            '- 分かったこと',
            '- まだ分からないこと',
            '- 次回調整案（優先度・対象値・根拠・副作用・確認方法）',
            '- 追加で収集すべきデータ',
            '',
            '## 匿名集計データ',
            '',
            '```json',
            $json,
            '```',
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ((int) $filters['raid_day'] > 0) {
            $query->where('raid_day', (int) $filters['raid_day']);
        }
        if ($filters['affiliation'] === 'eligible') {
            $query->where('is_nation_eligible', true);
        } elseif ($filters['affiliation'] === 'unaffiliated') {
            $query->whereNull('nation_id');
        } elseif ($filters['affiliation'] === 'ineligible') {
            $query->whereNotNull('nation_id')->where('is_nation_eligible', false);
        }
        if ($filters['boss_phase'] !== 'all') {
            $query->where('boss_phase', $filters['boss_phase']);
        }
        if ($filters['adaptive_lineage'] === 'none') {
            $query->whereNull('adaptive_lineage');
        } elseif ($filters['adaptive_lineage'] !== 'all') {
            $query->where('adaptive_lineage', $filters['adaptive_lineage']);
        }
        if ($filters['result_status'] !== 'all') {
            $query->where('result_status', $filters['result_status']);
        }

        return $query;
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @param Collection<int, NationRaidBattleTelemetryLog> $resolved */
    private function summary(Collection $rows, Collection $resolved, array $eventSnapshot): array
    {
        $damages = $resolved->map(fn ($row) => (int) $row->applied_damage_total)->all();
        $turns = $resolved->map(fn ($row) => (int) $row->turn_count)->all();
        $averageDamage = $this->average($damages);
        $observedHp = $resolved->map(fn ($row): int => max(0, (int) ($row->event_snapshot['boss_max_hp'] ?? 0)))->unique();
        // 段階HPが混在する観測を、最後の個体HPだけで討伐数へ換算しない。
        $bossMaxHp = $observedHp->count() === 1 ? (int) $observedHp->first() : 0;
        $uniqueCharacterCount = $resolved->pluck('character_id')->filter()->unique()->count();
        $healingRows = $resolved->filter(fn ($row): bool => count($row->turns ?? []) === (int) $row->turn_count
            && (int) $row->turn_count > 0
            && collect($row->turns)->every(fn ($turn): bool => ($turn['player_action']['healing'] ?? null) !== null));
        $coordinationDamage = (int) $resolved->sum(fn ($row): int => (int) ($row->event_snapshot['coordination_damage'] ?? 0));

        return [
            'records' => $rows->count(),
            'resolved_sorties' => $resolved->count(),
            'aborted_sorties' => $rows->where('result_status', 'aborted')->count(),
            'refunded_sorties' => $rows->where('result_status', 'refunded')->count(),
            'resolved_rate' => $this->rate($resolved->count(), $rows->count()),
            'unique_characters' => $uniqueCharacterCount,
            'total_applied_damage' => array_sum($damages),
            'total_coordination_damage' => $coordinationDamage,
            'total_boss_damage' => array_sum($damages) + $coordinationDamage,
            'average_damage' => $averageDamage,
            'median_damage' => $this->percentile($damages, 0.50),
            'p25_damage' => $this->percentile($damages, 0.25),
            'p75_damage' => $this->percentile($damages, 0.75),
            'p90_damage' => $this->percentile($damages, 0.90),
            'max_sortie_damage' => $resolved->max(fn ($row): int => (int) $row->applied_damage_total) ?? 0,
            'max_action_damage' => $resolved->max(fn ($row): int => (int) $row->max_action_damage) ?? 0,
            'average_turns' => $this->average($turns, 2),
            'turn_twenty_rate' => $this->rate($resolved->where('reached_turn_twenty', true)->count(), $resolved->count()),
            'defeat_rate' => $this->rate($resolved->whereIn('end_reason', ['player_defeated', 'defeated'])->count(), $resolved->count()),
            'average_damage_taken' => $this->average($resolved->pluck('damage_taken_total')->map(fn ($v) => (int) $v)->all()),
            'average_healing' => $healingRows->isEmpty() ? null : $this->average($healingRows->pluck('healing_total')->map(fn ($v) => (int) $v)->all()),
            'healing_observed_sorties' => $healingRows->count(),
            'boss_max_hp' => $bossMaxHp,
            'estimated_defeats_from_damage' => $bossMaxHp > 0 ? round((array_sum($damages) + $coordinationDamage) / $bossMaxHp, 4) : null,
            'sorties_for_one_defeat_at_average' => $bossMaxHp > 0 && $averageDamage > 0
                ? (int) ceil($bossMaxHp / $averageDamage)
                : null,
            'observed_cycle_count' => $resolved->pluck('boss_cycle_no')->unique()->count(),
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function dailyStats(Collection $rows): array
    {
        return $rows->groupBy('raid_day')->sortKeys()->map(function (Collection $dayRows, int|string $day): array {
            $summary = $this->compactGroupSummary($dayRows);

            return ['raid_day' => (int) $day, ...$summary];
        })->values()->all();
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function phaseStats(Collection $rows): array
    {
        return $rows->groupBy('boss_phase')->map(function (Collection $phaseRows, string $phase): array {
            return [
                'phase' => $phase,
                'label' => $this->phaseLabel($phase),
                ...$this->compactGroupSummary($phaseRows),
            ];
        })->sortByDesc('sorties')->values()->all();
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function lineageStats(Collection $rows): array
    {
        $stats = [];
        foreach (self::LINEAGE_LABELS as $key => $label) {
            $lineageRows = $rows->filter(fn ($row): bool => in_array($key, $row->loadout_lineages ?? [], true));
            $targeted = $lineageRows->where('adaptive_lineage', $key);
            $untargeted = $lineageRows->where('adaptive_lineage', '!=', $key);
            $targetedAverage = $this->average($targeted->pluck('applied_damage_total')->map(fn ($v) => (int) $v)->all());
            $untargetedAverage = $this->average($untargeted->pluck('applied_damage_total')->map(fn ($v) => (int) $v)->all());

            $stats[] = [
                'lineage' => $key,
                'label' => $label,
                'sorties' => $lineageRows->count(),
                'adoption_rate' => $this->rate($lineageRows->count(), $rows->count()),
                'average_damage' => $this->average($lineageRows->pluck('applied_damage_total')->map(fn ($v) => (int) $v)->all()),
                'median_damage' => $this->percentile($lineageRows->pluck('applied_damage_total')->map(fn ($v) => (int) $v)->all(), 0.50),
                'turn_twenty_rate' => $this->rate($lineageRows->where('reached_turn_twenty', true)->count(), $lineageRows->count()),
                'targeted_sorties' => $targeted->count(),
                'targeted_average_damage' => $targetedAverage,
                'untargeted_sorties' => $untargeted->count(),
                'untargeted_average_damage' => $untargetedAverage,
                'targeted_vs_untargeted_percent' => $untargetedAverage > 0
                    ? round($targetedAverage / $untargetedAverage * 100, 1)
                    : null,
            ];
        }

        usort($stats, static fn (array $left, array $right): int => $right['sorties'] <=> $left['sorties']);

        return $stats;
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function turnStats(Collection $rows): array
    {
        $stats = array_fill(1, 20, null);
        foreach (range(1, 20) as $turn) {
            $stats[$turn] = [
                'turn' => $turn,
                'samples' => 0,
                'player_damage_total' => 0,
                'boss_damage_total' => 0,
                'deaths' => 0,
                'telegraphs' => 0,
                'cap_samples' => 0,
                'cap_hits' => 0,
                'cap_reduction_total' => 0,
            ];
        }

        foreach ($rows as $row) {
            $seenTurnNumbers = [];
            foreach ($row->turns ?? [] as $turnData) {
                if (! is_array($turnData)) {
                    continue;
                }
                $turn = max(1, min(20, (int) ($turnData['turn'] ?? 0)));
                if (isset($seenTurnNumbers[$turn])) {
                    continue;
                }
                $seenTurnNumbers[$turn] = true;
                $playerAction = is_array($turnData['player_action'] ?? null) ? $turnData['player_action'] : [];
                $bossAction = is_array($turnData['boss_action'] ?? null) ? $turnData['boss_action'] : [];
                $stats[$turn]['samples']++;
                $stats[$turn]['player_damage_total'] += max(0, (int) ($playerAction['damage_total'] ?? 0));
                $stats[$turn]['boss_damage_total'] += max(0, (int) ($bossAction['damage_final'] ?? 0));
                $stats[$turn]['deaths'] += (int) (($turnData['player_hp_before'] ?? 0) > 0 && ($turnData['player_hp_after'] ?? 0) <= 0);
                $stats[$turn]['telegraphs'] += (int) (bool) ($bossAction['telegraphed'] ?? false);
                // 未計測や無攻撃の観測turnを「cap非到達=0」として分母へ入れない。
                if (($bossAction['damage_before_cap'] ?? null) !== null && ($bossAction['damage_after_cap'] ?? null) !== null) {
                    $reduction = max(0, (int) $bossAction['damage_before_cap'] - (int) $bossAction['damage_after_cap']);
                    $stats[$turn]['cap_samples']++;
                    $stats[$turn]['cap_hits'] += (int) ($reduction > 0);
                    $stats[$turn]['cap_reduction_total'] += $reduction;
                }
            }
        }

        return collect($stats)->map(function (array $turn): array {
            $samples = $turn['samples'];

            return [
                'turn' => $turn['turn'],
                'samples' => $samples,
                'reach_rate' => null,
                'average_player_damage' => $samples > 0 ? (int) round($turn['player_damage_total'] / $samples) : 0,
                'average_boss_damage' => $samples > 0 ? (int) round($turn['boss_damage_total'] / $samples) : 0,
                'deaths' => $turn['deaths'],
                'death_rate' => $this->rate($turn['deaths'], $samples),
                'telegraphs' => $turn['telegraphs'],
                'cap_samples' => $turn['cap_samples'],
                'cap_hits' => $turn['cap_hits'],
                'cap_hit_rate' => $this->rate($turn['cap_hits'], $turn['cap_samples']),
                'cap_reduction_total' => $turn['cap_reduction_total'],
            ];
        })->values()->map(function (array $turn) use ($rows): array {
            $turn['reach_rate'] = $this->rate($turn['samples'], $rows->count());

            return $turn;
        })->all();
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function damageSourceStats(Collection $rows): array
    {
        $totals = array_fill_keys(array_keys(self::DAMAGE_SOURCE_LABELS), 0);
        foreach ($rows as $row) {
            foreach (($row->damage_by_source ?? []) as $key => $value) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += max(0, (int) $value);
                }
            }
        }
        $grandTotal = array_sum($totals);

        return collect($totals)->map(fn (int $total, string $key): array => [
            'source' => $key,
            'label' => self::DAMAGE_SOURCE_LABELS[$key],
            'damage' => $total,
            'share' => $this->rate($total, $grandTotal),
        ])->sortByDesc('damage')->values()->all();
    }

    /** 装備効果の出撃分布。所持者間の能力差を含むので因果的な倍率比較には使わない。 */
    private function equipmentEffectStats(Collection $rows): array
    {
        $observed = $rows->filter(fn ($row): bool => is_numeric($row->event_snapshot['killer_raw_rate'] ?? null)
            && is_numeric($row->event_snapshot['killer_effective_rate'] ?? null));
        $rates = $observed->groupBy(fn ($row): string => number_format((float) $row->event_snapshot['killer_effective_rate'] * 100, 4, '.', ''))
            ->map(fn ($group, $rate): array => ['effective_percent' => (float) $rate, 'sorties' => $group->count()])
            ->sortBy('effective_percent')->values()->all();

        return [
            'observed_sorties' => $observed->count(), 'unavailable_sorties' => $rows->count() - $observed->count(),
            'matched_sorties' => $observed->filter(fn ($row): bool => $row->event_snapshot['killer_effective_rate'] > 0)->count(),
            'unmatched_sorties' => $observed->filter(fn ($row): bool => $row->event_snapshot['killer_effective_rate'] <= 0)->count(),
            'raw_rate_max' => $observed->max(fn ($row) => $row->event_snapshot['killer_raw_rate']),
            'effective_rate_max' => $observed->max(fn ($row) => $row->event_snapshot['killer_effective_rate']),
            'cap_before_rate_max' => $observed->max(fn ($row) => isset($row->event_snapshot['killer_rate_multiplier'])
                ? $row->event_snapshot['killer_raw_rate'] * $row->event_snapshot['killer_rate_multiplier'] : null),
            'cap_reached_sorties' => $observed->filter(fn ($row): bool => isset($row->event_snapshot['killer_rate_cap'])
                && $row->event_snapshot['killer_effective_rate'] >= $row->event_snapshot['killer_rate_cap'])->count(),
            'effective_rate_distribution' => $rates,
            'resistance_observed_sorties' => $rows->filter(fn ($row): bool => is_numeric($row->event_snapshot['armor_resistance_rate'] ?? null))->count(),
            'resistance_matched_sorties' => $rows->filter(fn ($row): bool => ($row->event_snapshot['armor_resistance_rate'] ?? 0) > 0)->count(),
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function counterplayStats(Collection $rows): array
    {
        $totals = array_fill_keys(array_keys(self::COUNTERPLAY_LABELS), 0);
        foreach ($rows as $row) {
            foreach (($row->counterplay_metrics ?? []) as $key => $value) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += max(0, (int) $value);
                }
            }
        }
        $denialKeys = ['aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay', 'turn_20_delay', 'denial_overlap'];

        return collect($totals)->map(function (int $count, string $key) use ($rows, $denialKeys): array {
            // 旧schemaの「項目なし」は0回ではない。項目ごとに観測済みの分母を使う。
            $observed = $rows->filter(fn ($row): bool => array_key_exists($key, $row->counterplay_metrics ?? []));
            $telegraphs = (int) $observed->sum(fn ($row): int => (int) ($row->counterplay_metrics['telegraphs_seen'] ?? 0));
            $twenty = $observed->where('reached_turn_twenty', true)->count();

            return [
                'metric' => $key, 'label' => self::COUNTERPLAY_LABELS[$key], 'count' => $count,
                'observed_sorties' => $observed->count(),
                'per_telegraph_rate' => $key === 'telegraphs_seen' || in_array($key, $denialKeys, true) ? null : $this->rate($count, $telegraphs),
                'per_turn_twenty_rate' => in_array($key, $denialKeys, true) ? $this->rate($count, $twenty) : null,
            ];
        })->values()->all();
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return list<array<string, mixed>> */
    private function nationSizeStats(Collection $rows): array
    {
        $labels = [
            'unaffiliated_or_ineligible' => '無所属・国家集計外',
            '1' => '1人国家',
            '2_3' => '2〜3人',
            '4_6' => '4〜6人',
            '7_10' => '7〜10人',
            '11_20' => '11〜20人',
            '21_plus' => '21人以上',
        ];

        return $rows->groupBy(fn ($row): string => $this->nationSizeBucket($row))
            ->map(fn (Collection $bucketRows, string $bucket): array => [
                'bucket' => $bucket,
                'label' => $labels[$bucket] ?? $bucket,
                ...$this->compactGroupSummary($bucketRows),
            ])->sortByDesc('sorties')->values()->all();
    }

    /**
     * 国家名・IDを出力せず、総ダメージ順位順の匿名ラベルで国家間の偏りを示す。
     *
     * @param  Collection<int, NationRaidBattleTelemetryLog>  $rows
     * @return list<array<string, mixed>>
     */
    private function nationCompetitionStats(Collection $rows): array
    {
        $eligible = $rows->filter(fn (NationRaidBattleTelemetryLog $row): bool => $row->is_nation_eligible && $row->nation_id !== null
        );
        $grandTotal = (int) $eligible->sum(fn ($row): int => (int) ($row->event_snapshot['nation_damage'] ?? $row->applied_damage_total));

        return $eligible
            ->groupBy('nation_id')
            ->map(function (Collection $nationRows): array {
                $participants = $nationRows->pluck('character_id')->filter()->unique()->count();
                $activeCountSnapshots = $nationRows->pluck('nation_active_count')->map(fn ($value): int => (int) $value)->unique()->values();
                $activeCount = (int) ($activeCountSnapshots->first() ?? 0);
                $activeCountIsUsable = $activeCountSnapshots->count() === 1 && $activeCount > 0;
                $totalDamage = (int) $nationRows->sum(fn ($row): int => (int) $row->applied_damage_total);
                $nationDamage = (int) $nationRows->sum(fn ($row): int => (int) ($row->event_snapshot['nation_damage'] ?? $row->applied_damage_total));

                return [
                    'sorties' => $nationRows->count(),
                    'participants' => $participants,
                    'active_count_snapshot' => $activeCount,
                    'active_count_snapshot_consistent' => $activeCountIsUsable,
                    'total_damage' => $nationDamage,
                    'personal_damage' => $totalDamage,
                    'coordination_damage' => $nationDamage - $totalDamage,
                    'damage_per_sortie' => $nationRows->isNotEmpty() ? (int) round($totalDamage / $nationRows->count()) : 0,
                    'damage_per_participant' => $participants > 0 ? (int) round($totalDamage / $participants) : 0,
                    'damage_per_active_member' => $activeCountIsUsable ? (int) round($totalDamage / $activeCount) : null,
                    'max_sortie_damage' => (int) ($nationRows->max('applied_damage_total') ?? 0),
                    'max_action_damage' => (int) ($nationRows->max('max_action_damage') ?? 0),
                ];
            })
            ->sortByDesc('total_damage')
            ->values()
            ->map(fn (array $row, int $index): array => [
                'anonymous_nation' => '国家順位'.($index + 1),
                'damage_share' => $this->rate($row['total_damage'], $grandTotal),
                ...$row,
            ])
            ->all();
    }

    /**
     * 個人を特定せず、上位依存と1出撃・1行動の突出を示す。
     *
     * @param  Collection<int, NationRaidBattleTelemetryLog>  $rows
     * @return array<string, mixed>
     */
    private function participantDistribution(Collection $rows): array
    {
        $participants = $rows
            ->filter(fn (NationRaidBattleTelemetryLog $row): bool => $row->character_id !== null)
            ->groupBy('character_id')
            ->map(function (Collection $participantRows): array {
                $totalDamage = (int) $participantRows->sum(fn ($row): int => (int) $row->applied_damage_total);

                return [
                    'sorties' => $participantRows->count(),
                    'total_damage' => $totalDamage,
                    'damage_per_sortie' => $participantRows->isNotEmpty() ? (int) round($totalDamage / $participantRows->count()) : 0,
                    'max_sortie_damage' => (int) ($participantRows->max('applied_damage_total') ?? 0),
                    'max_action_damage' => (int) ($participantRows->max('max_action_damage') ?? 0),
                ];
            })
            ->sortByDesc('total_damage')
            ->values();

        $totals = $participants->pluck('total_damage')->map(fn ($value): int => (int) $value)->all();
        $grandTotal = array_sum($totals);
        $topTenCount = $participants->isEmpty() ? 0 : max(1, (int) ceil($participants->count() * 0.10));

        return [
            'participants' => $participants->count(),
            'average_cumulative_damage' => $this->average($totals),
            'median_cumulative_damage' => $this->percentile($totals, 0.50),
            'p90_cumulative_damage' => $this->percentile($totals, 0.90),
            'max_cumulative_damage' => (int) ($participants->max('total_damage') ?? 0),
            'average_sorties' => $this->average($participants->pluck('sorties')->map(fn ($value): int => (int) $value)->all(), 2),
            'max_sortie_damage' => (int) ($participants->max('max_sortie_damage') ?? 0),
            'max_action_damage' => (int) ($participants->max('max_action_damage') ?? 0),
            'top_one_damage_share' => $this->rate((int) ($participants->first()['total_damage'] ?? 0), $grandTotal),
            'top_ten_percent_count' => $topTenCount,
            'top_ten_percent_damage_share' => $this->rate(
                (int) $participants->take($topTenCount)->sum('total_damage'),
                $grandTotal,
            ),
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return array<string, mixed> */
    private function rewardReach(Collection $rows, array $eventSnapshot): array
    {
        $validSorties = max(0, (int) ($eventSnapshot['valid_participation_sorties'] ?? 0));
        $rawThresholds = $eventSnapshot['reward_thresholds']['damage'] ?? [];
        $thresholds = is_array($rawThresholds)
            ? array_values(array_unique(array_filter(array_map('intval', $rawThresholds), fn (int $value): bool => $value > 0)))
            : [];
        sort($thresholds);

        $participants = [];
        $unlinkedRows = 0;
        foreach ($rows as $row) {
            if ($row->character_id === null) {
                $unlinkedRows++;

                continue;
            }
            $key = (string) $row->character_id;
            $participants[$key] ??= ['sorties' => 0, 'damage' => 0];
            $participants[$key]['sorties']++;
            $participants[$key]['damage'] += (int) $row->applied_damage_total;
        }

        $valid = array_filter($participants, fn (array $row): bool => $validSorties > 0 && $row['sorties'] >= $validSorties);

        return [
            'valid_participation_sorties' => $validSorties,
            'damage_thresholds' => $thresholds,
            'linked_participants' => count($participants),
            'valid_participants' => count($valid),
            'valid_participation_rate' => $this->rate(count($valid), count($participants)),
            'threshold_reach' => array_map(fn (int $threshold): array => [
                'damage' => $threshold,
                'participants' => count(array_filter($valid, fn (array $row): bool => $row['damage'] >= $threshold)),
                'rate_among_valid' => $this->rate(
                    count(array_filter($valid, fn (array $row): bool => $row['damage'] >= $threshold)),
                    count($valid),
                ),
            ], $thresholds),
            'unlinked_sorties' => $unlinkedRows,
            'definition_available' => $validSorties > 0 && $thresholds !== [],
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return array<string, mixed> */
    private function powerQuantiles(Collection $rows): array
    {
        $powers = $rows->pluck('player_power')->filter(fn ($value): bool => $value !== null)->map(fn ($v) => (int) $v)->all();
        if ($powers === []) {
            return ['sample_count' => 0, 'p25' => null, 'median' => null, 'p75' => null, 'p90' => null];
        }

        return [
            'sample_count' => count($powers),
            'p25' => $this->percentile($powers, 0.25),
            'median' => $this->percentile($powers, 0.50),
            'p75' => $this->percentile($powers, 0.75),
            'p90' => $this->percentile($powers, 0.90),
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return array<string, mixed> */
    private function dataQuality(Collection $rows): array
    {
        $flagCounts = [];
        $missingTurnDetails = 0;
        foreach ($rows as $row) {
            foreach ($row->quality_flags ?? [] as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }
            if ($row->result_status === 'resolved'
                && (int) $row->turn_count > 0
                && count($row->turns ?? []) !== (int) $row->turn_count
            ) {
                $missingTurnDetails++;
            }
        }

        $rulesets = $rows->pluck('ruleset_version')->unique()->values()->all();
        $schemaVersions = $rows->pluck('telemetry_schema_version')->unique()->values()->all();
        $rulesetHashes = $rows->map(fn ($row) => $row->event_snapshot['ruleset_hash'] ?? null)->filter()->unique()->values()->all();
        $eligibleNationRows = $rows->filter(fn (NationRaidBattleTelemetryLog $row): bool => $row->is_nation_eligible && $row->nation_id !== null
        );
        $inconsistentNationActiveCounts = $eligibleNationRows
            ->groupBy('nation_id')
            ->filter(fn (Collection $nationRows): bool => $nationRows->pluck('nation_active_count')->unique()->count() > 1)
            ->count();
        $missingNationActiveCountRows = $eligibleNationRows
            ->where('nation_active_count', 0)
            ->count();
        $warnings = [];
        if ($rows->where('result_status', 'resolved')->count() < 30) {
            $warnings[] = 'resolved出撃が30未満のため、系譜差・分位差を断定しない。';
        }
        if ($missingTurnDetails > 0) {
            $warnings[] = 'turn_countと詳細turn数が一致しない出撃がある。ターン別判断の前に計測hookを確認する。';
        }
        if (count($rulesets) > 1 || count($rulesetHashes) > 1) {
            $warnings[] = '複数rulesetが混在している。ruleset別に分けず単純比較しない。';
        }
        if ($rows->whereNull('character_id')->isNotEmpty()) {
            $warnings[] = 'Character参照消失行があるため、参加・報酬到達人数は過少になる可能性がある。';
        }
        if ($inconsistentNationActiveCounts > 0) {
            $warnings[] = '同一国家で開始時active人数snapshotが揺れている。国家一人あたり値の前にsnapshot作成処理を確認する。';
        }
        if ($missingNationActiveCountRows > 0) {
            $warnings[] = '国家集計対象なのに開始時active人数が0の出撃がある。国家一人あたり値は算出不能として扱う。';
        }
        if (isset($flagCounts['player_hit_critical_counts_unavailable'])) {
            $warnings[] = 'プレイヤー側の命中・会心数は未計測。0回という観測結果には使わない。';
        }
        if (isset($flagCounts['player_turn_observation_missing']) || isset($flagCounts['phase4_turn_metrics_not_adapted'])) {
            $warnings[] = '旧出撃に手番観測の欠損がある。再戦闘で補完せず、回復・SP消費・使用戦技の判断から除外する。';
        }
        if ($rows->whereNull('player_power')->isNotEmpty()) {
            $warnings[] = '戦力指標が未計測の出撃がある。能力値snapshotは参照できるが、独自の戦力式で埋めない。';
        }

        ksort($flagCounts);

        return [
            'records' => $rows->count(),
            'schema_versions' => $schemaVersions,
            'ruleset_versions' => $rulesets,
            'ruleset_hashes' => $rulesetHashes,
            'missing_character_rows' => $rows->whereNull('character_id')->count(),
            'missing_event_snapshot_rows' => $rows->filter(fn ($row) => empty($row->event_snapshot))->count(),
            'turn_detail_mismatch_rows' => $missingTurnDetails,
            'inconsistent_nation_active_count_groups' => $inconsistentNationActiveCounts,
            'missing_nation_active_count_rows' => $missingNationActiveCountRows,
            'quality_flag_counts' => $flagCounts,
            'calculated_but_not_applied_damage' => $rows->sum(fn ($row): int => max(
                0,
                (int) $row->calculated_damage_total - (int) $row->applied_damage_total,
            )),
            'warnings' => $warnings,
        ];
    }

    /** @param Collection<int, NationRaidBattleTelemetryLog> $rows @return array<string, mixed> */
    private function compactGroupSummary(Collection $rows): array
    {
        $damages = $rows->pluck('applied_damage_total')->map(fn ($v) => (int) $v)->all();

        return [
            'sorties' => $rows->count(),
            'unique_characters' => $rows->pluck('character_id')->filter()->unique()->count(),
            'total_damage' => array_sum($damages),
            'average_damage' => $this->average($damages),
            'median_damage' => $this->percentile($damages, 0.50),
            'average_turns' => $this->average($rows->pluck('turn_count')->map(fn ($v) => (int) $v)->all(), 2),
            'turn_twenty_rate' => $this->rate($rows->where('reached_turn_twenty', true)->count(), $rows->count()),
            'defeat_rate' => $this->rate($rows->where('end_reason', 'player_defeated')->count(), $rows->count()),
        ];
    }

    private function nationSizeBucket(NationRaidBattleTelemetryLog $row): string
    {
        if (! $row->is_nation_eligible || $row->nation_id === null) {
            return 'unaffiliated_or_ineligible';
        }
        $count = (int) $row->nation_active_count;

        return match (true) {
            $count <= 1 => '1',
            $count <= 3 => '2_3',
            $count <= 6 => '4_6',
            $count <= 10 => '7_10',
            $count <= 20 => '11_20',
            default => '21_plus',
        };
    }

    private function phaseLabel(string $phase): string
    {
        return self::PHASE_LABELS[$phase] ?? $phase;
    }

    /** @param list<int|float> $values */
    private function average(array $values, int $precision = 0): float|int
    {
        if ($values === []) {
            return 0;
        }

        $average = array_sum($values) / count($values);

        return $precision > 0 ? round($average, $precision) : (int) round($average);
    }

    /** @param list<int|float> $values */
    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $position = (count($values) - 1) * $percentile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }
        $fraction = $position - $lower;

        return round((float) $values[$lower] + ((float) $values[$upper] - (float) $values[$lower]) * $fraction, 2);
    }

    private function rate(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : null;
    }

    /** @param array<string, mixed> $filters @param list<array<string, mixed>> $eventOptions @return array<string, mixed> */
    private function emptyAnalysis(array $filters, array $eventOptions): array
    {
        $analysis = [
            'table_available' => $this->tableExists(),
            'has_records' => false,
            'event_options' => $eventOptions,
            'filter_options' => ['phases' => [], 'lineages' => collect(self::LINEAGE_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all()],
            'filters' => $filters,
            'event_snapshot' => [],
            'metric_definitions' => $this->metricDefinitions(),
            'summary' => [],
            'daily' => [],
            'phases' => [],
            'lineages' => [],
            'turns' => [],
            'damage_sources' => [],
            'equipment_effects' => [],
            'counterplay' => [],
            'nation_sizes' => [],
            'nation_competition' => [],
            'participant_distribution' => [],
            'reward_reach' => [],
            'power_quantiles' => [],
            'data_quality' => ['warnings' => ['対象データがまだありません。']],
        ];
        $analysis['codex_prompt'] = $this->codexPrompt($analysis);

        return $analysis;
    }

    private function tableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            return $this->tableExists = Schema::hasTable('nation_raid_battle_telemetry');
        } catch (\Throwable) {
            return $this->tableExists = false;
        }
    }
}
