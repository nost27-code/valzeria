<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use App\Services\CharacterService;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Facades\Auth;

#[Layout('components.layouts.simple')]
class CharacterCreate extends Component
{
    public function mount()
    {
        // 既にキャラクターが存在する場合は作成を禁止してリダイレクト
        if (Auth::user()->characters()->count() > 0) {
            return redirect()->route('character.select');
        }
    }
    public $icon_path = CharacterIconCatalog::DEFAULT_ICON;

    #[Validate('required|string|min:2|max:10')]
    public $name = '';

    #[Validate('required|in:男性,女性,その他,未設定')]
    public $gender = '未設定';

    #[Validate('required|in:warrior,mage,priest,thief')]
    public $job_key = 'warrior';

    public function create(CharacterService $service)
    {
        // 既にキャラクターが存在する場合は作成を拒否
        if (Auth::user()->characters()->count() > 0) {
            session()->flash('error', 'キャラクターは1アカウントにつき1体までです。');
            return redirect()->route('character.select');
        }
        $this->name = $this->trimDisplayName((string) $this->name);
        $this->validate();

        $user = Auth::user();

        // keyから実際のjob_idを取得
        $jobClass = \App\Models\JobClass::where('key', $this->job_key)->first();
        if (!$jobClass) {
            // 万が一見つからない場合は一番最初のジョブ
            $jobClass = \App\Models\JobClass::first();
        }

        $character = $service->createCharacter($user, [
            'name' => $this->name,
            'gender' => $this->gender,
            'job_id' => $jobClass->id,
            'icon_path' => CharacterIconCatalog::isSelectable($this->icon_path)
                ? CharacterIconCatalog::normalize($this->icon_path)
                : CharacterIconCatalog::DEFAULT_ICON,
        ]);

        // 作成したキャラクターを現在の操作キャラとしてセッションに設定
        session([
            'current_character_id' => $character->id,
            'current_location' => 'home',
        ]);

        return redirect()->route('home');
    }

    public function render()
    {
        return view('livewire.character-create', [
            'characterIconPaths' => CharacterIconCatalog::paths(),
        ]);
    }

    private function trimDisplayName(string $value): string
    {
        return preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $value) ?? '';
    }
}
