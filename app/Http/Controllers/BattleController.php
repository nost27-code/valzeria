<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\ArenaLog;
use App\Models\ArenaRanking;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\Enemy;
use App\Services\ExplorationService;
use App\Services\CharacterStatusService;
use App\Services\ExplorationItemService;
use App\Services\CityThemeService;
use App\Services\StorageCapacityService;
use App\Services\ExplorationDepthService;
use App\Services\ExplorationStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BattleController extends Controller
{
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

        return view('battle.resume', [
            'character' => $character,
            'state' => $state,
            'area' => Area::find((int) $state->area_id),
            'lootSummary' => $lootSummary,
        ]);
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
        $result = $this->explorationService->explore($character, $areaId, false, $forcedEvent, $skipBattleCooldown);
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

        if (($result['special_event'] ?? null) !== null) {
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
                $character,
                $area,
                "{$gate['label']}の入口は地図に記録せず、現在の探索を続けます。"
            );
        }

        $this->recordDepthGateDiscovery($character, $area, $gate);

        return $this->continueAfterDepthGate(
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

        if (!Enemy::where('area_id', $areaId)->where('is_boss', true)->exists()) {
            return redirect()->route('home')->with('error', 'この場所には討伐対象のボスがいません。探索で道を開拓しましょう。');
        }

        $progress = $character->areaProgresses()->where('area_id', $areaId)->first();
        if ($progress && $progress->boss_defeated) {
            return redirect()->route('home')->with('error', 'このエリアのボスはすでに討伐済みです。');
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
        $gate = $area ? $this->currentDepthGate($character, $area) : null;
        if (!$gate || (string) ($gate['key'] ?? '') !== $confirmed) {
            return false;
        }

        if (in_array($confirmed, ['deepest', 'otherworld'], true)) {
            $this->recordDepthGateDiscovery($character, $area, $gate);
        }

        app(ExplorationDepthService::class)->markGateHandled($character, $areaId, $confirmed);
        app(ExplorationDepthService::class)->markEntered($character, $areaId, $confirmed);

        return true;
    }

    private function continueAfterDepthGate($character, Area $area, string $statusMessage)
    {
        $areaId = (int) $area->id;
        $gate = $this->currentDepthGate($character, $area);
        $depthKey = (string) ($gate['key'] ?? '');
        if ($depthKey !== '') {
            app(ExplorationDepthService::class)->markGateHandled($character, $areaId, $depthKey);
            $this->armDepthGateCooldownBypass($character, $areaId);
        }

        $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
        $jobLevel = $jobHistory ? $jobHistory->job_level : 1;
        $enemy = (object) [
            'name' => $area->name,
            'role' => '探索中',
            'type_name' => '探索中',
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
        ];

        return redirect()->route('battle.result')
            ->with('status', $statusMessage)
            ->with('battleData', [
                'result' => [
                    'result' => 'victory',
                    'enemy' => $enemy,
                    'log' => '',
                    'logs' => [],
                    'exp_gained' => 0,
                    'gold_gained' => 0,
                    'job_exp_gained' => 0,
                    'special_event' => 'depth_retreat',
                ],
                'areaId' => $areaId,
                'isBoss' => false,
                'jobLevel' => $jobLevel,
            ]);
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

        return [
            'key' => $key,
            'label' => $label,
            'area_name' => $area->name,
            'entrance_text' => $entranceText,
            'risk_text' => $riskText,
            'recommended_level_min' => (int) ($recommended['min'] ?? 0),
            'recommended_level_max' => (int) ($recommended['max'] ?? 0),
            'current_level' => (int) ($character->level ?? 1),
        ];
    }

    /**
     * ランダムな相手に闘技場（PvP）戦を挑む
     */
    public function randomPvp(Request $request, \App\Services\PvPBattleService $pvpService)
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

        // 挑める相手（自分より1〜3つ上の順位のプレイヤー）を取得
        $minRank = max(1, $myRanking->rank - 3);
        $maxRank = $myRanking->rank - 1;
        
        $targetRankings = ArenaRanking::with('character')
            ->whereBetween('rank', [$minRank, $maxRank])
            ->get();

        if ($targetRankings->isEmpty()) {
            return $this->redirectToColosseum()->with('error', '挑める相手が見つかりません。');
        }

        // ランダムに1人選ぶ
        $targetRanking = $targetRankings->random();
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
        $battleData['finalStats'] = $this->statusService->getFinalStats($battleData['character']);
        if (!($battleData['isBoss'] ?? false)) {
            $battleData['recoveryItems'] = app(ExplorationItemService::class)->carriedItems($battleData['character']);
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
        $battleData['battleHeaderIconImage'] = $this->battleHeaderIconImage($areaId);
        $battleData['battleCityBackgroundStyle'] = (($battleData['result']['special_event'] ?? null) === 'secret_realm_lord')
            ? 'background-color: #d9b72f;'
            : $this->battleCityBackgroundStyle($areaId);
        $battleData['discoveryAreaCardBackgrounds'] = $this->discoveryAreaCardBackgrounds($battleData['result']['new_discoveries'] ?? []);

        return view('battle.result', $battleData);
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
        if (is_array($battleData['defender'])) {
            $battleData['defender'] = \App\Models\Character::find($battleData['defender']['id']);
        }
        if (isset($battleData['arenaLog']) && is_array($battleData['arenaLog'])) {
            $battleData['arenaLog'] = (object) $battleData['arenaLog'];
        }

        return view('battle.pvp_result', $battleData);
    }
}
