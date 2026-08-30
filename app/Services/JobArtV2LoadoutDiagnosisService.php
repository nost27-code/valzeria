<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use Illuminate\Support\Collection;

final class JobArtV2LoadoutDiagnosisService
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2SpCostCalculator $spCostCalculator,
        private readonly JobArtV2Rank5V6Catalog $rank5V6Catalog,
    ) {}

    /**
     * @param  Collection<int, CharacterJobArtSlot>  $slots
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     checks: list<array{level:string,title:string,detail:string}>
     * }
     */
    public function diagnose(
        Character $character,
        Collection $slots,
        string $slotContext,
        int $maxSp,
        string $spPolicy,
        int $maxSlots,
        int $maxCost,
    ): array {
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;
        if (! $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId)) {
            return $this->result([], 'ready');
        }

        $checks = [];
        $activeSlots = $slots
            ->filter(fn (CharacterJobArtSlot $slot): bool => (bool) $slot->getAttribute('job_art_active'))
            ->sortBy(fn (CharacterJobArtSlot $slot): int => (int) $slot->slot_no)
            ->values();
        $activeSkills = $activeSlots
            ->pluck('skill')
            ->filter(fn ($skill): bool => $skill instanceof Skill)
            ->values();
        $inactiveCount = $slots->count() - $activeSlots->count();
        $totalCost = (int) $activeSlots->sum(
            fn (CharacterJobArtSlot $slot): int => (int) $slot->getAttribute('job_art_effective_cost'),
        );

        if ($inactiveCount > 0) {
            $checks[] = $this->check(
                'error',
                '休止中の戦技があります',
                '休止中の戦技は戦闘中に発動しません。5枠・Cost'.$maxCost.'以内に収めてください。',
            );
        } elseif ($activeSkills->count() < $maxSlots) {
            $checks[] = $this->check(
                'warning',
                '空き枠があります',
                '現在は'.$activeSkills->count().'枠です。最大'.$maxSlots.'枠まで戦技を追加できます。',
            );
        } else {
            $checks[] = $this->check('ok', '5枠・Cost成立', 'Cost '.$totalCost.' / '.$maxCost.'で使用できます。');
        }

        $resources = [];
        $rank5V6Enabled = $this->featureGate->usesRank5V6ForCurrentJob($currentJobId);
        foreach ($activeSkills as $skill) {
            $origin = (string) ($skill->getAttribute('job_art_origin') ?: 'inherited');
            $metadata = $this->resourceCatalog->forCurrentJobArt($currentJobId, $skill, $origin);
            if ($metadata === null) {
                continue;
            }

            $key = (string) $metadata['resource_key'];
            $resources[$key] ??= [
                'name' => (string) $metadata['resource_name'],
                'producer_count' => 0,
                'producer_gain' => 0,
                'consumer_count' => 0,
                'finisher_count' => 0,
                'required' => 0,
                'has_passive_gain' => false,
                'cap' => (int) $metadata['resource_max_points'],
                'scheduled_rank5_count' => 0,
                'reactive_rank5_count' => 0,
                'scheduled_requirements' => [],
                'unreachable_rank_fives' => [],
            ];
            $resources[$key]['has_passive_gain'] = $resources[$key]['has_passive_gain']
                || $this->metadataHasPassiveGain($metadata);
            $role = ResourceRole::tryFrom((string) ($metadata['resource_role'] ?? ''));
            if ($role === ResourceRole::PRODUCER) {
                $resources[$key]['producer_count']++;
                $resources[$key]['producer_gain'] = max(
                    $resources[$key]['producer_gain'],
                    (int) ($metadata['resource_gain_points'] ?? 0),
                );
            } elseif ($role === ResourceRole::CONSUMER) {
                $resources[$key]['consumer_count']++;
                $required = (int) ($metadata['minimum_resource_points'] ?? 4);
                if ($rank5V6Enabled && $this->rank5V6Catalog->forSkill($skill) !== null) {
                    if ($this->rank5V6Catalog->isReactive($skill)) {
                        $resources[$key]['reactive_rank5_count']++;
                        $required = $this->rank5V6Catalog->requiredResourcePoints(
                            $skill,
                            0,
                            $required,
                        ) ?? $required;
                    } else {
                        $resources[$key]['scheduled_rank5_count']++;
                        $required = $this->rank5V6Catalog->requiredResourcePoints(
                            $skill,
                            $resources[$key]['scheduled_rank5_count'],
                            $required,
                        ) ?? $required;
                        if ($required > $resources[$key]['cap']) {
                            $resources[$key]['unreachable_rank_fives'][] = [
                                'name' => (string) $skill->name,
                                'required' => $required,
                            ];
                        } else {
                            $resources[$key]['scheduled_requirements'][] = $required;
                        }
                    }
                }
                if ($required <= $resources[$key]['cap']) {
                    $resources[$key]['required'] = max($resources[$key]['required'], $required);
                }
            } elseif ($role === ResourceRole::FINISHER) {
                $resources[$key]['finisher_count']++;
                $resources[$key]['required'] = max(
                    $resources[$key]['required'],
                    (int) ($metadata['minimum_resource_points'] ?? 12),
                );
            }
        }

        foreach ($resources as $resource) {
            $hasSpender = $resource['consumer_count'] > 0 || $resource['finisher_count'] > 0;
            $hasProducer = $resource['producer_count'] > 0 && $resource['producer_gain'] > 0;
            $hasPassiveRoute = $resource['has_passive_gain'];
            $usesNaturalCycle = $rank5V6Enabled
                && $resource['consumer_count'] > 0
                && $resource['finisher_count'] === 0
                && ($resource['scheduled_rank5_count'] > 0 || $resource['reactive_rank5_count'] > 0);

            if ($resource['unreachable_rank_fives'] !== []) {
                $unreachable = collect($resource['unreachable_rank_fives'])
                    ->map(static fn (array $rankFive): string => $rankFive['name'].'（必要'.$rankFive['required'].'）')
                    ->implode('、');
                $checks[] = $this->check(
                    'error',
                    $resource['name'].'で発動できない連携があります',
                    $unreachable.'は資源上限'.$resource['cap'].'を超えます。同じ系譜の予定連携は、4・8・12で使える3枚までにしてください。',
                );
            }

            if ($hasSpender && ! $hasProducer && ! $hasPassiveRoute) {
                $checks[] = $this->check(
                    'error',
                    $resource['name'].'を貯められません',
                    $resource['name'].'を使う連携・奥義がありますが、この系譜の始動がセットされていません。',
                );
                continue;
            }

            if ($hasSpender && $hasProducer) {
                $target = $usesNaturalCycle ? $resource['cap'] : $resource['required'];
                $uses = (int) ceil(max(1, $target) / max(1, $resource['producer_gain']));
                if ($usesNaturalCycle) {
                    $detail = $this->naturalCycleDetail($resource, '始動を約'.$uses.'回成功させて');
                    $checks[] = $this->check('ok', $resource['name'].'の自然循環成立', $detail);
                } else {
                    $checks[] = $this->check(
                        'ok',
                        $resource['name'].'の循環成立',
                        '始動を約'.$uses.'回成功させると、必要量'.$resource['required'].'へ到達できます。',
                    );
                }
            } elseif ($hasSpender && $hasPassiveRoute) {
                if ($usesNaturalCycle) {
                    $checks[] = $this->check(
                        'warning',
                        $resource['name'].'は受動獲得で自然循環します',
                        $this->naturalCycleDetail($resource, '通常攻撃・被弾などで'),
                    );
                } else {
                    $checks[] = $this->check(
                        'warning',
                        $resource['name'].'は受動獲得頼みです',
                        '通常攻撃・被弾など、この系譜に設定された行動でも貯められますが、始動を入れる構成より到達は遅めです。',
                    );
                }
            } elseif ($hasProducer && ! $hasSpender) {
                $checks[] = $this->check(
                    'warning',
                    $resource['name'].'の使い道がありません',
                    '始動で'.$resource['name'].'を得ますが、消費する連携・奥義がセットされていません。',
                );
            }
        }

        $alwaysEligibleFront = $activeSlots->first(function (CharacterJobArtSlot $slot): bool {
            $skill = $slot->skill;
            if (! $skill instanceof Skill) {
                return false;
            }

            $metadata = $this->resourceCatalog->forArt($skill);
            $role = ResourceRole::tryFrom((string) ($metadata['resource_role'] ?? ''));

            return $role === null || $role === ResourceRole::NEUTRAL;
        });
        if ($alwaysEligibleFront instanceof CharacterJobArtSlot
            && (int) $alwaysEligibleFront->slot_no < (int) $activeSlots->max('slot_no')
        ) {
            $checks[] = $this->check(
                'warning',
                '後ろの戦技が選ばれにくい可能性があります',
                'Slot'.(int) $alwaysEligibleFront->slot_no.'「'.$alwaysEligibleFront->skill->name.'」は資源待ちになりにくいため、後方枠より先に候補になり続ける場合があります。',
            );
        }

        $spCosts = $activeSkills
            ->map(fn (Skill $skill): int => $this->spCostCalculator->forCharacter($character, $skill, $maxSp))
            ->values();
        $unpayable = $activeSkills->filter(
            fn (Skill $skill): bool => $this->spCostCalculator->forCharacter($character, $skill, $maxSp) > $maxSp,
        );
        if ($unpayable->isNotEmpty()) {
            $checks[] = $this->check(
                'error',
                '必要SPが最大SPを超えています',
                $unpayable->pluck('name')->implode('、').'は現在の最大SPでは発動できません。',
            );
        } elseif ($spCosts->sum() > $maxSp && $activeSkills->isNotEmpty()) {
            $checks[] = $this->check(
                'warning',
                '長期戦ではSP切れに注意',
                '5技を各1回使う合計SPは'.$spCosts->sum().'です。最大SP'.$maxSp.'を上回るため、回復なしでは全てを1回ずつ使えません。',
            );
        } elseif ($activeSkills->isNotEmpty()) {
            $policyLabel = match ($spPolicy) {
                'conserve' => '温存（SP60%以上）',
                'normal' => '通常（SP30%以上）',
                default => '積極',
            };
            $checks[] = $this->check(
                'ok',
                'SP方針で発動可能',
                $policyLabel.'では、最大SPから全ての戦技の必要SPを支払えます。',
            );
        }

        if ($activeSkills->isEmpty()) {
            $checks[] = $this->check('error', '戦技が未設定です', '少なくとも1つの戦技をセットしてください。');
        }

        $ultimateCount = $activeSkills->filter(
            fn (Skill $skill): bool => (int) $skill->learn_rank === 9,
        )->count();
        if ($ultimateCount > 1) {
            $checks[] = $this->check(
                'error',
                '奥義は1つだけセットできます',
                '奥義が'.$ultimateCount.'個あります。決着に使う奥義を1つ選んでください。',
            );
        }

        return $this->result($checks);
    }

    /** @return array{level:string,title:string,detail:string} */
    private function check(string $level, string $title, string $detail): array
    {
        return compact('level', 'title', 'detail');
    }

    /**
     * @param list<array{level:string,title:string,detail:string}> $checks
     * @return array{status:string,status_label:string,summary:string,checks:list<array{level:string,title:string,detail:string}>}
     */
    private function result(array $checks, ?string $forcedStatus = null): array
    {
        $status = $forcedStatus ?? (collect($checks)->contains('level', 'error')
            ? 'invalid'
            : (collect($checks)->contains('level', 'warning') ? 'warning' : 'ready'));

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'invalid' => '不成立',
                'warning' => '要確認',
                default => '成立',
            },
            'summary' => match ($status) {
                'invalid' => '発動できない戦技があります。赤い項目を直してください。',
                'warning' => '使用できますが、回りにくい可能性があります。',
                default => '資源・Cost・SPの基本経路は成立しています。',
            },
            'checks' => $checks,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function metadataHasPassiveGain(array $metadata): bool
    {
        foreach ($metadata as $key => $value) {
            if ((string) $key === 'resource_gain_points') {
                continue;
            }
            if (str_ends_with((string) $key, '_gain_points') && (int) $value > 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $resource */
    private function naturalCycleDetail(array $resource, string $gainRoute): string
    {
        if ($resource['scheduled_rank5_count'] > 0) {
            $requirements = implode('/', array_values(array_unique($resource['scheduled_requirements'])));
            $detail = $gainRoute.$resource['name'].'を'.$resource['cap'].'まで満たし、必要量'.$requirements
                .'で使える連携を一度ずつ判定すると、自動で0へ戻って次の周期に入ります。';
            if ($resource['reactive_rank5_count'] > 0) {
                $detail .= '反応型の連携は、未判定でも自然循環を止めません。';
            }

            return $detail;
        }

        return $gainRoute.$resource['name'].'を'.$resource['cap']
            .'まで満たし、反応型の連携を一度判定すると、自動で0へ戻って次の周期に入ります。';
    }
}
