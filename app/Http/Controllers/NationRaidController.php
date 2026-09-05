<?php

namespace App\Http\Controllers;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidPersonalReward;
use App\Models\NationRaidNationReward;
use App\Services\Nation\Raid\NationRaidRewardService;
use App\Services\Nation\Raid\NationRaidRewardScreenService;
use App\Services\Nation\Raid\NationRaidRankingService;
use App\Services\Nation\Raid\NationRaidScreenService;
use App\Services\Nation\Raid\NationRaidSortieService;
use App\Services\Nation\Raid\NationRaidHistoryService;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidStrategyPolicy;
use App\Services\Nation\Raid\NationRaidPortalService;
use Illuminate\Http\Request;

class NationRaidController extends Controller
{
    public function index()
    {
        if (! config('features.nation_competitive_raid_enabled', false)
            && config('features.nation_competitive_raid_preview_enabled', false)) {
            return redirect()->route('nation-raid.preview');
        }
        $this->gate();
        $event = NationRaidEvent::query()->whereIn('status', [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED])
            ->orderByDesc('starts_at')->first();

        return $event ? redirect()->route('nation-raid.top', $event) : view('nation-raid.unavailable');
    }

    public function top(Request $request, NationRaidEvent $event, NationRaidPortalService $portal)
    {
        $this->visibleEvent($event);

        return view('nation-raid.top', ['event' => $event, 'portal' => $portal->build($event, $request->user()->currentCharacter())]);
    }

    public function rankings(Request $request, NationRaidEvent $event, NationRaidPortalService $portal)
    {
        $this->visibleEvent($event);
        $data = $portal->build($event, $request->user()->currentCharacter());

        return view('nation-raid.rankings', ['event' => $event, 'portal' => $data,
            'screen' => ['standings' => $data['standings'], 'own_progress' => $data['own_progress']]]);
    }

    public function show(Request $request, NationRaidEvent $event, NationRaidScreenService $screens, NationRaidStrategyPolicy $strategies)
    {
        $this->gate();
        abort_unless(in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED], true), 404);
        if ($event->status === NationRaidEvent::STATUS_COMPLETED && ! $request->filled('battle')) {
            return redirect()->route('nation-raid.rewards', $event);
        }
        $character = $request->user()->currentCharacter();
        $battle = null;
        if ($request->filled('battle')) {
            abort_unless(is_string($request->query('battle')) && preg_match('/\A[a-f0-9]{64}\z/', $request->query('battle')), 404);
            $battle = NationRaidBattleResult::query()->where('event_id', $event->id)->where('account_id', $request->user()->id)
                ->where('character_id', $character->id)->where('battle_token', $request->query('battle'))->firstOrFail();
        }

        return view('nation-raid.trial', [
            'screen' => $screens->screen($event, $character),
            'lastResult' => $battle?->status === NationRaidBattleResult::STATUS_RESOLVED ? $battle->summary['display'] : null,
            'sortieStatus' => $battle?->status,
            'selection' => ['strategy' => $strategies->forDisplay(old('strategy', $battle?->strategy))],
        ]);
    }

    public function history(Request $request, NationRaidHistoryService $history)
    {
        $this->gate();

        return view('nation-raid.history', $history->forCharacter($request->user()->currentCharacter()));
    }

    public function battle(Request $request, NationRaidEvent $event, NationRaidSortieService $sorties, NationRaidStrategyPolicy $strategies)
    {
        $this->gate();
        $data = $request->validate([
            'strategy' => $strategies->validationRules(),
            'battle_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        try {
            $battle = $sorties->fight($event, $request->user()->currentCharacter(), $data['strategy'] ?? NationRaidRules::STRATEGY_BOSS_SET, $data['battle_token']);
        } catch (\DomainException $exception) {
            return redirect()->route('nation-raid.show', $event)->withInput()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('nation-raid.show', $event)->with('error', '出撃の確認に時間がかかっています。少し待ってから確認してください。');
        }

        return redirect()->route('nation-raid.show', ['event' => $event, 'battle' => $battle->battle_token]);
    }

    public function rewards(Request $request, NationRaidEvent $event, NationRaidRankingService $rankings, NationRaidRewardScreenService $screens)
    {
        $this->gate();
        abort_unless(in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED], true), 404);
        $character = $request->user()->currentCharacter();
        $standings = $rankings->standings($event);
        $rewards = NationRaidPersonalReward::where('event_id', $event->id)->where('account_id_snapshot', $request->user()->id)
            ->where('character_id_snapshot', $character->id)->orderBy('id')->get();
        $rewardScreen = $screens->build($event, $character, $standings, $rewards);
        return view('nation-raid.rewards', [
            'event' => $event,
            'rewardScreen' => $rewardScreen,
            'nationRewards' => NationRaidNationReward::where('event_id', $event->id)->orderBy('nation_id_snapshot')->orderBy('id')->get(),
            'screen' => ['standings' => $standings, 'own_progress' => $rewardScreen['own_progress']],
        ]);
    }

    public function claim(Request $request, NationRaidEvent $event, int $reward, NationRaidRewardService $rewards)
    {
        $this->gate();
        $data = $request->validate(['selection' => ['nullable', 'string', 'in:enhance,guard,tune,talisman']]);
        try {
            $rewards->claim($event, $request->user()->currentCharacter(), $reward, $data['selection'] ?? null);
            return redirect()->route('nation-raid.rewards', $event)->with('success', 'レイド報酬を受け取った！');
        } catch (\DomainException $exception) {
            return redirect()->route('nation-raid.rewards', $event)->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->route('nation-raid.rewards', $event)->with('error', '報酬は保管されています。時間をおいて、もう一度確認してください。');
        }
    }

    private function gate(): void
    {
        abort_unless((bool) config('features.nation_competitive_raid_enabled', false), 404);
    }

    private function visibleEvent(NationRaidEvent $event): void
    {
        $this->gate();
        abort_unless(in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED], true), 404);
    }
}
