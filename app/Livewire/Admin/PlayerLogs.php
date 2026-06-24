<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Character;

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
        $query = Character::with(['user', 'currentCity']);

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

        // 現在のページのオフセットを取得して順位を計算する
        // $characters->firstItem() で現在のページの最初のアイテムの全体におけるインデックス（1始まり）が取れる
        $rankOffset = $characters->firstItem() ?? 1;

        return view('livewire.admin.player-logs', [
            'characters' => $characters,
            'rankOffset' => $rankOffset,
        ])->layout('components.layouts.admin');
    }
}
