<?php

namespace App\Http\Controllers;

use App\Services\Nation\Raid\NationRaidEntryService;
use App\Services\Nation\Raid\NationRaidRewardScreenService;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTrialService;
use Illuminate\Contracts\View\View;

/** 開催レコードを参照・作成しない事前案内。出撃・受取の権限は持たない。 */
final class NationRaidPreviewController extends Controller
{
    public function __invoke(NationRaidEntryService $entries, NationRaidRewardScreenService $rewards, NationRaidRules $rules, string $page = 'top'): View
    {
        abort_unless($entries->isPreviewPublished(), 404);

        return view('nation-raid.preview', [
            'page' => $page,
            'bossName' => NationRaidTrialService::BOSS_NAME,
            'bossImage' => $rules->formParameters(NationRaidRules::FORM_SEALED_SCALE)['image_path'],
            'rewardScreen' => $page === 'rewards' ? $rewards->preview() : null,
        ]);
    }
}
