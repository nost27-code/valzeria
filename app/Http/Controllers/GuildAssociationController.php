<?php

namespace App\Http\Controllers;

use App\Services\GuildService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GuildAssociationController extends Controller
{
    public function index(GuildService $guildService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $donationTotal = (int) ($character->guild_donation_total ?? 0);
        $rank = $guildService->rankByDonation($donationTotal);
        $nextRank = $guildService->nextRank($donationTotal);

        return view('guild-association.index', [
            'character' => $character,
            'rank' => $rank,
            'nextRank' => $nextRank,
            'donationTotal' => $donationTotal,
            'guildService' => $guildService,
        ]);
    }

    public function donate(Request $request, GuildService $guildService)
    {
        return redirect()->route('association.index')->with('error', '冒険者協会の寄付機能は現在利用できません。');
    }
}
