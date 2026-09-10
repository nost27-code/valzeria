<?php

namespace App\Http\Controllers;

use App\Services\CharacterIconSetService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialService;
use App\Services\InnService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HeroTrialController extends Controller
{
    private const REQUEST_DELAY_SECONDS = 3;

    public function index(HeroTrialService $trialService, InnService $innService): View|RedirectResponse
    {
        if (! $trialService->isEnabled()) {
            return redirect()->route('home')->with('error', '英雄試練は現在公開されていません。');
        }

        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }

        $cityId = (int) $character->current_city_id;
        if (! $trialService->canViewHall($character, $cityId)) {
            return redirect()->route('home')->with('error', '現在挑戦できる英雄試練はありません。');
        }

        session(['current_location' => 'dungeon']);

        $trials = collect($trialService->hallFacilitiesFor($character, $cityId))
            ->map(function (array $trial) use ($character, $innService): array {
                if ((bool) ($trial['challenge_requirements']['only_hp_sp_missing'] ?? false)) {
                    $trial['inn_fee'] = $innService->fee($character);
                }

                return $trial;
            })
            ->all();

        return view('hero-trials.index', ['trials' => $trials]);
    }

    public function rest(
        string $trialKey,
        HeroTrialService $trialService,
        InnService $innService,
        CharacterStatusService $statusService,
    ): JsonResponse {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return response()->json([
                'success' => false,
                'message' => '冒険者を選択してください。',
            ], 422);
        }

        try {
            $requirements = $trialService->challengeRequirementsFor($character, $trialKey);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ((bool) $requirements['ready']) {
            return response()->json([
                'success' => false,
                'message' => 'HP/SPはすでに全快です。そのまま試練に挑めます。',
            ], 422);
        }

        if (! (bool) $requirements['only_hp_sp_missing']) {
            return response()->json([
                'success' => false,
                'message' => 'HP/SP以外の挑戦条件を満たしてから宿屋を利用してください。',
            ], 422);
        }

        if (! Cache::add(
            "hero_trial_rest_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::REQUEST_DELAY_SECONDS)
        )) {
            return response()->json([
                'success' => false,
                'message' => '宿屋の処理中です。少し待ってからもう一度お試しください。',
            ], 429);
        }

        $result = $innService->rest($character);
        if (! (bool) ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['message'] ?? '宿屋を利用できませんでした。'),
            ], 422);
        }

        $character->refresh();
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = $statusService->getFinalStats($character);
        $paid = (int) ($result['paid'] ?? 0);
        $rescued = (bool) ($result['rescued'] ?? false);
        $payText = $paid > 0 ? "{$paid}G支払った" : '支払いは免除された';
        $message = $rescued
            ? "宿屋のおばちゃんの厚意でHP/SPが全回復した！（{$payText}）"
            : "宿屋で休み、HP/SPが全回復した！（{$payText}）";

        return response()->json([
            'success' => true,
            'message' => $message,
            'paid' => $paid,
            'rescued' => $rescued,
            'hp' => (int) $character->current_hp,
            'max_hp' => (int) ($stats['max_hp'] ?? 1),
            'sp' => (int) ($character->current_mp ?? 0),
            'max_sp' => (int) ($stats['max_mp'] ?? 0),
            'money' => (int) $character->money,
        ]);
    }

    public function challenge(string $trialKey, HeroTrialService $trialService): RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }

        session(['current_location' => 'dungeon']);

        if (! Cache::add(
            "hero_trial_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::REQUEST_DELAY_SECONDS)
        )) {
            return redirect()->route('home')
                ->with('error', '試練の処理中です。少し待ってからもう一度お試しください。');
        }

        try {
            $outcome = $trialService->challenge($character, $trialKey);
        } catch (DomainException $exception) {
            return redirect()->route('home')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('hero-trials.result', ['trialKey' => $trialKey])
            ->with('heroTrialData', $outcome);
    }

    public function result(
        Request $request,
        string $trialKey,
        HeroTrialService $trialService,
        CharacterStatusService $statusService,
        CharacterIconSetService $iconSetService,
    ): View|RedirectResponse {
        if (! $trialService->isEnabled()) {
            return redirect()->route('home')->with('error', '英雄試練は現在公開されていません。');
        }

        $outcome = $request->session()->get('heroTrialData');
        if (! is_array($outcome) || (string) ($outcome['trial_key'] ?? '') !== $trialKey) {
            return redirect()->route('home');
        }

        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }
        $character->loadMissing('jobClass');
        $jobLevel = (int) ($character->jobHistories()
            ->where('job_class_id', $character->current_job_id)
            ->value('job_level') ?? 1);

        return view('hero-trials.result', [
            'outcome' => $outcome,
            'character' => $character,
            'finalStats' => $statusService->getFinalStats($character),
            'jobLevel' => $jobLevel,
            'characterBattleImagePath' => $iconSetService->pathFor($character, 'battle'),
            'characterVictoryImagePath' => $iconSetService->pathFor($character, 'victory'),
            'characterDefeatImagePath' => $iconSetService->pathFor($character, 'defeat'),
        ]);
    }
}
