<?php

namespace App\Services\Nation\Raid;

/**
 * @deprecated profile採取後の再選択には使わない。作戦は既存player選択前の候補順へ適用する。
 */
final class NationRaidStrategySelector
{
    public function __construct(
        private readonly NationRaidRules $rules,
        private readonly NationRaidCounterplayResolver $resolver,
    ) {}

    /**
     * @param  list<string>  $equipped
     * @param  list<string>  $eligible
     */
    public function select(
        string $strategy,
        array $equipped,
        array $eligible,
        NationRaidCounterplayContext $context,
    ): ?string {
        $allowed = array_fill_keys($equipped, true);
        $candidates = [];
        foreach ($eligible as $identity) {
            if (isset($allowed[$identity]) && $this->resolver->isSelectable($identity, $context)) {
                $candidates[] = $identity;
            }
        }

        // exact identity間の優先順位は発明せず、snapshot builderが渡す既存候補順を保つ。
        // 猛攻と堅守だけ、仕様で明示された大分類を前へ寄せる。
        $preferredCategory = match ($strategy) {
            NationRaidRules::STRATEGY_ASSAULT => 'offense',
            NationRaidRules::STRATEGY_FORTIFY => 'defense',
            default => null,
        };
        if ($preferredCategory !== null) {
            $preferred = [];
            $fallback = [];
            foreach ($candidates as $identity) {
                if ($this->rules->counterplayArt($identity)['category'] === $preferredCategory) {
                    $preferred[] = $identity;
                } else {
                    $fallback[] = $identity;
                }
            }
            $candidates = array_merge($preferred, $fallback);
        }

        return $candidates[0] ?? null;
    }
}
