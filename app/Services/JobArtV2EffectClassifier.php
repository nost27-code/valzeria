<?php

namespace App\Services;

use App\Models\Skill;
use App\Support\JobArtEffectCatalog;

/**
 * 戦技v2の効果区分を判定する共通正本。
 *
 * 戦略の候補優先順（JobArtV2StrategyService）と対人戦技集約の計測が同じ判定を
 * 共有する。静的な JobArtEffectCatalog だけでは v2 の override を拾えないため、
 * 冠位balanceの実行値・runtime templateの置換・role effect metadataを併用する。
 *
 * 複合効果は単一の主区分へ丸めず、該当するtagをすべて返す（`categoriesFor()`）。
 * 単一区分が要る集計は、tag集合から後段で導出する。
 */
final class JobArtV2EffectClassifier
{
    /**
     * 判定ロジックのversion。集約行へ保存し、baseline期間中の固定を検査する。
     * 判定の定義を変更したら必ず更新する。
     */
    public const VERSION = 'v2';

    public const CATEGORY_DAMAGE = 'damage';

    public const CATEGORY_HEAL = 'heal';

    public const CATEGORY_GUARD = 'guard';

    public const CATEGORY_CLEANSE = 'cleanse';

    public const CATEGORY_BUFF = 'buff';

    public const CATEGORY_DEBUFF = 'debuff';

    public const CATEGORY_OTHER = 'other';

    public const BUILD_OFFENSE_HEAVY = 'offense_heavy';

    public const BUILD_SUSTAIN_INCLUDING = 'sustain_including';

    public const BUILD_MIXED = 'mixed';

    /**
     * 判定材料のcache。戦闘ループでは1手番あたり数十回判定するため、
     * 同じ戦技・同じ現在職の冠位balance cloneとtemplate解決を繰り返さない。
     *
     * @var \WeakMap<Skill, array<string, array{0: Skill, 1: string}>>|null
     */
    private ?\WeakMap $resolvedCache = null;

    public function __construct(
        private readonly JobArtV2RoleEffectCatalog $roleEffectCatalog,
        private readonly JobArtV2CrownBalanceCatalog $crownBalanceCatalog,
        private readonly JobArtV2EffectSemanticsResolver $effectSemanticsResolver,
    ) {
    }

    public function isHealingArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [$balanced, $template] = $this->resolve($skill, $currentJobId);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];

        return $balanced->isHealArt()
            || in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)
            || (int) $balanced->heal_percent > 0
            || ($template === 'DRAIN' && (float) $balanced->drain_hp_rate > 0)
            || is_array($metadata['heal'] ?? null)
            || is_array($metadata['adaptive_sustain'] ?? null);
    }

    public function isCleanseArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [, $template] = $this->resolve($skill, $currentJobId);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];

        return $template === 'HEAL_CLEANSE'
            || is_array($metadata['cleanse'] ?? null);
    }

    public function isGuardArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [$balanced, $template] = $this->resolve($skill, $currentJobId);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];
        $timedModifiers = $metadata['timed_effect']['modifiers'] ?? [];
        $hasDefensiveModifier = is_array($timedModifiers)
            && ((float) ($timedModifiers['def'] ?? 0) > 0 || (float) ($timedModifiers['spr'] ?? 0) > 0);

        return in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER', 'GUTS'], true)
            || (int) $balanced->damage_reduction_percent > 0
            || is_array($metadata['guard'] ?? null)
            || $hasDefensiveModifier;
    }

    public function isBuffArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [$balanced, $template] = $this->resolve($skill, $currentJobId);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];

        return in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)
            || (int) $balanced->self_buff_percent > 0
            || $this->crownBalanceCatalog->hasSelfBuff($skill)
            || is_array($metadata['timed_effect'] ?? null)
            || is_array($metadata['prepared_effect'] ?? null);
    }

    public function isDebuffArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [$balanced, $template] = $this->resolve($skill, $currentJobId);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];
        $masterDebuff = collect([
            $balanced->enemy_atk_down_percent,
            $balanced->enemy_mag_down_percent,
            $balanced->enemy_def_down_percent,
            $balanced->enemy_spr_down_percent,
            $balanced->enemy_spd_down_percent,
        ])->contains(static fn ($value): bool => (int) $value > 0);

        return in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true)
            || $masterDebuff
            || is_array($metadata['structured_debuff'] ?? null)
            || is_array($metadata['break_debuff'] ?? null);
    }

    /**
     * damage分類。runtime置換で V2_ROLE_EFFECT_ONLY 等になる戦技を取りこぼさないため、
     * role effect metadata の execution_power も参照する。
     */
    public function isDamageArt(Skill $skill, ?int $currentJobId = null): bool
    {
        [, $template] = $this->resolve($skill, $currentJobId);
        if ($template !== '' && JobArtEffectCatalog::has($template) && JobArtEffectCatalog::dealsDamage($template)) {
            return true;
        }

        return ($this->roleEffectCatalog->executionPower($skill) ?? 0) > 0;
    }

    /**
     * SP出力用の直接damage判定。
     *
     * 現在職に依存するdisplay置換は使わず、カード自身のruntime metadataを
     * 正本にする。これにより 22:5 / 23:1 / 70:5 を含め、本職と継承で
     * 可変費・威力が変わらない。
     */
    public function isSpOutputDamageArt(Skill $skill): bool
    {
        $balanced = $this->crownBalanceCatalog->applyToExecution($skill);
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];
        $rank5Metadata = $this->roleEffectCatalog->rank5V6MetadataForArt($skill) ?? [];
        $metadata = array_replace_recursive($metadata, $rank5Metadata);
        $template = is_string($metadata['replacement_template'] ?? null)
            ? (string) $metadata['replacement_template']
            : (string) $balanced->effect_template;
        $intrinsicPower = max(
            0,
            (int) $balanced->power,
            (int) round((float) $balanced->power_multiplier * 100),
            is_numeric($metadata['execution_power'] ?? null)
                ? (int) $metadata['execution_power']
                : 0,
        );

        $knownTemplate = $template !== '' && JobArtEffectCatalog::has($template);
        if ($knownTemplate && JobArtEffectCatalog::dealsDamage($template) && $intrinsicPower > 0) {
            return true;
        }

        if (is_array($metadata['adaptive_route'] ?? null) && $intrinsicPower > 0) {
            return true;
        }

        if ($intrinsicPower > 0 && is_numeric($metadata['execution_power'] ?? null)) {
            return true;
        }

        // A known support/reward template remains non-damaging even if legacy
        // master data happens to retain a non-zero power field. Only explicit
        // runtime damage metadata above may promote it to a damage card.
        if ($knownTemplate) {
            return false;
        }

        return $intrinsicPower > 0
            && ! in_array($template, ['HEAL', 'HEAL_CLEANSE', 'GUARD_BARRIER', 'GUTS', 'SELF_BUFF', 'ENEMY_DEBUFF', 'TIME_CONTROL_CURRENT_ONLY', 'V2_ROLE_EFFECT_ONLY'], true);
    }

    /**
     * 該当する効果区分をすべて返す。単一の主区分へは丸めない。
     * 1つも該当しない場合だけ `other` を返す。
     *
     * @return list<string>
     */
    public function categoriesFor(Skill $skill, ?int $currentJobId = null): array
    {
        $categories = [];
        if ($this->isDamageArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_DAMAGE;
        }
        if ($this->isHealingArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_HEAL;
        }
        if ($this->isGuardArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_GUARD;
        }
        if ($this->isCleanseArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_CLEANSE;
        }
        if ($this->isBuffArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_BUFF;
        }
        if ($this->isDebuffArt($skill, $currentJobId)) {
            $categories[] = self::CATEGORY_DEBUFF;
        }

        return $categories === [] ? [self::CATEGORY_OTHER] : $categories;
    }

    /**
     * 13.1.1の構成区分。heal/guardを1枚でも含めば sustain_including、
     * 含まずdamage分類が過半数なら offense_heavy、それ以外は mixed。
     *
     * @param  iterable<mixed>  $arts
     */
    public function buildCategory(iterable $arts, ?int $currentJobId = null): string
    {
        $total = 0;
        $damage = 0;
        $hasSustain = false;

        foreach ($arts as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }

            $total++;
            if ($this->isHealingArt($skill, $currentJobId) || $this->isGuardArt($skill, $currentJobId)) {
                $hasSustain = true;
            }
            if ($this->isDamageArt($skill, $currentJobId)) {
                $damage++;
            }
        }

        if ($hasSustain) {
            return self::BUILD_SUSTAIN_INCLUDING;
        }

        if ($total > 0 && $damage * 2 > $total) {
            return self::BUILD_OFFENSE_HEAVY;
        }

        return self::BUILD_MIXED;
    }

    /**
     * 冠位balanceの実行値とruntime template置換を適用した判定材料を返す。
     *
     * @return array{0: Skill, 1: string}
     */
    private function resolve(Skill $skill, ?int $currentJobId): array
    {
        // 戦闘routeは必ず実行用cloneを作り、装備中のmaster artを書き換えない
        // （JobArtBattleSupportService::skillForExecution）。よってcache可能。
        $this->resolvedCache ??= new \WeakMap();
        $key = $currentJobId === null ? 'null' : (string) $currentJobId;
        $entries = $this->resolvedCache[$skill] ?? [];
        if (isset($entries[$key])) {
            return $entries[$key];
        }

        $balanced = $this->crownBalanceCatalog->applyToExecution($skill);
        $template = $this->effectSemanticsResolver->replacementEffectTemplateForDisplay($currentJobId, $skill)
            ?? (string) $balanced->effect_template;

        $entries[$key] = [$balanced, $template];
        $this->resolvedCache[$skill] = $entries;

        return $entries[$key];
    }
}
