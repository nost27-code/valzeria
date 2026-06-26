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
        if ($character->current_hp <= ($character->hp_base * 0.3)) {
            return 'HPが減っています。宿屋で休んでから探索しましょう';
        }

        // 解放済みでボス未撃破の最初のエリアを1クエリで取得
        $nextTargetArea = Area::join('character_area_progresses as cap', 'areas.id', '=', 'cap.area_id')
            ->where('cap.character_id', $character->id)
            ->where('cap.is_unlocked', true)
            ->where('cap.boss_defeated', false)
            ->orderBy('areas.sort_order', 'asc')
            ->select('areas.*')
            ->first();

        if ($nextTargetArea) {
            $nextArea = Area::where('unlock_required_area_id', $nextTargetArea->id)->first();
            if ($nextArea) {
                return "{$nextTargetArea->name}のボスを倒して、{$nextArea->name}を解放しよう";
            }
            return "{$nextTargetArea->name}のボスを倒して、現在の最深部を突破しよう";
        }

        $totalAreas = Area::count();
        $clearedCount = CharacterAreaProgress::where('character_id', $character->id)
            ->where('is_unlocked', true)
            ->where('boss_defeated', true)
            ->count();

        if ($clearedCount >= $totalAreas) {
            return '実装済みの迷宮をすべて突破しました。次の更新を待ちましょう';
        }

        return 'エリアのボスを倒して、次の迷宮を解放しよう';
    }
}
