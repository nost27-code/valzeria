<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\JobExpTable;
use App\Models\MaterialDrop;
use App\Services\Battle\WeaponOffenseCalculator;
use App\Services\CharacterStatusService;
use App\Services\InnService;
use App\Services\JobService;
use App\Services\LevelService;
use App\Services\ShopService;
use App\Support\JobRankCatalog;
use DomainException;

final class ValzeriaLabVirtualAdventurerService
{
    public const MAX_ACTIONS = 100;

    public const DEFAULT_ACTIONS = 30;

    public const PROFILES = [
        'beginner' => [
            'label' => '初心者',
            'summary' => '被害を残さず、安全側で進む',
        ],
        'efficiency' => [
            'label' => '効率',
            'summary' => '経験値の高い敵と早いボス挑戦を選ぶ',
        ],
        'collector' => [
            'label' => '収集',
            'summary' => '未確認の登録ドロップ元を優先する',
        ],
    ];

    private const GROWTH_MULTIPLIER = 1.12;

    /** @var array<int, array{item:list<string>,material:list<string>}> */
    private array $dropSources = [];

    public function __construct(
        private readonly ValzeriaLabReplayService $replayService,
        private readonly LevelService $levelService,
        private readonly JobService $jobService,
        private readonly InnService $innService,
        private readonly ShopService $shopService,
        private readonly CharacterStatusService $statusService,
        private readonly WeaponOffenseCalculator $offenseCalculator,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $profile, int $actionLimit, int $seed): array
    {
        if (! isset(self::PROFILES[$profile])) {
            throw new DomainException('仮想冒険者の方針が正しくありません。');
        }
        if ($actionLimit < 1 || $actionLimit > self::MAX_ACTIONS) {
            throw new DomainException('行動数は1〜100にしてください。');
        }
        if ($seed < 0 || $seed > ValzeriaLabReplayService::MAX_SEED) {
            throw new DomainException('seedが範囲外です。');
        }

        $city = City::query()->where('is_initial', true)->orderBy('sort_order')->orderBy('id')->first();
        $job = $this->initialJob($profile);
        if (! $city || ! $job) {
            throw new DomainException('初期街または有効な基本職が見つかりません。');
        }
        $area = Area::query()
            ->where('city_id', $city->id)
            ->where('is_published', true)
            ->whereNull('unlock_required_area_id')
            ->whereHas('enemies')
            ->orderBy('unlock_order')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
        if (! $area) {
            throw new DomainException('初期街に探索可能な公開エリアがありません。');
        }

        $this->loadDropSources();
        $state = $this->initialState($city, $area, $job);
        $initial = $this->stateSummary($state);
        $timeline = [];
        $stopReason = null;

        for ($step = 1; $step <= $actionLimit; $step++) {
            if ($step === 1) {
                $this->record($timeline, $step, 'town', '街から旅を始める',
                    "{$city->name}で支度し、{$area->name}を最初の探索先に選びました。", $state,
                    engine: 'CharacterServiceの初期値・既存マスタ + Lab簡略モデル');
                continue;
            }

            if ($state['pending_area'] instanceof Area) {
                $nextArea = $state['pending_area'];
                $state['pending_area'] = null;
                $state['area'] = $nextArea;
                $state['city'] = $nextArea->city()->first() ?? $state['city'];
                $this->record($timeline, $step, 'town', $state['city']->name.'へ進む',
                    "ボス撃破で明示解放された{$nextArea->name}を次の探索先にしました。", $state,
                    engine: 'areas.unlock_required_area_id参照 + Lab簡略移動');
                $state['job_check_due'] = true;
                continue;
            }

            if ($state['pending_battle'] !== null) {
                $enemy = Enemy::query()->with(['actions', 'area.city'])->find($state['pending_battle']['enemy_id']);
                if (! $enemy) {
                    $stopReason = 'enemy_missing';
                    break;
                }
                $battleType = (string) $state['pending_battle']['battle_type'];
                $state['pending_battle'] = null;
                $battle = $this->executeBattle($state, $enemy, $battleType, $seed);
                $nextArea = null;
                $bossCleared = $battle['result'] === 'victory' && $battleType === 'boss';
                if ($bossCleared) {
                    $state['cleared_area_ids'][(int) $state['area']->id] = true;
                    $nextArea = $this->nextArea($state, $profile);
                    $battle['reason'] .= ' ボス撃破を仮想進行へ反映しました。';
                }
                $this->record($timeline, $step, 'battle', $enemy->name.'との戦闘',
                    $battle['reason'], $state, $battle,
                    'ValzeriaLabReplayService → 現行BattleService（非永続）');
                if ($bossCleared) {
                    if ($nextArea) {
                        $state['pending_area'] = $nextArea;
                    } else {
                        $stopReason = 'no_next_area';
                        if ($step < $actionLimit) {
                            $this->record($timeline, ++$step, 'decision', '次の進行先を確認',
                                '直接の解放先が見つからないため、この試行を終了しました。', $state,
                                engine: 'areas.unlock_required_area_id参照 + Lab簡略判定');
                        }
                        break;
                    }
                }
                continue;
            }

            $stats = $this->calculateStats($state);
            if ($this->shouldRest($state, $profile, $stats)) {
                $virtualCharacter = $this->virtualCharacter($state);
                $fee = $this->innService->fee($virtualCharacter);
                if ((int) $state['gold'] < $fee) {
                    $stopReason = 'insufficient_gold_for_inn';
                    $this->record($timeline, $step, 'inn', '宿屋を利用できない',
                        "宿泊料金{$fee}Gに対して所持Goldが{$state['gold']}Gのため終了しました。救済処理はLabでは実行しません。", $state,
                        engine: 'InnService::fee() + Lab簡略支払判定');
                    break;
                }
                $state['gold'] -= $fee;
                $state['current_hp'] = $stats['max_hp'];
                $state['current_sp'] = $stats['max_mp'];
                $state['last_battle_result'] = null;
                $this->record($timeline, $step, 'inn', '宿屋で休む',
                    "現行宿代{$fee}Gを仮想所持金から差し引き、HP/SPを全回復しました。", $state,
                    engine: 'InnService::fee() + Lab内メモリ更新');
                continue;
            }

            $equipment = $this->equipmentCandidate($state, $profile);
            if ($equipment !== null) {
                $type = (string) $equipment->type;
                $old = $state['equipment'][$type] ?? null;
                $price = $this->shopService->priceFor($this->virtualCharacter($state), $equipment);
                $state['gold'] -= $price;
                $state['equipment'][$type] = $equipment;
                $afterStats = $this->calculateStats($state);
                $state['current_hp'] = min((int) $state['current_hp'], $afterStats['max_hp']);
                $state['current_sp'] = min((int) $state['current_sp'], $afterStats['max_mp']);
                $this->record($timeline, $step, 'equipment', $equipment->name.'へ更新',
                    ($old ? $old->name.'から' : '未装備から')."{$equipment->name}へ変更し、{$price}Gを仮想所持金から差し引きました。", $state,
                    engine: 'ShopService::priceFor()・CharacterStatusService::equipmentStatsForItem() + Lab内メモリ更新');
                continue;
            }

            if ($state['job_check_due']) {
                $state['job_check_due'] = false;
                $decision = $this->evaluateJobChange($state);
                $this->record($timeline, $step, 'job', $decision['title'], $decision['reason'], $state,
                    engine: 'JobRequirement・JobExpTable・JobService倍率参照 + Lab簡略判定');
                continue;
            }

            if ($this->bossReady($state, $profile)) {
                $boss = Enemy::query()
                    ->where('area_id', $state['area']->id)
                    ->where('is_boss', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();
                if (! $boss) {
                    $stopReason = 'boss_missing';
                    $this->record($timeline, $step, 'boss', 'ボスを確認できない',
                        "{$state['area']->name}にボスの明示マスタがないため終了しました。", $state,
                        engine: 'enemies.area_id / is_boss参照');
                    break;
                }
                $state['pending_battle'] = ['enemy_id' => (int) $boss->id, 'battle_type' => 'boss'];
                $this->record($timeline, $step, 'boss', $boss->name.'へ挑む',
                    $this->bossDecisionReason($state, $profile), $state,
                    engine: 'Area推奨Lv・登録drop参照 + Lab簡略判断');
                continue;
            }

            $enemy = $this->normalEnemy($state, $profile);
            if (! $enemy) {
                $stopReason = 'normal_enemy_missing';
                $this->record($timeline, $step, 'explore', '探索対象がない',
                    "{$state['area']->name}に通常敵の明示マスタがないため終了しました。", $state,
                    engine: 'enemies.area_id / is_boss参照');
                break;
            }
            $state['pending_battle'] = ['enemy_id' => (int) $enemy->id, 'battle_type' => 'pve'];
            $this->record($timeline, $step, 'explore', $state['area']->name.'を探索',
                $this->enemyDecisionReason($enemy, $profile, $state), $state,
                ['enemy' => $enemy->name, 'battle_type' => 'pve', 'result' => 'encounter'],
                'enemies・dropマスタ参照 + Lab簡略選択');
        }

        $stopReason ??= count($timeline) >= $actionLimit ? 'action_limit' : 'completed';

        return [
            'profile' => [
                'key' => $profile,
                ...self::PROFILES[$profile],
            ],
            'seed' => $seed,
            'requested_action_limit' => $actionLimit,
            'executed_actions' => count($timeline),
            'stop_reason' => $stopReason,
            'stop_reason_label' => $this->stopReasonLabel($stopReason),
            'initial' => $initial,
            'final' => $this->stateSummary($state),
            'timeline' => $timeline,
            'boundaries' => [
                'exact' => [
                    '戦闘: ValzeriaLabReplayService経由の現行BattleService',
                    '必要EXP: LevelService::getRequiredExp()',
                    '職業EXP上限・倍率: LevelService / JobService / JobExpTable',
                    '宿代: InnService::fee()',
                    '装備性能: CharacterStatusService::equipmentStatsForItem() と WeaponOffenseCalculator',
                    '敵・エリア・装備・drop・職業条件: 現行マスタ',
                ],
                'simplified' => [
                    '行動選択、探索進行、ボス挑戦時期、装備購入、Lv上昇時のメモリ反映、直接の次エリア選択',
                    'CharacterServiceの初期能力をメモリ上に複製し、個人レコードは作成しない',
                ],
                'not_modeled' => [
                    '探索度・危険度・スタミナ・クールダウン・宝箱・実drop抽選・所持品付与',
                    'ボーナスポイント配分、称号・アイテム・特殊証明を要する転職、戦技編成、モンスターマーク、ヴァルモン',
                    '宿屋の救済、銀行、装備売却、強化・進化、市場、イベント固有進行',
                ],
            ],
            'persistence' => false,
        ];
    }

    private function initialJob(string $profile): ?JobClass
    {
        $jobs = JobClass::query()
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (JobClass $job): bool => JobRankCatalog::isBasic($job->rank));

        return $jobs->sort(function (JobClass $left, JobClass $right) use ($profile): int {
            $leftScore = $this->jobProfileScore($left, $profile);
            $rightScore = $this->jobProfileScore($right, $profile);

            return $rightScore <=> $leftScore ?: ((int) $left->sort_order <=> (int) $right->sort_order)
                ?: ((int) $left->id <=> (int) $right->id);
        })->first();
    }

    /** @return list<int> */
    private function jobProfileScore(JobClass $job, string $profile): array
    {
        return match ($profile) {
            'beginner' => [(int) $job->bonus_hp, (int) $job->bonus_def + (int) $job->bonus_spr],
            'efficiency' => [max((int) $job->bonus_str, (int) $job->bonus_mag), (int) $job->bonus_spd],
            'collector' => [(int) $job->bonus_drop_rate, (int) $job->bonus_luk],
        };
    }

    /** @return array<string, mixed> */
    private function initialState(City $city, Area $area, JobClass $job): array
    {
        $state = [
            'level' => 1,
            'exp' => 0,
            'base' => ['hp' => 100, 'mp' => 0, 'str' => 10, 'def' => 8, 'agi' => 8, 'mag' => 8, 'spr' => 10, 'luk' => 5],
            'fractions' => ['hp' => 0.0, 'mp' => 0.0, 'str' => 0.0, 'def' => 0.0, 'agi' => 0.0, 'mag' => 0.0, 'spr' => 0.0, 'luk' => 0.0],
            'bonus_points' => 0,
            'gold' => max(0, (int) config('gold.starting_balance', 1000)),
            'job' => $job,
            'job_histories' => [
                (int) $job->id => ['level' => 1, 'exp' => 0, 'mastered' => false],
            ],
            'equipment' => ['weapon' => null, 'armor' => null],
            'city' => $city,
            'area' => $area,
            'current_hp' => 1,
            'current_sp' => 0,
            'wins' => 0,
            'losses' => 0,
            'timeouts' => 0,
            'battle_count' => 0,
            'wins_by_area' => [],
            'cleared_area_ids' => [],
            'observed_sources' => [],
            'pending_battle' => null,
            'pending_area' => null,
            'last_battle_result' => null,
            'job_check_due' => true,
        ];
        $stats = $this->calculateStats($state);
        $state['current_hp'] = $stats['max_hp'];
        $state['current_sp'] = $stats['max_mp'];

        return $state;
    }

    /** @return array<string, int> */
    private function calculateStats(array $state): array
    {
        /** @var JobClass $job */
        $job = $state['job'];
        $jobHistory = $state['job_histories'][(int) $job->id];
        $jobLevel = max(1, (int) $jobHistory['level']);
        $base = $state['base'];

        foreach ($state['job_histories'] as $jobId => $history) {
            if (! $history['mastered'] || (int) $jobId === (int) $job->id) {
                continue;
            }
            $masteredJob = JobClass::query()->find((int) $jobId);
            if (! $masteredJob) {
                continue;
            }
            foreach (['hp', 'mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'] as $key) {
                $column = $key === 'agi' ? 'bonus_spd' : 'bonus_'.$key;
                $base[$key] += (int) ($masteredJob->{$column} ?? 0);
            }
        }

        $pre = [];
        foreach (['hp', 'mp', 'str', 'def', 'mag', 'spr', 'luk'] as $key) {
            $pre[$key] = (int) $base[$key] + (int) (((int) ($job->{'bonus_'.$key} ?? 0)) * $jobLevel * 0.5);
        }
        $pre['agi'] = (int) $base['agi'] + (int) (((int) ($job->bonus_spd ?? 0)) * $jobLevel * 0.5);

        $equipmentTotals = ['hp' => 0, 'mp' => 0, 'str' => 0, 'def' => 0, 'agi' => 0, 'mag' => 0, 'spr' => 0, 'luk' => 0];
        $weapon = ['str' => 0, 'mag' => 0];
        $armor = ['def' => 0, 'spr' => 0];
        $virtualCharacter = $this->virtualCharacter($state);
        foreach ($state['equipment'] as $item) {
            if (! $item instanceof Item) {
                continue;
            }
            $itemStats = $this->statusService->equipmentStatsForItem($virtualCharacter, $item);
            foreach ($equipmentTotals as $key => $_) {
                $equipmentTotals[$key] += (int) ($itemStats[$key] ?? 0);
            }
            if ($item->type === 'weapon') {
                $weapon['str'] += (int) ($itemStats['str'] ?? 0);
                $weapon['mag'] += (int) ($itemStats['mag'] ?? 0);
                $equipmentTotals['str'] -= (int) ($itemStats['str'] ?? 0);
                $equipmentTotals['mag'] -= (int) ($itemStats['mag'] ?? 0);
            }
            if ($item->type === 'armor') {
                $armor['def'] += (int) ($itemStats['def'] ?? 0);
                $armor['spr'] += (int) ($itemStats['spr'] ?? 0);
                $equipmentTotals['def'] -= (int) ($itemStats['def'] ?? 0);
                $equipmentTotals['spr'] -= (int) ($itemStats['spr'] ?? 0);
            }
        }

        $weaponBaseStr = $pre['str'] + $equipmentTotals['str'];
        $weaponBaseMag = $pre['mag'] + $equipmentTotals['mag'];
        $armorBaseDef = $pre['def'] + $equipmentTotals['def'];
        $armorBaseSpr = $pre['spr'] + $equipmentTotals['spr'];

        return [
            'max_hp' => max(1, $pre['hp'] + $equipmentTotals['hp']),
            'max_mp' => max(0, $pre['mp'] + $equipmentTotals['mp']),
            'str' => max(1, $this->offenseCalculator->calculateEffectiveOffense($weaponBaseStr, $weapon['str'])),
            'def' => max(0, $armorBaseDef + max(intdiv($armor['def'], 8), $this->offenseCalculator->calculateProportionalBonus($armorBaseDef, $armor['def']))),
            'agi' => max(1, $pre['agi'] + $equipmentTotals['agi']),
            'mag' => max(0, $this->offenseCalculator->calculateEffectiveOffense($weaponBaseMag, $weapon['mag'])),
            'spr' => max(0, $armorBaseSpr + max(intdiv($armor['spr'], 8), $this->offenseCalculator->calculateProportionalBonus($armorBaseSpr, $armor['spr']))),
            'luk' => max(0, $pre['luk'] + $equipmentTotals['luk']),
        ];
    }

    private function virtualCharacter(array $state): Character
    {
        /** @var JobClass $job */
        $job = $state['job'];
        $character = new Character([
            'name' => '匿名冒険者',
            'level' => (int) $state['level'],
            'exp' => (int) $state['exp'],
            'money' => (int) $state['gold'],
            'current_job_id' => (int) $job->id,
            'current_city_id' => (int) $state['city']->id,
            'hp_base' => (int) $state['base']['hp'],
            'mp_base' => (int) $state['base']['mp'],
            'attack_base' => (int) $state['base']['str'],
            'defense_base' => (int) $state['base']['def'],
            'speed_base' => (int) $state['base']['agi'],
            'magic_base' => (int) $state['base']['mag'],
            'spirit_base' => (int) $state['base']['spr'],
            'luck_base' => (int) $state['base']['luk'],
            'current_hp' => max(1, (int) $state['current_hp']),
            'current_mp' => max(0, (int) $state['current_sp']),
            'bonus_points' => (int) $state['bonus_points'],
        ]);
        $character->setRelation('jobClass', $job);
        $character->setRelation('currentJob', $job);
        $character->exists = false;

        return $character;
    }

    private function shouldRest(array $state, string $profile, array $stats): bool
    {
        if ($state['last_battle_result'] === 'defeat' || (int) $state['current_hp'] <= 0) {
            return true;
        }
        if ($profile === 'beginner') {
            return (int) $state['current_hp'] < $stats['max_hp'] || (int) $state['current_sp'] < $stats['max_mp'];
        }
        if ($profile !== 'collector') {
            return false;
        }

        $enemyOffense = Enemy::query()
            ->where('area_id', $state['area']->id)
            ->where('is_boss', false)
            ->get(['str', 'mag'])
            ->max(fn (Enemy $enemy): int => max((int) $enemy->str, (int) $enemy->mag)) ?? 0;

        return (int) $state['current_hp'] <= (int) $enemyOffense;
    }

    private function equipmentCandidate(array $state, string $profile): ?Item
    {
        $currentScore = $this->profileStatScore($this->calculateStats($state), $profile);
        $candidates = Item::query()
            ->whereIn('type', ['weapon', 'armor'])
            ->where('is_active', true)
            ->where('is_shop_item', true)
            ->where('unlock_city_id', $state['city']->id)
            ->where('required_level', '<=', $state['level'])
            ->where('price', '>', 0)
            ->where('price', '<=', $state['gold'])
            ->orderBy('id')
            ->get();

        $best = null;
        $bestScore = $currentScore;
        $bestPrice = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $type = (string) $candidate->type;
            $current = $state['equipment'][$type] ?? null;
            if ($current instanceof Item && (int) $current->id === (int) $candidate->id) {
                continue;
            }
            $trial = $state;
            $trial['equipment'][$type] = $candidate;
            $score = $this->profileStatScore($this->calculateStats($trial), $profile);
            $price = $this->shopService->priceFor($this->virtualCharacter($state), $candidate);
            if ($score > $bestScore
                || ($score === $bestScore && $score > $currentScore && $price < $bestPrice)
                || ($score === $bestScore && $price === $bestPrice && $best && (int) $candidate->id < (int) $best->id)
            ) {
                $best = $candidate;
                $bestScore = $score;
                $bestPrice = $price;
            }
        }

        return $best;
    }

    /** @return list<int> */
    private function profileStatScore(array $stats, string $profile): array
    {
        return match ($profile) {
            'beginner' => [$stats['max_hp'], $stats['def'] + $stats['spr'], $stats['str'] + $stats['mag']],
            'efficiency' => [max($stats['str'], $stats['mag']), $stats['agi'], $stats['max_hp']],
            'collector' => [$stats['luk'], $stats['agi'], $stats['str'] + $stats['mag']],
        };
    }

    /** @return array<string, mixed> */
    private function evaluateJobChange(array &$state): array
    {
        /** @var JobClass $current */
        $current = $state['job'];
        $currentHistory = $state['job_histories'][(int) $current->id];
        if (! $currentHistory['mastered']) {
            return [
                'title' => '転職条件を確認',
                'reason' => "{$current->name}は職業ランク{$currentHistory['level']}で、まだマスター条件に達していません。",
            ];
        }
        if ((int) $state['level'] < 30) {
            return ['title' => '転職条件を確認', 'reason' => '現行条件のLv30に達していないため、転職を見送りました。'];
        }
        if ((int) $state['bonus_points'] > 0) {
            return ['title' => '転職条件を確認', 'reason' => '未配分ボーナスポイントがあるため、現行条件では転職できません。配分はLab対象外です。'];
        }

        $masteredIds = collect($state['job_histories'])
            ->filter(fn (array $history): bool => (bool) $history['mastered'])
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $candidate = JobClass::query()
            ->with('requirements')
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->whereKeyNot($current->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn (JobClass $job): bool => $this->supportedJobRequirementsMet($job, $state, $masteredIds));
        if (! $candidate) {
            return ['title' => '転職条件を確認', 'reason' => 'Labが扱うLv・職業マスター条件だけでは転職可能な職業が見つかりませんでした。'];
        }

        $state['job'] = $candidate;
        $state['job_histories'][(int) $candidate->id] ??= ['level' => 1, 'exp' => 0, 'mastered' => false];
        $stats = $this->calculateStats($state);
        $state['current_hp'] = min((int) $state['current_hp'], $stats['max_hp']);
        $state['current_sp'] = min((int) $state['current_sp'], $stats['max_mp']);

        return ['title' => $candidate->name.'へ転職', 'reason' => 'Lvと現行JobRequirementの対応可能な条件を満たしたため、メモリ上の現在職だけを変更しました。'];
    }

    private function supportedJobRequirementsMet(JobClass $job, array $state, array $masteredIds): bool
    {
        if (JobRankCatalog::isBasic($job->rank)) {
            return false;
        }
        if ($job->requirements->isEmpty()) {
            return false;
        }

        foreach ($job->requirements as $requirement) {
            if ($requirement->requirement_type === 'master_job') {
                if (! in_array((int) $requirement->required_job_id, $masteredIds, true)) {
                    return false;
                }
            } elseif ($requirement->requirement_type === 'character_level') {
                if ((int) $state['level'] < (int) $requirement->required_value) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    private function bossReady(array $state, string $profile): bool
    {
        if (isset($state['cleared_area_ids'][(int) $state['area']->id])) {
            return false;
        }

        return match ($profile) {
            'beginner' => (int) $state['level'] >= max(1, (int) $state['area']->recommended_level_max),
            'efficiency' => (int) $state['level'] >= max(1, (int) $state['area']->recommended_level_min),
            'collector' => ($state['wins_by_area'][(int) $state['area']->id] ?? 0) > 0
                && $this->unobservedSourceCountForArea($state) === 0,
        };
    }

    private function bossDecisionReason(array $state, string $profile): string
    {
        return match ($profile) {
            'beginner' => 'エリアの推奨Lv上限に達し、慎重方針の挑戦条件を満たしました。',
            'efficiency' => 'エリアの推奨Lv下限に達したため、早期攻略を選びました。',
            'collector' => '通常敵の登録drop元を一通り確認したため、ボスへ進みました。実drop獲得を意味しません。',
        };
    }

    private function normalEnemy(array $state, string $profile): ?Enemy
    {
        $enemies = Enemy::query()
            ->where('area_id', $state['area']->id)
            ->where('is_boss', false)
            ->orderBy('id')
            ->get();
        if ($enemies->isEmpty()) {
            return null;
        }

        return match ($profile) {
            'beginner' => $enemies->sortBy(fn (Enemy $enemy): array => [(int) $enemy->level, (int) $enemy->max_hp, (int) $enemy->id])->first(),
            'efficiency' => $enemies->sortByDesc(fn (Enemy $enemy): array => [(int) $enemy->exp_reward, (int) $enemy->job_exp_reward, -(int) $enemy->id])->first(),
            'collector' => $enemies->sortByDesc(fn (Enemy $enemy): array => [
                $this->unobservedSourceCount($enemy, $state),
                (int) $enemy->appearance_weight,
                -(int) $enemy->id,
            ])->first(),
        };
    }

    private function enemyDecisionReason(Enemy $enemy, string $profile, array $state): string
    {
        return match ($profile) {
            'beginner' => "通常敵のうちLvとHPが低い{$enemy->name}を選びました。",
            'efficiency' => "通常敵のうち獲得EXPが高い{$enemy->name}を選びました。",
            'collector' => $this->unobservedSourceCount($enemy, $state) > 0
                ? "未確認の登録drop元がある{$enemy->name}を選びました。実drop獲得を意味しません。"
                : "未確認の登録drop元がないため、出現weightの高い{$enemy->name}を選びました。",
        };
    }

    /** @return array<string, mixed> */
    private function executeBattle(array &$state, Enemy $enemy, string $battleType, int $seed): array
    {
        $stats = $this->calculateStats($state);
        $snapshot = $this->replayService->captureSynthetic([
            'level' => (int) $state['level'],
            'current_hp' => max(1, (int) $state['current_hp']),
            'current_sp' => max(0, (int) $state['current_sp']),
            'stats' => $stats,
            'job' => $this->jobSnapshot($state['job']),
            'equipment' => $this->equipmentSnapshot($state),
        ], $enemy, $battleType, ($seed + (int) $state['battle_count']) % (ValzeriaLabReplayService::MAX_SEED + 1));
        $result = $this->replayService->presentResult($this->replayService->executeSnapshot($snapshot));

        $state['battle_count']++;
        $state['current_hp'] = (int) $result['hp_after'];
        $state['current_sp'] = (int) $result['sp_after'];
        $state['last_battle_result'] = (string) $result['result'];
        $levelUps = [];
        $jobRankUps = [];
        if ($result['result'] === 'victory') {
            $state['wins']++;
            $state['wins_by_area'][(int) $state['area']->id] = ($state['wins_by_area'][(int) $state['area']->id] ?? 0) + 1;
            $state['gold'] += max(0, (int) $result['gold']);
            $state['exp'] += max(0, (int) $result['exp']);
            $levelUps = $this->applyLevelProgress($state);
            $jobRankUps = $this->applyJobProgress($state, max(0, (int) $result['job_exp']));
            $this->observeDropSources($state, $enemy);
        } elseif ($result['result'] === 'defeat') {
            $state['losses']++;
        } else {
            $state['timeouts']++;
        }

        $label = $result['result_label'];
        $reason = "{$label}。HP {$result['hp_before']}→{$result['hp_after']}、SP {$result['sp_before']}→{$result['sp_after']}、{$result['turn_count']}ターンでした。";
        if ($levelUps !== []) {
            $reason .= ' Lv'.implode('・Lv', $levelUps).'へ上昇しました。';
        }
        if ($jobRankUps !== []) {
            $reason .= ' 職業ランク'.implode('・', $jobRankUps).'へ上昇しました。';
        }

        return [
            'enemy' => (string) $enemy->name,
            'battle_type' => $battleType,
            'result' => (string) $result['result'],
            'result_label' => (string) $result['result_label'],
            'turn_count' => (int) $result['turn_count'],
            'damage_dealt' => (int) $result['damage_dealt'],
            'damage_taken' => (int) $result['damage_taken'],
            'exp' => (int) $result['exp'],
            'gold' => (int) $result['gold'],
            'job_exp' => (int) $result['job_exp'],
            'level_ups' => $levelUps,
            'job_rank_ups' => $jobRankUps,
            'log_excerpt' => collect($result['logs'])
                ->take(2)
                ->merge(collect($result['logs'])->take(-2))
                ->unique()
                ->map(fn (string $line): string => trim(html_entity_decode(strip_tags($line))))
                ->filter()
                ->values()
                ->all(),
            'reason' => $reason,
        ];
    }

    /** @return list<int> */
    private function applyLevelProgress(array &$state): array
    {
        $levels = [];
        while ((int) $state['level'] < 255
            && (int) $state['exp'] >= $this->levelService->getRequiredExp((int) $state['level'])
        ) {
            $state['exp'] -= $this->levelService->getRequiredExp((int) $state['level']);
            $state['level']++;
            $growth = $this->levelGrowth($state);
            foreach ($growth as $key => $amount) {
                $state['base'][$key] += $amount;
            }
            $state['bonus_points']++;
            $state['job_check_due'] = true;
            $levels[] = (int) $state['level'];
        }
        if ((int) $state['level'] >= 255) {
            $state['exp'] = 0;
        }

        return $levels;
    }

    /** @return array<string, int> */
    private function levelGrowth(array &$state): array
    {
        /** @var JobClass $job */
        $job = $state['job'];
        $rates = [
            'hp' => ((int) ($job->hp_rate ?? 100)) / 100,
            'mp' => ((int) ($job->mp_rate ?? 100)) / 100,
            'str' => ((int) ($job->atk_rate ?? 100)) / 100,
            'def' => ((int) ($job->def_rate ?? 100)) / 100,
            'agi' => ((int) ($job->spd_rate ?? 100)) / 100,
            'mag' => ((int) ($job->mag_rate ?? 100)) / 100,
            'spr' => ((int) ($job->spr_rate ?? 100)) / 100,
            'luk' => ((int) ($job->luck_rate ?? 100)) / 100,
        ];
        $bases = ['hp' => 8.25, 'mp' => 4.95, 'str' => 3.85, 'def' => 3.85, 'agi' => 3.85, 'mag' => 3.85, 'spr' => 3.85, 'luk' => 3.85];
        $growth = [];
        foreach ($bases as $key => $base) {
            $total = (float) $state['fractions'][$key] + ($base * self::GROWTH_MULTIPLIER * $rates[$key]);
            $growth[$key] = (int) floor($total);
            $state['fractions'][$key] = $total - $growth[$key];
        }

        return $growth;
    }

    /** @return list<int> */
    private function applyJobProgress(array &$state, int $gain): array
    {
        /** @var JobClass $job */
        $job = $state['job'];
        $history =& $state['job_histories'][(int) $job->id];
        if ($history['mastered']) {
            return [];
        }
        $history['exp'] += $this->levelService->capJobExpGain($gain);
        $rankUps = [];
        $maxLevel = max(1, (int) ($job->max_job_level ?? 10));
        while ((int) $history['level'] < $maxLevel) {
            $next = (int) $history['level'] + 1;
            $required = JobExpTable::query()->where('job_level', $next)->value('required_exp');
            if ($required === null) {
                break;
            }
            $required = (int) ((int) $required * $this->jobService->jobExpMultiplier($job->rank));
            if ((int) $history['exp'] < $required) {
                break;
            }
            $history['level'] = $next;
            $rankUps[] = $next;
        }
        if ((int) $history['level'] >= $maxLevel) {
            $history['mastered'] = true;
            $state['job_check_due'] = true;
        }

        return $rankUps;
    }

    /** @return array<string, mixed> */
    private function jobSnapshot(JobClass $job): array
    {
        return [
            'master_id' => (int) $job->id,
            'key' => (string) $job->key,
            'name' => (string) $job->name,
            'normal_attack_type' => $job->normal_attack_type,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function equipmentSnapshot(array $state): array
    {
        $character = $this->virtualCharacter($state);

        return collect($state['equipment'])
            ->filter(fn ($item): bool => $item instanceof Item)
            ->map(function (Item $item, string $slot) use ($character): array {
                $stats = $this->statusService->equipmentStatsForItem($character, $item);

                return [
                    'slot' => $slot,
                    'type' => (string) $item->type,
                    'name' => (string) $item->name,
                    'enhance_level' => 0,
                    'quality' => null,
                    'effective_stats' => [
                        'max_hp' => max(0, (int) ($stats['hp'] ?? 0)),
                        'max_mp' => max(0, (int) ($stats['mp'] ?? 0)),
                        'str' => max(0, (int) ($stats['str'] ?? 0)),
                        'def' => max(0, (int) ($stats['def'] ?? 0)),
                        'agi' => max(0, (int) ($stats['agi'] ?? 0)),
                        'mag' => max(0, (int) ($stats['mag'] ?? 0)),
                        'spr' => max(0, (int) ($stats['spr'] ?? 0)),
                        'luk' => max(0, (int) ($stats['luk'] ?? 0)),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function nextArea(array $state, string $profile): ?Area
    {
        $areas = Area::query()
            ->with('city')
            ->where('is_published', true)
            ->where('unlock_required_area_id', $state['area']->id)
            ->whereHas('enemies')
            ->orderBy('unlock_order')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($profile !== 'collector') {
            return $areas->first();
        }

        return $areas->sortByDesc(fn (Area $area): array => [
            $this->registeredSourceCountForArea($area),
            -(int) ($area->unlock_order ?? $area->sort_order ?? $area->id),
        ])->first();
    }

    private function loadDropSources(): void
    {
        $this->dropSources = [];
        EnemyDrop::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['enemy_id', 'item_id'])
            ->each(function (EnemyDrop $drop): void {
                $this->dropSources[(int) $drop->enemy_id]['item'][] = 'item:'.(int) $drop->item_id;
            });
        MaterialDrop::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['enemy_id', 'material_id'])
            ->each(function (MaterialDrop $drop): void {
                $this->dropSources[(int) $drop->enemy_id]['material'][] = 'material:'.(int) $drop->material_id;
            });
        foreach ($this->dropSources as &$sources) {
            $sources['item'] = array_values(array_unique($sources['item'] ?? []));
            $sources['material'] = array_values(array_unique($sources['material'] ?? []));
        }
        unset($sources);
    }

    private function observeDropSources(array &$state, Enemy $enemy): void
    {
        foreach ($this->dropSources[(int) $enemy->id] ?? [] as $sources) {
            foreach ($sources as $source) {
                $state['observed_sources'][$source] = true;
            }
        }
    }

    private function unobservedSourceCount(Enemy $enemy, array $state): int
    {
        return collect($this->dropSources[(int) $enemy->id] ?? [])
            ->flatten()
            ->unique()
            ->reject(fn (string $source): bool => isset($state['observed_sources'][$source]))
            ->count();
    }

    private function unobservedSourceCountForArea(array $state): int
    {
        return Enemy::query()
            ->where('area_id', $state['area']->id)
            ->where('is_boss', false)
            ->get()
            ->sum(fn (Enemy $enemy): int => $this->unobservedSourceCount($enemy, $state));
    }

    private function registeredSourceCountForArea(Area $area): int
    {
        return Enemy::query()
            ->where('area_id', $area->id)
            ->get(['id'])
            ->sum(fn (Enemy $enemy): int => collect($this->dropSources[(int) $enemy->id] ?? [])->flatten()->unique()->count());
    }

    /** @param list<array<string, mixed>> $timeline */
    private function record(
        array &$timeline,
        int $step,
        string $type,
        string $title,
        string $reason,
        array $state,
        ?array $battle = null,
        string $engine = 'Lab簡略モデル',
    ): void {
        $timeline[] = [
            'step' => $step,
            'type' => $type,
            'type_label' => match ($type) {
                'town' => '街',
                'explore' => '探索',
                'battle' => '戦闘',
                'inn' => '宿屋',
                'equipment' => '装備',
                'job' => '転職',
                'boss' => 'ボス挑戦',
                default => '判断',
            },
            'title' => $title,
            'reason' => $reason,
            'engine' => $engine,
            'state' => $this->stateSummary($state),
            'battle' => $battle,
        ];
    }

    /** @return array<string, mixed> */
    private function stateSummary(array $state): array
    {
        $stats = $this->calculateStats($state);
        /** @var JobClass $job */
        $job = $state['job'];
        $jobHistory = $state['job_histories'][(int) $job->id];

        return [
            'level' => (int) $state['level'],
            'exp' => (int) $state['exp'],
            'next_exp' => (int) $state['level'] >= 255 ? null : $this->levelService->getRequiredExp((int) $state['level']),
            'hp' => (int) $state['current_hp'],
            'sp' => (int) $state['current_sp'],
            'stats' => $stats,
            'gold' => (int) $state['gold'],
            'job' => (string) $job->name,
            'job_rank' => (int) $jobHistory['level'],
            'job_exp' => (int) $jobHistory['exp'],
            'job_mastered' => (bool) $jobHistory['mastered'],
            'bonus_points' => (int) $state['bonus_points'],
            'city' => (string) $state['city']->name,
            'area' => (string) $state['area']->name,
            'equipment' => [
                'weapon' => $state['equipment']['weapon']?->name,
                'armor' => $state['equipment']['armor']?->name,
            ],
            'wins' => (int) $state['wins'],
            'losses' => (int) $state['losses'],
            'timeouts' => (int) $state['timeouts'],
            'observed_drop_sources' => count($state['observed_sources']),
            'cleared_areas' => count($state['cleared_area_ids']),
        ];
    }

    private function stopReasonLabel(string $reason): string
    {
        return match ($reason) {
            'action_limit' => '指定した行動上限に到達',
            'insufficient_gold_for_inn' => '宿代不足',
            'enemy_missing' => '選択済み敵マスタが消失',
            'normal_enemy_missing' => '通常敵が存在しない',
            'boss_missing' => 'ボスが存在しない',
            'no_next_area' => '直接の次エリアがない',
            default => '試行完了',
        };
    }
}
