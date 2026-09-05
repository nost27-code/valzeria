<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use Illuminate\Support\Facades\Validator;

final class NationRaidRewardPolicy
{
    public function candidate(): array
    {
        $policy = [...config('nation_raid_rewards'),
            'minimum_resolved_sorties' => config('nation_raid.qualification.minimum_resolved_sorties')];
        $this->validate($policy);

        return $policy;
    }

    public function forEvent(NationRaidEvent $event): array
    {
        $policy = $event->reward_policy_snapshot;
        throw_unless(is_array($policy) && is_string($event->reward_policy_hash)
            && hash_equals($event->reward_policy_hash, $this->hash($policy)),
            \DomainException::class, '開催時の報酬条件を確認できません。運営へお問い合わせください。');
        $this->validate($policy);

        return $policy;
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
    }

    private function validate(array $policy): void
    {
        $rules = ['version' => 'required|integer|in:1,2', 'minimum_resolved_sorties' => 'required|integer|min:1|max:35',
            'nation_thresholds' => 'required|array|size:3'];
        foreach (['bottles.participation', 'bottles.stage10', 'bottles.completion', 'completion_free_kiseki',
            'damage_thresholds.damage2m',
            'nation_reference_cap', 'nation_qualified_cap', 'nation_thresholds.*'] as $path) {
            $rules[$path] = 'required|integer|min:1';
        }
        if (($policy['version'] ?? null) === 2) {
            $rules += [
                'participation_minimum_resolved_sorties' => 'required|integer|min:1|lte:minimum_resolved_sorties',
                'milestones' => 'required|array|min:1|max:35',
                'milestones.*' => 'required|array:damage,fragment,quantity,free_kiseki,bottles,talismans',
                'milestones.*.damage' => 'required|integer|min:1|distinct',
                'milestones.*.fragment' => 'required|in:enhance,guard,tune',
                'milestones.*.quantity' => 'required|integer|min:1',
                'milestones.*.free_kiseki' => 'required|integer|min:1',
                'milestones.*.bottles' => 'required|integer|min:0',
                'milestones.*.talismans' => 'required|integer|min:0',
                'damage_thresholds' => 'required|array:damage2m',
                'fragment_quantities' => 'prohibited', 'talisman_quantity' => 'prohibited',
            ];
        } else {
            foreach (['damage_thresholds.damage250k', 'damage_thresholds.damage1m',
                'fragment_quantities.damage250k', 'fragment_quantities.damage1m', 'talisman_quantity'] as $path) {
                $rules[$path] = 'required|integer|min:1';
            }
        }
        throw_if(Validator::make($policy, $rules)->fails(), \DomainException::class, '報酬条件に不正な値があります。');
        throw_unless(in_array($policy['version'], [1, 2], true), \DomainException::class, '報酬条件の版が不正です。');
        if ($policy['version'] === 2) {
            $previous = 0;
            throw_unless(array_is_list($policy['milestones']), \DomainException::class, '到達報酬の順序が不正です。');
            foreach ($policy['milestones'] as $milestone) {
                foreach (['quantity', 'free_kiseki', 'bottles', 'talismans'] as $quantityKey) {
                    throw_unless(is_int($milestone[$quantityKey]), \DomainException::class, '到達報酬の数量が不正です。');
                }
                throw_unless(is_int($milestone['damage']) && $milestone['damage'] > $previous,
                    \DomainException::class, '到達報酬の順序が不正です。');
                $previous = $milestone['damage'];
            }
        }
        foreach (array_keys($policy['nation_thresholds']) as $threshold) {
            throw_unless(filter_var($threshold, FILTER_VALIDATE_INT) > 0, \DomainException::class, '国家報酬条件に不正な値があります。');
        }
    }
}
