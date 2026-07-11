<?php

namespace App\Livewire;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\ValmonMaster;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\CharacterProfileService;
use App\Services\CharacterNotificationService;
use App\Services\ExplorationStaminaService;
use App\Services\ExplorationStateService;
use App\Services\FerdiaMapService;
use App\Services\SupportPassService;
use App\Support\CharacterIconCatalog;
use App\Support\CityVisualCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class CityHeader extends Component
{
    // モーダル用状態
    public $isPlayerModalOpen = false;
    public $playerInfo = null;
    public $locationName = '';
    public bool $showCityPanel = true;

    public function openPlayerModal(int $characterId)
    {
        $character = Character::with([
            'arenaRanking',
            'jobClass',
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

    public function markNotificationRead(int $notificationId): void
    {
        if (!Schema::hasTable('character_notifications')) {
            return;
        }

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if (!$character) {
            return;
        }

        CharacterNotification::query()
            ->where('character_id', $character->id)
            ->whereKey($notificationId)
            ->first()
            ?->markAsRead();
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

    public function mount(bool $showCityPanel = true)
    {
        $this->showCityPanel = $showCityPanel;
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
        $cityId = $currentCity ? (int) $currentCity->id : null;

        if ($cityId && $this->shouldShowFerdiaSimpleBase($character, $cityId)) {
            $cityName = 'フェルディア簡易拠点';
        }

        $notifications = collect();
        $unreadNotificationCount = 0;

        $onlinePlayers = $this->onlinePlayers();

        if ($character && Schema::hasTable('character_notifications')) {
            $notificationService = app(CharacterNotificationService::class);
            $notifications = $notificationService->latest($character, 6);
            $unreadNotificationCount = $notificationService->unreadCount($character);
        }

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

    private function onlinePlayers(): array
    {
        return Cache::remember('city_header_online_players_v2', now()->addSeconds(20), function (): array {
            return Character::visibleToPublic()
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->orderBy('last_seen_at', 'desc')
                ->take(20)
                ->get(['id', 'name'])
                ->map(fn (Character $char): array => [
                    'id' => (int) $char->id,
                    'name' => $char->name,
                ])
                ->toArray();
        });
    }

    private function shouldShowFerdiaSimpleBase(?Character $character, int $cityId): bool
    {
        if (!$character || session('current_location') !== 'dungeon') {
            return false;
        }

        $ferdiaMapService = app(FerdiaMapService::class);
        if (!$ferdiaMapService->isFerdiaCityId($cityId)) {
            return false;
        }

        $areaId = (int) session('target_area_id', 0);
        if ($areaId <= 0) {
            $state = app(ExplorationStateService::class)->currentFor($character);
            $areaId = (int) ($state?->area_id ?? 0);
        }

        return $areaId > 0 && $ferdiaMapService->isFerdiaAreaId($areaId);
    }

    private function topPlayerBar(Character $character): array
    {
        $character->loadMissing('jobClass');
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base ?? 1));
        $maxSp = max(1, (int) ($stats['max_mp'] ?? $character->mp_base ?? 1));
        $currentHp = max(0, min((int) ($character->current_hp ?? 0), $maxHp));
        $currentSp = max(0, min((int) ($character->current_mp ?? 0), $maxSp));

        $character->loadMissing('jobHistories');
        $currentJobHistory = $character->jobHistories->where('job_class_id', $character->current_job_id)->first();
        $jobRank = $currentJobHistory ? (int) $currentJobHistory->job_level : 1;

        $profileService = app(CharacterProfileService::class);
        $profileFrameTheme = $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme);
        $explorationStaminaService = app(ExplorationStaminaService::class);
        $explorationStamina = $explorationStaminaService->enabled()
            ? $explorationStaminaService->summary($character)
            : null;
        $supportPassStatus = app(SupportPassService::class)->statusForCharacter($character);

        return [
            'name' => $character->name,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'job_rank' => $jobRank,
            'power' => app(CharacterPowerService::class)->fromFinalStats($stats),
            'icon' => CharacterIconCatalog::versionedAsset($character->icon_path),
            'profile_frame_image' => asset($profileService->frameImageForTheme($profileFrameTheme)),
            'hp' => $currentHp,
            'max_hp' => $maxHp,
            'hp_percent' => (int) floor(($currentHp / $maxHp) * 100),
            'sp' => $currentSp,
            'max_sp' => $maxSp,
            'sp_percent' => (int) floor(($currentSp / $maxSp) * 100),
            'gold' => (int) ($character->money ?? 0),
            'kiseki' => (int) ($character->kiseki ?? 0),
            'exploration_stamina' => $explorationStamina,
            'support_pass' => $supportPassStatus,
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
        $profileService = app(CharacterProfileService::class);
        $supportPassService = app(SupportPassService::class);
        $supportPassStatus = $supportPassService->statusForCharacter($character);
        $profileFrameTheme = $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme);
        $adventureRecords = $this->adventureRecords($character);
        $equippedTitle = $character->titles()
            ->where('is_equipped', true)
            ->with('title')
            ->first()
            ?->title;

        return [
            'id' => (int) $character->id,
            'is_self' => $viewerCharacter && (int) $viewerCharacter->id === (int) $character->id,
            'name' => $character->name,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'equipped_title' => $equippedTitle?->name ?? '未装備',
            'power' => app(CharacterPowerService::class)->fromFinalStats($stats),
            'arena_rank' => $arenaRank ? number_format($arenaRank) . '位' : '未参加',
            'arena_rank_number' => $arenaRank,
            'arena_rank_trophy' => $arenaRank && $arenaRank <= 3 ? asset('images/icon/icon_100' . $arenaRank . '.webp') : null,
            'guild' => '未実装',
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
            'profile_frame_image' => asset($profileService->frameImageForTheme($profileFrameTheme)),
            'adventurer_card_skin' => $supportPassService->displayedCardSkin($character->user),
            'support_pass' => [
                'active' => (bool) ($supportPassStatus['active'] ?? false),
                'remaining_days' => (int) ($supportPassStatus['remaining_days'] ?? 0),
            ],
            'adventurer_card_background' => asset($profileService->selectedAdventurerCardBackground($character, $character->profile_card_background)),
            'adventurer_card_frame' => asset($profileService->selectedAdventurerCardFrame($character, $character->profile_card_frame)),
            'adventurer_avatar_frame' => asset($profileService->selectedAdventurerAvatarFrame($character, $character->profile_avatar_frame)),
            'valmon_case' => asset($profileService->selectedValmonCase($character, $character->profile_valmon_case)),
            'adventure_records' => $adventureRecords,
            'card_records' => $this->cardRecords($adventureRecords),
            'valmon_badges' => $this->valmonBadges($character),
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
        ];
    }

    private function adventureRecords(Character $character): array
    {
        $battleQuery = BattleLog::query()->where('character_id', $character->id);
        $battleCount = (clone $battleQuery)->count();
        $winCount = (clone $battleQuery)->where('result', 'win')->count();
        $lossCount = (clone $battleQuery)->where('result', 'lose')->count();
        $winRate = $battleCount > 0 ? (int) floor(($winCount / $battleCount) * 100) : 0;
        $bossWinCount = (clone $battleQuery)
            ->where('battle_type', 'boss')
            ->where('result', 'win')
            ->count();
        $masteredJobCount = $character->jobHistories()
            ->where('is_mastered', true)
            ->count();
        $adventureDays = $character->created_at
            ? max(1, (int) $character->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1)
            : 1;
        $titleCount = $character->titles()->count();
        $equipmentCount = $character->characterItems()->count();
        $materialKindCount = $character->characterMaterials()->where('quantity', '>', 0)->count();
        $materialTotal = (int) $character->characterMaterials()->sum('quantity');
        $valmonCount = $character->valmons()->count();
        $highestValmonLevel = (int) ($character->valmons()->max('level') ?? 0);

        return [
            ['label' => '戦闘回数', 'value' => number_format($battleCount), 'unit' => '回'],
            ['label' => '勝利数', 'value' => number_format($winCount), 'unit' => '勝'],
            ['label' => '敗北数', 'value' => number_format($lossCount), 'unit' => '敗'],
            ['label' => '勝率', 'value' => number_format($winRate), 'unit' => '%'],
            ['label' => 'ボス討伐数', 'value' => number_format($bossWinCount), 'unit' => '体'],
            ['label' => '冒険日数', 'value' => number_format($adventureDays), 'unit' => '日'],
            ['label' => '職業マスター数', 'value' => number_format($masteredJobCount), 'unit' => '職'],
            ['label' => '称号数', 'value' => number_format($titleCount), 'unit' => '個'],
            ['label' => '所持装備数', 'value' => number_format($equipmentCount), 'unit' => '個'],
            ['label' => '素材種類数', 'value' => number_format($materialKindCount), 'unit' => '種'],
            ['label' => '素材総数', 'value' => number_format($materialTotal), 'unit' => '個'],
            ['label' => '仲間ヴァルモン数', 'value' => number_format($valmonCount), 'unit' => '体'],
            ['label' => '最高ヴァルモンLv', 'value' => number_format($highestValmonLevel), 'unit' => ''],
        ];
    }

    private function valmonBadges(Character $character): array
    {
        $ownedByMasterId = $character->valmons
            ->filter(fn ($valmon) => $valmon->master)
            ->keyBy('valmon_master_id');

        $badges = ValmonMaster::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->take(21)
            ->get()
            ->map(function (ValmonMaster $master) use ($ownedByMasterId) {
                $owned = $ownedByMasterId->get($master->id);

                return [
                    'owned' => (bool) $owned,
                    'name' => $owned ? $owned->displayName() : '未発見',
                    'species' => $master->name,
                    'level' => $owned ? (int) $owned->level : null,
                    'is_partner' => $owned ? (bool) $owned->is_partner : false,
                    'image' => $owned ? $master->imageUrl() : null,
                ];
            })
            ->values()
            ->all();

        while (count($badges) < 21) {
            $badges[] = [
                'owned' => false,
                'name' => '未発見',
                'species' => '未発見',
                'level' => null,
                'is_partner' => false,
                'image' => null,
            ];
        }

        return $badges;
    }

    private function cardRecords(array $adventureRecords): array
    {
        $byLabel = collect($adventureRecords)->keyBy('label');

        return [
            'battles' => $byLabel->get('戦闘回数', ['value' => '0', 'unit' => '回']),
            'days' => $byLabel->get('冒険日数', ['value' => '0', 'unit' => '日']),
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
                'bonus_text' => null,
            ];
        }

        $rank = $characterItem->item?->{$rankColumn}
            ?? $characterItem->item?->rarity;
        $rank = strtoupper((string) $rank);
        if ($rank === '' || $rank === 'NORMAL') {
            $rank = null;
        }

        return [
            'name' => $characterItem->displayName(),
            'rank' => $this->equipmentRankLabel($rank, (string) ($characterItem->item?->source_type ?? '')),
            'rank_color' => $this->rankColor($rank),
            'bonus_text' => null,
        ];
    }

    private function equipmentRankLabel(?string $rank, string $sourceType): ?string
    {
        if (strtoupper((string) $rank) === 'SPECIAL' && $sourceType === 'star_tree_tower_reward') {
            return '星樹';
        }

        return $rank;
    }

    private function rankColor(?string $rank): string
    {
        $rankColors = [
            'EPIC' => '#e11d48',
            'SSS' => '#f97316',
            'SS' => '#c084fc',
            'S' => '#d4af37',
            'SPECIAL' => '#0f766e',
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
