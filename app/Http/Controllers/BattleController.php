<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\ArenaLog;
use App\Models\ArenaNpcLog;
use App\Models\ArenaNpcRanking;
use App\Models\ArenaRanking;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\Enemy;
use App\Services\ExplorationService;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\ExplorationItemService;
use App\Services\CityThemeService;
use App\Services\StorageCapacityService;
use App\Services\ExplorationDepthService;
use App\Services\ExplorationStateService;
use App\Services\ArenaNpcBattleService;
use App\Services\ArenaNpcRankingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BattleController extends Controller
{
    private const EXPLORE_REQUEST_DELAY_SECONDS = 2;

    protected ExplorationService $explorationService;
    protected CharacterStatusService $statusService;
    protected \App\Services\AreaService $areaService;

    public function __construct(ExplorationService $explorationService, CharacterStatusService $statusService, \App\Services\AreaService $areaService)
    {
        $this->explorationService = $explorationService;
        $this->statusService = $statusService;
        $this->areaService = $areaService;
    }

    /**
     * 通常探索
     */
    public function exploreGetFallback(Request $request, int $areaId)
    {
        session(['current_location' => 'dungeon']);

        return redirect()
            ->route('home')
            ->with('error', '探索の再開に失敗しました。お手数ですが、探索ボタンからもう一度実行してください。');
    }

    public function resumeExploration(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || !app(ExplorationStateService::class)->hasActiveExploration($character)) {
            session(['current_location' => 'home']);
            return redirect()->route('home')->with('message', '進行中の探索はありません。');
        }

        session(['current_location' => 'dungeon']);
        $lootSummary = app(ExplorationStateService::class)->currentLootSummary($character, (int) $state->area_id);

        return response()->view('battle.resume', [
            'character' => $character,
            'state' => $state,
            'area' => Area::find((int) $state->area_id),
            'lootSummary' => $lootSummary,
            'hasActiveValmonEgg' => $this->hasActiveValmonEgg($character),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function explore(Request $request, int $areaId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        if (!$request->boolean('continue_chain') && $redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        if (!$this->areaService->canEnterArea($character, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        if ($request->boolean('continue_chain')) {
            $explorationStateService = app(\App\Services\ExplorationStateService::class);
            $activeState = $explorationStateService->currentFor($character);
            $hasActive = $explorationStateService->hasActiveExploration($character);
            if ($hasActive && (int) $activeState->area_id !== $areaId) {
                // ブラウザバック等で古い画面から別エリア宛にPOSTされたケース。
                // 現在の探索状態を書き換えず、正規の復帰画面へ誘導する。
                session(['current_location' => 'dungeon']);

                return redirect()->route('battle.resume')
                    ->with('message', '別の探索が進行中です。こちらの画面から再開してください。');
            }
        }

        // 深度入口を選んだ直後の継続は、同じ操作内で直前の探索リクエストを完了済みとして扱う。
        // Request attributes are server-side only, so a client cannot forge this bypass.
        $skipRequestDelay = (bool) $request->attributes->get('skip_explore_request_delay', false);
        if (!$skipRequestDelay && !$this->acquireExploreRequestDelay($character)) {
            return $this->redirectExploreRequestBusy($request, $character, $areaId);
        }

        $targetDepth = (string) $request->input('depth_target', '');
        if (!$request->boolean('continue_chain')) {
            if ($targetDepth !== '') {
                $depthStartRedirect = $this->startRecordedDepthExploration($character, $areaId, $targetDepth);
                if ($depthStartRedirect) {
                    return $depthStartRedirect;
                }
            } else {
                app(\App\Services\ExplorationStateService::class)->reset($character, $areaId);
            }
        } elseif ($depthGateRedirect = $this->redirectToDepthGateIfNeeded($request, $character, $areaId)) {
            return $depthGateRedirect;
        }

        $skipBattleCooldown = $this->acknowledgeDepthGateIfConfirmed($request, $character, $areaId);
        if (!$skipBattleCooldown && $request->boolean('continue_chain')) {
            $skipBattleCooldown = $this->consumeDepthGateCooldownBypass($character, $areaId);
        }

        $forcedEvent = $request->boolean('challenge_dungeon_lord') ? 'dungeon_lord' : null;
        $batchCount = (int) $request->input('batch_count', 1);
        $canBatchExplore = $batchCount > 1
            && $targetDepth === ''
            && $forcedEvent === null
            && app(\App\Services\ExplorationStaminaService::class)->enabled();

        if ($canBatchExplore) {
            $result = $this->explorationService->exploreRepeated(
                $character,
                $areaId,
                min(10, $batchCount)
            );
        } else {
            $result = $this->explorationService->explore($character, $areaId, false, $forcedEvent, $skipBattleCooldown);
        }

        $result = $this->replaceWithDepthGateResultIfCrossed($request, $character, $areaId, $result);

        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
        $jobLevel = $jobHistory ? $jobHistory->job_level : 1;

        $battleData = [
            'result' => $result,
            'areaId' => $areaId,
            'isBoss' => false,
            'jobLevel' => $jobLevel,
        ];

        return redirect()->route('battle.result')->with('battleData', $battleData);
    }

    public function travelDiscoveredArea(Request $request, int $areaId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $battleData = session('lastBattleData') ?? session('battleData');
        if (!$this->battleResultDiscoveredArea($battleData, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        if (!$this->areaService->canEnterArea($character, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        $this->rollbackSourceDevelopmentForDiscoveryTravel($character, $battleData);
        session()->forget(['lastBattleData', 'battleData']);

        $request->merge(['continue_chain' => false]);

        return $this->explore($request, $areaId);
    }

    private function battleResultDiscoveredArea(mixed $battleData, int $areaId): bool
    {
        $discoveries = data_get($battleData, 'result.new_discoveries', []);
        if (!is_array($discoveries)) {
            return false;
        }

        foreach ($discoveries as $discovery) {
            if (($discovery['type'] ?? null) === 'area' && (int) ($discovery['id'] ?? 0) === $areaId) {
                return true;
            }
        }

        return false;
    }

    private function rollbackSourceDevelopmentForDiscoveryTravel($character, mixed $battleData): void
    {
        $development = data_get($battleData, 'result.development');
        if (!is_array($development)) {
            return;
        }

        $areaId = (int) ($development['area_id'] ?? 0);
        $before = (int) ($development['before'] ?? 0);
        $after = (int) ($development['after'] ?? 0);
        $gained = max(0, (int) ($development['gained'] ?? ($after - $before)));
        if ($areaId <= 0 || $gained <= 0 || $after <= $before) {
            return;
        }

        $progress = CharacterAreaProgress::where('character_id', $character->id)
            ->where('area_id', $areaId)
            ->first();
        if (!$progress) {
            return;
        }

        $current = (int) ($progress->development_point ?? 0);
        if ($current < $after) {
            return;
        }

        $progress->development_point = max(0, $current - $gained);
        $progress->save();
    }

    private function replaceWithDepthGateResultIfCrossed(Request $request, $character, int $areaId, array $result): array
    {
        if (($result['result'] ?? null) !== 'victory') {
            return $result;
        }

        $specialEvent = $result['special_event'] ?? null;
        if ($specialEvent !== null && $specialEvent !== 'golden_goblin') {
            return $result;
        }

        $transitions = collect($result['exploration_progress']['depth_transitions'] ?? [])
            ->filter(fn (array $tier): bool => in_array((string) ($tier['key'] ?? ''), ['inner', 'deep', 'deepest', 'otherworld'], true))
            ->values();

        if ($transitions->isEmpty()) {
            return $result;
        }

        $gate = $this->currentDepthGate($character, Area::findOrFail($areaId));
        if (!$gate) {
            return $result;
        }

        if ($request->input('depth_confirmed') === $gate['key']) {
            return $result;
        }

        $enemy = (object) [
            'name' => $gate['label'] . '入口',
            'role' => '探索深度',
            'type_name' => '入口',
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
        ];

        return array_merge($result, [
            'result' => 'event',
            'enemy' => $enemy,
            'log' => '',
            'logs' => [],
            'exp_gained' => 0,
            'gold_gained' => 0,
            'job_exp_gained' => 0,
            'special_event' => 'depth_gate',
            'depth_gate' => $gate,
            'new_discoveries' => [],
        ]);
    }

    public function recordDepthEntrance(Request $request, int $areaId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        if (!$this->areaService->canEnterArea($character, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        $area = Area::findOrFail($areaId);
        $gate = $this->currentDepthGate($character, $area);
        if (!$gate) {
            return redirect()->route('home')->with('status', '記録できる入口は見つかりませんでした。');
        }

        if (!in_array((string) ($gate['key'] ?? ''), ['deepest', 'otherworld'], true)) {
            return $this->continueAfterDepthGate(
                $request,
                $character,
                $area,
                "{$gate['label']}の入口は地図に記録せず、現在の探索を続けます。"
            );
        }

        $this->recordDepthGateDiscovery($character, $area, $gate);

        return $this->continueAfterDepthGate(
            $request,
            $character,
            $area,
            "{$area->name}・{$gate['label']}の入口を地図に記録しました。後で挑戦できるようにして、現在の探索を続けます。"
        );
    }

    public function retreatDepthEntrance(Request $request, int $areaId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        if (!$this->areaService->canEnterArea($character, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        $area = Area::findOrFail($areaId);

        return $this->continueAfterDepthGate(
            $request,
            $character,
            $area,
            '危険な入口から引き返し、現在のエリア探索を続けます。'
        );
    }

    public function confirmSubArea(CharacterSubAreaRouteDiscovery $discovery)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || (int) $discovery->character_id !== (int) $character->id) {
            return redirect()->route('home')->with('error', 'この入口は利用できません。');
        }

        $discovery->loadMissing('route.subArea', 'route.sourceArea');
        if (!$discovery->route?->subArea || !$discovery->route?->is_enabled || !$discovery->route?->subArea?->is_enabled) {
            return redirect()->route('home')->with('error', 'この入口は現在利用できません。');
        }

        return view('battle.sub-area-confirm', [
            'character' => $character,
            'discovery' => $discovery,
            'route' => $discovery->route,
            'subArea' => $discovery->route->subArea,
            'sourceArea' => $discovery->route->sourceArea,
        ]);
    }

    public function exploreSubArea(Request $request, CharacterSubAreaRouteDiscovery $discovery)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || (int) $discovery->character_id !== (int) $character->id) {
            return redirect()->route('home')->with('error', 'この入口は利用できません。');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        $discovery->loadMissing('route.subArea', 'route.sourceArea');
        if (!$this->acquireExploreRequestDelay($character)) {
            return $this->redirectExploreRequestBusy($request, $character, (int) ($discovery->route?->source_area_id ?? 0));
        }

        $result = app(\App\Services\SubAreaExplorationService::class)->explore($character, $discovery);
        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
        $jobLevel = $jobHistory ? $jobHistory->job_level : 1;

        return redirect()->route('battle.result')->with('battleData', [
            'result' => $result,
            'areaId' => (int) ($discovery->route?->source_area_id ?? 0),
            'isBoss' => false,
            'jobLevel' => $jobLevel,
        ]);
    }

    /**
     * ボス戦
     */
    public function boss(Request $request, int $areaId)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        if (!$this->areaService->canEnterArea($character, $areaId)) {
            return redirect()->route('home')->with('error', 'このエリアにはまだ入れません。');
        }

        $area = Area::findOrFail($areaId);
        $ferdiaMap = app(\App\Services\FerdiaMapService::class);
        if ($ferdiaMap->hasBossForArea($area) && !$ferdiaMap->canChallengeBoss($character, $area)) {
            return redirect()->route('home')->with('error', '開拓度を100まで進めると、この地の関門ボスに挑めます。');
        }

        if (!Enemy::where('area_id', $areaId)->where('is_boss', true)->exists()) {
            return redirect()->route('home')->with('error', 'この場所には討伐対象のボスがいません。探索で道を開拓しましょう。');
        }

        $progress = $character->areaProgresses()->where('area_id', $areaId)->first();
        if ($progress && $progress->boss_defeated) {
            return redirect()->route('home')->with('error', 'このエリアのボスはすでに討伐済みです。');
        }

        if (!$this->acquireExploreRequestDelay($character)) {
            return $this->redirectExploreRequestBusy($request, $character, $areaId, true);
        }

        $result = $this->explorationService->explore($character, $areaId, true);
        if (isset($result['error'])) {
            session(['current_location' => 'dungeon']);

            return redirect()->route('home')->with('error', $result['error']);
        }

        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
        $jobLevel = $jobHistory ? $jobHistory->job_level : 1;

        $battleData = [
            'result' => $result,
            'areaId' => $areaId,
            'isBoss' => true,
            'jobLevel' => $jobLevel,
        ];

        return redirect()->route('battle.result')->with('battleData', $battleData);
    }

    public function returnToTown(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if ($character) {
            $hatchedValmons = app(\App\Services\ValmonService::class)->hatchActiveEggs($character);
            $explorationStateService = app(\App\Services\ExplorationStateService::class);
            $explorationStateService->reset($character);
        }

        session()->forget('lastBattleData');
        session(['current_location' => 'dungeon']);

        $redirect = redirect()->route('home', ['skip_resume' => 1]);
        if (!empty($hatchedValmons ?? [])) {
            $messages = ['卵が淡く光りはじめた……'];
            foreach ($hatchedValmons as $hatched) {
                $prefix = in_array($hatched['rarity'] ?? 'normal', ['rare', 'super_rare'], true)
                    ? '卵が強く輝いた……'
                    : null;
                if ($prefix) {
                    $messages[] = $prefix;
                }
                $messages[] = "{$hatched['name']}が生まれた！";
                if ($hatched['already_had'] ?? false) {
                    $messages[] = 'すでに仲間にしたことのあるヴァルモンです。';
                } else {
                    $messages[] = '新しいヴァルモンが仲間になった！';
                }
            }
            $redirect->with('message', implode('<br>', $messages));
        }

        return $redirect;
    }

    public function abandonInterruptedExploration(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if ($character) {
            app(\App\Services\ValmonService::class)->hatchActiveEggs($character);
            app(ExplorationStateService::class)->reset($character);
        }

        session()->forget('lastBattleData');
        session(['current_location' => 'dungeon']);

        return redirect()
            ->route('home', ['skip_resume' => 1])
            ->with('message', '探索を切り上げて街へ帰還しました。獲得済みの戦利品は所持品に残ります。');
    }

    private function redirectToDepthGateIfNeeded(Request $request, $character, int $areaId)
    {
        $area = Area::findOrFail($areaId);
        $gate = $this->currentDepthGate($character, $area);
        if (!$gate) {
            return null;
        }

        if ($request->input('depth_confirmed') === $gate['key']) {
            return null;
        }

        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
        $jobLevel = $jobHistory ? $jobHistory->job_level : 1;
        $enemy = (object) [
            'name' => $gate['label'] . '入口',
            'role' => '探索深度',
            'type_name' => '入口',
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
        ];

        return redirect()->route('battle.result')->with('battleData', [
            'result' => [
                'result' => 'event',
                'enemy' => $enemy,
                'log' => '',
                'logs' => [],
                'exp_gained' => 0,
                'gold_gained' => 0,
                'job_exp_gained' => 0,
                'special_event' => 'depth_gate',
                'depth_gate' => $gate,
            ],
            'areaId' => $areaId,
            'isBoss' => false,
            'jobLevel' => $jobLevel,
        ]);
    }

    private function startRecordedDepthExploration($character, int $areaId, string $depthKey)
    {
        if (!in_array($depthKey, ['deepest', 'otherworld'], true)) {
            return redirect()->route('home')->with('error', 'この入口からは探索を開始できません。');
        }

        $discovered = DB::table('character_depth_gate_discoveries')
            ->where('character_id', (int) $character->id)
            ->where('area_id', $areaId)
            ->where('depth_key', $depthKey)
            ->exists();

        if (!$discovered) {
            return redirect()->route('home')->with('error', 'まだ地図に記録していない入口です。');
        }

        $state = app(ExplorationStateService::class)->startAtDepth($character, $areaId, $depthKey);
        if (!$state) {
            return redirect()->route('home')->with('error', 'この入口からは探索を開始できません。');
        }

        app(ExplorationDepthService::class)->markEntered($character, $areaId, $depthKey);
        app(ExplorationDepthService::class)->markGateHandled($character, $areaId, $depthKey);

        return null;
    }

    private function acknowledgeDepthGateIfConfirmed(Request $request, $character, int $areaId): bool
    {
        $confirmed = (string) $request->input('depth_confirmed', '');
        if ($confirmed === '') {
            return false;
        }

        $area = Area::find($areaId);
        $gate = $area ? $this->confirmedDepthGate($character, $area, $confirmed) : null;
        if (!$gate) {
            return false;
        }

        if (in_array($confirmed, ['deepest', 'otherworld'], true)) {
            $this->recordDepthGateDiscovery($character, $area, $gate);
        }

        app(ExplorationDepthService::class)->markGateHandled($character, $areaId, $confirmed);
        app(ExplorationDepthService::class)->markEntered($character, $areaId, $confirmed);

        return true;
    }

    private function confirmedDepthGate($character, Area $area, string $confirmed): ?array
    {
        if (!in_array($confirmed, ['inner', 'deep', 'deepest', 'otherworld'], true)) {
            return null;
        }

        $gate = $this->currentDepthGate($character, $area);
        if ($gate && (string) ($gate['key'] ?? '') === $confirmed) {
            return $gate;
        }

        $battleData = session('lastBattleData') ?? session('battleData');
        if ((int) data_get($battleData, 'areaId', 0) !== (int) $area->id) {
            return null;
        }

        $result = data_get($battleData, 'result', []);
        $previousGate = data_get($result, 'depth_gate');
        $previousGateKey = is_array($previousGate) ? (string) ($previousGate['key'] ?? '') : '';
        $hasTransition = collect(data_get($result, 'exploration_progress.depth_transitions', []))
            ->contains(fn ($tier): bool => is_array($tier) && (string) ($tier['key'] ?? '') === $confirmed);

        if ($previousGateKey !== $confirmed && !$hasTransition) {
            return null;
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        $tier = app(ExplorationDepthService::class)->tierByKey($confirmed);
        if (!$state || !$tier || (int) $state->area_id !== (int) $area->id) {
            return null;
        }

        if ((int) ($state->exploration_point ?? 0) < (int) ($tier['min_point'] ?? 0)) {
            return null;
        }

        if (is_array($previousGate) && $previousGateKey === $confirmed) {
            return $previousGate;
        }

        return [
            'key' => $confirmed,
            'label' => (string) ($tier['label'] ?? '深部'),
            'area_name' => $area->name,
        ];
    }

    private function continueAfterDepthGate(Request $request, $character, Area $area, string $statusMessage)
    {
        $areaId = (int) $area->id;
        $gate = $this->currentDepthGate($character, $area);
        $depthKey = (string) ($gate['key'] ?? '');
        if ($depthKey !== '') {
            app(ExplorationDepthService::class)->markGateHandled($character, $areaId, $depthKey);
            $this->armDepthGateCooldownBypass($character, $areaId);
        }

        $request->merge(['continue_chain' => true]);
        $request->attributes->set('skip_explore_request_delay', true);

        return $this->explore($request, $areaId)->with('status', $statusMessage);
    }

    private function recordDepthGateDiscovery($character, Area $area, array $gate): void
    {
        $now = now();
        $key = [
            'character_id' => (int) $character->id,
            'area_id' => (int) $area->id,
            'depth_key' => (string) ($gate['key'] ?? ''),
        ];

        if ($key['depth_key'] === '') {
            return;
        }

        $existing = DB::table('character_depth_gate_discoveries')->where($key)->first();
        if ($existing) {
            DB::table('character_depth_gate_discoveries')->where('id', $existing->id)->update([
                'depth_label' => (string) ($gate['label'] ?? ''),
                'last_recorded_at' => $now,
                'times_recorded' => ((int) ($existing->times_recorded ?? 0)) + 1,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('character_depth_gate_discoveries')->insert($key + [
            'depth_label' => (string) ($gate['label'] ?? ''),
            'discovered_at' => $now,
            'last_recorded_at' => $now,
            'times_recorded' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function armDepthGateCooldownBypass($character, int $areaId): void
    {
        session([$this->depthGateCooldownBypassKey($character, $areaId) => true]);
    }

    private function consumeDepthGateCooldownBypass($character, int $areaId): bool
    {
        $key = $this->depthGateCooldownBypassKey($character, $areaId);
        $enabled = (bool) session()->pull($key, false);

        return $enabled;
    }

    private function depthGateCooldownBypassKey($character, int $areaId): string
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        $startedAt = $state?->started_at ? $state->started_at->timestamp : 0;

        return "depth_gate_cooldown_bypass:{$character->id}:{$areaId}:{$startedAt}";
    }

    private function acquireExploreRequestDelay(Character $character): bool
    {
        return Cache::add(
            "explore_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::EXPLORE_REQUEST_DELAY_SECONDS)
        );
    }

    private function redirectExploreRequestBusy(Request $request, Character $character, int $areaId, bool $isBoss = false)
    {
        if ($request->ajax()) {
            return response('探索処理中です。少し待ってからもう一度お試しください。', 409)
                ->header('X-Explore-Busy', '1')
                ->header('Retry-After', (string) self::EXPLORE_REQUEST_DELAY_SECONDS);
        }

        if (app(ExplorationStateService::class)->hasActiveExploration($character)) {
            session(['current_location' => 'dungeon']);

            return redirect()->route('battle.resume')
                ->with('message', '探索処理が混み合っています。少し待ってから「探索を続ける」を押してください。');
        }

        return redirect()->route('battle.result')->with('battleData', [
            'result' => [
                'error' => '探索処理中です。少し待ってからもう一度お試しください。',
            ],
            'areaId' => $areaId,
            'isBoss' => $isBoss,
            'jobLevel' => $this->currentJobLevel($character),
        ]);
    }

    private function currentJobLevel(Character $character): int
    {
        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();

        return $jobHistory ? (int) $jobHistory->job_level : 1;
    }

    private function currentDepthGate($character, Area $area): ?array
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || (int) $state->area_id !== (int) $area->id) {
            return null;
        }

        $depthService = app(ExplorationDepthService::class);
        $current = $depthService->currentGateFor(
            $character,
            $area,
            (int) $state->exploration_point,
            (int) ($state->danger_rate ?? 0)
        );
        $key = $current['key'] ?? 'surface';
        if (!in_array($key, ['inner', 'deep', 'deepest', 'otherworld'], true)) {
            return null;
        }

        $label = $current['label'] ?? '深部';
        $entranceText = match ($key) {
            'inner' => "{$area->name}の奥で、薄暗い下り道を見つけた。",
            'deep' => "{$area->name}の奥で、さらに深く続く裂け目を見つけた。",
            'deepest' => "{$area->name}の奥で、地下へ続く古い石階段を見つけた。",
            'otherworld' => "{$area->name}の奥で、空間が歪む裂け目を見つけた。",
            default => "{$area->name}の奥で、見慣れない入口を見つけた。",
        };
        $riskText = match ($key) {
            'inner' => 'この先は敵が強くなります。準備が不十分なら引き返してください。',
            'deep' => 'この先は通常よりかなり強い敵が出現します。',
            'deepest' => 'これ以上進むのは極めて危険です。',
            'otherworld' => 'この先は現実の理から外れています。生還できる保証はありません。',
            default => 'この先は危険です。',
        };
        $recommended = $depthService->recommendedLevelRangeForTier($area, $current);
        $powerService = app(CharacterPowerService::class);
        $recommendedPower = $powerService->openingRecommendedRangeForLevels(
            (int) ($recommended['min'] ?? 1),
            (int) ($recommended['max'] ?? $recommended['min'] ?? 1)
        );
        $currentPower = $powerService->fromFinalStats($this->statusService->getFinalStats($character));

        return [
            'key' => $key,
            'label' => $label,
            'area_name' => $area->name,
            'entrance_text' => $entranceText,
            'risk_text' => $riskText,
            'recommended_level_min' => (int) ($recommended['min'] ?? 0),
            'recommended_level_max' => (int) ($recommended['max'] ?? 0),
            'current_level' => (int) ($character->level ?? 1),
            'recommended_power_min' => (int) ($recommendedPower['min'] ?? 0),
            'recommended_power_max' => (int) ($recommendedPower['max'] ?? 0),
            'current_power' => $currentPower,
        ];
    }

    /**
     * ランダムな相手に闘技場（PvP）戦を挑む
     */
    public function randomPvp(Request $request, \App\Services\PvPBattleService $pvpService, ArenaNpcBattleService $npcBattleService)
    {
        $attacker = Auth::user()->currentCharacter();
        if (!$attacker) {
            return redirect()->route('home');
        }

        if ($redirect = $this->redirectIfStorageFull($attacker)) {
            return $redirect;
        }

        // 闘技場ランキングに参加しているか
        $myRanking = ArenaRanking::where('character_id', $attacker->id)->first();
        if (!$myRanking) {
            return $this->redirectToColosseum()->with('error', '闘技場ランキングに参加していません。');
        }

        if ($myRanking->rank === 1) {
            return $this->redirectToColosseum()->with('error', 'あなたは現在1位です。これ以上挑める上位プレイヤーはいません。');
        }

        $cooldownRemaining = $this->arenaRankBattleCooldownRemaining($attacker->id);
        if ($cooldownRemaining > 0) {
            return $this->redirectToColosseum()
                ->with('error', "ランク戦は連続で挑戦できません。あと{$cooldownRemaining}秒お待ちください。");
        }

        $targetRankings = app(ArenaNpcRankingService::class)->targetEntries($myRanking, 3);

        if ($targetRankings->isEmpty()) {
            return $this->redirectToColosseum()->with('error', '挑める相手が見つかりません。');
        }

        $targetEntry = $targetRankings->random();

        if (($targetEntry['type'] ?? null) === 'npc') {
            $npcRanking = ArenaNpcRanking::with('npc')->findOrFail((int) $targetEntry['id']);
            $attacker->load('arenaRanking');
            $result = $npcBattleService->executeBattle($attacker, $npcRanking);

            session(['current_location' => 'colosseum']);

            $npcLog = ArenaNpcLog::where('attacker_id', $attacker->id)
                ->where('arena_npc_ranking_id', $npcRanking->id)
                ->orderBy('id', 'desc')
                ->first();

            $battleData = [
                'result' => [
                    'result' => $result->result,
                    'logs' => $result->logs,
                ],
                'attacker' => $attacker,
                'defender' => [
                    'name' => $npcRanking->npc?->npc_name ?? '放浪冒険者',
                    'job' => $npcRanking->npc?->npc_title ?? '放浪冒険者',
                    'level' => '???',
                    'image_path' => $npcRanking->npc?->image_path,
                    'is_npc' => true,
                ],
                'arenaLog' => $npcLog,
                'attackerStats' => $this->statusService->getFinalStats($attacker),
                'defenderStats' => [
                    'max_hp' => '???',
                ],
                'isNpcBattle' => true,
            ];

            return redirect()->route('battle.pvp_result')->with('battleData', $battleData);
        }

        $targetRanking = ArenaRanking::with('character')->findOrFail((int) $targetEntry['id']);
        $defender = $targetRanking->character;

        // PvPバトルの実行
        $result = $pvpService->executeBattle($attacker, $defender);

        // 戻ったときに闘技場タブを維持する
        session(['current_location' => 'colosseum']);

        // バトル結果と必要な情報を View に渡すためセッションに保存
        $battleData = [
            'result' => [
                'result' => $result->result,
                'logs' => $result->logs,
            ],
            'attacker' => $attacker,
            'defender' => $defender,
            // 順位変動ログを取得（先ほど登録された最新のもの）
            'arenaLog' => ArenaLog::where('attacker_id', $attacker->id)
                ->where('defender_id', $defender->id)
                ->orderBy('id', 'desc')
                ->first(),
            'attackerStats' => $this->statusService->getFinalStats($attacker),
            'defenderStats' => $this->statusService->getFinalStats($defender),
        ];

        return redirect()->route('battle.pvp_result')->with('battleData', $battleData);
    }

    private function arenaRankBattleCooldownRemaining(int $characterId): int
    {
        $latestAttack = ArenaLog::where('attacker_id', $characterId)
            ->latest('created_at')
            ->first();
        $latestNpcAttack = Schema::hasTable('arena_npc_logs')
            ? ArenaNpcLog::where('attacker_id', $characterId)
                ->latest('created_at')
                ->first()
            : null;

        if ($latestNpcAttack && (!$latestAttack || $latestNpcAttack->created_at->gt($latestAttack->created_at))) {
            $latestAttack = $latestNpcAttack;
        }

        if (!$latestAttack?->created_at) {
            return 0;
        }

        $cooldownSeconds = app(\App\Services\CooldownSettingService::class)->arenaRankBattleSeconds();
        if ($cooldownSeconds <= 0) {
            return 0;
        }

        $availableAt = $latestAttack->created_at->copy()->addSeconds($cooldownSeconds);
        if (now()->gte($availableAt)) {
            return 0;
        }

        return max(0, $availableAt->getTimestamp() - now()->getTimestamp());
    }

    private function redirectToColosseum()
    {
        session(['current_location' => 'colosseum']);

        return redirect()->route('home');
    }

    /**
     * 闘技場（PvP）戦
     */
    public function pvp(Request $request, int $targetCharacterId, \App\Services\PvPBattleService $pvpService)
    {
        $attacker = Auth::user()->currentCharacter();
        if (!$attacker) {
            return redirect()->route('home');
        }

        if ($redirect = $this->redirectIfStorageFull($attacker)) {
            return $redirect;
        }

        $defender = \App\Models\Character::findOrFail($targetCharacterId);
        
        // 自分自身とは戦えない
        if ($attacker->id === $defender->id) {
            return redirect()->route('home')->with('error', '自分自身とは戦えません。');
        }

        // PvPバトルの実行
        $result = $pvpService->executeBattle($attacker, $defender);

        // 戻ったときに闘技場タブを維持する
        session(['current_location' => 'colosseum']);

        // バトル結果と必要な情報を View に渡すためセッションに保存
        $battleData = [
            'result' => [
                'result' => $result->result,
                'logs' => $result->logs,
            ],
            'attacker' => $attacker,
            'defender' => $defender,
            // 順位変動ログを取得（先ほど登録された最新のもの）
            'arenaLog' => \App\Models\ArenaLog::where('attacker_id', $attacker->id)
                ->where('defender_id', $defender->id)
                ->orderBy('id', 'desc')
                ->first(),
            'attackerStats' => $this->statusService->getFinalStats($attacker),
            'defenderStats' => $this->statusService->getFinalStats($defender),
        ];

        return redirect()->route('battle.pvp_result')->with('battleData', $battleData);
    }

    /**
     * 通常・ボス戦結果の表示 (PRGパターン用)
     */
    public function showResult(Request $request)
    {
        $battleData = session('battleData') ?? session('lastBattleData');

        if (!$battleData) {
            return redirect()->route('home');
        }

        session(['lastBattleData' => $battleData]);

        // セッションから復元した際に配列化されている場合の対策
        // ログイン中のキャラクターを再取得
        $battleData['character'] = Auth::user()->currentCharacter();
        \App\Livewire\MainScreen::clearHomeCache($battleData['character']->id);
        $battleData['hasActiveValmonEgg'] = $this->hasActiveValmonEgg($battleData['character']);
        $battleData['finalStats'] = $this->statusService->getFinalStats($battleData['character']);
        if (!($battleData['isBoss'] ?? false)) {
            $battleData['recoveryItems'] = app(ExplorationItemService::class)->carriedItems($battleData['character']);
            $battleData['supportItemCounts'] = app(\App\Services\AdventureSupportService::class)->countsFor($battleData['character']);
        }
        if (!($battleData['isBoss'] ?? false) && isset($battleData['result']['exploration_stamina'])) {
            $battleData['result']['exploration_stamina'] = app(\App\Services\ExplorationStaminaService::class)
                ->summary($battleData['character']);
            session(['lastBattleData' => $battleData]);
        }

        // 敵情報が配列ならオブジェクトにキャスト
        if (isset($battleData['result']['enemy']) && is_array($battleData['result']['enemy'])) {
            $battleData['result']['enemy'] = (object) $battleData['result']['enemy'];
        }

        // アンロックされたエリアが配列ならオブジェクトにキャスト
        if (isset($battleData['result']['unlocked_areas']) && is_array($battleData['result']['unlocked_areas'])) {
            foreach ($battleData['result']['unlocked_areas'] as $key => $area) {
                if (is_array($area)) {
                    $battleData['result']['unlocked_areas'][$key] = (object) $area;
                }
            }
        }

        $areaId = (int) ($battleData['areaId'] ?? 0);
        $battleDepthKey = $this->battleDepthKey($battleData['character'], $areaId);
        $battleData['areaName'] = Area::find($areaId)?->name;
        $battleData['battleHeaderIconImage'] = $this->battleHeaderIconImage($areaId);
        $battleData['battleCityBackgroundStyle'] = in_array($battleDepthKey, ['deep', 'deepest', 'otherworld'], true)
            ? $this->depthBattleBackgroundStyle($battleDepthKey)
            : ((($battleData['result']['special_event'] ?? null) === 'secret_realm_lord')
                ? 'background-color: #d9b72f;'
                : $this->battleCityBackgroundStyle($areaId));
        $battleData = array_merge($battleData, $this->depthBattleHeaderTheme($battleDepthKey));
        $battleData['discoveryAreaCardBackgrounds'] = $this->discoveryAreaCardBackgrounds($battleData['result']['new_discoveries'] ?? []);

        return response()->view('battle.result', $battleData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function hasActiveValmonEgg(Character $character): bool
    {
        return $character->valmonEggs()
            ->where('is_hatched', false)
            ->where('is_lost', false)
            ->exists();
    }

    private function battleDepthKey(Character $character, int $areaId): string
    {
        $area = Area::find($areaId);
        if (!$area) {
            return 'surface';
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || (int) $state->area_id !== $areaId) {
            return 'surface';
        }

        $tier = app(ExplorationDepthService::class)->activeTierFor(
            $character,
            $area,
            (int) ($state->exploration_point ?? 0),
            (int) ($state->danger_rate ?? 0)
        );

        return (string) ($tier['key'] ?? 'surface');
    }

    private function depthBattleBackgroundStyle(string $depthKey): string
    {
        $layers = match ($depthKey) {
            'deep' => [
                'background-color: #080d24;',
                'radial-gradient(circle at 16% 18%, rgba(59, 130, 246, 0.24), transparent 32%),',
                'radial-gradient(circle at 84% 74%, rgba(99, 102, 241, 0.20), transparent 34%),',
                'linear-gradient(135deg, #050816 0%, #0d1b3d 46%, #241452 76%, #070913 100%);',
            ],
            'deepest' => [
                'background-color: #070103;',
                'radial-gradient(circle at 18% 18%, rgba(127, 29, 29, 0.30), transparent 32%),',
                'radial-gradient(circle at 82% 72%, rgba(88, 28, 135, 0.26), transparent 35%),',
                'linear-gradient(135deg, #030102 0%, #17030a 42%, #28104a 72%, #050005 100%);',
            ],
            default => [
                'background-color: #05010f;',
                'radial-gradient(circle at 18% 18%, rgba(124, 58, 237, 0.36), transparent 30%),',
                'radial-gradient(circle at 82% 72%, rgba(190, 24, 93, 0.24), transparent 34%),',
                'linear-gradient(135deg, #020106 0%, #10051f 42%, #241044 72%, #06010d 100%);',
            ],
        };

        return implode(' ', [
            $layers[0],
            'background-image:',
            $layers[1],
            $layers[2],
            $layers[3],
            'background-attachment: fixed;',
        ]);
    }

    private function depthBattleHeaderTheme(string $depthKey): array
    {
        if (!in_array($depthKey, ['deep', 'deepest', 'otherworld'], true)) {
            return [];
        }

        $style = $this->depthBattleBackgroundStyle($depthKey);

        return [
            'battleHeaderShellStyle' => $style,
            'battleHeaderOverlayClass' => match ($depthKey) {
                'deep' => 'bg-[#07152e]/82',
                'deepest' => 'bg-[#120106]/86',
                default => 'bg-[#070211]/86',
            },
            'battleHeaderTitleClass' => match ($depthKey) {
                'deep' => 'text-cyan-100',
                'deepest' => 'text-rose-100',
                default => 'text-fuchsia-100',
            },
            'battleHeaderBorderClass' => match ($depthKey) {
                'deep' => 'border-blue-400',
                'deepest' => 'border-rose-600',
                default => 'border-fuchsia-500',
            },
        ];
    }

    private function battleCityBackgroundStyle(int $areaId): string
    {
        $cityId = Area::whereKey($areaId)->value('city_id');
        $color = app(CityThemeService::class)->backgroundColorForCityId($cityId ? (int) $cityId : null);

        return "background-color: {$color};";
    }

    private function discoveryAreaCardBackgrounds(array $discoveries): array
    {
        $backgrounds = [];
        foreach ($discoveries as $discovery) {
            if (($discovery['type'] ?? null) !== 'area' || empty($discovery['id'])) {
                continue;
            }

            $background = $this->dungeonCardBackgroundImage((int) $discovery['id']);
            if ($background) {
                $backgrounds[(int) $discovery['id']] = $background;
            }
        }

        return $backgrounds;
    }

    private function dungeonCardBackgroundImage(int $areaId): ?string
    {
        $area = Area::find($areaId);
        if (!$area) {
            return null;
        }

        $ferdiaPath = $this->ferdiaDungeonVisualPath($area, 'card_bg');
        if ($ferdiaPath) {
            return 'images/' . $ferdiaPath;
        }

        $order = (bool) ($area->is_route_area ?? false)
            ? 10
            : $this->normalDungeonImageOrder($area);
        if ($order <= 0) {
            return null;
        }

        $relativePath = sprintf('images/card_bg/dungeon_%02d_%02d.webp', (int) $area->city_id, $order);

        return file_exists(public_path($relativePath)) ? $relativePath : null;
    }

    private function normalDungeonImageOrder(Area $area): int
    {
        $areaIds = Area::where('city_id', $area->city_id)
            ->where(function ($query) {
                $query->where('is_route_area', false)
                    ->orWhereNull('is_route_area');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->values();
        $areaIndex = $areaIds->search($area->id);

        return $areaIndex === false ? 0 : (int) $areaIndex + 1;
    }

    private function redirectIfStorageFull($character)
    {
        $storageCapacity = app(StorageCapacityService::class);
        if (!$storageCapacity->isFull($character)) {
            return null;
        }

        return redirect()
            ->route('home')
            ->with('message', $storageCapacity->fullMessageHtml($character));
    }

    private function battleHeaderIconImage(int $areaId): ?string
    {
        $area = Area::find($areaId);
        if (!$area) {
            return null;
        }

        $ferdiaPath = $this->ferdiaDungeonVisualPath($area, 'symbol');
        if ($ferdiaPath) {
            return 'images/' . $ferdiaPath;
        }

        $areaIds = Area::where('city_id', $area->city_id)
            ->orderBy('id')
            ->pluck('id')
            ->values();
        $areaIndex = $areaIds->search($area->id);
        if ($areaIndex === false) {
            return null;
        }

        $relativePath = sprintf('images/symbol/dungeon_%02d_%02d.webp', (int) $area->city_id, $areaIndex + 1);

        return file_exists(public_path($relativePath)) ? $relativePath : null;
    }

    private function ferdiaDungeonVisualPath(Area $area, string $directory): ?string
    {
        $mainAreaIds = collect(config('ferdia_world_map.nodes', []))
            ->filter(fn (array $node): bool => ($node['route_group'] ?? null) === 'main' && !empty($node['area_id']))
            ->sortBy('sequence')
            ->pluck('area_id')
            ->values();
        $index = $mainAreaIds->search((int) $area->id);
        if ($index === false) {
            return null;
        }

        $relativePath = sprintf('%s/dungeon_11_%02d.webp', $directory, $index + 1);

        return file_exists(public_path("images/{$relativePath}")) ? $relativePath : null;
    }

    /**
     * PvP戦結果の表示 (PRGパターン用)
     */
    public function showPvpResult(Request $request)
    {
        $battleData = session('battleData');

        if (!$battleData) {
            return redirect()->route('home');
        }

        // 配列化対策としてキャラクター情報を再取得
        if (is_array($battleData['attacker'])) {
            $battleData['attacker'] = \App\Models\Character::find($battleData['attacker']['id']);
        }
        if (is_array($battleData['defender']) && !($battleData['defender']['is_npc'] ?? false)) {
            $battleData['defender'] = \App\Models\Character::find($battleData['defender']['id']);
        }
        if (isset($battleData['arenaLog']) && is_array($battleData['arenaLog'])) {
            $battleData['arenaLog'] = (object) $battleData['arenaLog'];
        }

        return view('battle.pvp_result', $battleData);
    }
}
