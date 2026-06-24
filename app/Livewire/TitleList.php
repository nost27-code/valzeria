<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Title;
use Illuminate\Support\Facades\Auth;
use App\Services\TitleService;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.app')]
class TitleList extends Component
{
    public $titles = [];
    public $characterTitles = [];

    public function mount()
    {
        $this->updateCharacterTitles();
        
        // 全ての称号を表示順に取得（カテゴリのグループ化はしない）
        $this->titles = Title::orderBy('display_order')->get()->toArray();
    }

    public function updateCharacterTitles()
    {
        if (Auth::check()) {
            $character = Auth::user()->currentCharacter();
            if ($character) {
                // キャラクターが所持している称号IDの配列
                $this->characterTitles = $character->titles()->pluck('title_id')->toArray();
            }
        }
    }

    public function equipTitle($titleId, TitleService $titleService)
    {
        if (Auth::check()) {
            $character = Auth::user()->currentCharacter();
            if ($character) {
                $titleService->equipTitle($character, $titleId);
                $this->updateCharacterTitles();
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
