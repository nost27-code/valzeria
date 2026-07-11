<?php

namespace App\Http\Controllers;

use App\Models\TowerCharacterRecord;
use App\Models\TowerFloorMaster;
use App\Models\TowerMerchantPurchase;
use App\Models\TowerRewardClaim;
use App\Models\TowerRunEvent;
use App\Models\TowerWeeklyRecord;
use App\Services\AdventureSupportService;
use App\Services\CharacterStatusService;
use App\Services\ExplorationStaminaService;
use App\Services\StarTreeTowerRewardService;
use App\Services\StarTreeTowerService;
use App\Services\TowerBattleService;
use App\Services\TowerMerchantService;
use App\Services\TowerRankingService;
use App\Services\TowerTitleRewardService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class StarTreeTowerController extends Controller
{
    public function index(
        StarTreeTowerService $towerService,
        ExplorationStaminaService $staminaService,
        TowerBattleService $battleService,
        TowerTitleRewardService $titleRewardService,
        StarTreeTowerRewardService $rewardService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $towerKey = $towerService->towerKey();
        $activeRun = $towerService->getActiveRun($character);
        $maxTowerFloor = max(1, (int) config('star_tree_tower.star_tree.seed_floor_count', 100));
        $isTowerCompleted = $activeRun && (int) ($activeRun->cleared_floor ?? 0) >= $maxTowerFloor;
        $currentFloor = $activeRun && !$isTowerCompleted
            ? TowerFloorMaster::query()
                ->where('tower_key', $towerKey)
                ->where('floor', (int) $activeRun->current_floor)
                ->where('is_active', true)
                ->first()
            : null;
        $recentEvent = $this->recentEvent($character->id);
        $merchantService = app(TowerMerchantService::class);
        $merchantProducts = $activeRun && $activeRun->pending_event === TowerMerchantService::PENDING_EVENT
            ? $merchantService->products($activeRun)
            : [];
        $towerRecoveryItems = $activeRun
            ? $merchantService->availableRecoveryItems($activeRun)
            : [];
        $checkpointStartFloor = $activeRun && !$isTowerCompleted
            ? (int) $activeRun->current_floor
            : $towerService->checkpointStartFloor($character);
        $entryFloor = $currentFloor ?: TowerFloorMaster::query()
            ->where('tower_key', $towerKey)
            ->where('floor', $checkpointStartFloor)
            ->where('is_active', true)
            ->first();
        $restartFloor = TowerFloorMaster::query()
            ->where('tower_key', $towerKey)
            ->where('floor', 1)
            ->where('is_active', true)
            ->first();
        $weeklyRecord = TowerWeeklyRecord::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $towerKey)
            ->where('season_key', $towerService->seasonKey())
            ->first();
        $characterRecord = TowerCharacterRecord::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $towerKey)
            ->first();
        if ($characterRecord) {
            $rewardService->createPendingRewardsFromBestRecord($character, (int) $characterRecord->best_cleared_floor, $towerKey);
        }
        $unlockedTitleNames = $titleRewardService->unlockEarnedMilestones($character, $towerKey);
        $pendingTowerRewards = $rewardService->pendingRewardsFor($character, $towerKey);
        $hasScoutedEntryFloor = $activeRun && !$isTowerCompleted
            ? TowerRunEvent::query()
                ->where('tower_run_id', $activeRun->id)
                ->where('event_type', 'scout')
                ->where('floor', (int) $activeRun->current_floor)
                ->exists()
            : false;

        return view('tower.star-tree.index', [
            'character' => $character,
            'canAccess' => $towerService->canAccess($character),
            'activeRun' => $activeRun,
            'currentFloor' => $currentFloor,
            'entryFloor' => $entryFloor,
            'restartFloor' => $restartFloor,
            'recentEvent' => $recentEvent,
            'merchantProducts' => $merchantProducts,
            'towerRecoveryItems' => $towerRecoveryItems,
            'checkpointStartFloor' => $checkpointStartFloor,
            'weeklyRecord' => $weeklyRecord,
            'characterRecord' => $characterRecord,
            'unlockedTitleNames' => $unlockedTitleNames,
            'pendingTowerRewards' => $pendingTowerRewards,
            'hasScoutedEntryFloor' => $hasScoutedEntryFloor,
            'supportItemCounts' => app(AdventureSupportService::class)->countsFor($character),
            'stamina' => $staminaService->summary($character),
            'towerActionStrategies' => $battleService->actionStrategies(),
            'towerStanceChoices' => $battleService->stanceChoices(),
            'pendingTowerStance' => $activeRun && !$isTowerCompleted ? $battleService->pendingStance($activeRun) : null,
            'towerStanceState' => $activeRun ? $battleService->stanceState($activeRun) : null,
            'towerUi' => $towerService->uiConfig(),
        ]);
    }

    public function start(
        StarTreeTowerService $towerService,
        TowerBattleService $battleService,
        ExplorationStaminaService $staminaService,
    )
    {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();

        try {
            $strategy = (string) request()->input('strategy', 'normal');
            $run = $towerService->startRun($character);
            $event = $battleService->challengeCurrentFloor($character, $run, true, $strategy);
        } catch (RuntimeException | InvalidArgumentException $e) {
            if (isset($run) && $run->pending_event === TowerMerchantService::PENDING_EVENT) {
                $event = $this->merchantEventForRun($run, $character->id);

                return $this->redirectToResult($event);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        return $this->redirectToResult($event);
    }

    public function restart(
        StarTreeTowerService $towerService,
        TowerBattleService $battleService,
        ExplorationStaminaService $staminaService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();

        try {
            $run = $towerService->restartFromFirstFloor($character);
            $event = $battleService->challengeCurrentFloor($character, $run);
        } catch (RuntimeException | InvalidArgumentException $e) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        return $this->redirectToResult($event);
    }

    public function challenge(
        StarTreeTowerService $towerService,
        TowerBattleService $battleService,
        ExplorationStaminaService $staminaService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            if ($battleService->pendingStance($run)) {
                $battleService->chooseStance($character, $run, (string) request()->input('stance', 'none'));
                $run->refresh();
            }

            $event = $battleService->challengeCurrentFloor(
                $character,
                $run,
                true,
                (string) request()->input('strategy', 'normal')
            );
        } catch (RuntimeException | InvalidArgumentException $e) {
            if ($run->pending_event === TowerMerchantService::PENDING_EVENT) {
                $event = $this->merchantEventForRun($run, $character->id);

                return $this->redirectToResult($event);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        return $this->redirectToResult($event);
    }

    public function chooseStance(
        StarTreeTowerService $towerService,
        TowerBattleService $battleService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            $result = $battleService->chooseStance($character, $run, (string) request()->input('stance', 'none'));
        } catch (RuntimeException | InvalidArgumentException $e) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        $returnEventId = (int) request()->input('return_event_id', 0);
        $event = $returnEventId > 0
            ? TowerRunEvent::query()
                ->whereKey($returnEventId)
                ->where('tower_run_id', $run->id)
                ->where('character_id', $character->id)
                ->first()
            : null;

        $message = (int) ($result['floor'] ?? 0).'階の節目で「'.(string) ($result['choice']['name'] ?? '構えなし').'」を選びました。';

        return $event
            ? $this->redirectToResult($event)->with('message', $message)
            : redirect()->route('tower.star-tree.index')->with('message', $message);
    }

    public function return(StarTreeTowerService $towerService)
    {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            $pausedRun = $towerService->pauseRun($character, $run);
        } catch (RuntimeException | InvalidArgumentException $e) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        $maxTowerFloor = max(1, (int) config('star_tree_tower.star_tree.seed_floor_count', 100));
        $message = (int) ($pausedRun->cleared_floor ?? 0) >= $maxTowerFloor
            ? "{$towerService->displayText('name', '星樹の塔')}を踏破しました。入口へ戻りました。"
            : "{$towerService->displayText('name', '星樹の塔')}から出ました。次は{$pausedRun->current_floor}階から再開できます。";

        return redirect()
            ->route('tower.star-tree.index')
            ->with('message', $message)
            ->with('tower_event_id', TowerRunEvent::query()
                ->where('tower_run_id', $pausedRun->id)
                ->where('event_type', 'pause')
                ->latest('id')
                ->value('id'));
    }

    public function resumeMerchant(
        StarTreeTowerService $towerService,
        ExplorationStaminaService $staminaService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        if ($run->pending_event !== TowerMerchantService::PENDING_EVENT) {
            return redirect()->route('tower.star-tree.index')->with('error', $towerService->displayText('merchant_none_message', '星灯の行商人はいません。'));
        }

        $event = $this->merchantEventForRun($run, $character->id);

        return $this->redirectToResult($event);
    }

    public function buyMerchantItem(
        StarTreeTowerService $towerService,
        TowerMerchantService $merchantService,
        ExplorationStaminaService $staminaService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $validated = request()->validate([
            'item_key' => 'required|string|max:100',
        ]);
        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            if (request()->expectsJson() || request()->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $this->noActiveRunMessage($towerService),
                ], 422);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            $event = $merchantService->buy($character, $run, (string) $validated['item_key']);
        } catch (RuntimeException | InvalidArgumentException $e) {
            if (request()->expectsJson() || request()->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $this->friendlyError($e),
                ], 422);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        if (request()->expectsJson() || request()->ajax()) {
            $run->refresh();

            return response()->json($this->towerRecoveryItemPayload($merchantService, $run, $event));
        }

        return $this->redirectToResult($event);
    }

    public function skipMerchant(
        StarTreeTowerService $towerService,
        TowerMerchantService $merchantService,
        TowerBattleService $battleService,
        ExplorationStaminaService $staminaService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            $strategy = (string) request()->input('strategy', 'normal');
            if ($battleService->pendingStance($run)) {
                $battleService->chooseStance($character, $run, (string) request()->input('stance', 'none'));
                $run->refresh();
            }

            $towerKey = $towerService->towerKey();
            $currentFloor = TowerFloorMaster::query()
                ->where('tower_key', $towerKey)
                ->where('floor', (int) $run->current_floor)
                ->where('is_active', true)
                ->first();
            $strategySpec = $battleService->actionStrategies()[$strategy] ?? $battleService->actionStrategies()['normal'];
            $stamina = $staminaService->summary($character);
            $staminaCost = isset($strategySpec['fixed_stamina_cost'])
                ? (int) $strategySpec['fixed_stamina_cost']
                : (int) ($currentFloor->stamina_cost ?? 0) + (int) ($strategySpec['stamina_extra'] ?? 0);
            if ($currentFloor && (int) ($stamina['current'] ?? 0) < $staminaCost) {
                throw new RuntimeException($towerService->displayText('name', '星樹の塔').'へ挑むための探索力が足りません。回復を待ってください。');
            }

            $battleService->acquireBattleRequestGuard($character);
            $merchantService->skip($character, $run);
            $event = $battleService->challengeCurrentFloor($character, $run, false, $strategy);
        } catch (RuntimeException | InvalidArgumentException $e) {
            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        return $this->redirectToResult($event);
    }

    public function useMerchantItem(
        StarTreeTowerService $towerService,
        TowerMerchantService $merchantService,
        ExplorationStaminaService $staminaService,
        TowerMerchantPurchase $purchase,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $run = $towerService->getActiveRun($character);

        if (!$run) {
            if (request()->expectsJson() || request()->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $this->noActiveRunMessage($towerService),
                ], 422);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->noActiveRunMessage($towerService));
        }

        try {
            $event = $merchantService->usePurchasedItem($character, $run, $purchase);
        } catch (RuntimeException | InvalidArgumentException $e) {
            if (request()->expectsJson() || request()->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $this->friendlyError($e),
                ], 422);
            }

            return redirect()->route('tower.star-tree.index')->with('error', $this->friendlyError($e));
        }

        if (request()->expectsJson() || request()->ajax()) {
            $run->refresh();

            return response()->json($this->towerRecoveryItemPayload($merchantService, $run, $event));
        }

        return $this->redirectToResult($event);
    }

    public function result(
        StarTreeTowerService $towerService,
        ExplorationStaminaService $staminaService,
        StarTreeTowerRewardService $rewardService,
        TowerRunEvent $event,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        if ((int) $event->character_id !== (int) $character->id) {
            return redirect()->route('tower.star-tree.index')->with('error', $towerService->displayText('name', '星樹の塔').'の結果が見つかりません。');
        }

        return view('tower.star-tree.result', $this->resultViewData(
            $towerService,
            $staminaService,
            $rewardService,
            $character,
            $event
        ));
    }

    public function claimReward(
        StarTreeTowerService $towerService,
        StarTreeTowerRewardService $rewardService,
        TowerRewardClaim $reward,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();

        try {
            $result = $rewardService->claim($reward, $character, request()->input('weapon_category'));
        } catch (RuntimeException | InvalidArgumentException $e) {
            return back()->with('error', $this->friendlyError($e));
        }

        return back()->with('message', (string) ($result['message'] ?? '報酬を受け取りました。'));
    }

    public function ranking(
        StarTreeTowerService $towerService,
        TowerRankingService $rankingService,
    ) {
        if (! $towerService->isEnabled()) {
            return $this->disabledRedirect();
        }

        $character = Auth::user()->currentCharacter();
        $towerKey = $towerService->towerKey();
        $seasonKey = $towerService->seasonKey();

        return view('tower.star-tree.ranking', [
            'character' => $character,
            'seasonKey' => $seasonKey,
            'weeklyRankings' => $rankingService->weeklyRanking($towerKey, $seasonKey),
            'allTimeRankings' => $rankingService->allTimeRanking($towerKey),
            'towerUi' => $towerService->uiConfig(),
        ]);
    }

    private function recentEvent(int $characterId): ?TowerRunEvent
    {
        $eventId = session('tower_event_id');
        if (!$eventId) {
            return null;
        }

        return TowerRunEvent::query()
            ->where('character_id', $characterId)
            ->whereKey((int) $eventId)
            ->first();
    }

    private function disabledRedirect()
    {
        return redirect()->route('home')->with('error', app(StarTreeTowerService::class)->displayText('disabled_message', '星樹の塔は現在準備中です。'));
    }

    private function redirectToResult(TowerRunEvent $event)
    {
        return redirect()
            ->route('tower.star-tree.result', ['event' => $event->id])
            ->with('tower_event_id', $event->id);
    }

    private function merchantEventForRun($run, int $characterId): TowerRunEvent
    {
        $event = TowerRunEvent::query()
            ->where('tower_run_id', $run->id)
            ->where('character_id', $characterId)
            ->whereIn('event_type', ['merchant', 'merchant_purchase'])
            ->latest('id')
            ->first();

        if ($event) {
            return $event;
        }

        return TowerRunEvent::query()->create([
            'tower_run_id' => $run->id,
            'character_id' => $characterId,
            'floor' => (int) ($run->last_merchant_floor ?: max(1, (int) $run->cleared_floor)),
            'event_type' => 'merchant',
            'result' => 'appeared',
            'hp_after' => $run->tower_current_hp,
            'mp_after' => $run->tower_current_mp,
            'message' => app(StarTreeTowerService::class)->displayText('merchant_appeared_message', '星灯の行商人が、枝の上に腰かけていた。'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resultViewData(
        StarTreeTowerService $towerService,
        ExplorationStaminaService $staminaService,
        StarTreeTowerRewardService $rewardService,
        $character,
        TowerRunEvent $event,
    ): array {
        $character = $character->fresh(['jobClass']) ?? $character;
        $run = $event->towerRun()->first();
        $towerKey = $towerService->towerKey();
        $currentFloor = $run && $run->status === StarTreeTowerService::STATUS_RUNNING
            ? TowerFloorMaster::query()
                ->where('tower_key', $towerKey)
                ->where('floor', (int) $run->current_floor)
                ->where('is_active', true)
                ->first()
            : null;
        $eventFloor = TowerFloorMaster::query()
            ->where('tower_key', $towerKey)
            ->where('floor', (int) $event->floor)
            ->where('is_active', true)
            ->first();

        $merchantService = app(TowerMerchantService::class);
        $merchantProducts = $run && $run->pending_event === TowerMerchantService::PENDING_EVENT
            ? $merchantService->products($run)
            : [];
        $towerRecoveryItems = $run
            ? $merchantService->availableRecoveryItems($run)
            : [];
        $hasScoutedCurrentFloor = $run && $currentFloor
            ? TowerRunEvent::query()
                ->where('tower_run_id', $run->id)
                ->where('event_type', 'scout')
                ->where('floor', (int) $currentFloor->floor)
                ->exists()
            : false;
        $battleService = app(TowerBattleService::class);
        $challengeGuardSeconds = $battleService->requestGuardSeconds();
        $challengeGuardRemainingMs = 0;
        if ($character->last_battle_at) {
            $challengeAvailableAt = $character->last_battle_at->copy()->addSeconds($challengeGuardSeconds);
            $challengeGuardRemainingMs = max(0, ($challengeAvailableAt->getTimestamp() - now(config('app.timezone', 'Asia/Tokyo'))->getTimestamp()) * 1000);
        }

        return [
            'character' => $character,
            'run' => $run,
            'event' => $event,
            'currentFloor' => $currentFloor,
            'eventFloor' => $eventFloor,
            'merchantProducts' => $merchantProducts,
            'towerRecoveryItems' => $towerRecoveryItems,
            'hasScoutedCurrentFloor' => $hasScoutedCurrentFloor,
            'towerActionStrategies' => $battleService->actionStrategies(),
            'towerStanceChoices' => $battleService->stanceChoices(),
            'pendingTowerStance' => $run ? $battleService->pendingStance($run) : null,
            'towerStanceState' => $run ? $battleService->stanceState($run) : null,
            'towerChallengeGuardSeconds' => $challengeGuardSeconds,
            'towerChallengeGuardRemainingMs' => $challengeGuardRemainingMs,
            'towerUi' => $towerService->uiConfig(),
            'supportItemCounts' => app(AdventureSupportService::class)->countsFor($character),
            'pendingTowerRewards' => $rewardService->pendingRewardsFor($character, $towerKey)
                ->filter(fn (array $reward): bool => (int) ($reward['floor'] ?? 0) === (int) $event->floor)
                ->values(),
            'checkpointStartFloor' => $towerService->checkpointStartFloor($character),
            'stamina' => $staminaService->summary($character),
            'finalStats' => app(CharacterStatusService::class)->getFinalStats($character),
            'jobLevel' => $character->jobHistories()
                ->where('job_class_id', $character->current_job_id)
                ->value('job_level') ?: 1,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function towerRecoveryItemPayload(
        TowerMerchantService $merchantService,
        $run,
        TowerRunEvent $event,
    ): array {
        $hpMax = max(1, (int) ($run->tower_max_hp ?? 0));
        $spMax = max(1, (int) ($run->tower_max_mp ?? 0));
        $items = [];

        foreach ($merchantService->availableRecoveryItems($run) as $item) {
            $items[(string) $item['key']] = [
                'purchase_id' => (int) $item['purchase_id'],
                'key' => (string) $item['key'],
                'name' => (string) $item['name'],
                'description' => (string) $item['description'],
                'count' => (int) $item['count'],
                'effect_type' => (string) ($item['effect_type'] ?? ''),
                'usable' => (bool) ($item['usable'] ?? true),
                'armed' => (bool) ($item['armed'] ?? false),
            ];
        }
        $products = [];

        foreach ($merchantService->products($run) as $product) {
            $products[(string) $product['key']] = [
                'purchased' => (bool) ($product['purchased'] ?? false),
            ];
        }

        return [
            'ok' => true,
            'message' => $event->message,
            'hp' => [
                'current' => (int) ($run->tower_current_hp ?? 0),
                'max' => (int) ($run->tower_max_hp ?? 0),
                'percent' => min(100, (int) floor(((int) ($run->tower_current_hp ?? 0) / $hpMax) * 100)),
            ],
            'sp' => [
                'current' => (int) ($run->tower_current_mp ?? 0),
                'max' => (int) ($run->tower_max_mp ?? 0),
                'percent' => min(100, (int) floor(((int) ($run->tower_current_mp ?? 0) / $spMax) * 100)),
            ],
            'items' => $items,
            'products' => $products,
            'has_purchased_merchant_product' => collect($products)->contains(fn (array $product): bool => (bool) ($product['purchased'] ?? false)),
        ];
    }

    private function friendlyError(RuntimeException | InvalidArgumentException $e): string
    {
        return str_starts_with($e->getMessage(), 'Star tree tower')
            ? app(StarTreeTowerService::class)->displayText('locked_message', '星樹の塔はまだ解放されていません。')
            : $e->getMessage();
    }

    private function noActiveRunMessage(StarTreeTowerService $towerService): string
    {
        return '進行中の'.$towerService->displayText('name', '星樹の塔').'がありません。';
    }
}
