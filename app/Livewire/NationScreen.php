<?php

namespace App\Livewire;

use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationWar;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class NationScreen extends Component
{
    private const PENDING_FEATURES = [
        'nation-search' => '国家を探す',
        'nation-create' => '建国',
        'nation-list' => '国家一覧',
        'nation-detail' => '国家の詳細',
        'nation-apply' => '加入申請',
        'nation-settings' => '国家設定',
        'members' => '国民管理',
        'donation' => '国家資材の納品',
        'fortress' => '要塞',
        'war' => '国家戦',
        'history' => '戦史',
        'recruitment' => '募集内容の編集',
        'notices' => 'お知らせ・申請',
    ];

    public ?string $pendingFeature = null;

    public function boot(): void
    {
        abort_unless(config('features.nation_screen_enabled', true), 404);
    }

    public function showNotImplemented(string $feature): void
    {
        $this->pendingFeature = self::PENDING_FEATURES[$feature] ?? 'この機能';
    }

    public function closeNotImplementedModal(): void
    {
        $this->pendingFeature = null;
    }

    public function render(): View
    {
        $character = Auth::user()->currentCharacter();
        $membership = NationMembership::query()
            ->with(['nation.facilities', 'nation.memberships.character'])
            ->where('character_id', $character->id)
            ->first();

        $nations = collect();
        $nationCount = 0;
        $dashboard = null;

        if ($membership) {
            $nation = $membership->nation;
            $facilities = $nation->facilities;
            $averageLevel = (float) ($facilities->avg('level') ?? 0);
            $wins = (int) $nation->war_wins;
            $losses = (int) $nation->war_losses;
            $draws = (int) $nation->war_draws;
            $totalWars = $wins + $losses + $draws;
            $currentWar = NationWar::query()
                ->where(function ($query) use ($nation): void {
                    $query->where('declaring_nation_id', $nation->id)
                        ->orWhere('defending_nation_id', $nation->id);
                })
                ->whereIn('status', ['reserved', 'preparing', 'active'])
                ->latest('starts_at')
                ->first();

            $facilityDefinitions = [
                'wall' => ['label' => '城壁', 'icon' => '🏰'],
                'magic_cannon' => ['label' => '魔導砲', 'icon' => '🔮'],
                'logistics' => ['label' => '兵站所', 'icon' => '📦'],
                'arsenal' => ['label' => '要塞工廠', 'icon' => '⚒️'],
                'headquarters' => ['label' => '本陣', 'icon' => '⛺'],
            ];

            $dashboard = [
                'nation' => $nation,
                'king_name' => $nation->memberships->firstWhere('role', 'king')?->character?->name ?? '不明',
                'member_count' => $nation->memberships->count(),
                'average_level' => $averageLevel,
                'development_percent' => min(100, (int) round(($averageLevel / 10) * 100)),
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'win_rate' => $totalWars > 0 ? round(($wins / $totalWars) * 100, 1) : 0,
                'war_status' => match ($currentWar?->status) {
                    'reserved' => '開戦待ち',
                    'preparing' => '開戦準備中',
                    'active' => '交戦中',
                    default => '平時',
                },
                'is_at_war' => $currentWar !== null,
                'facilities' => collect($facilityDefinitions)->map(function (array $definition, string $type) use ($facilities): array {
                    $facility = $facilities->firstWhere('facility_type', $type);

                    return [
                        ...$definition,
                        'level' => (int) ($facility?->level ?? 1),
                        'condition_percent' => (int) round(((int) ($facility?->condition_bps ?? 10000)) / 100),
                    ];
                })->values(),
            ];
        } else {
            $nationCount = Nation::query()->count();
            $nations = Nation::query()
                ->withCount('memberships')
                ->with(['memberships' => fn ($query) => $query->where('role', 'king')->with('character')])
                ->orderByDesc('prestige')
                ->orderBy('id')
                ->limit(3)
                ->get();
        }

        return view('livewire.nation-screen', [
            'membership' => $membership,
            'nations' => $nations,
            'nationCount' => $nationCount,
            'dashboard' => $dashboard,
        ]);
    }
}
