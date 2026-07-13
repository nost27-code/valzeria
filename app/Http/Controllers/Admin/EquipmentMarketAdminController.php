<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EquipmentMarketListing;
use App\Models\EquipmentMarketTransaction;
use App\Services\EquipmentMarketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EquipmentMarketAdminController extends Controller
{
    public function index()
    {
        $summary = [
            'active' => EquipmentMarketListing::where('status', 'active')->where('expires_at', '>', now())->count(),
            'sold' => EquipmentMarketTransaction::count(),
            'gross' => (int) EquipmentMarketTransaction::sum('sale_price'),
            'fees' => (int) EquipmentMarketTransaction::sum('fee_amount'),
            'average' => (int) EquipmentMarketTransaction::avg('sale_price'),
        ];
        $byRank = EquipmentMarketListing::select('weapon_rank', DB::raw('COUNT(*) as count'))->where('status', 'sold')->groupBy('weapon_rank')->orderByDesc('count')->get();
        $listings = EquipmentMarketListing::with(['seller', 'buyer'])->latest()->limit(100)->get();
        return view('admin.equipment-market.index', compact('summary', 'byRank', 'listings'));
    }

    public function cancel(EquipmentMarketListing $listing, EquipmentMarketService $service, Request $request)
    {
        try { $service->adminCancelListing($listing); }
        catch (RuntimeException $e) { return redirect()->back()->with('error', $e->getMessage()); }
        return redirect()->back()->with('status', '出品を運営取消し、武器を出品者へ返却しました。');
    }
}
