<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Support\JobArtEffectCatalog;
use Illuminate\Validation\ValidationException;

final class JobArtV2StrategyService
{
    public const MODE_AUTO = 'auto';

    public const MODE_CUSTOM = 'custom';

    public const OUTPUT_NONE = 'none';

    public const OUTPUT_LOW = 'low';

    public const OUTPUT_STANDARD = 'standard';

    public const OUTPUT_HIGH = 'high';

    public const OUTPUT_MAX = 'max';

    /** @var list<string> */
    private const MODES = [self::MODE_AUTO, self::MODE_CUSTOM];

    /** @var list<string> */
    private const OUTPUTS = [
        self::OUTPUT_NONE,
        self::OUTPUT_LOW,
        self::OUTPUT_STANDARD,
        self::OUTPUT_HIGH,
        self::OUTPUT_MAX,
    ];

    /** @var array<string, array{label: string, description: string, options: array<string, string>}> */
    private const SETTING_DEFINITIONS = [
        'base_priority' => [
            'label' => '普段の戦技',
            'description' => '特別な状況がない時に、装備中のどの段階の戦技を優先するかを選びます。',
            'options' => [
                'balanced' => '標準の巡回順',
                'starter' => '始動を優先',
                'combo' => '連携を優先',
            ],
        ],
        'resource_policy' => [
            'label' => '資源の運用',
            'description' => '装備中の戦技から、資源を増やす始動と消費する連携のどちらを優先するかを選びます。',
            'options' => [
                'balanced' => '均衡',
                'build' => '資源の蓄積を優先',
                'spend' => '資源の消費を優先',
            ],
        ],
        'ultimate_policy' => [
            'label' => '奥義の発動',
            'description' => '装備中の奥義が必要資源・SPなどの条件をすべて満たした後の扱いです。より優先度の高い対策・浄化・回復・防御型戦技がある時は、そちらを先に使います。',
            'options' => [
                'ready_guaranteed' => '満タン後の初回奥義を優先（発動判定100%）',
                'normal_rate' => '準備完了後も通常発動率',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'heal_policy' => [
            'label' => '回復型戦技の優先',
            'description' => '装備中のHP回復効果を持つ戦技を優先し始める条件です。回復行動を新しく追加する設定ではありません。',
            'options' => [
                'hp_50' => 'HP50%以下で優先',
                'hp_30' => 'HP30%以下で優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'guard_policy' => [
            'label' => '防御型戦技の優先',
            'description' => '装備中の軽減・バリア・受け流し・防御強化を持つ戦技を優先する条件です。通常防御を追加する設定ではありません。',
            'options' => [
                'telegraph' => '敵の大技予告中に優先',
                'hp_50' => 'HP50%以下で優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'cleanse_policy' => [
            'label' => '浄化型戦技の優先',
            'description' => '装備中の浄化効果を持つ戦技を、有害状態や崩し印がある時に優先するかを選びます。',
            'options' => [
                'immediate' => '浄化できる時は優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'buff_policy' => [
            'label' => '強化型戦技の優先',
            'description' => '装備中の自分を強化する戦技を、通常の巡回順より先に判定するかを選びます。',
            'options' => [
                'priority' => '強化型戦技を優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'debuff_policy' => [
            'label' => '弱体型戦技の優先',
            'description' => '装備中の相手を弱体化する戦技を、通常の巡回順より先に判定するかを選びます。',
            'options' => [
                'priority' => '弱体型戦技を優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
        'counter_policy' => [
            'label' => '対奥義連携の優先',
            'description' => '相手の奥義予告または敵の大技予告に対応できる、装備中の対策戦技を優先するかを選びます。',
            'options' => [
                'priority' => '対応できる時は最優先',
                'slot_order' => '特別扱いせず巡回順',
            ],
        ],
    ];

    private readonly JobArtV2EffectClassifier $effectClassifier;

    public function __construct(
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2CleanseService $cleanseService,
        private readonly JobArtV2UltimateCounterplayService $ultimateCounterplayService,
        ?JobArtV2EffectClassifier $effectClassifier = null,
        private readonly ?JobArtV2FeatureGate $featureGate = null,
    ) {
        $this->effectClassifier = $effectClassifier ?? app(JobArtV2EffectClassifier::class);
    }

    /** @return array<string, string> */
    public function modeLabels(): array
    {
        return [
            self::MODE_AUTO => 'おまかせ',
            self::MODE_CUSTOM => 'こだわり設定',
        ];
    }

    /** @return array<string, string> */
    public function outputLabels(): array
    {
        return [
            self::OUTPUT_NONE => 'なし',
            self::OUTPUT_LOW => '低い',
            self::OUTPUT_STANDARD => '標準',
            self::OUTPUT_HIGH => '高い',
            self::OUTPUT_MAX => 'MAX',
        ];
    }

    /** @return array<string, array{label: string, description: string, options: array<string, string>}> */
    public function settingDefinitions(): array
    {
        return self::SETTING_DEFINITIONS;
    }

    /** @return array<string, string> */
    public function autoSettings(): array
    {
        return [
            'base_priority' => 'balanced',
            'resource_policy' => 'balanced',
            'ultimate_policy' => 'ready_guaranteed',
            'heal_policy' => 'hp_30',
            'guard_policy' => 'telegraph',
            'cleanse_policy' => 'immediate',
            'buff_policy' => 'slot_order',
            'debuff_policy' => 'slot_order',
            'counter_policy' => 'priority',
        ];
    }

    /** @return array<string, string> */
    public function currentBehaviorSettings(): array
    {
        return [
            'base_priority' => 'balanced',
            'resource_policy' => 'balanced',
            'ultimate_policy' => 'normal_rate',
            'heal_policy' => 'slot_order',
            'guard_policy' => 'slot_order',
            'cleanse_policy' => 'slot_order',
            'buff_policy' => 'slot_order',
            'debuff_policy' => 'slot_order',
            'counter_policy' => 'priority',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $storedSettings
     * @return array{mode: string, sp_policy: string, sp_output: string, settings: array<string, string>}
     */
    public function resolve(string $mode, ?array $storedSettings, string $spPolicy): array
    {
        // Missing/corrupt data must preserve the pre-strategy candidate order.
        $mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_CUSTOM;
        $spOutput = $this->normalizeOutput($storedSettings['sp_output'] ?? null);
        $base = $mode === self::MODE_AUTO
            ? $this->autoSettings()
            : $this->currentBehaviorSettings();

        if ($mode === self::MODE_CUSTOM) {
            foreach (self::SETTING_DEFINITIONS as $key => $definition) {
                $value = $storedSettings[$key] ?? null;
                if (is_string($value) && array_key_exists($value, $definition['options'])) {
                    $base[$key] = $value;
                }
            }
        }

        // SP出力は「おまかせ／こだわり設定」と独立したcontext設定。
        // 自動戦術でも保存値を採用し、候補順の切替では上書きしない。
        $base['sp_output'] = $spOutput;

        return [
            'mode' => $mode,
            'sp_policy' => $mode === self::MODE_AUTO
                ? 'aggressive'
                : (in_array($spPolicy, ['aggressive', 'normal', 'conserve'], true)
                    ? $spPolicy
                    : 'aggressive'),
            'sp_output' => $spOutput,
            'settings' => $base,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public function validateCustomSettings(array $settings): array
    {
        $validated = [];
        foreach (self::SETTING_DEFINITIONS as $key => $definition) {
            $value = $settings[$key] ?? null;
            if (! is_string($value) || ! array_key_exists($value, $definition['options'])) {
                throw ValidationException::withMessages([
                    "strategy_settings.{$key}" => $definition['label'].'の選択が正しくありません。',
                ]);
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    public function validateMode(string $mode): string
    {
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['strategy_mode' => '戦略モードが正しくありません。']);
        }

        return $mode;
    }

    public function validateOutput(string $output): string
    {
        if (! in_array($output, self::OUTPUTS, true)) {
            throw ValidationException::withMessages(['sp_output' => 'SP出力の選択が正しくありません。']);
        }

        return $output;
    }

    public function normalizeOutput(mixed $output): string
    {
        return is_string($output) && in_array($output, self::OUTPUTS, true)
            ? $output
            : self::OUTPUT_NONE;
    }

    /**
     * @param  list<Skill>  $candidates
     * @param  callable(Skill): bool  $isReadyUltimate
     * @param  callable(Skill): bool  $isResponseCandidate
     * @return list<Skill>
     */
    public function orderCandidates(
        BattleActor $actor,
        BattleState $state,
        array $candidates,
        callable $isReadyUltimate,
        callable $isResponseCandidate,
    ): array {
        $profile = $this->profileFor($actor);
        if ($profile === null || $candidates === []) {
            return $candidates;
        }

        $settings = $profile['settings'];
        $hpRate = $actor->maxHp > 0 ? $actor->hp / $actor->maxHp : 0.0;
        $canCleanse = $this->cleanseService->canCleanse($actor);
        $hasTelegraph = $this->ultimateCounterplayService->pveTelegraphAvailable($actor, $state);
        $currentJobId = $actor->currentJobId;
        $indexed = [];

        foreach ($candidates as $index => $skill) {
            $tier = 0;
            $secondary = 0;
            $role = $this->resourceCatalog->roleForActorArt($actor, $skill);

            if ($settings['counter_policy'] === 'priority' && $isResponseCandidate($skill)) {
                $tier = max($tier, 100);
            }
            if ($settings['cleanse_policy'] === 'immediate' && $canCleanse && $this->isCleanseArt($skill, $currentJobId)) {
                $tier = max($tier, 90);
            }
            if ($this->shouldPrioritizeHealing($settings['heal_policy'], $hpRate) && $this->isHealingArt($skill, $currentJobId)) {
                $tier = max($tier, 80);
            }
            if ($this->shouldPrioritizeGuard($settings['guard_policy'], $hpRate, $hasTelegraph)
                && $this->isGuardArt($skill, $currentJobId)
            ) {
                $tier = max($tier, 75);
            }
            if ($settings['ultimate_policy'] !== 'slot_order' && $isReadyUltimate($skill)) {
                $tier = max($tier, 70);
            }
            if ($settings['buff_policy'] === 'priority' && $this->isBuffArt($skill, $currentJobId)) {
                $tier = max($tier, 40);
            }
            if ($settings['debuff_policy'] === 'priority' && $this->isDebuffArt($skill, $currentJobId)) {
                $tier = max($tier, 40);
            }

            if ($settings['resource_policy'] === 'build' && $role === ResourceRole::PRODUCER) {
                $secondary += 20;
            } elseif ($settings['resource_policy'] === 'spend' && $role === ResourceRole::CONSUMER) {
                $secondary += 20;
            }

            if ($settings['base_priority'] === 'starter' && $role === ResourceRole::PRODUCER) {
                $secondary += 10;
            } elseif ($settings['base_priority'] === 'combo' && $role === ResourceRole::CONSUMER) {
                $secondary += 10;
            }

            $indexed[] = compact('skill', 'tier', 'secondary', 'index');
        }

        usort($indexed, static fn (array $left, array $right): int =>
            ($right['tier'] <=> $left['tier'])
            ?: ($right['secondary'] <=> $left['secondary'])
            ?: ($left['index'] <=> $right['index'])
        );

        return array_values(array_map(static fn (array $row): Skill => $row['skill'], $indexed));
    }

    /** @param callable(): bool $isReadyUltimate */
    public function guaranteedUltimateRate(BattleActor $actor, Skill $skill, callable $isReadyUltimate): ?int
    {
        $profile = $this->profileFor($actor);
        if ($profile === null
            || $profile['settings']['ultimate_policy'] !== 'ready_guaranteed'
            || (int) $skill->learn_rank !== 9
            || ! $isReadyUltimate()
        ) {
            return null;
        }

        return 100;
    }

    /** @return array{mode: string, sp_policy: string, sp_output: string, settings: array<string, string>}|null */
    public function profileFor(BattleActor $actor): ?array
    {
        if (! ($this->featureGate ?? app(JobArtV2FeatureGate::class))->usesDetailedStrategy($actor)) {
            return null;
        }

        $profile = $actor->jobArtStrategy;
        if (! is_array($profile) || ! isset($profile['mode'], $profile['settings'])) {
            return null;
        }

        return $this->resolve(
            (string) $profile['mode'],
            is_array($profile['settings']) ? $profile['settings'] : null,
            (string) ($profile['sp_policy'] ?? 'aggressive'),
        );
    }

    private function shouldPrioritizeHealing(string $policy, float $hpRate): bool
    {
        return match ($policy) {
            'hp_50' => $hpRate <= 0.50,
            'hp_30' => $hpRate <= 0.30,
            default => false,
        };
    }

    private function shouldPrioritizeGuard(string $policy, float $hpRate, bool $hasTelegraph): bool
    {
        return match ($policy) {
            'telegraph' => $hasTelegraph,
            'hp_50' => $hpRate <= 0.50,
            default => false,
        };
    }

    private function isHealingArt(Skill $skill, ?int $currentJobId): bool
    {
        return $this->effectClassifier->isHealingArt($skill, $currentJobId);
    }

    private function isCleanseArt(Skill $skill, ?int $currentJobId): bool
    {
        return $this->effectClassifier->isCleanseArt($skill, $currentJobId);
    }

    private function isGuardArt(Skill $skill, ?int $currentJobId): bool
    {
        return $this->effectClassifier->isGuardArt($skill, $currentJobId);
    }

    private function isBuffArt(Skill $skill, ?int $currentJobId): bool
    {
        return $this->effectClassifier->isBuffArt($skill, $currentJobId);
    }

    private function isDebuffArt(Skill $skill, ?int $currentJobId): bool
    {
        return $this->effectClassifier->isDebuffArt($skill, $currentJobId);
    }
}
