<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Character;
use App\Models\ArenaRanking;
use App\Models\ArenaLog;
use App\Services\PvPBattleService;
use App\Services\StorageCapacityService;
use App\Services\CooldownSettingService;
use Illuminate\Support\Facades\Auth;

class ColosseumScreen extends Component
{
    public $character;
    public $myRanking;
    public $topRankings = [];
    public $targetRankings = [];
    public int $rankBattleCooldownRemaining = 0;

    public function mount()
    {
        $this->character = Auth::user()->characters()->first();
        if (!$this->character) {
            return redirect()->route('character.create');
        }

        // 闘技場画面を開いた時点で初期ランクを付与
        $this->myRanking = ArenaRanking::firstOrCreate(
            ['character_id' => $this->character->id],
            ['rank' => ArenaRanking::max('rank') + 1, 'wins' => 0, 'losses' => 0]
        );

        $this->loadRankings();
    }

    public function loadRankings()
    {
        $this->myRanking->refresh();
        $this->rankBattleCooldownRemaining = $this->rankBattleCooldownRemaining();
        
        // トップランカーは闘技場トップ画面では3位まで表示
        $this->topRankings = ArenaRanking::with('character')->orderBy('rank')->limit(3)->get();

        // 自分が挑める相手（自分より1〜3つ上の順位のプレイヤー）を取得
        if ($this->myRanking->rank > 1) {
            $minRank = max(1, $this->myRanking->rank - 3);
            $maxRank = $this->myRanking->rank - 1;
            
            $this->targetRankings = ArenaRanking::with('character')
                ->whereBetween('rank', [$minRank, $maxRank])
                ->orderBy('rank', 'desc') // 自分に近い順
                ->get();
        } else {
            $this->targetRankings = [];
        }
    }

    public function render(StorageCapacityService $storageCapacityService)
    {
        // 自分の関連ログを取得（最新10件）
        $logs = ArenaLog::with(['attacker', 'defender'])
            ->where('attacker_id', $this->character->id)
            ->orWhere('defender_id', $this->character->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $storageIsFull = $this->character ? $storageCapacityService->isFull($this->character) : false;

        return view('livewire.colosseum-screen', [
            'recentLogs' => $logs,
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageIsFull ? $storageCapacityService->fullMessageHtml($this->character) : null,
        ]);
    }

    private function rankBattleCooldownRemaining(): int
    {
        $latestAttack = ArenaLog::where('attacker_id', $this->character->id)
            ->latest('created_at')
            ->first();

        if (!$latestAttack?->created_at) {
            return 0;
        }

        $cooldownSeconds = app(CooldownSettingService::class)->arenaRankBattleSeconds();
        if ($cooldownSeconds <= 0) {
            return 0;
        }

        $availableAt = $latestAttack->created_at->copy()->addSeconds($cooldownSeconds);
        if (now()->gte($availableAt)) {
            return 0;
        }

        return max(0, $availableAt->getTimestamp() - now()->getTimestamp());
    }
}
