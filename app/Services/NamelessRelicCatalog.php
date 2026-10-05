<?php

namespace App\Services;

use App\Support\PlayerStatLabel;
use InvalidArgumentException;
use RuntimeException;

class NamelessRelicCatalog
{
    public const MAX_RANK = 9;

    public function all(): array
    {
        return (array) config('nameless_relic_effects');
    }

    public function grouped(): array
    {
        $labels = ['stat' => '単能力・全能力', 'compound' => '複合能力', 'trade' => '代償付き能力', 'killer' => '種族特攻', 'resist' => '種族耐性', 'brand' => '敵への種族刻印', 'species' => '複合特攻・耐性', 'form' => '自分の種族変更', 'special' => '戦闘効果', 'countermeasure' => '刻印への対抗'];
        $groups = [];
        foreach ($labels as $category => $label) {
            $groups[$label] = array_filter($this->all(), fn ($effect) => $effect['category'] === $category);
        }

        return $groups;
    }

    public function definition(string $key): array
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException('未知の遺物です。');
    }

    public function imagePath(string $key): ?string
    {
        return array_key_exists($key, $this->all()) ? 'images/relics/'.$key.'.webp' : null;
    }

    public function rankLabel(int $rank): string
    {
        $this->assertRank($rank);

        return [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'][$rank];
    }

    public function value(string $key, int $rank): float
    {
        $this->assertRank($rank);
        $effect = $this->definition($key);
        $path = $effect['category'] === 'special' ? 'special_values' : 'profiles';

        return (float) config('nameless_relics.'.$path.'.'.$effect['profile'].'.'.$rank);
    }

    /** 固定加算は廃止。既存の表示用呼び出しとの互換口。 */
    public function statBonuses(string $key, int $rank): array
    {
        $this->value($key, $rank);

        return [];
    }

    public function statRates(string $key, int $rank): array
    {
        $effect = $this->definition($key);
        $rates = array_fill_keys($effect['stats'] ?? [], $this->value($key, $rank));
        foreach ($effect['negative_stats'] ?? [] as $stat) {
            $rates[$stat] = -(float) config('nameless_relics.profiles.'.$effect['negative_profile'].'.'.$rank);
        }

        return $rates;
    }

    /** 増加分に30%上限を適用してから、減少分を別に差し引く。 */
    public function aggregateStatRates(iterable $relics): array
    {
        $positive = $negative = array_fill_keys(['hp', 'mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'], 0.0);
        foreach ($relics as $relic) {
            foreach ($this->statRates($relic->effect_key, (int) $relic->rank) as $stat => $rate) {
                if ($rate >= 0) {
                    $positive[$stat] += $rate;
                } else {
                    $negative[$stat] += $rate;
                }
            }
        }
        $result = [];
        foreach ($positive as $stat => $rate) {
            $result[$stat] = min((float) config('nameless_relics.stat_rate_cap'), $rate) + $negative[$stat];
        }

        return $result;
    }

    public function applyStatRates(array $baseline, array $rates): array
    {
        foreach ($rates as $stat => $rate) {
            $key = match ($stat) {
                'hp' => array_key_exists('max_hp', $baseline) ? 'max_hp' : 'hp',
                'mp' => array_key_exists('max_mp', $baseline) ? 'max_mp' : 'mp',
                default => $stat,
            };
            if (array_key_exists($key, $baseline)) {
                $baseline[$key] = max(in_array($stat, ['hp', 'str', 'agi'], true) ? 1 : 0, (int) floor(round($baseline[$key] * (1 + $rate), 6)));
            }
        }

        return $baseline;
    }

    public function validateLoadout(array $keys): void
    {
        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('同じ遺物効果は装備中の武器・防具・装飾品を合わせて一つまでです。');
        }
        $groups = [];
        foreach ($keys as $key) {
            $group = $this->definition($key)['exclusive_group'] ?? null;
            if ($group && isset($groups[$group])) {
                throw new RuntimeException('種族刻印・変身・通常攻撃の変換は、それぞれ一種類まで装着できます。');
            }
            if ($group) {
                $groups[$group] = true;
            }
        }
    }

    public function summary(string $key, int $rank, bool $includeRules = true): string
    {
        $effect = $this->definition($key);
        $pct = static fn (float $v): string => rtrim(rtrim(number_format($v * 100, 2, '.', ''), '0'), '.').'%';
        $parts = [];
        foreach ($this->statRates($key, $rank) as $stat => $rate) {
            $parts[] = PlayerStatLabel::for($stat).($rate >= 0 ? '+' : '−').$pct(abs($rate));
        }
        foreach ($effect['killers'] ?? [] as $species) {
            $parts[] = config('enemy_species.labels.'.$species).'特攻 +'.$pct($this->value($key, $rank));
        }
        foreach ($effect['resists'] ?? [] as $species) {
            $parts[] = config('enemy_species.labels.'.$species).'耐性 '.$pct($this->value($key, $rank));
        }
        if ($effect['category'] === 'brand') {
            $parts[] = config('enemy_species.labels.'.$effect['target']).'刻印（特攻の効力 '.$pct($this->value($key, $rank)).'）';
        } elseif ($effect['category'] === 'special' && ! str_starts_with($effect['target'], 'convert_')) {
            $parts[] = ($includeRules ? $effect['description'].'：' : '').($effect['target'] === 'critical' ? $this->value($key, $rank).'ポイント' : $pct($this->value($key, $rank)));
        } elseif ($effect['target'] === 'brand_guard') {
            $parts[] = '特攻の追加分を'.$pct($this->value($key, $rank)).'軽減';
        }
        if ($includeRules && in_array($effect['category'], ['killer', 'resist', 'species'], true)) {
            $parts[] = 'PvPは効力75%（特攻上限30%・耐性上限35%）';
        }
        if ($includeRules && (in_array($effect['category'], ['form', 'countermeasure'], true) || str_starts_with($effect['target'], 'convert_'))) {
            $parts[] = $effect['description'];
        }

        return implode(' / ', $parts);
    }

    public function feedExp(int $rank): int
    {
        $this->assertRank($rank);

        return (int) config('nameless_relics.relic_feed_exp.'.$rank);
    }

    private function assertRank(int $rank): void
    {
        if ($rank < 1 || $rank > self::MAX_RANK) {
            throw new InvalidArgumentException('遺物ランクはI〜IXです。');
        }
    }
}
