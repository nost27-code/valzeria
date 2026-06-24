<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\ExplorationStateService;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.simple')]
class CharacterSelect extends Component
{
    public $characters;

    public function mount()
    {
        $this->characters = Auth::user()->characters()->with('jobClass')->get();

        // 1キャラの場合は自動選択し、探索中なら探索へ復帰する
        if ($this->characters->count() === 1) {
            $character = $this->characters->first();
            session([
                'current_character_id' => $character->id,
                'current_location' => $this->initialLocationFor($character),
            ]);
            return redirect()->route($this->initialRouteFor($character));
        }

        // 0キャラの場合は新規作成画面へ
        if ($this->characters->count() === 0) {
            return redirect()->route('character.create');
        }
    }

    public function selectCharacter($characterId)
    {
        // 自分のキャラクターか確認
        $character = Auth::user()->characters()->find($characterId);
        
        if ($character) {
            // セッションに選択したキャラクターIDと復帰先を保存
            session([
                'current_character_id' => $character->id,
                'current_location' => $this->initialLocationFor($character),
            ]);
            return redirect()->route($this->initialRouteFor($character));
        }
    }

    private function initialLocationFor(Character $character): string
    {
        return app(ExplorationStateService::class)->hasActiveExploration($character)
            ? 'dungeon'
            : 'home';
    }

    private function initialRouteFor(Character $character): string
    {
        return app(ExplorationStateService::class)->hasActiveExploration($character)
            ? 'battle.resume'
            : 'home';
    }

    public function deleteCharacter($characterId)
    {
        $character = Auth::user()->characters()->find($characterId);
        
        if ($character) {
            $character->delete();
            // 一覧を再取得
            $this->characters = Auth::user()->characters()->with('jobClass')->get();
        }
    }

    public function createNewCharacter()
    {
        return redirect()->route('character.create');
    }

    public function render()
    {
        return view('livewire.character-select');
    }
}
