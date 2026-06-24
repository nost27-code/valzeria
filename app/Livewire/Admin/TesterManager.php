<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\JobClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TesterManager extends Component
{
    public $name = 'テストマン';
    public $level = 100;
    public $hp_base = 9999;
    public $mp_base = 999;
    public $attack_base = 500;
    public $defense_base = 500;
    public $speed_base = 500;
    public $magic_base = 500;
    public $luck_base = 500;
    public $spirit_base = 500;

    public $editingTesterId = null;
    public $editData = [];

    protected $rules = [
        'name' => 'required|string|max:20',
        'level' => 'required|integer|min:1',
        'hp_base' => 'required|integer|min:1',
        'mp_base' => 'required|integer|min:0',
        'attack_base' => 'required|integer|min:1',
        'defense_base' => 'required|integer|min:1',
        'speed_base' => 'required|integer|min:1',
        'magic_base' => 'required|integer|min:1',
        'luck_base' => 'required|integer|min:1',
        'spirit_base' => 'required|integer|min:1',
    ];

    public function createTester()
    {
        $this->validate();

        $user = User::create([
            'name' => $this->name . '_user',
            'email' => 'tester_' . Str::random(8) . '@valzeria.local',
            'password' => null,
            'role' => 'user',
        ]);

        $beginnerJob = JobClass::where('key', 'beginner')->first();

        $character = Character::create([
            'user_id' => $user->id,
            'name' => $this->name,
            'level' => $this->level,
            'hp_base' => $this->hp_base,
            'current_hp' => $this->hp_base,
            'mp_base' => $this->mp_base,
            'current_mp' => $this->mp_base,
            'attack_base' => $this->attack_base,
            'defense_base' => $this->defense_base,
            'speed_base' => $this->speed_base,
            'magic_base' => $this->magic_base,
            'luck_base' => $this->luck_base,
            'spirit_base' => $this->spirit_base,
            'exp' => 0,
            'money' => 1000000,
            'current_job_id' => $beginnerJob ? $beginnerJob->id : null,
            'current_city_id' => 1,
        ]);

        if ($beginnerJob) {
            CharacterJob::create([
                'character_id' => $character->id,
                'job_class_id' => $beginnerJob->id,
                'job_level' => 1,
                'job_exp' => 0,
            ]);
        }

        session()->flash('message', 'テストプレイヤーを作成しました。');
    }

    public function editTester($userId)
    {
        $user = User::with('characters')->find($userId);
        if ($user && $user->characters->isNotEmpty()) {
            $this->editingTesterId = $userId;
            $c = $user->characters->first();
            $this->editData = [
                'level' => $c->level,
                'hp_base' => $c->hp_base,
                'mp_base' => $c->mp_base,
                'attack_base' => $c->attack_base,
                'defense_base' => $c->defense_base,
                'speed_base' => $c->speed_base,
                'magic_base' => $c->magic_base,
                'luck_base' => $c->luck_base,
                'spirit_base' => $c->spirit_base ?? 500,
            ];
        }
    }

    public function updateTester()
    {
        if (!$this->editingTesterId) return;

        $user = User::with('characters')->find($this->editingTesterId);
        if ($user && $user->characters->isNotEmpty()) {
            $character = $user->characters->first();
            $character->update([
                'level' => $this->editData['level'],
                'hp_base' => $this->editData['hp_base'],
                'current_hp' => $this->editData['hp_base'], // HPも全快させる
                'mp_base' => $this->editData['mp_base'],
                'current_mp' => $this->editData['mp_base'],
                'attack_base' => $this->editData['attack_base'],
                'defense_base' => $this->editData['defense_base'],
                'speed_base' => $this->editData['speed_base'],
                'magic_base' => $this->editData['magic_base'],
                'luck_base' => $this->editData['luck_base'],
                'spirit_base' => $this->editData['spirit_base'],
            ]);
            session()->flash('message', $character->name . 'の能力値を更新しました。');
        }
        $this->editingTesterId = null;
    }

    public function cancelEdit()
    {
        $this->editingTesterId = null;
    }

    public function playAs($userId)
    {
        // 管理者権限チェック（念のため）
        if (Auth::user()->role !== 'admin') {
            return;
        }

        Auth::loginUsingId($userId);
        return redirect()->route('home');
    }

    public function render()
    {
        // tester_ から始まるメアドを持つユーザーを取得
        $testers = User::with('characters.jobClass')->where('email', 'like', 'tester_%')->orderBy('id', 'desc')->get();

        return view('livewire.admin.tester-manager', [
            'testers' => $testers,
        ])->layout('components.layouts.admin');
    }
}
