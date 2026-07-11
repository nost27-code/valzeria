<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Services\AdventureSupportService;
use App\Services\SupportPassService;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

class KisekiShopController extends Controller
{
    public function index(AdventureSupportService $supportService)
    {
        $character = Auth::user()->characters()->first();
        if (!$character) {
            return redirect()->route('character.create');
        }

        $packs = config('kiseki.packs');
        app(\App\Services\PlayerLifecycleEventService::class)->recordPurchaseScreenViewed($character);

        return view('kiseki.shop', compact('character', 'packs'));
    }

    public function supportShop(AdventureSupportService $supportService)
    {
        $character = Auth::user()->characters()->first();
        if (!$character) {
            return redirect()->route('character.create');
        }

        $supportCatalog = $supportService->catalogFor($character);
        $supportCounts = $supportService->countsFor($character);
        $insuranceEnabled = $supportService->insuranceEnabled($character);
        $supportPassStatus = app(SupportPassService::class)->statusForCharacter($character);
        app(\App\Services\PlayerLifecycleEventService::class)->recordPurchaseScreenViewed($character);

        return view('kiseki.support', compact('character', 'supportCatalog', 'supportCounts', 'insuranceEnabled', 'supportPassStatus'));
    }

    public function createCheckout(Request $request)
    {
        $request->validate(['pack_key' => 'required|string']);

        $packKey = $request->input('pack_key');
        $packs   = config('kiseki.packs');

        if (!array_key_exists($packKey, $packs)) {
            return back()->with('error', '無効なパックです。');
        }

        $pack      = $packs[$packKey];
        $character = Auth::user()->characters()->first();

        if (!$character) {
            return redirect()->route('character.create');
        }

        $lockKey = "kiseki_checkout_lock:{$character->id}:{$packKey}";
        if (!Cache::add($lockKey, true, now()->addSeconds(10))) {
            return back()->with('info', '購入処理を開始しています。Stripe画面が開かない場合は、少し待ってからもう一度お試しください。');
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeSession::create([
            'mode'        => 'payment',
            'payment_method_types' => ['card', 'link', 'paypay'],
            'line_items'  => [[
                'price'    => $pack['price_id'],
                'quantity' => 1,
            ]],
            'success_url' => route('kiseki.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('kiseki.shop'),
            'metadata'    => [
                'character_id'  => $character->id,
                'pack_key'      => $packKey,
                'kiseki_amount' => $pack['kiseki_amount'],
            ],
        ]);

        return redirect($session->url, 303);
    }

    public function success(Request $request)
    {
        $character = Auth::user()->characters()->first();

        return view('kiseki.success', compact('character'));
    }

    public function cancel()
    {
        return redirect()->route('kiseki.shop')->with('info', '購入をキャンセルしました。');
    }

    public function purchaseSupport(Request $request, AdventureSupportService $supportService)
    {
        $request->validate(['item_key' => 'required|string']);

        $character = Auth::user()->characters()->first();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('character.create');
        }

        $itemKey = $request->input('item_key');
        $lockKey = "adventure_support_purchase:{$character->id}:{$itemKey}";
        if (!Cache::add($lockKey, true, now()->addSeconds(5))) {
            return back()->with('info', '購入処理中です。少し待ってから再度お試しください。');
        }

        try {
            $result = $supportService->purchase($character, $itemKey);
        } catch (\Throwable $e) {
            report($e);
            $result = ['success' => false, 'message' => '購入処理に失敗しました。時間をおいて再度お試しください。'];
        } finally {
            Cache::forget($lockKey);
        }

        $character->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'kiseki' => (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0),
                'money' => (int) ($character->money ?? 0),
                'support_items' => $supportService->ownedConsumablesFor($character),
            ], $result['success'] ? 200 : 422);
        }

        return back()->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function useRescueInsurance(AdventureSupportService $supportService)
    {
        $character = Auth::user()->characters()->first();
        if (!$character) {
            return redirect()->route('character.create');
        }

        $result = $supportService->useRescueInsurance($character);

        return back()->with($result['success'] ? 'status' : 'error', $result['message']);
    }
}
