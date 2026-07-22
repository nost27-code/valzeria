<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlayerShop;
use Illuminate\Http\Request;

class PlayerShopAdminController extends Controller
{
    public function index(Request $request)
    {
        $shops = PlayerShop::query()->with('character')->withCount(['materialListings', 'equipmentListings', 'eggListings', 'favorites'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', '%' . $request->string('q') . '%')->orWhereHas('character', fn ($cq) => $cq->where('name', 'like', '%' . $request->string('q') . '%'))))
            ->latest()->paginate(50)->withQueryString();
        return view('admin.player-shops.index', compact('shops'));
    }

    public function update(Request $request, PlayerShop $shop)
    {
        $data = $request->validate(['status' => ['required', 'in:open,suspended,closed']]);
        $shop->update(['status' => $data['status']]);
        return back()->with('status', '商店の営業状態を更新しました。');
    }
}
