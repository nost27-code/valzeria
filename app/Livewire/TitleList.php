<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Title;
use Illuminate\Support\Facades\Auth;
use App\Services\TitleService;

class TitleList extends Component
{
    public $titles = [];
    public $characterTitles = [];
    public $summary = [
        'unlocked_count' => 0,
        'total_count' => 0,
        'equipped_name' => null,
        'hidden_count' => 0,
    ];

    public function mount()
    {
        $this->updateCharacterTitles();
        $this->updateTitles();
    }

    public function updateCharacterTitles()
    {
        if (Auth::check()) {
            $character = Auth::user()->currentCharacter();
            if ($character) {
                $this->characterTitles = $character->titles()
                    ->get(['title_id', 'is_equipped', 'created_at'])
                    ->mapWithKeys(fn ($row) => [
                        $row->title_id => [
                            'is_equipped' => (bool) $row->is_equipped,
                            'unlocked_at' => optional($row->created_at)->format('Y/m/d'),
                        ],
                    ])
                    ->toArray();
            }
        }
    }

    public function updateTitles()
    {
        $titles = Title::orderBy('display_order')->orderBy('id')->get();
        $this->titles = $titles->toArray();

        $equippedId = null;
        foreach ($this->characterTitles as $titleId => $row) {
            if ((bool) ($row['is_equipped'] ?? false)) {
                $equippedId = (int) $titleId;
                break;
            }
        }

        $this->summary = [
            'unlocked_count' => count($this->characterTitles),
            'total_count' => $titles->count(),
            'equipped_name' => $equippedId ? ($titles->firstWhere('id', $equippedId)?->name) : null,
            'hidden_count' => $titles->where('is_hidden', true)->count(),
        ];
    }

    public function equipTitle($titleId, TitleService $titleService)
    {
        if (Auth::check()) {
            $character = Auth::user()->currentCharacter();
            if ($character) {
                $titleService->equipTitle($character, $titleId);
                $this->updateCharacterTitles();
                $this->updateTitles();
                // 左サイドバーに更新を通知
                $this->dispatch('character-updated');
                $this->dispatch('titleEquipped');
                
                session()->flash('message', '称号を変更しました！');
            }
        }
    }

    public function render()
    {
        return view('livewire.title-list');
    }
}
