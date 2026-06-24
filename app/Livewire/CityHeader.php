<?php

namespace App\Livewire;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\CharacterProfileService;
use App\Services\CharacterNotificationService;
use App\Support\CharacterIconCatalog;
use App\Support\CityVisualCatalog;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class CityHeader extends Component
{
    // モーダル用状態
    public $isPlayerModalOpen = false;
    public $playerInfo = null;
    public $locationName = '';

    public function openPlayerModal(int $characterId)
    {
        $character = Character::with([
            'arenaRanking',
            'jobClass',
            'partnerValmon.master',
            'valmons.master',
        ])->find($characterId);

        if (!$character) {
            $this->playerInfo = null;
            $this->isPlayerModalOpen = false;
            return;
        }

        $this->playerInfo = $this->profileFor($character);
        $this->isPlayerModalOpen = true;
    }

    public function closePlayerModal()
    {
        $this->isPlayerModalOpen = false;
        $this->playerInfo = null;
    }

    public function openNotification(int $notificationId)
    {
        if (!Schema::hasTable('character_notifications')) {
            return null;
        }

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if (!$character) {
            return null;
        }

        $notification = CharacterNotification::query()
            ->where('character_id', $character->id)
            ->find($notificationId);

        if (!$notification) {
            return null;
        }

        $notification->markAsRead();

        if ($notification->url) {
            return redirect()->to($notification->url);
        }

        return null;
    }

    public function markAllNotificationsRead(): void
    {
        if (!Schema::hasTable('character_notifications')) {
            return;
        }

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if (!$character) {
            return;
        }

        app(CharacterNotificationService::class)->markAllAsRead($character);
    }

    public function mount()
    {
        $this->determineLocationName();
    }

    // タブ切り替え時のリッスンは不要な再描画を防ぐため削除しました

    private function determineLocationName()
    {
        $routeName = request()->route()->getName();
        
        if ($routeName === 'home') {
            $this->locationName = '';
        } elseif (str_starts_with($routeName, 'shop.')) {
            if ($routeName === 'shop.equipment' || in_array($routeName, ['shop.weapons', 'shop.armors', 'shop.accessories'], true)) $this->locationName = '装備屋';
            if ($routeName === 'shop.items') $this->locationName = '補給所';
        } elseif ($routeName === 'jobs.index') {
            $this->locationName = '転職所';
        } elseif ($routeName === 'association.index') {
            $this->locationName = '冒険者協会';
        } elseif ($routeName === 'equipment.index') {
            $this->locationName = '装備変更';
        } elseif ($routeName === 'monster-marks.index') {
            $this->locationName = '印図鑑';
        } elseif ($routeName === 'inventory.index') {
            $this->locationName = '倉庫';
        } elseif ($routeName === 'titles.index') {
            $this->locationName = '称号一覧';
        }
    }

    public function render()
    {
        // ヘッダー用ダミーデータ
        $headerInfo = [
            'online_count' => rand(15, 30),
            'duel_count' => rand(100, 300),
            'current_king' => 'アスナ',
            'news' => [
                '「ヴァルゼリアの冒険者」β版稼働中！'
            ]
        ];

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        $topPlayer = $character ? $this->topPlayerBar($character) : null;
        $currentCity = $character ? $character->currentCity : null;
        $cityName = $currentCity ? $currentCity->name : '冒険都市ヴァルゼリア';
        $notifications = collect();
        $unreadNotificationCount = 0;

        // 5分以内にアクセスがあったプレイ中のキャラクターを表示
        $characters = Character::with(['jobClass'])
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->orderBy('last_seen_at', 'desc')
            ->take(20)
            ->get();

        $onlinePlayers = $characters->map(function ($char) {
            return [
                'id' => (int) $char->id,
                'name' => $char->name,
            ];
        })->toArray();

        if ($character && Schema::hasTable('character_notifications')) {
            $notificationService = app(CharacterNotificationService::class);
            $notifications = $notificationService->latest($character, 6);
            $unreadNotificationCount = $notificationService->unreadCount($character);
        }

        $cityId = $currentCity ? (int) $currentCity->id : null;
        $cityIcon = CityVisualCatalog::icon($cityId);
        $cityBackground = CityVisualCatalog::background($cityId);

        return view('livewire.city-header', [
            'headerInfo' => $headerInfo,
            'onlinePlayers' => $onlinePlayers,
            'locationName' => $this->locationName,
            'cityName' => $cityName,
            'cityIcon' => $cityIcon,
            'cityBackground' => $cityBackground,
            'topPlayer' => $topPlayer,
            'notifications' => $notifications,
            'unreadNotificationCount' => $unreadNotificationCount,
        ]);
    }

    private function topPlayerBar(Character $character): array
    {
        $character->loadMissing('jobClass');
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base ?? 1));
        $maxSp = max(1, (int) ($stats['max_mp'] ?? $character->mp_base ?? 1));
        $currentHp = max(0, min((int) ($character->current_hp ?? 0), $maxHp));
        $currentSp = max(0, min((int) ($character->current_mp ?? 0), $maxSp));

        $profileService = app(CharacterProfileService::class);
        $profileFrameTheme = $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme);

        return [
            'name' => $character->name,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'icon' => CharacterIconCatalog::versionedAsset($character->icon_path),
            'hp' => $currentHp,
            'max_hp' => $maxHp,
            'hp_percent' => (int) floor(($currentHp / $maxHp) * 100),
            'sp' => $currentSp,
            'max_sp' => $maxSp,
            'sp_percent' => (int) floor(($currentSp / $maxSp) * 100),
            'gold' => (int) ($character->money ?? 0),
            'kiseki' => (int) ($character->kiseki ?? 0),
        ];
    }

    private function profileFor(Character $character): array
    {
        $viewerCharacter = auth()->check() ? auth()->user()->currentCharacter() : null;
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $equippedItems = app(EquipmentService::class)->getEquippedItems($character);
        $weapon = $equippedItems['weapon'] ?? null;
        $armor = $equippedItems['armor'] ?? null;
        $accessory = $equippedItems['accessory'] ?? null;

        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base));
        $currentHp = max(0, min((int) $character->current_hp, $maxHp));
        $hpPercent = (int) floor(($currentHp / $maxHp) * 100);
        $maxMp = max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0));
        $currentMp = max(0, min((int) ($character->current_mp ?? 0), $maxMp));
        $spPercent = $maxMp > 0 ? (int) floor(($currentMp / $maxMp) * 100) : 0;
        $ranking = $character->arenaRanking;
        $arenaRank = $ranking?->rank ? (int) $ranking->rank : null;
        $partner = $character->partnerValmon;
        $profileService = app(CharacterProfileService::class);
        $profileFrameTheme = $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme);
        $fieldSlots = [
            [50, 5, 14, 40],
            [24, 4, 12, 32],
            [76, 4, 12, 36],
            [38, 13, 10, 28],
            [63, 12, 10, 30],
            [9, 3, 10, 22],
            [90, 3, 10, 24],
            [28, 20, 9, 18],
            [55, 19, 9, 20],
            [76, 21, 8, 14],
        ];
        $valmons = $character->valmons
            ->sortByDesc('is_partner')
            ->values()
            ->take(10)
            ->map(function ($valmon, int $index) use ($fieldSlots) {
                [$left, $bottom, $size, $zIndex] = $fieldSlots[$index] ?? [50, 5, 12, 20];

                return [
                    'name' => $valmon->displayName(),
                    'level' => (int) $valmon->level,
                    'is_partner' => (bool) $valmon->is_partner,
                    'image' => $valmon->master?->imageUrl(),
                    'style' => "left:{$left}%;bottom:{$bottom}%;transform:translateX(-50%);z-index:{$zIndex};width:{$size}%;",
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (int) $character->id,
            'is_self' => $viewerCharacter && (int) $viewerCharacter->id === (int) $character->id,
            'name' => $character->name,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'arena_rank' => $arenaRank ? number_format($arenaRank) . '位' : '未参加',
            'arena_rank_number' => $arenaRank,
            'arena_rank_trophy' => $arenaRank && $arenaRank <= 3 ? asset('images/icon/icon_100' . $arenaRank . '.webp') : null,
            'guild' => '未所属',
            'state' => '滞在中',
            'icon' => CharacterIconCatalog::versionedAsset($character->icon_path),
            'hp' => $currentHp,
            'max_hp' => $maxHp,
            'hp_percent' => max(0, min(100, $hpPercent)),
            'sp' => $currentMp,
            'max_sp' => $maxMp,
            'sp_percent' => max(0, min(100, $spPercent)),
            'profile_comment' => $character->profile_comment ?: 'よろしくお願いします',
            'ranch_background' => asset($profileService->selectedRanchBackground($character, $character->profile_ranch_background)),
            'profile_frame_theme' => $profileFrameTheme,
            'profile_frame_label' => $profileService->frameThemeLabel($profileFrameTheme),
            'adventure_records' => $this->adventureRecords($character),
            'stats' => [
                'str' => $this->statBreakdown($stats, 'str'),
                'def' => $this->statBreakdown($stats, 'def'),
                'agi' => $this->statBreakdown($stats, 'agi'),
                'mag' => $this->statBreakdown($stats, 'mag'),
                'spr' => $this->statBreakdown($stats, 'spr'),
                'luk' => $this->statBreakdown($stats, 'luk'),
            ],
            'equipment' => [
                'weapon' => $this->equipmentLine($weapon, 'weapon_rank'),
                'armor' => $this->equipmentLine($armor, 'armor_rank'),
                'accessory' => $this->equipmentLine($accessory, 'accessory_rank'),
            ],
            'valmon' => $partner ? [
                'name' => $partner->displayName(),
                'level' => (int) $partner->level,
                'image' => $partner->master?->imageUrl(),
            ] : null,
            'valmons' => $valmons,
        ];
    }

    private function adventureRecords(Character $character): array
    {
        $battleQuery = BattleLog::query()->where('character_id', $character->id);
        $battleCount = (clone $battleQuery)->count();
        $winCount = (clone $battleQuery)->where('result', 'win')->count();
        $bossWinCount = (clone $battleQuery)
            ->where('battle_type', 'boss')
            ->where('result', 'win')
            ->count();
        $valmonCount = $character->relationLoaded('valmons')
            ? $character->valmons->count()
            : $character->valmons()->count();
        $masteredJobCount = $character->jobHistories()
            ->where('is_mastered', true)
            ->count();
        $adventureDays = $character->created_at
            ? max(1, (int) $character->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1)
            : 1;

        return [
            ['label' => '戦闘回数', 'value' => number_format($battleCount), 'unit' => '回'],
            ['label' => '勝利数', 'value' => number_format($winCount), 'unit' => '勝'],
            ['label' => 'ボス討伐数', 'value' => number_format($bossWinCount), 'unit' => '体'],
            ['label' => '仲間ヴァルモン数', 'value' => number_format($valmonCount), 'unit' => '体'],
            ['label' => '冒険日数', 'value' => number_format($adventureDays), 'unit' => '日'],
            ['label' => '職業マスター数', 'value' => number_format($masteredJobCount), 'unit' => '職'],
        ];
    }

    private function statBreakdown(array $stats, string $key): array
    {
        $total = (int) ($stats[$key] ?? 0);
        $bonus = (int) ($stats['bonuses'][$key] ?? 0);

        return [
            'base' => $total - $bonus,
            'bonus' => $bonus,
            'total' => $total,
        ];
    }

    private function equipmentLine($characterItem, string $rankColumn): array
    {
        if (!$characterItem) {
            return [
                'name' => 'なし',
                'rank' => null,
                'rank_color' => '#94a3b8',
            ];
        }

        $rank = $characterItem->item?->{$rankColumn};

        return [
            'name' => $characterItem->displayName(),
            'rank' => $rank,
            'rank_color' => $this->rankColor($rank),
        ];
    }

    private function rankColor(?string $rank): string
    {
        $rankColors = [
            'EPIC' => '#e11d48',
            'SSS' => '#f97316',
            'SS' => '#c084fc',
            'S' => '#d4af37',
            'A' => '#ef4444',
            'B' => '#3b82f6',
            'C' => '#22c55e',
            'D' => '#94a3b8',
            'E' => '#64748b',
            'F' => '#b0bec5',
            'G' => '#d1d5db',
        ];

        return $rankColors[strtoupper((string) $rank)] ?? '#94a3b8';
    }
}
