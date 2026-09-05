<?php

namespace App\Services\Nation\Raid;

use Illuminate\Validation\Rule;

/** 新規出撃だけに適用する作戦gate。保存済みの戦闘条件は書き換えない。 */
final readonly class NationRaidStrategyPolicy
{
    public function __construct(private NationRaidRules $rules) {}

    public function enabled(): bool
    {
        return (bool) config('nation_raid.strategy_enabled', false);
    }

    public function forNewSortie(string $requested): string
    {
        return $this->enabled() ? $requested : NationRaidRules::STRATEGY_BOSS_SET;
    }

    public function validationRules(): array
    {
        // OFF中は古い画面・改変POSTの指定も無視し、未送信を正規の操作とする。
        return $this->enabled()
            ? ['required', 'string', Rule::in($this->rules->selectableStrategyKeys())]
            : ['exclude'];
    }

    public function forDisplay(mixed $requested): string
    {
        return $this->forNewSortie(is_string($requested) && in_array($requested, $this->rules->selectableStrategyKeys(), true)
            ? $requested : NationRaidRules::STRATEGY_ASSAULT);
    }

    public function matchesReplay(string $stored, string $requested): bool
    {
        // OFF切替を挟む再送は同じ結果を返す。所有者/event/tokenの照合は呼出元で維持する。
        return ! $this->enabled() || $stored === NationRaidRules::STRATEGY_BOSS_SET || $stored === $requested;
    }
}
