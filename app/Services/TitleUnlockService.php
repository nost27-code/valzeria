<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\JobClass;
use App\Models\Title;
use Illuminate\Database\Eloquent\Collection;

class TitleUnlockService
{
    protected TitleService $titleService;

    public function __construct(TitleService $titleService)
    {
        $this->titleService = $titleService;
    }

    /**
     * 特定のキャラクターについて、すべての獲得可能な称号をチェックして付与する
     */
    public function checkAllUnlocks(Character $character): array
    {
        $unlockedTitles = [];
        $unlockedTitles = array_merge($unlockedTitles, $this->checkBattleTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkAreaClearTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkJobTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkLevelTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkEquipmentTitles($character));

        return $unlockedTitles;
    }

    /**
     * 戦闘系（戦闘回数、ボス撃破回数）の称号をチェック
     */
    public function checkBattleTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        // count系の称号を取得
        $titles = Title::where('target_type', 'count')->get();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;
            $targetCount = (int) $title->target_id;

            if ($title->unlock_type === 'battle_win_count') {
                // 戦闘勝利回数は wins プロパティを使用する
                $wins = $character->wins ?? 0;
                if ($wins >= $targetCount) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'boss_clear_count') {
                // ボス撃破数は area_progresses の boss_defeated = true の数を数える
                $bossDefeatedCount = $character->areaProgresses()->where('boss_defeated', true)->count();
                if ($bossDefeatedCount >= $targetCount) {
                    $shouldUnlock = true;
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }

    /**
     * ダンジョンクリア系、街クリア系、ワールドクリア系の称号をチェック
     */
    public function checkAreaClearTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        $titles = Title::whereIn('target_type', ['dungeon', 'city', 'world'])->get();

        // 事前にクリア済みエリアIDの配列を取得
        $clearedAreaIds = $character->areaProgresses()->where('boss_defeated', true)->pluck('area_id')->toArray();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;

            if ($title->target_type === 'dungeon' && $title->unlock_type === 'dungeon_boss_clear') {
                // 特定のダンジョンのボス撃破
                $targetAreaId = (int) $title->target_id;
                if (in_array($targetAreaId, $clearedAreaIds)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->target_type === 'city' && $title->unlock_type === 'city_all_dungeons_clear') {
                // 特定の街の全ダンジョン制覇
                $targetCityId = (int) $title->target_id;
                // その街に属するエリアのID一覧
                $cityAreaIds = Area::where('city_id', $targetCityId)->pluck('id')->toArray();

                // cityAreaIds が全て clearedAreaIds に含まれているか
                if (! empty($cityAreaIds)) {
                    $isAllCleared = true;
                    foreach ($cityAreaIds as $areaId) {
                        if (! in_array($areaId, $clearedAreaIds)) {
                            $isAllCleared = false;
                            break;
                        }
                    }
                    if ($isAllCleared) {
                        $shouldUnlock = true;
                    }
                }
            } elseif ($title->target_type === 'world' && $title->unlock_type === 'all_dungeons_clear') {
                // 全ダンジョン制覇
                $allAreaIds = Area::pluck('id')->toArray();
                if (! empty($allAreaIds)) {
                    $isAllCleared = true;
                    foreach ($allAreaIds as $areaId) {
                        if (! in_array($areaId, $clearedAreaIds)) {
                            $isAllCleared = false;
                            break;
                        }
                    }
                    if ($isAllCleared) {
                        $shouldUnlock = true;
                    }
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }

    /**
     * 職業系（特定職業マスター、上級職転職、全職マスター等）の称号をチェック
     */
    public function checkJobTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        $titles = Title::whereIn('target_type', ['job_name', 'rank', 'count', 'all_jobs'])->get();

        // 転職履歴・マスター状況を取得
        $jobHistories = $character->jobHistories()->with('jobClass')->get();
        $masteredJobNames = [];
        $experiencedRanks = [];
        $masteredCount = 0;

        foreach ($jobHistories as $jh) {
            $jobClass = $jh->jobClass;
            if (! $jobClass) {
                continue;
            }

            $experiencedRanks[] = strtolower(trim((string) $jobClass->rank));

            // max_levelに達していればマスターとする
            if ($jh->job_level >= $jobClass->max_level) {
                $masteredJobNames[] = $jobClass->name;
                $masteredCount++;
            }
        }

        $allJobsCount = JobClass::count();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;

            if ($title->unlock_type === 'job_master' && $title->target_type === 'job_name') {
                // 特定職業をマスター
                if (in_array($title->target_id, $masteredJobNames)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'first_rank_job' && $title->target_type === 'rank') {
                // 特定ランクの職に転職したか
                $targetRank = strtolower(trim((string) $title->target_id));
                if (in_array($targetRank, $experiencedRanks, true)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'job_master_count' && $title->target_type === 'count') {
                // マスターした職業の数
                $targetCount = (int) $title->target_id;
                if ($masteredCount >= $targetCount) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'all_jobs_master' && $title->target_type === 'all_jobs') {
                // 全職業マスター
                if ($masteredCount >= $allJobsCount && $allJobsCount > 0) {
                    $shouldUnlock = true;
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }

    /**
     * キャラクターLv到達系の称号をチェック
     */
    public function checkLevelTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        $titles = Title::where('unlock_type', 'character_level')
            ->where('target_type', 'level')
            ->get();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            if ((int) ($character->level ?? 1) < (int) $title->target_id) {
                continue;
            }

            $this->titleService->unlockTitle($character, $title->id);
            $unlockedTitles[] = $title;
            $ownedTitleIds[] = $title->id;
        }

        return $unlockedTitles;
    }

    /**
     * 今回追加した進行実績称号（ID 112〜121）のうち、未獲得かつ条件達成済みの称号を返す。
     *
     * @return Collection<int, Title>
     */
    public function eligibleNewProgressionTitles(Character $character): Collection
    {
        $ownedTitleIds = $character->titles()->pluck('title_id')->all();
        $titles = Title::query()
            ->whereBetween('id', [112, 121])
            ->whereNotIn('id', $ownedTitleIds)
            ->get();

        if ($titles->isEmpty()) {
            return $titles;
        }

        $experiencedRanks = $character->jobHistories()
            ->with('jobClass:id,rank')
            ->get()
            ->pluck('jobClass.rank')
            ->filter()
            ->map(static fn ($rank): string => strtolower(trim((string) $rank)))
            ->all();

        return $titles->filter(static function (Title $title) use ($character, $experiencedRanks): bool {
            return match ($title->unlock_type.':'.$title->target_type) {
                'character_level:level' => (int) ($character->level ?? 1) >= (int) $title->target_id,
                'battle_win_count:count' => (int) ($character->wins ?? 0) >= (int) $title->target_id,
                'first_rank_job:rank' => in_array(
                    strtolower(trim((string) $title->target_id)),
                    $experiencedRanks,
                    true,
                ),
                default => false,
            };
        })->values();
    }

    /**
     * 現在所持している装備の強化値・品質・特性による称号をチェック
     */
    public function checkEquipmentTitles(Character $character): array
    {
        $unlockedTitles = [];

        foreach ($this->eligibleEquipmentTitles($character) as $title) {
            $this->titleService->unlockTitle($character, $title->id);
            $unlockedTitles[] = $title;
        }

        return $unlockedTitles;
    }

    /**
     * 現在所持している装備から、未獲得かつ条件達成済みの称号を返す。
     *
     * @return Collection<int, Title>
     */
    public function eligibleEquipmentTitles(Character $character): Collection
    {
        $ownedTitleIds = $character->titles()->pluck('title_id')->all();

        $titles = Title::whereIn('unlock_type', [
            'equipment_enhance_level',
            'equipment_quality',
            'weapon_species_killer',
            'armor_species_resist',
            'equipment_trait_level',
            'equipment_masterpiece',
        ])
            ->whereNotIn('id', $ownedTitleIds)
            ->get();

        if ($titles->isEmpty()) {
            return $titles;
        }

        $equipment = $character->characterItems()
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->with(['item:id,type,weapon_rank,armor_rank,innate_killer_species_key,innate_killer_damage_rate'])
            ->get([
                'id',
                'character_id',
                'item_id',
                'enhance_level',
                'affix_prefix_id',
                'affix_suffix_id',
                'affix_prefix_level',
                'affix_suffix_level',
                'affix_quality',
                'killer_species_key',
                'killer_damage_rate',
                'resist_species_key',
                'species_damage_reduction_rate',
            ]);

        if ($equipment->isEmpty()) {
            return $titles->take(0);
        }

        $qualityRank = static fn (?string $quality): int => match (strtolower((string) $quality)) {
            'excellent' => 2,
            'good' => 1,
            default => 0,
        };
        $traitLevel = static fn ($characterItem): int => max(
            $characterItem->effectiveAffixPrefixLevel(),
            $characterItem->effectiveAffixSuffixLevel(),
        );

        $maxEnhanceLevel = (int) $equipment->max(fn ($characterItem) => (int) $characterItem->enhance_level);
        $maxQualityRank = (int) $equipment->max(fn ($characterItem) => $qualityRank($characterItem->affix_quality));
        $maxTraitLevel = (int) $equipment->max($traitLevel);
        $hasWeaponKiller = $equipment->contains(fn ($characterItem): bool => $characterItem->item?->type === 'weapon'
            && ($characterItem->hasInnateKiller()
                || (filled($characterItem->killer_species_key) && $characterItem->effectiveKillerDamageRate() > 0))
        );
        $hasArmorResist = $equipment->contains(fn ($characterItem): bool => $characterItem->item?->type === 'armor'
            && filled($characterItem->resist_species_key)
            && $characterItem->effectiveSpeciesDamageReductionRate() > 0
        );
        $hasMasterpiece = $equipment->contains(fn ($characterItem): bool => (int) $characterItem->enhance_level >= 30
            && $qualityRank($characterItem->affix_quality) >= 2
            && $traitLevel($characterItem) >= 5
        );

        return $titles->filter(function (Title $title) use (
            $qualityRank,
            $maxEnhanceLevel,
            $maxQualityRank,
            $maxTraitLevel,
            $hasWeaponKiller,
            $hasArmorResist,
            $hasMasterpiece,
        ): bool {
            $targetNumber = (int) $title->target_id;
            $targetQualityRank = $qualityRank($title->target_id);

            return match ($title->unlock_type.':'.$title->target_type) {
                'equipment_enhance_level:enhance_level' => $targetNumber > 0 && $maxEnhanceLevel >= $targetNumber,
                'equipment_quality:quality' => $targetQualityRank > 0 && $maxQualityRank >= $targetQualityRank,
                'weapon_species_killer:killer' => $title->target_id === 'any' && $hasWeaponKiller,
                'armor_species_resist:resist' => $title->target_id === 'any' && $hasArmorResist,
                'equipment_trait_level:trait_level' => $targetNumber > 0 && $maxTraitLevel >= $targetNumber,
                'equipment_masterpiece:masterpiece' => $title->target_id === '30:excellent:5' && $hasMasterpiece,
                default => false,
            };
        })->values();
    }
}
