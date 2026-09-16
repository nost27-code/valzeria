<?php

namespace App\Http\Controllers;

use App\Services\Nation\Raid\NationRaidEntryService;
use App\Services\Nation\Raid\NationRaidPublicIdentityService;
use App\Services\Nation\Raid\NationRaidRewardScreenService;
use Illuminate\Contracts\View\View;

/** 開催レコードを参照・作成しない事前案内。出撃・受取の権限は持たない。 */
final class NationRaidPreviewController extends Controller
{
    public function __invoke(
        NationRaidEntryService $entries,
        NationRaidRewardScreenService $rewards,
        NationRaidPublicIdentityService $identities,
        string $page = 'top',
    ): View
    {
        abort_unless($entries->isPreviewPublished(), 404);

        return view('nation-raid.preview', [
            'page' => $page,
            'publicIdentity' => $identities->preparing(),
            'startsAtLabel' => (string) config('nation_raid_preview.starts_at_label'),
            'rewardScreen' => $page === 'rewards' ? $rewards->preview() : null,
        ]);
    }
}
