<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterCityDiscovery;
use App\Models\CharacterJob;
use App\Models\JobClass;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
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
    public string $copiedCharacterPayload = '';
    public array $copiedProgressData = [];
    public string $copiedProgressSummary = '';

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
            'current_city_id' => (int) ($this->copiedProgressData['current_city_id'] ?? 1),
            'highest_city_id' => (int) ($this->copiedProgressData['highest_city_id'] ?? $this->copiedProgressData['current_city_id'] ?? 1),
        ]);

        if ($beginnerJob) {
            CharacterJob::create([
                'character_id' => $character->id,
                'job_class_id' => $beginnerJob->id,
                'job_level' => 1,
                'job_exp' => 0,
            ]);
        }

        $this->applyCopiedProgressTo($character);

        session()->flash('message', 'テストプレイヤーを作成しました。');
    }

    public function applyCopiedCharacter()
    {
        $payload = json_decode($this->copiedCharacterPayload, true);

        if (!is_array($payload)) {
            session()->flash('message', 'コピー内容を読み取れませんでした。プレイヤー一覧の「テスト用コピー」から貼り付けてください。');
            return;
        }

        $requiredKeys = [
            'name',
            'level',
            'hp_base',
            'mp_base',
            'attack_base',
            'defense_base',
            'speed_base',
            'magic_base',
            'luck_base',
            'spirit_base',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                session()->flash('message', 'コピー内容に必要な能力値が不足しています。');
                return;
            }
        }

        $this->copiedProgressData = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $this->copiedProgressSummary = $this->progressSummary($this->copiedProgressData);

        $this->name = mb_substr((string) $payload['name'], 0, 20);
        $this->level = max(1, (int) $payload['level']);
        $this->hp_base = max(1, (int) $payload['hp_base']);
        $this->mp_base = max(0, (int) $payload['mp_base']);
        $this->attack_base = max(1, (int) $payload['attack_base']);
        $this->defense_base = max(1, (int) $payload['defense_base']);
        $this->speed_base = max(1, (int) $payload['speed_base']);
        $this->magic_base = max(1, (int) $payload['magic_base']);
        $this->luck_base = max(1, (int) $payload['luck_base']);
        $this->spirit_base = max(1, (int) $payload['spirit_base']);

        $powerText = isset($payload['power']) ? '（戦力 ' . number_format((int) $payload['power']) . '）' : '';
        $progressText = $this->copiedProgressSummary !== '' ? ' 進行: ' . $this->copiedProgressSummary : '';
        session()->flash('message', 'コピー内容をテストキャラ生成フォームへ反映しました。' . $powerText . $progressText);
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
        $statusService = app(CharacterStatusService::class);
        $powerService = app(CharacterPowerService::class);

        $testers->each(function (User $tester) use ($statusService, $powerService): void {
            $character = $tester->characters->first();
            if (!$character) {
                return;
            }

            $character->setAttribute('admin_power', $powerService->fromFinalStats($statusService->getFinalStats($character)));
        });

        return view('livewire.admin.tester-manager', [
            'testers' => $testers,
        ])->layout('components.layouts.admin');
    }

    private function applyCopiedProgressTo(Character $character): void
    {
        if ($this->copiedProgressData === []) {
            return;
        }

        foreach (($this->copiedProgressData['area_progresses'] ?? []) as $progress) {
            if (!is_array($progress) || empty($progress['area_id'])) {
                continue;
            }

            CharacterAreaProgress::updateOrCreate(
                [
                    'character_id' => $character->id,
                    'area_id' => (int) $progress['area_id'],
                ],
                [
                    'is_unlocked' => (bool) ($progress['is_unlocked'] ?? false),
                    'boss_defeated' => (bool) ($progress['boss_defeated'] ?? false),
                    'development_point' => max(0, (int) ($progress['development_point'] ?? 0)),
                    'discovery_state' => (string) ($progress['discovery_state'] ?? 'undiscovered'),
                    'unlocked_at' => $progress['unlocked_at'] ?? null,
                    'boss_defeated_at' => $progress['boss_defeated_at'] ?? null,
                    'rumored_at' => $progress['rumored_at'] ?? null,
                    'discovered_at' => $progress['discovered_at'] ?? null,
                    'cleared_at' => $progress['cleared_at'] ?? null,
                ]
            );
        }

        foreach (($this->copiedProgressData['city_discoveries'] ?? []) as $discovery) {
            if (!is_array($discovery) || empty($discovery['city_id'])) {
                continue;
            }

            CharacterCityDiscovery::updateOrCreate(
                [
                    'character_id' => $character->id,
                    'city_id' => (int) $discovery['city_id'],
                ],
                [
                    'discovery_state' => (string) ($discovery['discovery_state'] ?? 'discovered'),
                    'rumored_at' => $discovery['rumored_at'] ?? null,
                    'discovered_at' => $discovery['discovered_at'] ?? null,
                ]
            );
        }
    }

    private function progressSummary(array $progress): string
    {
        if ($progress === []) {
            return '';
        }

        $areaProgresses = collect($progress['area_progresses'] ?? []);
        $cityDiscoveries = collect($progress['city_discoveries'] ?? []);

        return sprintf(
            '現在街#%d / 最高街#%d / 解放%d件 / ボス撃破%d件 / 街発見%d件',
            (int) ($progress['current_city_id'] ?? 1),
            (int) ($progress['highest_city_id'] ?? $progress['current_city_id'] ?? 1),
            $areaProgresses->where('is_unlocked', true)->count(),
            $areaProgresses->where('boss_defeated', true)->count(),
            $cityDiscoveries->count()
        );
    }
}
