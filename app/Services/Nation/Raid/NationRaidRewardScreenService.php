<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\Material;
use App\Models\NationRaidEvent;
use App\Models\NationRaidPersonalReward;
use Illuminate\Support\Collection;

/** 報酬目標の読み取り専用表示。達成見込みから受取権利を作成しない。 */
final readonly class NationRaidRewardScreenService
{
    public function __construct(private NationRaidRewardPolicy $policies, private NationRaidPersonalRewardCatalog $catalog) {}

    /** 現行候補の案内のみ。仮のイベントはメモリ上だけで、権利・進捗を生成しない。 */
    public function preview(): array
    {
        $policy = $this->policies->candidate();
        $event = new NationRaidEvent(['status' => NationRaidEvent::STATUS_DRAFT]);
        $groups = [];
        foreach (['participation' => '参加報酬', 'damage' => '個人ダメージ報酬', 'server' => '全体討伐報酬', 'honor' => '称号・順位報酬'] as $key => $label) {
            $groups[$key] = ['label' => $label, 'rows' => []];
        }
        foreach ($this->catalog->definitions($event, $policy, null, null) as $key => $definition) {
            $payload = $definition['payload'];
            $presentation = $this->presentation($key, $payload['label'], $policy, 0, 0);
            $groups[$presentation['group']]['rows'][] = [
                ...$presentation,
                'key' => $key, 'reward_id' => null, 'label' => $payload['label'], 'payload' => $payload,
                'items' => $this->items($payload), 'condition' => $definition['condition'],
                'state' => 'preview', 'status_label' => '開催前', 'progress' => '', 'meter' => null,
                'selected_label' => null, 'claimed_at' => null,
            ];
        }

        return ['groups' => $groups, 'minimum_sorties' => $policy['minimum_resolved_sorties'],
            'participation_minimum_sorties' => $this->catalog->participationMinimum($policy)];
    }

    public function build(NationRaidEvent $event, Character $character, array $standings, Collection $rewards): array
    {
        $policy = $this->policies->forEvent($event);
        $own = collect($standings['personal_total'])->first(fn (array $row) => (int) $row['account_id'] === (int) $character->user_id
            && (int) $row['character_id'] === (int) $character->id);
        $maxRank = $own === null ? null : (collect($standings['max_action'])->firstWhere('participation_id', $own['participation_id'])['rank'] ?? null);
        $sorties = $own['resolved_sorties'] ?? 0;
        $damage = $own['damage'] ?? 0;
        $minimum = $policy['minimum_resolved_sorties'];
        $qualified = $sorties >= $minimum;
        $completed = $event->status === NationRaidEvent::STATUS_COMPLETED;
        $restricted = $character->is_frozen || $character->isExcludedFromPublicLogs();
        $owned = $rewards->filter(fn (NationRaidPersonalReward $reward) => (int) $reward->event_id === (int) $event->id
            && $reward->account_id_snapshot === (int) $character->user_id
            && $reward->character_id_snapshot === (int) $character->id)->keyBy('reward_key');
        $definitions = $this->catalog->definitions($event, $policy, $own, $maxRank);
        $rows = [];
        foreach ($definitions as $key => $definition) {
            $reward = $owned->get($key);
            // 未達の目標も一覧に残す。確定後の内容は保存済み権利が正本。
            $payload = $reward?->reward_snapshot ?? $definition['payload'];
            $state = $definition['met'] ? ($completed ? 'unavailable' : 'awaiting') : 'unmet';
            if ($reward !== null) {
                $valid = $completed && ($payload['policy_hash'] ?? null) === $event->reward_policy_hash;
                $state = match (true) {
                    $valid && $reward->status === NationRaidPersonalReward::STATUS_CLAIMED => 'claimed',
                    $valid && ! $restricted && $reward->status === NationRaidPersonalReward::STATUS_PENDING => 'claimable',
                    default => 'unavailable',
                };
            }
            $items = $this->items($payload);
            $rows[] = [
                'key' => $key, 'reward_id' => $reward?->id, 'label' => $payload['label'], 'payload' => $payload,
                'contents' => array_column($items, 'label'), 'items' => $items,
                'condition' => $definition['condition'], 'progress' => $definition['progress'],
                'state' => $state, 'status_label' => match ($state) {
                    'claimable' => '未受取', 'claimed' => '受取済み', 'awaiting' => '確定待ち',
                    'unavailable' => '受取確認中', default => '条件未達',
                },
                'selected_label' => $payload['choices'][$reward?->selection_key]['label'] ?? null,
                'claimed_at' => $reward?->claimed_at?->format('Y/n/j H:i'),
                ...$this->presentation($key, $payload['label'], $policy, $sorties, $damage),
            ];
        }

        $groups = [];
        foreach (['participation' => '参加報酬', 'damage' => '個人ダメージ報酬', 'server' => '全体討伐報酬', 'honor' => '称号・順位報酬'] as $key => $label) {
            $groups[$key] = ['label' => $label, 'rows' => array_values(array_filter($rows, fn (array $row) => $row['group'] === $key))];
        }
        $nextDamage = collect($rows)->first(fn (array $row) => $row['group'] === 'damage' && $row['remaining'] > 0);

        return ['rows' => $rows, 'groups' => $groups, 'next_damage_goal' => $nextDamage,
            'own_progress' => $own, 'minimum_sorties' => $minimum,
            'participation_minimum_sorties' => $this->catalog->participationMinimum($policy),
            'resolved_sorties' => $sorties, 'personal_damage' => $damage, 'qualified' => $qualified];
    }

    /** 表示専用。バーは出撃数またはダメージの進捗であり、受取可否は表さない。 */
    private function presentation(string $key, string $label, array $policy, int $sorties, int $damage): array
    {
        $target = $policy['damage_thresholds'][$key] ?? null;
        foreach ($policy['milestones'] ?? [] as $milestone) {
            if ($key === 'milestone_'.$milestone['damage']) {
                $target = $milestone['damage'];
                break;
            }
        }
        $group = match (true) {
            $key === 'participation' => 'participation',
            in_array($key, ['stage10', 'completion'], true) => 'server',
            $target !== null && $key !== 'damage2m' => 'damage',
            default => 'honor',
        };
        $max = $key === 'participation' ? $this->catalog->participationMinimum($policy) : $target;
        $current = $key === 'participation' ? $sorties : $damage;
        $value = $max === null ? null : max(0, min($current, $max));

        return ['group' => $group,
            'display_label' => $target === null ? $label : ($target % 10_000 === 0 ? number_format($target / 10_000).'万' : number_format($target)).'ダメージ'.($key === 'damage2m' ? 'の称号' : ''),
            'remaining' => $max === null ? null : max(0, $max - $current),
            'meter' => $max === null ? null : ['max' => $max, 'value' => $value,
                'percent' => (int) floor($value / $max * 100),
                'label' => $key === 'participation' ? '参加報酬の有効出撃回数' : '個人累計ダメージ（出撃条件とは別）'],
        ];
    }

    private function items(array $payload): array
    {
        $contents = [];
        if (isset($payload['fixed_material'])) {
            $contents[] = ['label' => $payload['fixed_material']['label'],
                'icon' => Material::iconImagePathFor($payload['fixed_material']['code'], null)];
        }
        if (isset($payload['bottles'])) {
            $contents[] = ['label' => '探索力の小瓶 ×'.$payload['bottles'],
                'icon' => config('adventure_support.items.explore_stamina_small_bottle.icon_image')];
        }
        if (isset($payload['free_kiseki'])) {
            $contents[] = ['label' => '無償輝石 ×'.$payload['free_kiseki'], 'icon' => 'images/icon/kiseki.webp'];
        }
        if (isset($payload['talismans'])) {
            $contents[] = ['label' => '経験の護符 ×'.$payload['talismans'].'（Lv255では使用不可）',
                'icon' => config('adventure_support.inventory_items.experience_talisman.icon_image')];
        }
        foreach ($payload['choices'] ?? [] as $choice) {
            $contents[] = ['label' => $choice['label'], 'icon' => $choice['kind'] === 'material'
                ? Material::iconImagePathFor($choice['code'], null)
                : config('adventure_support.inventory_items.'.$choice['item_key'].'.icon_image')];
        }
        if (isset($payload['title'])) {
            $contents[] = ['label' => '称号「'.$payload['title'].'」（能力補正なし）', 'icon' => 'images/icon/icon_009.webp'];
            if ($payload['badge'] ?? false) {
                $contents[] = ['label' => '冒険者カード記章', 'icon' => 'images/icon/icon_009.webp'];
            }
        }

        return $contents;
    }
}
