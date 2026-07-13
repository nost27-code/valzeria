<?php

namespace App\Livewire;

use Livewire\Component;
use App\Services\AreaService;
use App\Services\PublicLogService;
use App\Services\CharacterGoalService;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use App\Services\BeginnerMissionService;
use App\Services\StorageCapacityService;
use App\Services\HomeActionService;
use App\Services\StarTreeTowerService;
use App\Services\ExplorationStateService;
use App\Services\ExplorationDepthService;
use App\Services\SubAreaDiscoveryService;
use App\Services\DiscoveryService;
use App\Services\TownRankingService;
use App\Support\CharacterIconCatalog;
use App\Support\FacilityConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

#[Layout('components.layouts.app')]
class MainScreen extends Component
{
    private const FERDIA_STORY_VISUAL_ORDERS = [
        1025 => 14, // 星詠みの廃塔
        1027 => 15, // 風化列柱都市オルド
        1026 => 16, // 瀑布神殿アクエリス
        1028 => 17, // 白潮灯台
        1029 => 18, // 地下の謎の穴
    ];

    public $currentLocation = 'home';
    public $character;

    public $isIconModalOpen = false;
    public $isNameModalOpen = false;
    public $newName = '';

    public function openIconModal()
    {
        $this->isIconModalOpen = true;
    }

    public function closeIconModal()
    {
        $this->isIconModalOpen = false;
    }

    public function updateIcon($iconPath)
    {
        if ($this->character) {
            if (CharacterIconCatalog::isSelectable($iconPath)) {
                $this->character->update(['icon_path' => CharacterIconCatalog::normalize($iconPath)]);
                $this->closeIconModal();
                $this->dispatch('character-updated');
            }
        }
    }

    public function openNameModal()
    {
        if ($this->character) {
            $this->newName = $this->character->name;
            $this->isNameModalOpen = true;
        }
    }

    public function openChatDisplaySettings(): void
    {
        $this->dispatch('open-chat-settings-modal');
    }

    public function closeNameModal()
    {
        $this->isNameModalOpen = false;
    }

    public function updateName()
    {
        $this->validate([
            'newName' => 'required|string|max:20',
        ]);

        if ($this->character) {
            $this->character->update(['name' => $this->newName]);
            $this->closeNameModal();
            $this->dispatch('character-updated');
            session()->flash('message', '名前を変更しました。');
        }
    }

    public function setHomeDisplayMode(string $mode): void
    {
        if (!$this->character || !in_array($mode, ['normal', 'simple'], true)) {
            return;
        }

        $this->character->home_display_mode = $mode;
        $this->character->save();
        $this->character->refresh();

        session()->flash('message', $mode === 'simple'
            ? '表示モードを簡易にしました。'
            : '表示モードを普通にしました。'
        );
    }

    public function mount()
    {
        $this->character = Auth::user()->currentCharacter();
        $hasActiveExploration = $this->character
            && app(ExplorationStateService::class)->hasActiveExploration($this->character);
        $defaultLocation = $hasActiveExploration ? 'dungeon' : 'home';

        if ($hasActiveExploration
            && request()->routeIs('home')
            && !request()->boolean('skip_resume')
            && !request()->hasHeader('X-Livewire')) {
            $this->redirectRoute('battle.resume', navigate: false);
            return;
        }

        // セッション指定がなければ、探索中は探索へ復帰し、それ以外はホームポジションの「冒険者」タブを初期表示にする
        $this->currentLocation = $this->normalizeLocation(session('current_location', $defaultLocation));
        if ($hasActiveExploration && $this->currentLocation === 'home') {
            $this->currentLocation = 'dungeon';
        }
        session(['current_location' => $this->currentLocation]); // 現在のタブを記憶

        if ($this->character) {
            $this->character->refresh();
            app(\App\Services\FerdiaMapService::class)->relocateFromDisabledRegion($this->character);

            // 称号の一括遡及チェック
            $titleUnlockService = app(\App\Services\TitleUnlockService::class);
            $unlockedTitles = $titleUnlockService->checkAllUnlocks($this->character);

            if (count($unlockedTitles) > 0) {
                $titleNames = collect($unlockedTitles)
                    ->pluck('name')
                    ->filter()
                    ->implode('、');

                session()->flash('message', "過去の実績により新たな称号を獲得しました！ {$titleNames}");
            }
        }
    }

    #[On('changeTab')]
    public function changeLocation($newLocation)
    {
        $newLocation = $this->normalizeLocation($newLocation);

        if ($this->character && $this->currentLocation === 'dungeon' && $newLocation !== 'dungeon') {
            $hatchedValmons = app(\App\Services\ValmonService::class)->hatchActiveEggs($this->character);
            if (!empty($hatchedValmons)) {
                $message = '卵が淡く光りはじめた……<br>';
                foreach ($hatchedValmons as $hatched) {
                    if (in_array($hatched['rarity'] ?? 'normal', ['rare', 'super_rare'], true)) {
                        $message .= '卵が強く輝いた……<br>';
                    }
                    $message .= $hatched['name'] . 'が生まれた！<br>';
                    $message .= ($hatched['already_had'] ?? false)
                        ? 'すでに仲間にしたことのあるヴァルモンです。<br>'
                        : '新しいヴァルモンが仲間になった！<br>';
                }
                session()->flash('message', $message);
            }
            app(\App\Services\ExplorationStateService::class)->reset($this->character);
        }

        $this->currentLocation = $newLocation;
        session(['current_location' => $newLocation]);

        if ($this->character && $newLocation === 'guild') {
            app(HomeActionService::class)->markDeliverableNpcRequestsSeen($this->character);
            $this->dispatch('marketActionsSeen');
        }
    }

    private function normalizeLocation(?string $location): string
    {
        return $location === 'job' ? 'town' : ($location ?: 'home');
    }

    public function render(AreaService $areaService, PublicLogService $logService, CharacterGoalService $goalService, CharacterStatusService $statusService, EquipmentService $equipmentService, BeginnerMissionService $beginnerMissionService, StorageCapacityService $storageCapacityService, HomeActionService $homeActionService)
    {
        // ジョブが取得できない場合のエラー回避
        $jobName = $this->character && $this->character->jobClass ? $this->character->jobClass->name : '冒険者';

        $isHomeTab = $this->currentLocation === 'home';

        // ホームタブ以外では不要なDB呼び出しをスキップ
        $finalStats = null;
        $equippedItems = [];
        if ($this->character && $isHomeTab) {
            $cid = $this->character->id;
            $finalStats = Cache::remember("home_stats_{$cid}", 60, fn() => $statusService->getFinalStats($this->character));
            $equippedItems = Cache::remember("home_equip_{$cid}", 60, fn() => $equipmentService->getEquippedItems($this->character));
        }

        $currentCity = $this->character && $this->character->currentCity ? $this->character->currentCity : null;

        $isFerdiaSimpleBase = $this->isFerdiaSimpleBase($currentCity);
        $isFerdiaRegion = $currentCity
            && app(\App\Services\FerdiaMapService::class)->isFerdiaCityId((int) $currentCity->id);
        $locationData = $this->getLocationData($currentCity, $this->character, $isFerdiaSimpleBase);
        // Apply facility text overrides from admin panel
        if (isset($locationData['town']['facilities'])) {
            $locationData['town']['facilities'] = $this->applyFacilityOverrides(
                $locationData['town']['facilities'], 'town'
            );
        }
        if ($this->character && isset($locationData['guild']['facilities'])) {
            $deliverableNpcRequestCount = $homeActionService->deliverableNpcRequestCount($this->character);
            foreach ($locationData['guild']['facilities'] as &$guildFacility) {
                if (($guildFacility['route'] ?? null) === 'market.npc-requests.index') {
                    $guildFacility['badge_count'] = $deliverableNpcRequestCount;
                    $guildFacility['badge_label'] = $deliverableNpcRequestCount > 9 ? '9+' : (string) $deliverableNpcRequestCount;
                }
            }
            unset($guildFacility);
        }
        $explorationStateService = app(\App\Services\ExplorationStateService::class);

        // location が dungeon の場合、DBから取得したデータをセットする
        if ($this->currentLocation === 'dungeon') {
            $dungeons = [];
            $allDungeonsCleared = false;
            $totalAreasInCity = 0;
            $nextCityTravel = null;
            $explorationCooldownRemaining = 0;
            $explorationStaminaService = app(\App\Services\ExplorationStaminaService::class);
            $explorationStaminaSummary = null;
            $discoveryRumors = collect();
            if ($currentCity) {
                $totalAreasInCity = \App\Models\Area::where('city_id', $currentCity->id)->where('id', '<=', 70)->count();
            }

            if ($this->character) {
                if ($this->character->exploration_cooldown_until && now()->lt($this->character->exploration_cooldown_until)) {
                    $explorationCooldownRemaining = (int) ceil(now()->diffInSeconds($this->character->exploration_cooldown_until, false));
                }
                if ($explorationStaminaService->enabled()) {
                    $explorationStaminaSummary = $explorationStaminaService->summary($this->character);
                }

                $areas = $areaService->getAreasWithProgress($this->character);
                $discoveryRumors = app(DiscoveryService::class)->visibleRumors($this->character, $currentCity?->id);
                $recordedDepthGatesByArea = DB::table('character_depth_gate_discoveries')
                    ->where('character_id', (int) $this->character->id)
                    ->whereIn('depth_key', ['deepest', 'otherworld'])
                    ->orderByRaw("CASE depth_key WHEN 'deepest' THEN 1 WHEN 'otherworld' THEN 2 ELSE 9 END")
                    ->get()
                    ->groupBy('area_id');
                $depthService = app(ExplorationDepthService::class);
                $powerService = app(CharacterPowerService::class);
                $ferdiaMapService = app(\App\Services\FerdiaMapService::class);
                $dungeonOrder = 1;
                foreach ($areas as $area) {
                    $cityIdStr = sprintf('%02d', $area->city_id);
                    $imageOrder = $this->dungeonImageOrder($area, $dungeonOrder);
                    $orderStr = sprintf('%02d', $imageOrder);
                    
                    // 左側の丸アイコン用
                    $symbolRelativePath = $this->ferdiaDungeonVisualPath($area, 'symbol')
                        ?? "symbol/dungeon_{$cityIdStr}_{$orderStr}.webp";
                    if (file_exists(public_path("images/" . $symbolRelativePath))) {
                        $iconHtml = '<img src="/images/' . $symbolRelativePath . '" alt="' . $area->name . '" class="w-full h-full object-cover rounded">';
                    } else {
                        $iconHtml = '🚪';
                    }
                    
                    // 専用のカード背景画像用
                    $cardBgRelativePath = $this->ferdiaDungeonVisualPath($area, 'card_bg')
                        ?? "card_bg/dungeon_{$cityIdStr}_{$orderStr}.webp";
                    if (file_exists(public_path("images/" . $cardBgRelativePath))) {
                        $bgImage = $cardBgRelativePath;
                    } else {
                        $bgImage = $area->background_image;
                    }
                    
                    $dungeonOrder++;

                    $hasBoss = \App\Models\Enemy::where('area_id', $area->id)->where('is_boss', true)->exists();

                    $status = $area->is_unlocked ? 'active' : 'locked';
                    $actionText = $area->is_route_area ? '道を進む' : '探索する';

                    if (isset($area->meets_job_requirements) && !$area->meets_job_requirements) {
                        $status = 'locked';
                        $actionText = '条件未達';
                    }

                    $explorationSummary = $explorationStateService->summaryForArea($this->character, $area);
                    $recommendedPower = $powerService->recommendedRangeForArea($area);
                    $details = ['目安戦力: ' . $powerService->formatRange($recommendedPower)];
                    $developmentMax = $ferdiaMapService->maxDevelopmentPointForArea($area) ?? 100;
                    if ((int) ($area->development_point ?? 0) > 0 || $area->is_route_area) {
                        $details[] = '開拓度: ' . min($developmentMax, (int) ($area->development_point ?? 0)) . '/' . $developmentMax;
                    }
                    if ($explorationSummary['exploration_point'] > 0 || $explorationSummary['chain_count'] > 0) {
                        $depth = $explorationSummary['depth'] ?? null;
                        if ($depth) {
                            $currentDepth = $depth['current'] ?? [];
                            $details[] = '現在フロア: ' . ($currentDepth['label'] ?? '表層');
                        }
                        $details[] = '探索度: ' . $explorationSummary['exploration_point'];
                        $details[] = '連戦: ' . $explorationSummary['chain_count'];
                        $details[] = '危険度: ' . $explorationSummary['danger_rate'] . '%（' . $explorationSummary['danger_label'] . '）';
                        if (!empty($depth['next'])) {
                            $next = $depth['next'];
                            $need = [];
                            if (($next['remaining_point'] ?? 0) > 0) {
                                $need[] = '探索度+' . $next['remaining_point'];
                            }
                            if (($next['remaining_danger'] ?? 0) > 0) {
                                $need[] = '危険度+' . $next['remaining_danger'] . '%';
                            }
                            $details[] = '次フロア: ' . ($next['label'] ?? '深部') . (!empty($need) ? '（' . implode(' / ', $need) . '）' : '');
                        }

                        $carrySummary = collect(app(\App\Services\ExplorationItemService::class)->carriedItems($this->character))
                            ->filter(fn (array $carry) => $carry['available_count'] > 0)
                            ->map(fn (array $carry) => $carry['name'] . 'x' . $carry['available_count'])
                            ->implode(' ');
                        if ($carrySummary !== '') {
                            $details[] = '持込: ' . $carrySummary;
                        }
                    }
                    $depthEntries = collect($recordedDepthGatesByArea->get($area->id, collect()))
                        ->map(function ($record) use ($area, $depthService, $powerService) {
                            $tier = $depthService->tierByKey((string) $record->depth_key);
                            if (!$tier) {
                                return null;
                            }

                            $recommended = $depthService->recommendedLevelRangeForTier($area, $tier);
                            $recommendedPower = $powerService->recommendedRangeForLevels(
                                (int) ($recommended['min'] ?? 1),
                                (int) ($recommended['max'] ?? $recommended['min'] ?? 1)
                            );

                            return [
                                'key' => (string) $record->depth_key,
                                'label' => (string) ($record->depth_label ?: $tier['label']),
                                'recommended' => '目安戦力 ' . $powerService->formatRange($recommendedPower),
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();

                    $facility = [
                        'id' => $area->id,
                        'name' => $area->name,
                        'icon' => $iconHtml,
                        'desc' => '',
                        'details' => $details,
                        'bg_image' => $bgImage,
                        'depth_overlay' => (int) ($explorationSummary['depth']['current']['overlay'] ?? 0),
                        'depth_key' => $explorationSummary['depth']['current']['key'] ?? 'surface',
                        'status' => $status,
                        'action' => $actionText,
                        'boss_defeated' => $area->boss_defeated,
                        'cooldown_remaining_seconds' => 0,
                        'stamina' => $explorationStaminaSummary,
                        'depth_entries' => $depthEntries,
                    ];

                    if (!$explorationStaminaSummary && $explorationCooldownRemaining > 0 && $status === 'active') {
                        $facility['cooldown_remaining_seconds'] = $explorationCooldownRemaining;
                    }
                    
                    if (isset($area->meets_job_requirements) && !$area->meets_job_requirements && !empty($area->missing_job_names)) {
                        $facility['details'][] = '要マスター: ' . implode(', ', $area->missing_job_names);
                    }

                    if ($area->id >= 71 && $area->id <= 74) {
                        $facility['hide_explore'] = true;
                    }

                    $canChallengeBoss = !$ferdiaMapService->hasBossForArea($area)
                        || $ferdiaMapService->canChallengeBoss($this->character, $area);
                    if ($hasBoss && $canChallengeBoss && ($area->id >= 71 && $area->id <= 74 || !$area->boss_defeated) && $status === 'active') {
                        $facility['boss_action'] = 'ボスに挑む';
                    }
                    
                    $dungeons[] = $facility;
                }

                if ($this->starTreeTowerEnabled() && (int) ($currentCity?->id ?? 0) === 3) {
                    $dungeons[] = $this->starTreeTowerExplorationFacility();
                }
            }

            $clearedDungeonsCount = collect($dungeons)->where('id', '<=', 70)->where('boss_defeated', true)->count();
            if ($totalAreasInCity > 0 && $totalAreasInCity === $clearedDungeonsCount) {
                $allDungeonsCleared = true;
            }
            if ($allDungeonsCleared && $currentCity && $this->character) {
                $highestCityOrder = (int) ($this->character->highestCity?->sort_order ?? 0);
                $nextCity = \App\Models\City::where('sort_order', '>', (int) $currentCity->sort_order)
                    ->orderBy('sort_order', 'asc')
                    ->first();

                if (
                    $nextCity
                    && (int) $nextCity->sort_order <= $highestCityOrder
                    && (int) $this->character->current_city_id !== (int) $nextCity->id
                ) {
                    $nextCityTravel = $nextCity;
                }
            }
            if (!$nextCityTravel && $currentCity && $this->character) {
                $nextCityTravel = app(\App\Services\FerdiaMapService::class)
                    ->nextTravelCityFor($this->character, $currentCity);
            }
            if ($allDungeonsCleared && (
                collect($dungeons)->contains(fn (array $dungeon) => (int) ($dungeon['id'] ?? 0) >= 75)
                || ($discoveryRumors instanceof \Illuminate\Support\Collection && $discoveryRumors->isNotEmpty())
            )) {
                $allDungeonsCleared = false;
            }

            $locationData['dungeon']['facilities'] = $dungeons;
            $locationData['dungeon']['all_cleared'] = $allDungeonsCleared;
            $locationData['dungeon']['next_city_travel'] = $nextCityTravel;
            $locationData['dungeon']['rumors'] = $discoveryRumors
                ->map(function ($link) {
                    $hint = null;
                    if (in_array($link->from_type, ['area', 'route_area'], true)) {
                        $fromArea = \App\Models\Area::find((int) $link->from_id);
                        if ($fromArea) {
                            $hint = $this->rumorHintFromArea($fromArea);
                        }
                    }
                    return [
                        'text'     => $link->rumor_text,
                        'hint'     => $hint,
                        'required' => $link->required_development_point ? '開拓度' . $link->required_development_point : null,
                    ];
                })
                ->values()
                ->all();
        }

        // ホームタブ専用データ（他タブでは計算不要）
        $nextGoal = ($this->character && $isHomeTab) ? $goalService->getNextGoal($this->character) : null;
        $showsBeginnerMissions = in_array($this->currentLocation, ['town', 'dungeon', 'home', 'guild'], true);
        $beginnerMissions = ($this->character && $showsBeginnerMissions) ? $beginnerMissionService->summary($this->character) : null;

        $currentCity = $this->character && $this->character->currentCity ? $this->character->currentCity : null;
        $storageIsFull = ($this->character && $isHomeTab) ? $storageCapacityService->isFull($this->character) : false;
        $storageFullMessage = $storageIsFull ? $storageCapacityService->fullMessageHtml($this->character) : null;
        $subAreaDiscoveries = ($this->character && $this->currentLocation === 'dungeon')
            ? app(SubAreaDiscoveryService::class)->discoveredRoutes($this->character, (int) ($currentCity?->id ?? 0))
            : collect();
        $hasActiveValmonEgg = $this->character && $this->currentLocation === 'dungeon'
            ? $this->character->valmonEggs()
                ->where('is_hatched', false)
                ->where('is_lost', false)
                ->exists()
            : false;
        $targetAreaId = (int) session()->pull('target_area_id', 0);
        $targetAreaPurpose = (string) session()->pull(
            'target_area_purpose',
            session()->has('material_hunt') ? 'material_source' : 'focus'
        );

        $cities = null;
        $highestCityOrder = 0;
        $cityPopulationCounts = collect();
        $cityIconSamples = collect();
        $ferdiaMap = null;
        $initialMapRegion = 'valzeria';
        if ($this->currentLocation === 'move') {
            $ferdiaMapService = app(\App\Services\FerdiaMapService::class);
            $cities = \App\Models\City::orderBy('sort_order', 'asc')
                ->get()
                ->reject(fn (\App\Models\City $city): bool => $ferdiaMapService->isFerdiaCityId((int) $city->id))
                ->values();
            $highestCityOrder = $this->character && $this->character->highestCity ? $this->character->highestCity->sort_order : 0;
            $cityPopulationService = app(\App\Services\CityPopulationService::class);
            $cityPopulationCounts = $cityPopulationService->countsByCity();
            $cityIconSamples = $cityPopulationService->iconSamplesByCity(12);
            if ($this->character) {
                $ferdiaMap = $ferdiaMapService->mapFor($this->character);
                if (!empty($ferdiaMap) && $ferdiaMapService->isFerdiaCityId((int) ($this->character->current_city_id ?? 0))) {
                    $initialMapRegion = 'ferdia';
                }
            }
        }
        $rankingSpotlightLeader = $this->currentLocation === 'town'
            ? $this->rankingSpotlightLeader()
            : null;

        return view('livewire.main-screen', [
            'character' => $this->character,
            'currentCity' => $currentCity,
            'cities' => $cities,
            'highestCityOrder' => $highestCityOrder,
            'cityPopulationCounts' => $cityPopulationCounts,
            'cityIconSamples' => $cityIconSamples,
            'ferdiaMap' => $ferdiaMap,
            'initialMapRegion' => $initialMapRegion,
            'isFerdiaSimpleBase' => $isFerdiaSimpleBase,
            'isFerdiaRegion' => $isFerdiaRegion,
            'jobName' => $jobName,
            'rankingData' => $rankingData ?? [],
            'locationData' => $locationData[$this->currentLocation] ?? $locationData['town'],
            'townFacilities' => $locationData['town']['facilities'] ?? [],
            'nextGoal' => $nextGoal,
            'beginnerMissions' => $beginnerMissions,
            'finalStats' => $finalStats,
            'equippedItems' => $equippedItems,
            'homeDisplayMode' => $this->character?->home_display_mode ?: 'normal',
            'homeMenuItems' => $isHomeTab ? $this->applyFacilityOverrides($this->homeMenuItems(), 'home') : [],
            'homeActions' => ($this->character && $isHomeTab)
                ? Cache::remember("home_actions_{$this->character->id}", 30, fn() => $homeActionService->getActions($this->character, 4, $finalStats))
                : [],
            'simpleFacilities' => $isHomeTab ? $this->applyFacilityOverrides($this->simpleFacilities($locationData), 'simple') : [],
            'storageIsFull' => $storageIsFull,
            'storageFullMessage' => $storageFullMessage,
            'subAreaDiscoveries' => $subAreaDiscoveries,
            'hasActiveValmonEgg' => $hasActiveValmonEgg,
            'targetAreaId' => $targetAreaId,
            'targetAreaPurpose' => $targetAreaPurpose,
            'characterIconPaths' => ($this->currentLocation === 'settings' || $this->isIconModalOpen) ? CharacterIconCatalog::paths() : [],
            'rankingSpotlightLeader' => $rankingSpotlightLeader,
        ]);
    }

    public static function clearHomeCache(int $characterId): void
    {
        Cache::forget("home_stats_{$characterId}");
        Cache::forget("home_equip_{$characterId}");
        Cache::forget("home_actions_{$characterId}");
    }

    private function rankingSpotlightLeader(): ?array
    {
        $candidates = collect(app(TownRankingService::class)->boards())
            ->map(function (array $board, string $key): ?array {
                $leader = $board['rows'][0] ?? null;
                if (!$leader) {
                    return null;
                }

                return [
                    'board_key' => $key,
                    'board_title' => (string) ($board['title'] ?? ''),
                    'unit' => (string) ($board['unit'] ?? ''),
                    'name' => (string) ($leader['name'] ?? ''),
                    'icon_path' => (string) ($leader['icon_path'] ?? CharacterIconCatalog::DEFAULT_ICON),
                    'image_type' => (string) ($leader['image_type'] ?? 'character'),
                    'score' => (int) ($leader['score'] ?? 0),
                ];
            })
            ->filter()
            ->values();

        return $candidates->isNotEmpty() ? $candidates->random() : null;
    }

    private function dungeonImageOrder($area, int $fallbackOrder): int
    {
        if ((bool) ($area->is_route_area ?? false)) {
            return 10;
        }

        return $fallbackOrder;
    }

    private function ferdiaDungeonVisualPath($area, string $directory): ?string
    {
        $areaId = (int) ($area->id ?? 0);
        if ($areaId <= 0) {
            return null;
        }

        $visualOrder = self::FERDIA_STORY_VISUAL_ORDERS[$areaId] ?? null;
        if ($visualOrder === null) {
            $mainAreaIds = collect(config('ferdia_world_map.nodes', []))
                ->filter(fn (array $node): bool => ($node['route_group'] ?? null) === 'main' && !empty($node['area_id']))
                ->sortBy('sequence')
                ->pluck('area_id')
                ->values();
            $index = $mainAreaIds->search($areaId);
            if ($index === false) {
                return null;
            }

            $visualOrder = $index + 1;
        }

        $relativePath = sprintf('%s/dungeon_11_%02d.webp', $directory, $visualOrder);

        return file_exists(public_path("images/{$relativePath}")) ? $relativePath : null;
    }

    private function simpleFacilities(array $locationData): array
    {
        $town = collect($locationData['town']['facilities'] ?? []);

        $findTown = function (string $name) use ($town): ?array {
            $facility = $town->first(fn (array $row) => ($row['name'] ?? '') === $name);
            return $facility ?: null;
        };

        $items = [
            ['group' => '冒険', 'name' => '探索する', 'icon_image' => 'icon/icon_004.webp', 'icon' => '⛩️', 'desc' => '解放済みダンジョンへ向かう', 'action' => '開く', 'tab' => 'dungeon', 'status' => 'active'],
            ['group' => '冒険', 'name' => '街を移動', 'icon_image' => 'icon/icon_003.webp', 'icon' => '🌍', 'desc' => '世界地図から街を選ぶ', 'action' => '移動', 'tab' => 'move', 'status' => 'active'],
            ['group' => '回復', ...($findTown('宿屋') ?? [])],
            ['group' => '回復', ...($findTown('補給所') ?? [])],
            ['group' => '倉庫', 'name' => '倉庫', 'icon_image' => 'icon/icon_025.webp', 'icon' => '📦', 'desc' => '素材や探索用アイテムを確認する', 'action' => '見る', 'route' => 'inventory.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '装備', 'name' => '装備変更', 'icon_image' => 'icon/icon_006.webp', 'icon' => '🗡️', 'desc' => '装備・保護・倉庫・売却を行う', 'action' => '開く', 'route' => 'equipment.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '育成', 'name' => '神殿', 'icon_image' => 'facilities/facility_temple.webp', 'icon' => '⛪', 'desc' => '職業変更と職業ランクを確認する', 'action' => '入る', 'route' => 'jobs.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '記録', 'name' => 'アイテム図鑑', 'icon_image' => 'icon/icon_241.webp', 'icon' => '📖', 'desc' => '素材の入手方法・作り方・用途を確認する', 'action' => '見る', 'route' => 'item-book.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '記録', 'name' => '印図鑑', 'icon_image' => 'icon/icon_240.webp', 'icon' => '📖', 'desc' => '集めた印の永続効果を確認する', 'action' => '見る', 'route' => 'monster-marks.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '育成', 'name' => 'ヴァルモン牧場', 'icon_image' => 'icon/icon_038.webp', 'icon' => '🥚', 'desc' => '相棒ヴァルモンを確認・育成する', 'action' => '見る', 'route' => 'valmons.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '記録', 'name' => '番付掲示板', 'icon_image' => 'icon/icon_223.webp', 'icon' => '📊', 'desc' => '冒険者たちの各種番付を確認する', 'action' => '見る', 'route' => 'ranking.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '工房', ...($findTown('鍛冶屋') ?? [])],
            ['group' => '工房', ...($findTown('合成屋') ?? [])],
            ['group' => '工房', ...($findTown('素材交換所') ?? [])],
            ['group' => '交流', 'name' => '冒険者市場', 'icon_image' => 'icon/icon_032.webp', 'icon' => '⚖️', 'desc' => '素材を冒険者同士で売買する', 'action' => '開く', 'route' => 'market.index', 'is_post' => false, 'status' => 'active'],
            ['group' => '交流', 'name' => '闘技場', 'icon_image' => 'icon/icon_005.webp', 'icon' => '⚔️', 'desc' => '他の冒険者と戦う', 'action' => '開く', 'tab' => 'colosseum', 'status' => 'active'],
            ['group' => '交流', ...($findTown('酒場') ?? [])],
            ['group' => '交流', 'name' => '個人チャット', 'icon_image' => 'icon/icon_015.webp', 'icon' => '✉️', 'desc' => '冒険者同士でメッセージを送る', 'action' => '開く', 'tab' => 'message', 'status' => 'active'],
            ['group' => 'その他', ...($findTown('案内所') ?? [])],
            ['group' => 'その他', 'name' => '設定', 'icon_image' => 'icon/icon_022.webp', 'icon' => '⚙️', 'desc' => '表示やキャラクター情報を変更する', 'action' => '開く', 'tab' => 'settings', 'status' => 'active'],
        ];

        return array_values(array_filter($items, fn (array $item) => !empty($item['name'])));
    }

    private function starTreeTowerExplorationFacility(): array
    {
        return [
            'name' => '星樹の塔',
            'symbol_image' => 'tower/01_tower_symbol.webp',
            'desc' => '星を宿す枝が、エルフィアの空へ静かに伸びている。',
            'details' => ['階層挑戦', '到達称号', '週次記録'],
            'bg_image' => 'tower/01_tower_card_bg.webp',
            'card_variant' => 'star_tree',
            'status' => 'active',
            'action' => '登る',
            'route' => 'tower.star-tree.index',
            'is_post' => false,
        ];
    }

    private function starTreeTowerEnabled(): bool
    {
        return app(StarTreeTowerService::class)->isEnabled();
    }

    private function homeMenuItems(): array
    {
        return [
            ['group' => '育成', 'name' => '能力割振り', 'icon_image' => 'menu/menu_bonus_points.webp', 'icon' => '✦', 'desc' => '未使用BPを使って能力を伸ばす', 'route' => 'bonus-points.index', 'status' => 'active'],
            ['group' => '育成', 'name' => '奥義', 'icon_image' => 'icon/icon_041.webp', 'icon' => '✦', 'desc' => '習得した奥義を最大3つまでセットする', 'route' => 'job-arts.index', 'status' => 'active'],
            ['group' => '記録', 'name' => 'アイテム図鑑', 'icon_image' => 'icon/icon_241.webp', 'icon' => '📖', 'desc' => '素材の入手方法・作り方・用途を確認する', 'route' => 'item-book.index', 'status' => 'active'],
            ['group' => '記録', 'name' => '印図鑑', 'icon_image' => 'icon/icon_240.webp', 'icon' => '📖', 'desc' => '集めた印の永続効果を確認する', 'route' => 'monster-marks.index', 'status' => 'active'],
            ['group' => '記録', 'name' => '称号', 'icon_image' => 'icon/icon_242.webp', 'icon' => '🏷️', 'desc' => '獲得した称号を確認する', 'route' => 'titles.index', 'status' => 'active'],
            ['group' => '育成', 'name' => 'ヴァルモン', 'icon_image' => 'menu/menu_valmon.webp', 'icon' => '🥚', 'desc' => '相棒ヴァルモンの確認・育成を行う', 'route' => 'valmons.index', 'status' => 'active'],
            ['group' => '持ち物', 'name' => '装備', 'icon_image' => 'icon/icon_006.webp', 'icon' => '🗡️', 'desc' => '装備変更・保護・売却を行う', 'route' => 'equipment.index', 'status' => 'active'],
            ['group' => '持ち物', 'name' => '倉庫', 'icon_image' => 'menu/menu_storage.webp', 'icon' => '📦', 'desc' => '素材や探索用アイテムを確認する', 'route' => 'inventory.index', 'status' => 'active'],
            ['group' => '交流', 'name' => '個人チャット', 'icon_image' => 'menu/menu_messages.webp', 'icon' => '✉️', 'desc' => '冒険者同士でメッセージをやり取りする', 'tab' => 'message', 'status' => 'active'],
            ['group' => '案内', 'name' => 'ヘルプ', 'icon_image' => 'menu/menu_help.webp', 'icon' => '📘', 'desc' => '遊び方や施設の説明を確認する', 'route' => 'town.guide', 'status' => 'active'],
            ['group' => '案内', 'name' => '不具合報告', 'icon_image' => 'icon/icon_033.webp', 'icon' => '!', 'desc' => '不具合や表示崩れを管理人へ報告する', 'route' => 'bug-reports.create', 'status' => 'active'],
            ['group' => '設定', 'name' => '設定', 'icon_image' => 'menu/menu_settings.webp', 'icon' => '⚙️', 'desc' => '名前やアイコンなどを変更する', 'tab' => 'settings', 'status' => 'active'],
        ];
    }

    private function applyFacilityOverrides(array $items, string $section): array
    {
        $slugMap = FacilityConfig::nameToSlug($section);
        return array_map(function (array $item) use ($section, $slugMap) {
            $name = $item['name'] ?? '';
            $slug = $slugMap[$name] ?? null;
            if (!$slug) {
                return $item;
            }
            $prefix = "fac.{$section}.{$slug}";
            $nameOverride = game_text("{$prefix}.name");
            $descOverride = game_text("{$prefix}.desc");
            $iconOverride = game_text("{$prefix}.icon");
            if ($nameOverride !== '') {
                $item['name'] = $nameOverride;
            }
            if ($descOverride !== '') {
                $item['desc'] = $descOverride;
            }
            if ($iconOverride !== '') {
                $item['icon_image'] = $iconOverride;
            }
            return $item;
        }, $items);
    }

    private function isFerdiaSimpleBase($currentCity = null): bool
    {
        if (!$this->character || !$currentCity || $this->currentLocation !== 'dungeon') {
            return false;
        }

        $ferdiaMapService = app(\App\Services\FerdiaMapService::class);
        if (!$ferdiaMapService->isFerdiaCityId((int) $currentCity->id)) {
            return false;
        }

        $areaId = (int) session('target_area_id', 0);
        if ($areaId <= 0) {
            $state = app(ExplorationStateService::class)->currentFor($this->character);
            $areaId = (int) ($state?->area_id ?? 0);
        }

        return $areaId > 0 && $ferdiaMapService->isFerdiaAreaId($areaId);
    }

    private function getLocationData($currentCity = null, $character = null, bool $isFerdiaSimpleBase = false)
    {
        $cityName = $currentCity ? $currentCity->name : '冒険都市ヴァルゼリア';
        $cityDesc = $currentCity ? $currentCity->description : '冒険者たちが集まるヴァルゼリアの玄関口です。';
        $cityId = (int) ($currentCity->id ?? 0);
        if ($isFerdiaSimpleBase) {
            $cityName = 'フェルディア簡易拠点';
            $cityDesc = '';
        }
        $hasEquipmentShop = $cityId >= 1 && $cityId <= 6;
        $innRestBlocked = false;
        $innRestBlockMessage = 'HP/SPが満タンです。宿屋で休む必要はありません。';
        if ($character) {
            $finalStats = app(CharacterStatusService::class)->getFinalStats($character);
            $maxHp = (int) ($finalStats['max_hp'] ?? $character->hp_base);
            $maxMp = (int) ($finalStats['max_mp'] ?? 0);
            $innRestBlocked = (int) $character->current_hp >= $maxHp
                && ($maxMp === 0 || (int) $character->current_mp >= $maxMp);
        }

        $explorationSupportEnabled = app(\App\Services\ExplorationSupportService::class)->isEnabled();

        $townFacilities = [
            ['category' => '休息・補給', 'name' => '宿屋', 'symbol_image' => 'facilities/facility_inn_300.webp', 'desc' => 'HPとSPを全回復して次の冒険に備える', 'details' => ['Lv20まで10G', 'Lv21以降: Lv × 10G'], 'badge' => ($this->character ? app(\App\Services\InnService::class)->fee($this->character) . 'G' : null), 'bg_image' => 'facilities/inn.webp', 'status' => 'active', 'action' => '休む', 'route' => 'inn.rest', 'is_post' => true, 'rest_blocked' => $innRestBlocked, 'rest_block_message' => $innRestBlockMessage],
            ['category' => '休息・補給', 'name' => '補給所', 'symbol_image' => 'facilities/facility_supply_300.webp', 'desc' => '毎日の回復アイテム補給と残りストックを受け取る', 'details' => ['薬草・回復薬・魔力水', '各10個/日'], 'bg_image' => 'facilities/item.webp', 'status' => 'active', 'action' => '受け取る', 'route' => 'shop.items', 'is_post' => false],
            ...($hasEquipmentShop && !$isFerdiaSimpleBase ? [
                ['category' => '装備', 'name' => '装備屋', 'symbol_image' => 'facilities/facility_equipment_shop.webp', 'desc' => 'この街で作られた店売り装備をGoldで購入する', 'details' => ['進化不可', '+5強化可'], 'bg_image' => 'facilities/item.webp', 'status' => 'active', 'action' => '入る', 'route' => 'shop.equipment', 'is_post' => false],
            ] : []),
            ['category' => '工房', 'name' => '鍛冶屋', 'symbol_image' => 'facilities/facility_blacksmith_300.webp', 'desc' => '強化石系素材で装備を+1〜+5へ強化する', 'details' => ['装備強化', '成功率100%'], 'bg_image' => 'card_bg/shop_blacksmith.webp', 'status' => 'active', 'action' => '入る', 'route' => 'blacksmith.index', 'is_post' => false],
            ['category' => '工房', 'name' => '合成屋', 'symbol_image' => 'facilities/facility_synthesis_300.webp', 'desc' => '装備と欠片・専用素材で武器・防具を進化させる', 'details' => ['成功率100%'], 'bg_image' => 'card_bg/shop_blacksmith.webp', 'status' => 'active', 'action' => '入る', 'route' => 'smith.index', 'is_post' => false],
            ['category' => '工房', 'name' => '素材交換所', 'symbol_image' => 'facilities/facility_material_exchange_300.webp', 'desc' => '素材精製・錬成・調合で必要素材を作る', 'details' => ['強化石・導石・秘境晶', '装飾素材・回復調合'], 'bg_image' => 'facilities/item.webp', 'status' => 'active', 'action' => '入る', 'route' => 'material-exchange.index', 'is_post' => false],
            ...($explorationSupportEnabled ? [
                ['category' => '工房', 'name' => '薬屋', 'symbol_image' => 'facilities/shop_item_symbol.webp', 'desc' => 'フェルディアの薬素材から30戦有効の探索補助品を調合する', 'details' => ['探索補助品', '薬素材調合'], 'bg_image' => 'facilities/item.webp', 'status' => 'active', 'action' => '入る', 'route' => 'apothecary.index', 'is_post' => false],
            ] : []),
            ['category' => '育成', 'name' => 'ヴァルモン牧場', 'symbol_image' => 'facilities/facility_valmon_farm_300.webp', 'desc' => '相棒ヴァルモンの確認・相棒設定・餌育成を行う', 'details' => ['探索補助', '図鑑'], 'bg_image' => 'facilities/valfarm.webp', 'status' => 'active', 'action' => '見る', 'route' => 'valmons.index', 'is_post' => false],
            ...(!$isFerdiaSimpleBase ? [
                ['category' => '記録', 'name' => 'アイテム図鑑', 'symbol_image' => 'icon/icon_241.webp', 'desc' => '素材の入手方法・作り方・用途を確認する', 'details' => ['未所持も表示', '作り方確認'], 'bg_image' => 'facilities/item.webp', 'status' => 'active', 'action' => '見る', 'route' => 'item-book.index', 'is_post' => false],
            ] : []),
            ['category' => '育成', 'name' => '神殿', 'symbol_image' => 'facilities/facility_temple.webp', 'desc' => '職業変更と職業ランクを確認する', 'details' => ['転職', '職業ランク'], 'bg_image' => 'facilities/01_転職所.webp', 'status' => 'active', 'action' => '入る', 'route' => 'jobs.index', 'is_post' => false],
            ...(!$isFerdiaSimpleBase ? [
                ['category' => 'その他', 'name' => '案内所', 'symbol_image' => 'facilities/facility_guide_300.webp', 'desc' => 'ヴァルゼリアの遊び方やヘルプを確認する', 'details' => ['初心者おすすめ'], 'bg_image' => 'facilities/guide.webp', 'status' => 'active', 'action' => '見る', 'route' => 'town.guide', 'is_post' => false],
            ] : []),
            ['category' => 'その他', 'name' => '銀行', 'symbol_image' => 'facilities/facility_bank.webp', 'desc' => 'Goldを預けて探索中の喪失から守る', 'details' => ['預ける', '引き出す'], 'bg_image' => 'facilities/bank.webp', 'status' => 'active', 'action' => '入る', 'route' => 'bank.index', 'is_post' => false],
            ...(!$isFerdiaSimpleBase ? [
                ['category' => 'その他', 'name' => '酒場', 'symbol_image' => 'facilities/facility_tavern_300.webp', 'desc' => '冒険者たちの噂話や名簿を確認する', 'details' => ['NPC出現中'], 'bg_image' => 'facilities/tavern.webp', 'status' => 'active', 'action' => '入る', 'route' => 'tavern.index', 'is_post' => false],
            ] : []),
            ['category' => 'その他', 'name' => '番付掲示板', 'symbol_image' => 'icon/icon_223.webp', 'desc' => '戦績・収集・商いなど冒険者たちの各種番付を見る', 'details' => ['勝利数', '収集', '市場売上'], 'bg_image' => 'facilities/guide.webp', 'status' => 'active', 'action' => '見る', 'route' => 'ranking.index', 'is_post' => false],
            ...(!$isFerdiaSimpleBase ? [
                ['category' => 'その他', 'name' => '冒険者協会', 'symbol_image' => 'facilities/association_symbol.webp', 'desc' => '救助支援システムを調整中', 'details' => ['準備中'], 'bg_image' => 'facilities/association.webp', 'status' => 'coming_soon', 'action' => '準備中'],
            ] : []),
            ['category' => 'ショップ', 'name' => '輝石ショップ', 'symbol_image' => 'facilities/kiseki_shop.webp', 'desc' => '有償輝石を購入してアイテムや強化に役立てる', 'details' => ['5種類のパック', 'クレジットカード・PayPay決済'], 'bg_image' => 'facilities/kiseki.webp', 'status' => 'active', 'action' => '購入する', 'route' => 'kiseki.shop', 'is_post' => false],
            ['category' => 'ショップ', 'name' => '補給商会', 'symbol_image' => 'facilities/hokyu_symbol.webp', 'desc' => '輝石やGoldで冒険支援アイテムを購入できる', 'details' => ['救助保険', '緊急支援', '補給箱'], 'bg_image' => 'facilities/hokyu.webp', 'status' => 'active', 'action' => '入る', 'route' => 'kiseki.support', 'is_post' => false],
        ];

        if ($isFerdiaSimpleBase) {
            $townFacilities = array_map(function (array $facility): array {
                $facility['category'] = '簡易拠点';

                return $facility;
            }, $townFacilities);
        }

        return [
            'home' => [
                'title' => 'ホーム',
                'description' => 'キャラクターのステータスと装備を確認する',
                'facilities' => [],
            ],
            'move' => [
                'title' => '街の移動',
                'description' => '世界地図から新しい街や拠点へ移動します。',
                'news_title' => '世界地図',
                'news_items' => ['新しい場所を開拓しよう'],
                'facilities' => [],
            ],
            'town' => [
                'title' => $cityName,
                'description' => $cityDesc,
                'news_title' => '街のうわさ',
                'news' => [
                    '初心者さんが冒険都市ヴァルゼリアに降り立ちました',
                    '黒猫旅団が新しい冒険者を募集しています',
                    '本日の決闘は198戦です'
                ],
                'facilities' => $townFacilities,
            ],
            'dungeon' => [
                'title' => '探索へ',
                'description' => '解放済みの戦闘場所へ向かいます。',
                'news_title' => '最近の討伐報告',
                'news' => [
                    'キリトさんが小鬼の森のボスを討伐しました！',
                    '名無しの権兵衛さんが古びた洞窟で全滅しました...'
                ],
                'facilities' => [] // renderで上書き
            ],
            'colosseum' => [
                'title' => '闘技場',
                'description' => '育てた力を試し、最強冒険者を目指す場所。',
                'news_title' => '闘技場の熱狂',
                'news' => [
                    'アスナさんがランク戦で5連勝中です！',
                    '本日の王者戦は20:00より開催されます'
                ],
                'facilities' => [
                    ['name' => 'ランク戦', 'icon_image' => 'icon/icon_010.webp', 'icon' => '🏆', 'desc' => '近い実力の冒険者とランダムマッチ', 'details' => ['報酬: BP+10'], 'status' => 'active', 'action' => '挑む'],
                    ['name' => '決闘相手', 'icon_image' => 'icon/icon_057.webp', 'icon' => '🤺', 'desc' => '対戦できる冒険者を一覧から探す', 'details' => ['指名対戦'], 'status' => 'active', 'action' => '挑む'],
                    ['name' => 'PvP順位', 'icon_image' => 'icon/icon_053.webp', 'icon' => '📊', 'desc' => '現在の闘技場ランキングを確認する', 'details' => ['毎週月曜リセット'], 'status' => 'active', 'action' => '確認する'],
                    ['name' => '王者戦', 'icon_image' => 'icon/icon_009.webp', 'icon' => '👑', 'desc' => '現在の王者に挑戦する特別な決闘', 'details' => ['参加資格: ランクS以上'], 'status' => 'locked', 'action' => '挑む'],
                ]
            ],
            'job' => [
                'title' => '神殿',
                'description' => '職を極め、新たな力を得る場所。',
                'news_title' => '最新の転職者',
                'news' => [
                    '初心者さんが戦士に転職しました',
                    '魔法使いが人気急上昇中です'
                ],
                'facilities' => [
                    ['name' => '神殿に入る', 'symbol_image' => 'facilities/facility_temple.webp', 'icon' => '🏛️', 'desc' => '転職可能な職業を確認し、新たな職に就く', 'details' => ['条件: Lv100到達'], 'bg_image' => 'facilities/01_転職所.webp', 'status' => 'active', 'action' => '入る', 'route' => 'jobs.index', 'is_post' => false],
                ]
            ],
            'guild' => [
                'title' => '市場・依頼',
                'description' => '素材・装備の売買や、冒険者同士の依頼を扱う場所。',
                'news_title' => '市場掲示板',
                'news' => [
                    '素材市場で素材の売買が始まりました',
                    '装備市場で銘・特攻付き武器を売買できます',
                    '調達依頼で素材を納品できるようになりました'
                ],
                'facilities' => [
                    ['name' => '素材市場', 'symbol_image' => 'facilities/facility_adventurer_market.webp', 'icon' => '⚖️', 'desc' => '通常素材・地域素材を匿名で売買する', 'details' => ['3%手数料', '48時間出品'], 'status' => 'active', 'action' => '開く', 'route' => 'market.index', 'is_post' => false],
                    ['name' => '装備市場', 'symbol_image' => 'facilities/facility_market_300.webp', 'icon' => '⚔️', 'desc' => '銘・特攻付き武器を匿名で売買する', 'details' => ['成立手数料10%', '72時間出品'], 'status' => 'active', 'action' => '開く', 'route' => 'equipment-market.index', 'is_post' => false],
                    ['name' => '調達依頼', 'symbol_image' => 'facilities/facility_request_board.webp', 'icon' => '📋', 'desc' => '街や組織が必要としている素材を納品する', 'details' => ['NPC依頼', '即時報酬'], 'status' => 'active', 'action' => '開く', 'route' => 'market.npc-requests.index', 'is_post' => false],
                ]
            ],
            'message' => [
                'title' => '個人チャット',
                'description' => '冒険者同士で個人的なメッセージをやり取りする場所です。',
                'news_title' => 'お知らせ',
                'news' => [
                    '個人チャットが利用可能になりました'
                ],
                'facilities' => [
                    ['name' => '会話一覧', 'icon_image' => 'icon/icon_016.webp', 'icon' => '💬', 'desc' => '冒険者ごとの個人チャットを開く', 'details' => ['会話を見る'], 'status' => 'active', 'action' => '開く', 'route' => 'message.index', 'is_post' => false],
                    ['name' => '相手を選ぶ', 'icon_image' => 'icon/icon_021.webp', 'icon' => '✍️', 'desc' => '冒険者を選んで新しい会話を始める', 'details' => ['会話を始める'], 'status' => 'active', 'action' => '開く', 'route' => 'message.index', 'params' => ['tab' => 'create'], 'is_post' => false],
                ]
            ],
            'settings' => [
                'title' => '各種設定',
                'description' => 'キャラクター情報の変更などを行います。',
                'news_title' => '設定について',
                'news' => [
                    '名前やアイコンなどを変更できます'
                ],
                'facilities' => [
                    ['name' => 'アイコン変更', 'icon_image' => 'icon/icon_059.webp', 'icon' => '🖼️', 'desc' => 'キャラクターの見た目を変更します', 'details' => ['現在: ' . basename($character->icon_path ?? '')], 'bg_image' => 'facilities/03_アイコン変更.webp', 'status' => 'active', 'action' => '変更する', 'method' => 'openIconModal'],
                    ['name' => '名前変更', 'icon_image' => 'icon/icon_014.webp', 'icon' => '🏷️', 'desc' => 'キャラクターの名前を変更します', 'details' => ['現在の名前: ' . ($character->name ?? '')], 'bg_image' => 'facilities/04_名前変更.webp', 'status' => 'active', 'action' => '変更する', 'method' => 'openNameModal'],
                    ['name' => 'プロフィール編集', 'icon_image' => 'icon/icon_021.webp', 'icon' => '📝', 'desc' => '自己紹介文や牧場背景を編集します', 'details' => ['コメント', '背景'], 'status' => 'active', 'action' => '編集する', 'route' => 'profile.edit', 'is_post' => false],
                    ['name' => 'チャット表示項目', 'icon_image' => 'icon/icon_022.webp', 'icon' => '⚙️', 'desc' => '全体チャットに表示する項目を変更します', 'details' => ['冒険者ごとに保存'], 'status' => 'active', 'action' => '開く', 'method' => 'openChatDisplaySettings'],
                    ['name' => 'ログアウト', 'icon_image' => 'icon/icon_046.webp', 'icon' => '↩', 'desc' => '現在のアカウントからログアウトします', 'details' => ['ログイン画面へ戻る'], 'status' => 'active', 'action' => 'ログアウト', 'route' => 'auth.logout', 'is_post' => true],
                    ['name' => 'アカウント削除', 'icon_image' => 'icon/icon_046.webp', 'icon' => '⚠️', 'desc' => 'Google連携と作成データを完全に削除します', 'details' => ['取り消し不可'], 'status' => 'active', 'action' => '確認する', 'route' => 'account.delete', 'is_post' => false],
                ]
            ],
        ];
    }

    private function rumorHintFromArea(\App\Models\Area $area): string
    {
        $type = '';
        if (preg_match('/^【([^】]+)】/u', $area->description ?? '', $m)) {
            $type = $m[1];
        }

        $phrases = [
            '草原'   => 'あの広い草原の奥を進んでいると、',
            '森'     => 'うっそうとした森の中へ踏み込むと、',
            '洞窟'   => 'ほの暗い洞窟の奥を探っていると、',
            '丘陵'   => '丘の頂を越えた先を眺めると、',
            '墓地'   => '薄気味悪い墓地の奥を歩いていると、',
            '泉'     => '静かな泉のほとりを探っていると、',
            '訓練場' => '訓練場の最奥まで踏み込んでみると、',
            '海岸'   => '波打ち際をずっと歩いていくと、',
            '船'     => '難破船の腐った板を押しのけていると、',
            '入り江' => '穏やかな入り江の奥まで進むと、',
            '拠点'   => '廃屋の暗がりを調べていると、',
            '迷宮'   => '入り組んだ回路の奥を辿っていると、',
            '神殿'   => '石畳の神殿の奥へ進んでいくと、',
            '樹海'   => '絡み合う根の合間を潜っていくと、',
            '世界樹' => '巨木の幹を見上げながら進むと、',
            '庭園'   => '手入れされた庭園の外れまで足を伸ばすと、',
            '鉱山'   => '掘り進められた坑道の奥へ入ると、',
            '坑道'   => '崩れかけた坑道の先を覗くと、',
            '炉跡'   => '熱気の残る炉跡を奥まで調べると、',
            '工場'   => '轟音が響く工場の奥を探ると、',
            '施設'   => '不思議な施設の奥へ踏み込むと、',
            '兵器庫' => '古びた兵器庫の最深部を調べると、',
            '雪原'   => '一面の雪原を吹雪に逆らって進むと、',
            '峡谷'   => '吹雪が舞う峡谷を奥まで歩くと、',
            '雪森'   => '雪に覆われた森の奥へ踏み込むと、',
            '竜巣'   => '爪痕が刻まれた巣穴の奥を進むと、',
            '山脈'   => '険しい岩肌の山道を登り続けると、',
            '砂漠'   => '砂嵐の合間を縫って砂丘を越えると、',
            '遺跡'   => '崩れた石壁の奥を探り当てると、',
            '墓所'   => '石棺が並ぶ墓所の奥へ踏み込むと、',
            '水路'   => '薄暗い地下水路を泳ぐように進むと、',
            '図書館' => '棚が延々と続く書架の奥を歩くと、',
            '書庫'   => '封じられた扉の向こうを調べると、',
            '研究所' => '不気味な匂いの漂う実験室を探ると、',
            '塔'     => '螺旋階段を延々と上り続けると、',
            '回廊'   => '薄闇が続く回廊を進み続けると、',
            '荒野'   => '枯れ果てた荒野をひたすら歩くと、',
            '城'     => '腐敗した空気が漂う城の廊下を進むと、',
            '門'     => '重く軋む門の周辺を調べていると、',
            '要塞'   => '厚い城壁が続く要塞の奥へ入ると、',
            '谷'     => '靄が立ち込める谷底を歩き続けると、',
            '階段'   => '底の見えない階段を下り続けると、',
            '雲海'   => '雲の上をふわふわと歩いていると、',
            '玉座前' => '邪気が渦巻く玉座の間の手前で、',
            '牢獄'   => '鉄格子が続く牢獄の最奥まで進むと、',
            '玉座'   => '漆黒の玉座が鎮座する間を調べると、',
            '中枢'   => '魔力が渦巻く中枢部へ踏み込むと、',
            '祭壇'   => '血の匂いが漂う祭壇の前に立つと、',
            '外郭'   => '魔王領の荒れた外壁沿いを歩くと、',
            '街道'   => '見知らぬ街道をどこまでも歩いていると、',
        ];

        return $phrases[$type] ?? '探索を続けていると、';
    }
}
