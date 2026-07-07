<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use Livewire\Component;
use Livewire\WithPagination;

class PlayerLogs extends Component
{
    use WithPagination;

    // 検索用（必要なら後で実装）
    public $searchQuery = '';

    public function updatingSearchQuery()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Character::with(['user', 'currentCity', 'currentJob', 'areaProgresses', 'cityDiscoveries']);

        if (!empty($this->searchQuery)) {
            $search = trim($this->searchQuery);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('id', $search)
                    ->orWhere('user_id', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', '%' . $search . '%')
                            ->orWhere('id', $search);
                    });
            });
        }

        // ランキングの基準：レベル降順、経験値降順
        $query->orderBy('level', 'desc')->orderBy('exp', 'desc');

        $characters = $query->paginate(50);

        $statusService = app(CharacterStatusService::class);
        $powerService = app(CharacterPowerService::class);

        $characters->setCollection($characters->getCollection()->map(function (Character $character) use ($statusService, $powerService) {
            $finalStats = $statusService->getFinalStats($character);

            $character->setAttribute('admin_power', $powerService->fromFinalStats($finalStats));
            $character->setAttribute('admin_tester_copy_payload', $this->testerCopyPayload($character));

            return $character;
        }));

        // 現在のページのオフセットを取得して順位を計算する
        // $characters->firstItem() で現在のページの最初のアイテムの全体におけるインデックス（1始まり）が取れる
        $rankOffset = $characters->firstItem() ?? 1;

        return view('livewire.admin.player-logs', [
            'characters' => $characters,
            'rankOffset' => $rankOffset,
        ])->layout('components.layouts.admin');
    }

    private function testerCopyPayload(Character $character): string
    {
        return json_encode([
            'source' => 'admin.player-logs',
            'character_id' => (int) $character->id,
            'user_id' => (int) ($character->user_id ?? 0),
            'name' => (string) $character->name,
            'level' => (int) $character->level,
            'hp_base' => (int) $character->hp_base,
            'mp_base' => (int) $character->mp_base,
            'attack_base' => (int) $character->attack_base,
            'defense_base' => (int) $character->defense_base,
            'speed_base' => (int) $character->speed_base,
            'magic_base' => (int) $character->magic_base,
            'luck_base' => (int) $character->luck_base,
            'spirit_base' => (int) ($character->spirit_base ?? 0),
            'power' => (int) ($character->admin_power ?? 0),
            'progress' => [
                'current_city_id' => (int) ($character->current_city_id ?? 1),
                'highest_city_id' => (int) ($character->highest_city_id ?? $character->current_city_id ?? 1),
                'area_progresses' => $character->areaProgresses
                    ->map(fn ($progress) => [
                        'area_id' => (int) $progress->area_id,
                        'is_unlocked' => (bool) $progress->is_unlocked,
                        'boss_defeated' => (bool) $progress->boss_defeated,
                        'development_point' => (int) ($progress->development_point ?? 0),
                        'discovery_state' => (string) ($progress->discovery_state ?? 'undiscovered'),
                        'unlocked_at' => optional($progress->unlocked_at)->toDateTimeString(),
                        'boss_defeated_at' => optional($progress->boss_defeated_at)->toDateTimeString(),
                        'rumored_at' => optional($progress->rumored_at)->toDateTimeString(),
                        'discovered_at' => optional($progress->discovered_at)->toDateTimeString(),
                        'cleared_at' => optional($progress->cleared_at)->toDateTimeString(),
                    ])
                    ->values()
                    ->all(),
                'city_discoveries' => $character->cityDiscoveries
                    ->map(fn ($discovery) => [
                        'city_id' => (int) $discovery->city_id,
                        'discovery_state' => (string) ($discovery->discovery_state ?? 'discovered'),
                        'rumored_at' => optional($discovery->rumored_at)->toDateTimeString(),
                        'discovered_at' => optional($discovery->discovered_at)->toDateTimeString(),
                    ])
                    ->values()
                    ->all(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
