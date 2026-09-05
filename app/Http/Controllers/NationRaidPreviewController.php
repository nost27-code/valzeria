<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Authenticated announcement only: no raid model, event, battle or claim dependency. */
final class NationRaidPreviewController extends Controller
{
    public function __invoke(string $page = 'top'): View
    {
        abort_unless((bool) config('features.nation_competitive_raid_preview_enabled', false), 404);

        $preview = config('nation_raid_preview');

        return view('nation-raid.preview', [
            'page' => $page,
            'bossName' => $preview['boss_name'],
            'bossImage' => $preview['boss_image'],
            'rewardScreen' => $page === 'rewards' ? $preview : null,
        ]);
    }
}
