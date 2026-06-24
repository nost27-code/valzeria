<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Area;
use App\Models\CharacterAreaProgress;

class CharacterGoalService
{
    /**
     * キャラクターの進行状況に応じた「次の目標」テキストを返す
     */
    public function getNextGoal(Character $character): string
    {
        // 1. HPが極端に少ない場合
        if ($character->current_hp <= ($character->hp_base * 0.3)) {
            return 'HPが減っています。宿屋で休んでから探索しましょう';
        }

        // 2. 解放済みだが、ボスが未撃破のエリアを探す
        // orderBy('unlock_order')等で一番最初の未撃破エリアを特定する
        $areas = Area::orderBy('sort_order', 'asc')->get();
        $progresses = CharacterAreaProgress::where('character_id', $character->id)->get()->keyBy('area_id');

        $nextTargetArea = null;
        $unlockedCount = 0;

        foreach ($areas as $area) {
            $progress = $progresses->get($area->id);
            if ($progress && $progress->is_unlocked) {
                $unlockedCount++;
                if (!$progress->boss_defeated) {
                    $nextTargetArea = $area;
                    break;
                }
            }
        }

        if ($nextTargetArea) {
            // ボス未撃破の解放済みエリアがある場合
            $nextArea = Area::where('unlock_required_area_id', $nextTargetArea->id)->first();
            if ($nextArea) {
                return "{$nextTargetArea->name}のボスを倒して、{$nextArea->name}を解放しよう";
            } else {
                return "{$nextTargetArea->name}のボスを倒して、現在の最深部を突破しよう";
            }
        }

        // 3. ボス未撃破のエリアがなく、解放エリアが全てボス撃破済みの場合
        // = 全エリアクリア済み または 次のエリア解放条件を満たしているがレコードがないだけ
        if ($unlockedCount === $areas->count()) {
            return '実装済みの迷宮をすべて突破しました。次の更新を待ちましょう';
        }

        return 'エリアのボスを倒して、次の迷宮を解放しよう';
    }
}
