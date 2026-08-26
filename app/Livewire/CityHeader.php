<?php

namespace App\Livewire;

use App\Enums\SixHeroRoomKey;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\City;
use App\Models\JobClass;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\ValmonMaster;
use App\Services\CharacterIconSetService;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\CharacterProfileService;
use App\Services\CharacterNotificationService;
use App\Services\ExplorationStaminaService;
use App\Services\FerdiaMapService;
use App\Services\FavoriteWeaponService;
use App\Services\JobService;
use App\Services\SchemaStateService;
use App\Services\SupportPassService;
use App\Services\TownUpdateService;
use App\Services\WeeklyWinRankingService;
use App\Support\CharacterIconCatalog;
use App\Support\CityVisualCatalog;
use App\Support\JobRankCatalog;
use App\Support\SixHeroRoomUiCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class CityHeader extends Component
{
    private const ADVENTURE_RECORDS_CACHE_MINUTES = 10;

    /** 本番確認用。直近5分の全冒険者一覧だけから除外するテストアカウント */
    private const HIDDEN_ONLINE_TEST_USER_ID = 1;

    private const HIDDEN_ONLINE_TEST_CHARACTER_ID = 5;

    // モーダル用状態
    public $isPlayerModalOpen = false;
    public $playerInfo = null;
    public $locationName = '';
    public bool $showCityPanel = true;
    public bool $modalOnly = false;

    public function openPlayerModal(int $characterId)
    {
        $character = Character::with([
            'arenaRanking',
            'jobClass',
            'nationMembership.nation',
            'user',
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

    public function openCurrentCharacterPreview(): void
    {
        $character = auth()->user()?->currentCharacter();
        if ($character) {
            $this->openPlayerModal((int) $character->id);
        }
    }

    public function closePlayerModal()
    {
        $this->isPlayerModalOpen = false;
        $this->playerInfo = null;
    }

    /** @return array{adventure_records: array<int, array{label: string, value: string, unit: string}>}|null */
    public function adventureRecordPayload(int $characterId): ?array
    {
        $character = Character::query()->find($characterId);
        if (!$character) {
            return null;
        }

        $adventureRecords = $this->adventureRecords($character);

        return [
            'adventure_records' => $adventureRecords,
        ];
    }

    public function jobBadgeTierJobs(string $rank): array
    {
        if (!$this->isPlayerModalOpen || !$this->playerInfo) {
            return [];
        }

        foreach ($this->playerInfo['job_master_badge_tiers'] ?? [] as $tier) {
            if ((string) $tier['rank'] === $rank && !($tier['locked'] ?? false)) {
                return self::expandCompactJobBadgeTier($tier);
            }
        }

        return [];
    }

    public static function expandCompactJobBadgeTier(array $tier): array
    {
        return collect($tier['compact_jobs'] ?? [])
            ->map(fn (array $job): array => [
                'id' => $job[0],
                'tier_rank' => (string) $tier['rank'],
                'name' => $job[1],
                'job_level' => $job[2],
                'fill_percent' => $job[3],
                'is_mastered' => $job[4],
                'mastered_at' => $job[5],
                'badge_image' => $job[6],
            ])
            ->all();
    }

    public function openNotification(int $notificationId)
    {
        if (! app(SchemaStateService::class)->hasTable('character_notifications')) {
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

        if ($notification->type === 'nation_join_application_submitted') {
            return redirect()->route('nation.applications');
        }

        return null;
    }

    public function markNotificationRead(int $notificationId): void
    {
        if (! app(SchemaStateService::class)->hasTable('character_notifications')) {
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
        if (! app(SchemaStateService::class)->hasTable('character_notifications')) {
            return;
        }

        $character = auth()->check() ? auth()->user()->currentCharacter() : null;
        if (!$character) {
            return;
        }

        app(CharacterNotificationService::class)->markAllAsRead($character);
    }

    public function mount(bool $showCityPanel = true, bool $modalOnly = false)
    {
        $this->modalOnly = $modalOnly;
        $this->showCityPanel = !$modalOnly && $showCityPanel;

        if (!$modalOnly) {
            $this->determineLocationName();
        }
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
        if ($this->modalOnly) {
            return view('livewire.city-header', [
                'topPlayer' => null,
                'townUpdates' => collect(),
                'isGuestUser' => app(\App\Services\AuthService::class)->isGuestUser(auth()->user()),
            ]);
        }

        $townUpdates = app(TownUpdateService::class)->published();
        $headlineUpdates = $townUpdates->take(3)->values();
        $headlineCount = $headlineUpdates->count();

        $headerInfo = [
            'online_count' => rand(15, 30),
            'duel_count' => rand(100, 300),
            'current_king' => 'アスナ',
            'news' => $headlineCount > 0
                ? $headlineUpdates
                    ->map(fn ($update, int $index): string => sprintf(
                        '[%d/%d] %s',
                        $index + 1,
                        $headlineCount,
                        $update->body
                    ))
                    ->all()
                : ['「ヴァルゼリアの冒険者」β版稼働中！'],
        ];

        $user = auth()->user();
        $character = $user?->currentCharacter();
        if ($character) {
            $character->setRelation('user', $user);
            $character->loadMissing([
                'currentCity',
                'jobClass',
                'jobHistories.jobClass',
                'characterItems' => fn ($query) => $query->where('is_equipped', true),
                'characterItems.item',
                'characterItems.affixPrefix',
            ]);
        }
        $topPlayer = $character ? $this->topPlayerBar($character) : null;
        $currentCity = $character ? $character->currentCity : null;
        $cityName = $currentCity ? $currentCity->name : '冒険都市ヴァルゼリア';
        $cityId = $currentCity ? (int) $currentCity->id : null;

        if ($currentCity && $this->shouldShowFerdiaSimpleBase($character, $currentCity)) {
            $cityName = 'フェルディア簡易拠点';
        }

        $notifications = collect();
        $unreadNotificationCount = 0;

        $onlinePlayers = $this->onlinePlayers();

        if ($character && app(SchemaStateService::class)->hasTable('character_notifications')) {
            $notificationService = app(CharacterNotificationService::class);
            $notifications = $notificationService->latest($character, 6);
            $unreadNotificationCount = $notificationService->unreadCount($character);
        }

        $cityIcon = CityVisualCatalog::icon($cityId);
        $cityBackground = CityVisualCatalog::background($cityId);

        return view('livewire.city-header', [
            'headerInfo' => $headerInfo,
            'townUpdates' => $townUpdates,
            'onlinePlayers' => $onlinePlayers,
            'locationName' => $this->locationName,
            'cityName' => $cityName,
            'cityIcon' => $cityIcon,
            'cityBackground' => $cityBackground,
            'topPlayer' => $topPlayer,
            'notifications' => $notifications,
            'unreadNotificationCount' => $unreadNotificationCount,
            'isGuestUser' => app(\App\Services\AuthService::class)->isGuestUser(auth()->user()),
        ]);
    }

    private function onlinePlayers(): array
    {
        $sixHeroUiEnabled = (bool) config('features.six_hero_ui_enabled', false);
        $cacheKey = 'city_header_online_players_v7_'.($sixHeroUiEnabled ? 'enabled' : 'disabled');

        return Cache::remember($cacheKey, now()->addSeconds(20), function (): array {
            $sixHeroCrownsByCharacter = $this->currentSixHeroCrownsByCharacter();

            return Character::visibleToPublic()
                // visibleToPublic() の全公開面除外とは分け、直近5分の全冒険者だけから運営テスト用を隠す。
                ->where(function ($query): void {
                    $query->where('id', '!=', self::HIDDEN_ONLINE_TEST_CHARACTER_ID)
                        ->orWhere('user_id', '!=', self::HIDDEN_ONLINE_TEST_USER_ID);
                })
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->orderBy('last_seen_at', 'desc')
                ->get(['id', 'name'])
                ->map(function (Character $char) use ($sixHeroCrownsByCharacter): array {
                    $crowns = $sixHeroCrownsByCharacter[(int) $char->id] ?? [];

                    return [
                        'id' => (int) $char->id,
                        'name' => $char->name,
                        'is_six_hero_top_ranker' => $crowns !== [],
                        'six_hero_crowns' => $crowns,
                    ];
                })
                ->toArray();
        });
    }

    /**
     * @return array<int, list<array{room_key: string, room_label: string, asset_url: string}>>
     */
    private function currentSixHeroCrownsByCharacter(): array
    {
        if (
            ! (bool) config('features.six_hero_ui_enabled', false)
            || ! Schema::hasTable('six_hero_seasons')
            || ! Schema::hasTable('six_hero_rankings')
        ) {
            return [];
        }

        $timezone = (string) config('app.timezone', 'Asia/Tokyo');
        $seasonId = SixHeroSeason::query()
            ->where('season_key', CarbonImmutable::now($timezone)->format('Y-m'))
            ->value('id');
        if ($seasonId === null) {
            return [];
        }

        $roomOrder = collect(SixHeroRoomKey::cases())
            ->mapWithKeys(fn (SixHeroRoomKey $room, int $index): array => [$room->value => $index]);

        return SixHeroRanking::query()
            ->where('season_id', $seasonId)
            ->where('rank', 1)
            ->get(['character_id', 'room_key'])
            ->groupBy(fn (SixHeroRanking $ranking): int => (int) $ranking->character_id)
            ->mapWithKeys(function (Collection $rankings, int $characterId) use ($roomOrder): array {
                $crowns = $rankings
                    ->sortBy(fn (SixHeroRanking $ranking): int => (int) $roomOrder->get($ranking->room_key->value, 999))
                    ->map(function (SixHeroRanking $ranking): array {
                        $room = $ranking->room_key;

                        return [
                            'room_key' => $room->value,
                            'room_label' => $room->label(),
                            'asset_url' => SixHeroRoomUiCatalog::crownImageUrl($room),
                        ];
                    })
                    ->values()
                    ->all();

                return [$characterId => $crowns];
            })
            ->all();
    }

    private function shouldShowFerdiaSimpleBase(?Character $character, City $city): bool
    {
        if (!$character) {
            return false;
        }

        $ferdiaMapService = app(FerdiaMapService::class);
        if (!$ferdiaMapService->isFerdiaCityId((int) $city->id)) {
            return false;
        }

        return !$ferdiaMapService->canTravelCity($character, $city);
    }

    private function topPlayerBar(Character $character): array
    {
        $character->loadMissing('jobClass');
        $resolvedPosePaths = app(CharacterIconSetService::class)->resolvedPaths($character);
        $headerPosePaths = collect(['normal', 'victory', 'battle', 'defeat'])
            ->map(fn (string $scene): string => CharacterIconCatalog::versionedAsset(
                $resolvedPosePaths[$scene] ?? $resolvedPosePaths['normal']
            ))
            ->unique()
            ->values()
            ->all();
        $stats = app(CharacterStatusService::class)->getFinalStatsUsingLoadedRelations($character);
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
            'pose_paths' => $headerPosePaths,
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
        $favoriteWeaponService = app(FavoriteWeaponService::class);
        $profileFrameTheme = $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme);
        $cardAssets = $profileService->selectedAdventurerCardAssets($character, [
            'background' => $character->profile_card_background,
            'card_frame' => $character->profile_card_frame,
            'avatar_frame' => $character->profile_avatar_frame,
            'valmon_case' => $character->profile_valmon_case,
        ]);
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
            'weekly_win_badge' => app(WeeklyWinRankingService::class)->latestBadgeFor($character),
            'guild' => $character->nationMembership?->nation?->display_name ?? '無所属',
            'state' => '滞在中',
            'icon' => CharacterIconCatalog::versionedAsset($character->icon_path),
            'hp' => $currentHp,
            'max_hp' => $maxHp,
            'hp_percent' => max(0, min(100, $hpPercent)),
            'sp' => $currentMp,
            'max_sp' => $maxMp,
            'sp_percent' => max(0, min(100, $spPercent)),
            'profile_comment' => $character->profile_comment ?: 'よろしくお願いします',
            'ranch_background' => asset($profileService->selectedRanchBackgroundForDisplay($character, $character->profile_ranch_background)),
            'profile_frame_theme' => $profileFrameTheme,
            'profile_frame_label' => $profileService->frameThemeLabel($profileFrameTheme),
            'profile_frame_image' => asset($profileService->frameImageForTheme($profileFrameTheme)),
            'adventurer_card_skin' => $supportPassService->displayedCardSkin($character->user),
            'support_pass' => [
                'active' => (bool) ($supportPassStatus['active'] ?? false),
                'remaining_days' => (int) ($supportPassStatus['remaining_days'] ?? 0),
            ],
            'favorite_weapons_enabled' => $favoriteWeaponService->enabled(),
            'favorite_weapons' => $favoriteWeaponService->enabled() ? $favoriteWeaponService->displayWeapons($character) : [],
            'job_master_badges_enabled' => (bool) config('job_master_badges.enabled', false),
            'job_master_badge_tiers' => $this->jobMasterBadgeTierSummaries($character),
            'adventurer_card_background' => asset($cardAssets['background']),
            'adventurer_card_frame' => $this->versionedProfileAsset(
                $cardAssets['card_frame']
            ),
            'adventurer_avatar_frame' => asset($cardAssets['avatar_frame']),
            'valmon_case' => asset($cardAssets['valmon_case']),
            'adventure_records_loaded' => false,
            'adventure_records' => [],
            'six_hero_current_record' => $this->sixHeroCurrentRecord($character),
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

    private function sixHeroCurrentRecord(Character $character): ?array
    {
        if (
            ! (bool) config('features.six_hero_ui_enabled', false)
            || ! Schema::hasTable('six_hero_seasons')
            || ! Schema::hasTable('six_hero_rankings')
        ) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'Asia/Tokyo');
        $currentMonth = CarbonImmutable::now($timezone);
        $season = SixHeroSeason::query()
            ->where('season_key', $currentMonth->format('Y-m'))
            ->first();
        if ($season === null) {
            return null;
        }

        $rankingsByRoom = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('character_id', $character->id)
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): string => $ranking->room_key->value);
        if ($rankingsByRoom->isEmpty()) {
            return null;
        }

        $rooms = collect(SixHeroRoomKey::cases())
            ->map(function (SixHeroRoomKey $room) use ($rankingsByRoom): ?array {
                /** @var SixHeroRanking|null $ranking */
                $ranking = $rankingsByRoom->get($room->value);
                if ($ranking === null) {
                    return null;
                }

                return [
                    'key' => $room->value,
                    'label' => $room->label(),
                    'rank' => (int) $ranking->rank,
                    'rankTone' => $this->sixHeroRankTone((int) $ranking->rank),
                    'isLeader' => (int) $ranking->rank === 1,
                    'challengeWins' => (int) $ranking->official_attack_wins,
                    'challengeLosses' => (int) $ranking->official_attack_losses,
                    'defenseWins' => (int) $ranking->defense_wins,
                    'defenseLosses' => (int) $ranking->defense_losses,
                ];
            })
            ->filter()
            ->values();

        return [
            'seasonLabel' => $currentMonth->format('Y年n月期'),
            'currentCrownCount' => $rooms->where('isLeader', true)->count(),
            'rooms' => $rooms->all(),
        ];
    }

    /** @return array{band: string, background: string, border: string, text: string} */
    private function sixHeroRankTone(int $rank): array
    {
        return match (true) {
            $rank === 1 => [
                'band' => 'first',
                'background' => '#fff4d6',
                'border' => '#d7a521',
                'text' => '#8f5100',
            ],
            $rank <= 3 => [
                'band' => 'top-three',
                'background' => '#fff8e8',
                'border' => '#e6c66b',
                'text' => '#765816',
            ],
            $rank <= 6 => [
                'band' => 'top-six',
                'background' => '#fffaf0',
                'border' => '#e8d9a4',
                'text' => '#665326',
            ],
            $rank <= 10 => [
                'band' => 'top-ten',
                'background' => '#f3f8fd',
                'border' => '#c8d5e6',
                'text' => '#344f70',
            ],
            $rank <= 20 => [
                'band' => 'top-twenty',
                'background' => '#f8fafc',
                'border' => '#d7dee8',
                'text' => '#44536a',
            ],
            default => [
                'band' => 'standard',
                'background' => '#ffffff',
                'border' => '#e1e5eb',
                'text' => '#4a5568',
            ],
        };
    }

    private function versionedProfileAsset(string $path): string
    {
        $absolutePath = public_path(ltrim($path, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset($path) . '?v=' . $version;
    }

    private function jobMasterBadgeTiers(Character $character): array
    {
        if (! config('job_master_badges.enabled', false) || ! app(SchemaStateService::class)->hasTable('job_classes')) {
            return [];
        }

        $jobs = JobRankCatalog::orderByRank(
            JobClass::query()->select(['id', 'rank', 'name', 'max_job_level', 'sort_order'])
        )
            ->orderBy('sort_order')
            ->get()
            ->values();
        $jobs->each(fn (JobClass $job, int $index) => $job->setAttribute('badge_index', $index + 1));
        $jobsByTier = $jobs->groupBy('rank');
        $hasCrownProof = app(JobService::class)->hasCrownProof($character);
        $jobProgress = $character->jobHistories()
            ->get(['job_class_id', 'job_level', 'is_mastered', 'mastered_at'])
            ->keyBy('job_class_id');

        return collect(config('job_master_badges.tiers', []))
            ->map(function (array $tier) use ($hasCrownProof, $jobsByTier, $jobProgress): array {
                if ($tier['rank'] === JobRankCatalog::CROWN && ! $hasCrownProof) {
                    return [
                        ...$tier,
                        'label' => '？？？',
                        'locked' => true,
                        'jobs' => [],
                        'total' => null,
                    ];
                }

                $tier['jobs'] = ($jobsByTier->get($tier['rank']) ?? collect())
                    ->map(function (JobClass $job) use ($jobProgress): array {
                        $progress = $jobProgress->get($job->id);
                        $maxLevel = max(1, (int) ($job->max_job_level ?? 10));
                        $level = min($maxLevel, max(0, (int) ($progress?->job_level ?? 0)));
                        $isMastered = (bool) ($progress?->is_mastered ?? false) || $level >= $maxLevel;
                        $badgePath = sprintf('images/job' . 'badge/job' . 'badge_%03d.webp', (int) $job->badge_index);

                        return [
                            'id' => (int) $job->id,
                            'tier_rank' => (string) $job->rank,
                            'name' => (string) $job->name,
                            'job_level' => $level,
                            'max_job_level' => $maxLevel,
                            'fill_percent' => $isMastered ? 100 : (int) round(($level / $maxLevel) * 100),
                            'is_mastered' => $isMastered,
                            'mastered_at' => $progress?->mastered_at?->format('Y年n月j日'),
                            // 未マスター職は水位と★ランクだけで進捗を見せるため、職業画像は表示しない。
                            'badge_image' => $isMastered && is_file(public_path($badgePath)) ? asset($badgePath) : null,
                        ];
                    })
                    ->values()
                    ->all();
                $tier['total'] = count($tier['jobs']);
                $tier['locked'] = false;

                return $tier;
            })
            ->filter(fn (array $tier): bool => $tier['total'] > 0)
            ->values()
            ->all();
    }

    private function jobMasterBadgeTierSummaries(Character $character): array
    {
        return collect($this->jobMasterBadgeTiers($character))
            ->map(function (array $tier): array {
                $compactJobs = collect($tier['jobs'] ?? [])
                    ->map(fn (array $job): array => [
                        $job['id'],
                        $job['name'],
                        $job['job_level'],
                        $job['fill_percent'],
                        $job['is_mastered'],
                        $job['mastered_at'],
                        $job['badge_image'] ? parse_url($job['badge_image'], PHP_URL_PATH) : null,
                    ])
                    ->all();

                return [
                    ...$tier,
                    'jobs' => [],
                    'compact_jobs' => $compactJobs,
                ];
            })
            ->all();
    }

    private function adventureRecords(Character $character): array
    {
        return Cache::remember(
            "adventurer_card_adventure_records_v2:{$character->id}",
            now()->addMinutes(self::ADVENTURE_RECORDS_CACHE_MINUTES),
            fn (): array => $this->buildAdventureRecords($character)
        );
    }

    private function buildAdventureRecords(Character $character): array
    {
        $battleSummary = BattleLog::query()
            ->where('character_id', $character->id)
            ->actualBattles()
            ->selectRaw('SUM(CASE WHEN result IN (?, ?) THEN 1 ELSE 0 END) as win_count', BattleLog::WIN_RESULTS)
            ->selectRaw('SUM(CASE WHEN result IN (?, ?, ?) THEN 1 ELSE 0 END) as loss_count', BattleLog::LOSS_RESULTS)
            ->selectRaw('SUM(CASE WHEN battle_type = ? AND result IN (?, ?) THEN 1 ELSE 0 END) as boss_win_count', [
                'boss',
                ...BattleLog::WIN_RESULTS,
            ])
            ->first();
        $winCount = (int) ($battleSummary?->win_count ?? 0);
        $lossCount = (int) ($battleSummary?->loss_count ?? 0);
        $battleCount = $winCount + $lossCount;
        $winRate = $battleCount > 0 ? (int) floor(($winCount / $battleCount) * 100) : 0;
        $bossWinCount = (int) ($battleSummary?->boss_win_count ?? 0);
        $masteredJobCount = $character->jobHistories()
            ->where('is_mastered', true)
            ->count();
        $adventureDays = $character->created_at
            ? max(1, (int) $character->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1)
            : 1;
        $titleCount = $character->titles()->count();
        $equipmentCount = $character->characterItems()->count();
        $materialSummary = $character->characterMaterials()
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as kind_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_quantity')
            ->first();
        $materialKindCount = (int) ($materialSummary?->kind_count ?? 0);
        $materialTotal = (int) ($materialSummary?->total_quantity ?? 0);
        $valmonSummary = $character->valmons()
            ->selectRaw('COUNT(*) as valmon_count')
            ->selectRaw('COALESCE(MAX(level), 0) as highest_level')
            ->first();
        $valmonCount = (int) ($valmonSummary?->valmon_count ?? 0);
        $highestValmonLevel = (int) ($valmonSummary?->highest_level ?? 0);

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
            'name' => $characterItem->displayName(false),
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
