<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;

/** Frozen policy → reward goals. Shared by read-only previews and final entitlement creation. */
final class NationRaidPersonalRewardCatalog
{
    public function participationMinimum(array $policy): int
    {
        return $policy['version'] === 2 ? $policy['participation_minimum_resolved_sorties'] : $policy['minimum_resolved_sorties'];
    }

    public function definitions(NationRaidEvent $event, array $policy, ?array $player, ?int $maxRank): array
    {
        $sorties = $player['resolved_sorties'] ?? 0;
        $damage = $player['damage'] ?? 0;
        $rank = $player['rank'] ?? null;
        $minimum = $policy['minimum_resolved_sorties'];
        $participationMinimum = $this->participationMinimum($policy);
        $definitions = ['participation' => [
            'payload' => ['label' => '参加報酬', 'bottles' => $policy['bottles']['participation']],
            'condition' => '有効出撃'.$participationMinimum.'回',
            'progress' => $sorties.' / '.$participationMinimum.'回', 'met' => $sorties >= $participationMinimum,
        ]];
        if ($policy['version'] === 1) {
            foreach (['damage250k', 'damage1m'] as $key) {
                $choices = [];
                foreach (['enhance', 'guard', 'tune'] as $fragment) {
                    $choices[$fragment] = $this->fragment($fragment, $policy['fragment_quantities'][$key]);
                }
                if ($key === 'damage1m') {
                    $choices['talisman'] = ['label' => '経験の護符 ×'.$policy['talisman_quantity'].'（Lv255では使用不可）',
                        'kind' => 'consumable', 'item_key' => 'experience_talisman', 'quantity' => $policy['talisman_quantity']];
                }
                $definitions[$key] = $this->damageGoal($policy['damage_thresholds'][$key], $damage, ['choices' => $choices]);
            }
        } else {
            foreach ($policy['milestones'] as $milestone) {
                $payload = ['fixed_material' => $this->fragment($milestone['fragment'], $milestone['quantity']),
                    'free_kiseki' => $milestone['free_kiseki']];
                foreach (['bottles', 'talismans'] as $item) {
                    if ($milestone[$item] > 0) {
                        $payload[$item] = $milestone[$item];
                    }
                }
                $definitions['milestone_'.$milestone['damage']] = $this->damageGoal($milestone['damage'], $damage, $payload);
            }
        }
        $definitions['damage2m'] = $this->damageGoal($policy['damage_thresholds']['damage2m'], $damage, $this->honor('黒天竜を穿つ者', false));
        $definitions += [
            'stage10' => [
                'payload' => ['label' => '第10再臨到達報酬', 'bottles' => $policy['bottles']['stage10']],
                'condition' => '全体で第10再臨に到達', 'progress' => $event->stage10_reached_at ? '到達済み' : '未到達',
                'met' => $event->stage10_reached_at !== null,
            ],
            'completion' => [
                'payload' => ['label' => '黒天竜討伐報酬', 'bottles' => $policy['bottles']['completion'], 'free_kiseki' => $policy['completion_free_kiseki']],
                'condition' => '全体で第20再臨を撃破', 'progress' => $event->completed_at ? '討伐済み' : '未討伐',
                'met' => $event->completed_at !== null,
            ],
        ];
        foreach (['personal_first' => ['万軍の先鋒', '個人累計ダメージ1位', $rank, $rank === 1, true],
            'personal_top3' => ['黒天竜討滅の功臣', '個人累計ダメージ2〜3位', $rank, in_array($rank, [2, 3], true), false],
            'max_first' => ['天穿の一撃', '1行動最大ダメージ1位', $maxRank, $maxRank === 1, true]] as $key => [$label, $condition, $place, $met, $badge]) {
            $definitions[$key] = ['payload' => $this->honor($label, $badge), 'condition' => $condition,
                'progress' => $place === null ? '記録なし' : ($event->status === NationRaidEvent::STATUS_COMPLETED ? '最終' : '現在').$place.'位', 'met' => $met];
        }
        foreach ($definitions as $key => &$definition) {
            if ($key !== 'participation') {
                $definition['met'] = $sorties >= $minimum && $definition['met'];
                if ($policy['version'] === 2) {
                    $definition['condition'] .= '・有効出撃'.$minimum.'回';
                    if ($sorties < $minimum) {
                        $definition['progress'] .= '（出撃あと'.($minimum - $sorties).'回）';
                    }
                }
            }
        }
        unset($definition);

        return $definitions;
    }

    private function fragment(string $key, int $quantity): array
    {
        [$code, $label] = match ($key) {
            'enhance' => ['MAT_ENHANCE_FRAGMENT', '強化石の欠片'],
            'guard' => ['5007', '守護石の欠片'],
            'tune' => ['ACC0007', '調律石の欠片'],
        };

        return ['label' => $label.' ×'.$quantity, 'kind' => 'material', 'code' => $code, 'quantity' => $quantity];
    }

    private function damageGoal(int $target, int $damage, array $payload): array
    {
        return ['payload' => ['label' => number_format($target).'ダメージ到達報酬', ...$payload],
            'condition' => '個人累計'.number_format($target).'ダメージ',
            'progress' => $damage >= $target ? '目標ダメージ達成' : 'あと'.number_format($target - $damage),
            'met' => $damage >= $target];
    }

    private function honor(string $label, bool $badge): array
    {
        return ['label' => $label, 'title' => $label, 'badge' => $badge];
    }
}
