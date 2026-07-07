<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use Illuminate\Support\Facades\Schema;

class AreaService
{
    private const AREA_CLEAR_MATERIAL_STORAGE_BONUS = 200;
    private const AREA_CLEAR_EQUIPMENT_STORAGE_BONUS = 100;

    /**
     * キャラクターがアクセスできるエリアとその進行状況を一覧取得する
     */
    public function getAreasWithProgress(Character $character)
    {
        app(DiscoveryService::class)->ensureInitialDiscoveries($character);

        $areas = Area::where('city_id', $character->current_city_id)
                     ->orderBy('sort_order', 'asc')
                     ->get();

        // 最初のエリアは常に進行データを生成して解放済みにする
        $firstArea = $areas->first();
        if ($firstArea) {
            CharacterAreaProgress::firstOrCreate(
                ['character_id' => $character->id, 'area_id' => $firstArea->id],
                ['is_unlocked' => true, 'unlocked_at' => now()]
            );
        }

        // キャラクターの進行度を取得
        $progresses = CharacterAreaProgress::where('character_id', $character->id)
            ->get()
            ->keyBy('area_id');

        // マスター職一覧を事前に取得
        $masteredJobKeys = $character->jobHistories()->where('is_mastered', true)
            ->join('job_classes', 'character_jobs.job_class_id', '=', 'job_classes.id')
            ->pluck('job_classes.key')->toArray();

        // 職業マスタ（名前解決用）
        $allJobClasses = \App\Models\JobClass::all()->keyBy('key');

        // 各エリアに対して、解放されているかどうかのフラグを付与する
        foreach ($areas as $area) {
            $progress = $progresses->get($area->id);
            $isDiscovered = $progress && in_array((string) ($progress->discovery_state ?? ''), ['discovered', 'cleared'], true);
            $area->is_unlocked = $progress ? ((bool) $progress->is_unlocked || $isDiscovered) : false;
            $area->boss_defeated = $progress ? $progress->boss_defeated : false;
            $area->development_point = $progress ? (int) ($progress->development_point ?? 0) : 0;
            $area->discovery_state = $progress ? (string) ($progress->discovery_state ?? 'undiscovered') : 'undiscovered';

            // マスター条件の判定追加
            $area->meets_job_requirements = true;
            $missingJobNames = [];
            if ($area->required_master_job_keys) {
                $requiredKeys = json_decode($area->required_master_job_keys, true);
                if (is_array($requiredKeys)) {
                    $missingKeys = array_diff($requiredKeys, $masteredJobKeys);
                    if (!empty($missingKeys)) {
                        $area->meets_job_requirements = false;
                        foreach ($missingKeys as $mKey) {
                            if (isset($allJobClasses[$mKey])) {
                                $missingJobNames[] = $allJobClasses[$mKey]->name;
                            }
                        }
                    }
                }
            }
            $area->missing_job_names = $missingJobNames;
        }

        // 表示すべきエリアのフィルタリング
        $usesDiscoveryDisplay = Schema::hasTable('area_discovery_links')
            && Schema::hasColumn('character_area_progresses', 'discovery_state')
            && \App\Models\AreaDiscoveryLink::exists();

        $filteredAreas = $areas->filter(function ($area) use ($areas, $usesDiscoveryDisplay) {
            // 既に解放済みのエリアは表示
            if ($area->is_unlocked) {
                return true;
            }

            if ($usesDiscoveryDisplay) {
                return false;
            }
            
            // 未解放の場合でも、前提エリアが解放済みであれば「一つ先の目標」として表示（入れない状態）
            if ($area->unlock_required_area_id) {
                $requiredArea = $areas->firstWhere('id', $area->unlock_required_area_id);
                if ($requiredArea && $requiredArea->is_unlocked) {
                    return true;
                }
            }
            
            // それ以上先のエリアは非表示
            return false;
        });

        return $filteredAreas->values();
    }

    /**
     * キャラクターが指定エリアに入場可能か判定する
     */
    public function canEnterArea(Character $character, int $areaId): bool
    {
        $areas = $this->getAreasWithProgress($character);
        $targetArea = collect($areas)->firstWhere('id', $areaId);
        
        if (!$targetArea) {
            return false;
        }
        
        if (!$targetArea->is_unlocked) {
            return false;
        }

        if (isset($targetArea->meets_job_requirements) && !$targetArea->meets_job_requirements) {
            return false;
        }

        return true;
    }

    /**
     * ボス討伐により次のエリアを解放する
     * @return array 解放されたエリアモデルの配列
     */
    public function unlockNextArea(Character $character, int $clearedAreaId, ?array &$clearRewards = null): array
    {
        if ($clearRewards !== null) {
            $clearRewards = [];
        }

        $clearedArea = Area::find($clearedAreaId);
        if (!$clearedArea) {
            return [];
        }
        $isCityFinalNormalArea = $this->isCityFinalNormalArea($clearedArea);
        
        // クリアしたエリアの進捗を更新
        $progress = CharacterAreaProgress::firstOrCreate(
            ['character_id' => $character->id, 'area_id' => $clearedAreaId]
        );
        
        if (!$progress->boss_defeated) {
            $progress->boss_defeated = true;
            $progress->boss_defeated_at = now();
            $progress->discovery_state = 'cleared';
            $progress->cleared_at ??= $progress->boss_defeated_at;
            $progress->save();

            if ($clearRewards !== null && $isCityFinalNormalArea) {
                $clearRewards['storage'] = $this->grantAreaClearStorageBonus($character);
            }
        }

        $usesDiscoveryProgress = Schema::hasTable('area_discovery_links')
            && \App\Models\AreaDiscoveryLink::exists();
        if ($usesDiscoveryProgress) {
            return [];
        }

        // 次のエリア（条件がclearedAreaIdのもの）を探して解放
        $unlockedAreas = [];
        $nextAreas = Area::where('unlock_required_area_id', $clearedAreaId)->get();
        foreach ($nextAreas as $nextArea) {
            $nextProgress = CharacterAreaProgress::firstOrCreate(
                ['character_id' => $character->id, 'area_id' => $nextArea->id]
            );
            
            if (!$nextProgress->is_unlocked) {
                $nextProgress->is_unlocked = true;
                $nextProgress->unlocked_at = now();
                $nextProgress->discovery_state = 'discovered';
                $nextProgress->discovered_at ??= $nextProgress->unlocked_at;
                $nextProgress->save();
                
                // 公開ログ
                app(PublicLogService::class)->addLog(
                    'area',
                    "【解放】{$character->name}さんが新たな領域「{$nextArea->name}」への道を開きました！",
                    $character,
                    2
                );
                
                $unlockedAreas[] = $nextArea;
            }
        }
        
        // --- 次の街の解放判定 ---
        // クリアしたエリアがその街の「通常」の最後のエリアかどうか（sort_order が最大、裏ダンジョン除外）
        $lastAreaInCity = Area::where('city_id', $clearedArea->city_id)
                              ->where('id', '<=', 70) // 裏ダンジョン(71〜)を除外
                              ->orderBy('sort_order', 'desc')
                              ->first();
        if ($lastAreaInCity && $lastAreaInCity->id === $clearedAreaId) {
            $currentCity = $clearedArea->city;
            if ($currentCity) {
                // sort_order が現在の街より大きい最初の街を取得
                $nextCity = \App\Models\City::where('sort_order', '>', $currentCity->sort_order)->orderBy('sort_order', 'asc')->first();
                
                if ($nextCity) {
                    $highestCity = $character->highestCity;
                    $highestOrder = $highestCity ? $highestCity->sort_order : 0;
                    
                    if ($nextCity->sort_order > $highestOrder) {
                        $character->highest_city_id = $nextCity->id;
                        $character->save();
                        
                        // 公開ログ（街の解放）
                        app(PublicLogService::class)->addLog(
                            'system',
                            "【新天地】{$character->name}さんが新たな街「{$nextCity->name}」への道を開きました！",
                            $character,
                            2
                        );
                    }
                }
            }
        }
        
        return $unlockedAreas;
    }

    private function isCityFinalNormalArea(Area $area): bool
    {
        if (!$area->city_id || (int) $area->id > 70) {
            return false;
        }

        $lastAreaId = Area::where('city_id', $area->city_id)
            ->where('id', '<=', 70)
            ->orderBy('sort_order', 'desc')
            ->orderBy('id', 'desc')
            ->value('id');

        return (int) $lastAreaId === (int) $area->id;
    }

    /**
     * @return array<string,int>
     */
    private function grantAreaClearStorageBonus(Character $character): array
    {
        $materialBefore = max(500, (int) ($character->material_storage_limit ?? 500));
        $equipmentBefore = max(300, (int) ($character->equipment_storage_limit ?? 300));
        $materialAfter = $materialBefore + self::AREA_CLEAR_MATERIAL_STORAGE_BONUS;
        $equipmentAfter = $equipmentBefore + self::AREA_CLEAR_EQUIPMENT_STORAGE_BONUS;

        $character->forceFill([
            'material_storage_limit' => $materialAfter,
            'equipment_storage_limit' => $equipmentAfter,
        ])->save();

        return [
            'material_bonus' => self::AREA_CLEAR_MATERIAL_STORAGE_BONUS,
            'equipment_bonus' => self::AREA_CLEAR_EQUIPMENT_STORAGE_BONUS,
            'material_before' => $materialBefore,
            'material_after' => $materialAfter,
            'equipment_before' => $equipmentBefore,
            'equipment_after' => $equipmentAfter,
        ];
    }
}
