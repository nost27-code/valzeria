<?php

namespace App\Services;

/**
 * 戦技セット画面で、10系譜の共通ルールと既存の戦い方を説明する表示用正本。
 *
 * resourceの数値はPrototypeCatalog、場の数値はFieldCatalogから取得し、
 * 戦闘用metadataとは別の数値表を持たない。
 */
final class JobArtV2LineageGuideCatalog
{
    /** @var array<string, array{job_id:int,identity:string,trait:string,ultimate:string}> */
    private const GUIDES = [
        'counter' => [
            'job_id' => 1,
            'identity' => '相手の攻撃本体でダメージを受けた時や受け流した時にも剣勢を増やし、攻守を切り替えて戦う系譜です。',
            'trait' => '構え、決着準備、多段攻撃、攻撃本体を受けた後の反撃など、剣勢の使い道で戦い方が変わります。',
            'ultimate' => '継戦強化、多段攻撃、攻撃本体を受けた後の条件付き大ダメージなど、奥義ごとに決着方法が異なります。',
        ],
        'eclipse' => [
            'job_id' => 9,
            'identity' => '自分のHPを代償にした時も冥蝕を増やし、反動と回復を両立させる系譜です。',
            'trait' => '実際に自傷が成立すると冥蝕が増えます。吸収や自己強化を持つ戦技で、危険と見返りを調整します。',
            'ultimate' => '大ダメージだけでなく、吸収、自己強化、弱体化などを伴う奥義があります。',
        ],
        'pierce' => [
            'job_id' => 2,
            'identity' => '準備効果や構えから、硬い相手へ一撃を通すことを得意とする系譜です。',
            'trait' => '一部の連携・奥義は物理防御を無視します。始動には次の貫通系戦技を強化するものがあります。',
            'ultimate' => '物理防御の高い相手ほど、貫通を持つ奥義へ竜気を温存する意味が大きくなります。',
        ],
        'hunt' => [
            'job_id' => 3,
            'identity' => '狩猟印を使い、手数、会心、弱体化を組み合わせて獲物を仕留める系譜です。',
            'trait' => '多段攻撃や敏捷・運を活かす攻撃、相手の回避や能力へ干渉する戦技があります。',
            'ultimate' => '多段、魔力攻撃、防御効果など、相手や自分の能力に合わせて決着技を選べます。',
        ],
        'aim' => [
            'job_id' => 4,
            'identity' => '通常攻撃のMISSも照準へ変え、命中、会心、攻撃経路を選んで狙う系譜です。プレイヤー同士の対人戦とチャンプ戦では、命中率100%を超えた分が急所命中率に加わります。',
            'trait' => '命中率を上げる攻撃、会心率を上げる攻撃、物理・魔力の有利な経路を選ぶ攻撃があります。急所命中は通常の会心とは別に発生します。',
            'ultimate' => '奥義には命中補正や相手SPへの圧力を持つものがあり、回避の高い相手にも対応できます。',
        ],
        'guard' => [
            'job_id' => 7,
            'identity' => '実際にダメージを軽減した時や有害状態を浄化した時にも聖護を増やす系譜です。',
            'trait' => '回復、1回軽減、浄化、踏みとどまりを使い分け、倒されない状況を作ります。',
            'ultimate' => '大回復、全浄化、軽減、踏みとどまりなど、生存のための奥義があります。',
        ],
        'transmute' => [
            'job_id' => 8,
            'identity' => '回復、能力変換、報酬補助、強化解除を触媒で使い分ける系譜です。',
            'trait' => '戦闘力だけでなく、HP・SP回復、浄化、Goldや素材の獲得補助を担う戦技があります。',
            'ultimate' => '回復、軽減、報酬、相手の強化解除など、目的に合わせた奥義を選べます。',
        ],
        'break' => [
            'job_id' => 5,
            'identity' => '相手の防御・精神・敏捷などを下げ、後続の攻撃を通しやすくする系譜です。',
            'trait' => '連携・奥義の弱体化は、その攻撃がHITした後から後続の攻撃へ有効になるものがあります。',
            'ultimate' => '大きな弱体化、低HP時の威力上昇、防御効果など、崩した後の勝ち筋を選べます。',
        ],
        'command' => [
            'job_id' => 12,
            'identity' => '戦技を使わない手番も指揮点へ変え、通常攻撃・職業技と戦技を組み合わせる系譜です。',
            'trait' => '通常攻撃がHITすると、通常攻撃分と戦技以外の手番分が両方加算され、合計+5になります。',
            'ultimate' => '物理、魔力、複合、多段など、部隊の構成に合わせた奥義があります。',
        ],
        'field' => [
            'job_id' => 6,
            'identity' => '場を展開・延長・上書きし、数ターンにわたって戦場のルールを変える系譜です。',
            'trait' => '星光・旋律・聖域・静寂・天測の場には、それぞれ異なる継続効果があります。',
            'ultimate' => '場の維持や上書き回数を活かす奥義があり、先に作った場が決着まで影響します。',
        ],
    ];

    public function __construct(
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2FieldCatalog $fieldCatalog,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $guides = [];

        foreach (self::GUIDES as $lineageKey => $copy) {
            $starter = $this->prototypeCatalog->artResourceMetadataForJobRank($copy['job_id'], 1);
            $connector = $this->prototypeCatalog->artResourceMetadataForJobRank($copy['job_id'], 5);
            $ultimate = $this->prototypeCatalog->artResourceMetadataForJobRank($copy['job_id'], 9);
            if ($starter === null || $connector === null || $ultimate === null) {
                continue;
            }

            $additionalGains = $this->additionalGains($starter);
            $guides[$lineageKey] = [
                'lineage_key' => $lineageKey,
                'lineage_name' => (string) $starter['lineage_name'],
                'resource_name' => (string) $starter['resource_name'],
                'max_points' => (int) $starter['resource_max_points'],
                'identity' => $copy['identity'],
                'trait' => $copy['trait'],
                'ultimate' => $copy['ultimate'],
                'base_flow' => [
                    $this->resourceFlowText('始動', $starter),
                    $this->resourceFlowText('連携', $connector),
                    $this->resourceFlowText('奥義', $ultimate),
                ],
                'additional_gains' => $additionalGains,
                'direct_gain' => $this->directGainText($lineageKey, $starter),
                'common_gain' => $this->commonGainText($lineageKey, $additionalGains),
                'field_effects' => $lineageKey === 'field' ? $this->fieldEffects() : [],
                'inheritance' => 'どの職で使っても、戦技の効果と威力は変わりません。カードに書かれた増減と系譜特性は、そのカード自身の系譜リソースへ反映されます。',
            ];
        }

        return $guides;
    }

    /** @param array<string, mixed> $metadata */
    private function resourceFlowText(string $stage, array $metadata): string
    {
        $gain = max(0, (int) ($metadata['resource_gain_points'] ?? 0));
        $cost = max(0, (int) ($metadata['resource_cost_points'] ?? 0));

        if ($gain > 0) {
            return "{$stage}で+{$gain}";
        }
        if ($cost > 0) {
            return "{$stage}で-{$cost}";
        }

        return "{$stage}は増減なし";
    }

    /** @param array<string, mixed> $metadata
     *  @return list<string>
     */
    private function additionalGains(array $metadata): array
    {
        $labels = [
            'normal_attack_hit_gain_points' => '通常攻撃HIT',
            'normal_attack_miss_gain_points' => '通常攻撃MISS',
            'direct_attack_damage_received_gain_points' => '攻撃本体で1以上のダメージを受ける',
            'parry_success_gain_points' => '受け流し成功',
            'self_damage_gain_points' => '実際に自傷',
            'damage_mitigated_gain_points' => '実際に1以上軽減',
            'cleanse_success_gain_points' => '浄化成功',
            'non_job_art_action_gain_points' => '戦技以外の手番',
        ];
        $gains = [];

        foreach ($labels as $key => $label) {
            $points = max(0, (int) ($metadata[$key] ?? 0));
            if ($points > 0) {
                $gains[] = "{$label} +{$points}";
            }
        }

        return $gains;
    }

    /** @param array<string, mixed> $starter */
    private function directGainText(string $lineageKey, array $starter): string
    {
        $gain = max(0, (int) ($starter['resource_gain_points'] ?? 0));

        return match ($lineageKey) {
            'eclipse' => "原則、始動HITで+{$gain}。「血潮の咆哮」「闇の契約」は使用成立で+{$gain}",
            'transmute' => "原則、最大HP5%消費と最大SP5%回復が両方成立すると+{$gain}。「金冠錬符」はHIT時+{$this->resourceGainPoints(67)}",
            'break' => "原則、始動HITで+{$gain}。「練気呼吸」「練気」は使用成立で+{$this->resourceGainPoints(21)}",
            'command' => "原則、始動では増えない。「戦線把握」「戦冠指揮」だけ使用成立で+{$this->resourceGainPoints(59)}",
            'field' => $this->fieldDirectGainText($gain),
            default => "始動使用で+{$gain}",
        };
    }

    /** @param list<string> $additionalGains */
    private function commonGainText(string $lineageKey, array $additionalGains): string
    {
        $text = implode('、', $additionalGains);

        return match ($lineageKey) {
            'counter' => $text.'。攻撃本体は通常攻撃・職業技・戦技による物理・魔力・複合攻撃です。毒などの継続ダメージ、反射、反撃、自傷、反動、固定・割合ダメージは含みません。多段攻撃は1行動につき1回で、撃破された場合と踏みとどまり発動時は増えません。完全に受け流した攻撃は受け流し成功の+1のみです',
            'command' => $text.'。通常攻撃がHITした場合は計+5',
            default => $text,
        };
    }

    private function fieldDirectGainText(int $gain): string
    {
        $metadata = $this->prototypeCatalog->artResourceMetadataForJobRank(63, 1) ?? [];
        $overwriteGain = max(0, (int) ($metadata['resource_gain_on_field_overwrite_points'] ?? 0));

        return "始動使用で+{$gain}。「星冠詠唱」は実際に既存の場を上書きすると追加+{$overwriteGain}、計+".($gain + $overwriteGain);
    }

    private function resourceGainPoints(int $jobId): int
    {
        $metadata = $this->prototypeCatalog->artResourceMetadataForJobRank($jobId, 1) ?? [];

        return max(0, (int) ($metadata['resource_gain_points'] ?? 0));
    }

    /** @return list<string> */
    private function fieldEffects(): array
    {
        $effects = [];
        foreach ($this->fieldCatalog->cycleKeys() as $fieldKey) {
            $field = $this->fieldCatalog->field($fieldKey);
            if ($field === null) {
                continue;
            }

            $parts = [];
            foreach ($field['effects'] as $effect) {
                $parts[] = match ($effect['axis']) {
                    'damage_multiplier' => '自分の魔力ダメージ+'.((int) round((float) $effect['value'] * 100)).'%',
                    'activation_rate_delta' => '自分の戦技発動率+'.(int) $effect['value'].'ポイント',
                    'heal_multiplier' => '自分の回復量+'.((int) round((float) $effect['value'] * 100)).'%',
                    'resource_gain_delta' => ($effect['target'] === 'opponent' ? '相手' : '自分').'のリソース獲得'.sprintf('%+d', (int) $effect['value']),
                    'accuracy_delta' => '自分の命中率+'.(int) $effect['value'].'ポイント',
                    default => '',
                };
            }

            $parts = array_values(array_filter($parts));
            if ($parts !== []) {
                $effects[] = $field['name'].'：'.implode('、', $parts);
            }
        }

        return $effects;
    }
}
