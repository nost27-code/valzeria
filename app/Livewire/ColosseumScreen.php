<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Character;
use App\Models\ArenaRanking;
use App\Models\ArenaLog;
use App\Models\ArenaNpcAutoLog;
use App\Models\ArenaNpcLog;
use App\Services\ArenaNpcRankingService;
use App\Services\PvPBattleService;
use App\Services\StorageCapacityService;
use App\Services\CooldownSettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

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
        $this->myRanking = app(ArenaNpcRankingService::class)->ensurePlayerRanking($this->character);

        $this->loadRankings();
    }

    public function loadRankings()
    {
        $this->myRanking->refresh();
        $this->rankBattleCooldownRemaining = $this->rankBattleCooldownRemaining();

        $rankingService = app(ArenaNpcRankingService::class);
        $this->topRankings = $rankingService->topEntries(5)->all();
        $this->targetRankings = $rankingService->targetEntries($this->myRanking, 3)->all();
    }

    public function render(StorageCapacityService $storageCapacityService)
    {
        $playerLogs = ArenaLog::with(['attacker', 'defender'])
            ->where('attacker_id', $this->character->id)
            ->orWhere('defender_id', $this->character->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (ArenaLog $log): array => [
                'kind' => 'player',
                'created_at' => $log->created_at,
                'is_attacker' => (int) $log->attacker_id === (int) $this->character->id,
                'is_win' => (int) $log->attacker_id === (int) $this->character->id ? (bool) $log->is_attacker_win : ! (bool) $log->is_attacker_win,
                'opponent_name' => (int) $log->attacker_id === (int) $this->character->id
                    ? ($log->defender?->name ?? '不明')
                    : ($log->attacker?->name ?? '不明'),
                'old_rank' => (int) $log->attacker_id === (int) $this->character->id ? (int) $log->attacker_old_rank : (int) $log->defender_old_rank,
                'new_rank' => (int) $log->attacker_id === (int) $this->character->id ? (int) $log->attacker_new_rank : (int) $log->defender_new_rank,
            ]);

        $npcLogs = Schema::hasTable('arena_npc_logs')
            ? ArenaNpcLog::with(['npc'])
                ->where('attacker_id', $this->character->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn (ArenaNpcLog $log): array => [
                    'kind' => 'npc',
                    'created_at' => $log->created_at,
                    'is_attacker' => true,
                    'is_win' => (bool) $log->is_attacker_win,
                    'opponent_name' => $log->npc?->npc_name ?? '放浪冒険者',
                    'old_rank' => (int) $log->attacker_old_rank,
                    'new_rank' => (int) $log->attacker_new_rank,
                ])
            : collect();

        $npcAutoLogs = Schema::hasTable('arena_npc_auto_logs')
            ? ArenaNpcAutoLog::with(['attackerNpc'])
                ->where('defender_character_id', $this->character->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function (ArenaNpcAutoLog $log): array {
                    $attackerName = app(ArenaNpcRankingService::class)->npcDisplayName($log->attackerNpc);

                    return [
                        'kind' => 'npc_auto',
                        'created_at' => $log->created_at,
                        'is_attacker' => false,
                        'is_win' => ! (bool) $log->is_attacker_win,
                        'opponent_name' => $attackerName,
                        'old_rank' => (int) $log->defender_old_rank,
                        'new_rank' => (int) $log->defender_new_rank,
                    ];
                })
            : collect();

        $logs = $playerLogs
            ->concat($npcLogs)
            ->concat($npcAutoLogs)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        $storageIsFull = $this->character ? $storageCapacityService->isFull($this->character) : false;
        $profileService = app(\App\Services\CharacterProfileService::class);
        $profileFrameTheme = $this->character
            ? $profileService->selectedFrameThemeFor($this->character, $this->character->profile_frame_theme)
            : 'standard';

        return view('livewire.colosseum-screen', [
            'recentLogs' => $logs,
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageIsFull ? $storageCapacityService->fullMessageHtml($this->character) : null,
            'myProfileFrameImage' => asset($profileService->frameImageForTheme($profileFrameTheme)),
        ]);
    }

    private function rankBattleCooldownRemaining(): int
    {
        $latestAttack = ArenaLog::where('attacker_id', $this->character->id)
            ->latest('created_at')
            ->first();
        $latestNpcAttack = Schema::hasTable('arena_npc_logs')
            ? ArenaNpcLog::where('attacker_id', $this->character->id)
                ->latest('created_at')
                ->first()
            : null;

        if ($latestNpcAttack && (!$latestAttack || $latestNpcAttack->created_at->gt($latestAttack->created_at))) {
            $latestAttack = $latestNpcAttack;
        }

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
