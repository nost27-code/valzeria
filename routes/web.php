<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Livewire\CharacterCreate;
use App\Livewire\MainScreen;
use App\Http\Controllers\BattleController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\GuildAssociationController;
use App\Http\Controllers\TavernController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChampBattleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\JobArtController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\NpcProcurementRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StarTreeTowerController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\TopPageAnalyticsController;

if (app()->environment('local')) {
Route::get('/debug-area', function () {
    $out = "=== All Areas ===\n";
    $areas = \App\Models\Area::orderBy('id')->get();
    foreach ($areas as $a) {
        $out .= "ID: {$a->id}, CityID: {$a->city_id}, Name: {$a->name}\n";
    }

    $out .= "\n=== Progress Exists For Area IDs ===\n";
    $progresses = \App\Models\CharacterAreaProgress::where('boss_defeated', 1)->select('area_id', 'character_id')->get();
    foreach ($progresses as $p) {
        $out .= "AreaID: {$p->area_id}, CharID: {$p->character_id}\n";
    }

    return nl2br($out);
});
}

Route::get('/', function () {
    $totalCharacters = \App\Models\User::count();
    $onlineCharacters = \App\Models\Character::where('last_seen_at', '>=', now()->subMinutes(5))
        ->orderBy('last_seen_at', 'desc')
        ->get(['name', 'last_seen_at']);
    $onlineCount = $onlineCharacters->count();
    $registrationOpen = app(\App\Services\GameSettingService::class)->getBool('auth.registration_open', true);
    $topPageVisit = app(\App\Services\TopPageAnalyticsService::class)->recordVisit(request());

    return view('welcome2', compact('totalCharacters', 'onlineCharacters', 'onlineCount', 'registrationOpen', 'topPageVisit'));
})->name('top');

Route::post('/top-analytics/event', [TopPageAnalyticsController::class, 'event'])
    ->name('top.analytics.event')
    ->middleware('throttle:120,1');

// 旧実験版URL: 正式採用に伴いTOPへ301リダイレクト（重複URLのSEO評価分散を防ぐ）
Route::redirect('/index2', '/', 301);
Route::redirect('/index2.html', '/', 301);

Route::get('/help', function (\App\Services\HelpContentService $helpContentService) {
    return view('help.index', [
        'helpContent' => $helpContentService->content(),
    ]);
})->name('help');

Route::get('/manifest.json', function () {
    return response()->file(public_path('manifest.json'), [
        'Content-Type' => 'application/manifest+json; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ]);
});

Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::get('/contact', [ContactController::class, 'show'])->name('legal.contact');
Route::post('/contact', [ContactController::class, 'store'])->name('legal.contact.store');
Route::view('/operator', 'legal.operator')->name('legal.operator');

if (app()->environment('local')) {
Route::get('/run-fix-seal', function () {
    try {
        ob_start();
        require base_path('fix_seal_item_type.php');
        $output = ob_get_clean();
        return nl2br($output);
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});

Route::get('/run-cleanup', function () {
    try {
        ob_start();
        require base_path('cleanup_drops_and_items.php');
        $output = ob_get_clean();
        return nl2br($output);
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});

Route::get('/dev/debug-recipes', function () {
    $recipes = \App\Models\Recipe::with('resultItem')->get();
    $out = "=== Recipe result_item 診断 ===\n\n";
    $nullCount = 0;
    foreach ($recipes as $r) {
        if (!$r->resultItem) {
            $nullCount++;
            $out .= "[NG] レシピ: {$r->name}\n";
            $out .= "     result_item_id: " . ($r->result_item_id ?? 'NULL') . "\n";
            $out .= "     result_item_name: {$r->result_item_name}\n";
            $byName = \App\Models\Item::where('name', $r->result_item_name)->first();
            if ($byName) {
                $out .= "     → DBには存在: ID={$byName->id}, type={$byName->type}, def={$byName->def_bonus}, str={$byName->str_bonus}\n";
            } else {
                $out .= "     → DB上に同名アイテムなし\n";
            }
        } else {
            $item = $r->resultItem;
            $out .= "[OK] {$r->name} → {$item->name} (ID={$item->id}, def={$item->def_bonus}, str={$item->str_bonus})\n";
        }
    }
    $out .= "\n合計NGレシピ数: {$nullCount}";
    return nl2br($out);
});

Route::get('/dev/fix-recipe-links', function () {
    $recipes = \App\Models\Recipe::whereNull('result_item_id')->get();
    $fixed = 0;
    $failed = 0;
    $out = "=== result_item_id 修復 ===\n\n";
    foreach ($recipes as $r) {
        $item = \App\Models\Item::where('name', $r->result_item_name)->first();
        if ($item) {
            $r->result_item_id = $item->id;
            $r->save();
            $fixed++;
            $out .= "[FIXED] {$r->name} → ID={$item->id} ({$item->name})\n";
        } else {
            $failed++;
            $out .= "[FAIL] {$r->name} → '{$r->result_item_name}' が見つからない\n";
        }
    }
    $out .= "\n修復成功: {$fixed}件, 失敗: {$failed}件";
    return nl2br($out);
});

Route::get('/dev/fix-accessory-stats', function () {
    $file = base_path('docs/sousyoku_tmp.md');
    if (!file_exists($file)) {
        return "docs/sousyoku_tmp.md が見つかりません";
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $header = explode("\t", $lines[0]);
    $nameIdx = array_search('完成品名', $header);
    $hpIdx   = array_search('HP', $header);
    $mpIdx   = array_search('MP', $header);
    $atkIdx  = array_search('ATK', $header);
    $defIdx  = array_search('DEF', $header);
    $magIdx  = array_search('MAG', $header);
    $sprIdx  = array_search('SPR', $header);
    $spdIdx  = array_search('SPD', $header);
    $lukIdx  = array_search('LUK', $header);

    $updated = 0;
    $notFound = 0;
    $out = "=== 装飾品ステータス修復 ===\n\n";

    for ($i = 1; $i < count($lines); $i++) {
        $p = explode("\t", $lines[$i]);
        if (count($p) < 21) continue;
        $name = trim($p[$nameIdx]);
        if (!$name) continue;

        $item = \App\Models\Item::where('name', $name)->where('type', 'accessory')->first();
        if ($item) {
            $item->hp_bonus  = (int)$p[$hpIdx];
            $item->mp_bonus  = (int)$p[$mpIdx];
            $item->str_bonus = (int)$p[$atkIdx];
            $item->def_bonus = (int)$p[$defIdx];
            $item->mag_bonus = (int)$p[$magIdx];
            $item->spr_bonus = (int)$p[$sprIdx];
            $item->agi_bonus = (int)$p[$spdIdx];
            $item->luk_bonus = (int)$p[$lukIdx];
            $item->save();
            $updated++;
            $out .= "[OK] {$name}: MP={$item->mp_bonus}, MAG={$item->mag_bonus}, SPR={$item->spr_bonus}, SPD={$item->agi_bonus}, LUK={$item->luk_bonus}\n";
        } else {
            $notFound++;
            $out .= "[NG] {$name} → DB上に見つからず\n";
        }
    }
    $out .= "\n更新成功: {$updated}件, 見つからず: {$notFound}件";
    return nl2br($out);
});
}


Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
Route::get('/login', [AuthController::class, 'showEmailLoginForm'])->name('auth.email.login');
Route::post('/login', [AuthController::class, 'emailLogin'])->name('auth.email.login.submit');
Route::get('/register', [AuthController::class, 'showEmailRegisterForm'])->name('auth.email.register');
Route::post('/register', [AuthController::class, 'emailRegister'])->name('auth.email.register.submit');
if (app()->environment('local')) {
    Route::get('/auth/mock-login', [AuthController::class, 'mockLogin'])->name('auth.mock'); // モックログイン用
}
Route::post('/auth/guest-login', [AuthController::class, 'guestLogin'])->name('auth.guest'); // ゲストログイン用
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
Route::get('/auth/logout', fn () => redirect()->route('top'))->name('auth.logout.get');

// 認証が必要なルート
Route::middleware('auth')->group(function () {
    // キャラクター未選択でもアクセス可能なルート
    Route::get('/character/select', \App\Livewire\CharacterSelect::class)->name('character.select');
    Route::get('/character/create', CharacterCreate::class)->name('character.create');
    Route::get('/account/delete', [AccountController::class, 'deleteConfirm'])->name('account.delete');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // キャラクターの選択が必須なルート
    Route::middleware(\App\Http\Middleware\CheckCharacterSelected::class)->group(function () {
        Route::get('/battle/result', [BattleController::class, 'showResult'])->name('battle.result');
        Route::get('/battle/pvp-result', [BattleController::class, 'showPvpResult'])->name('battle.pvp_result');
        
        // ランダムな相手に挑む
        Route::post('/battle/pvp-random', [BattleController::class, 'randomPvp'])->name('battle.pvp_random');

        Route::get('/battle/resume', [BattleController::class, 'resumeExploration'])->name('battle.resume');
        Route::post('/battle/resume/return', [BattleController::class, 'abandonInterruptedExploration'])->name('battle.resume.return');
        Route::get('/battle/areas/{area}/explore', [BattleController::class, 'exploreGetFallback'])->name('battle.explore.fallback');
        Route::post('/battle/discovered-areas/{area}/travel', [BattleController::class, 'travelDiscoveredArea'])->name('battle.discovered_area.travel');
        Route::post('/battle/areas/{area}/explore', [BattleController::class, 'explore'])->name('battle.explore');
        Route::post('/battle/areas/{area}/depth-record', [BattleController::class, 'recordDepthEntrance'])->name('battle.depth.record');
        Route::post('/battle/areas/{area}/depth-retreat', [BattleController::class, 'retreatDepthEntrance'])->name('battle.depth.retreat');
        Route::get('/battle/sub-area-entries/{discovery}/confirm', [BattleController::class, 'confirmSubArea'])->name('battle.sub_area.confirm');
        Route::post('/battle/sub-area-entries/{discovery}/explore', [BattleController::class, 'exploreSubArea'])->name('battle.sub_area.explore');
        Route::post('/battle/areas/{area}/boss', [BattleController::class, 'boss'])->name('battle.boss');
        Route::post('/battle/return', [BattleController::class, 'returnToTown'])->name('battle.return');
        Route::post('/exploration/items/{item}/use', [\App\Http\Controllers\ExplorationItemController::class, 'use'])->name('exploration.items.use');
        Route::post('/battle/pvp/{targetCharacter}', [BattleController::class, 'pvp'])->name('battle.pvp');
        Route::get('/champ/confirm', [ChampBattleController::class, 'confirm'])->name('champ.confirm');
        Route::post('/champ/challenge', [ChampBattleController::class, 'challenge'])->name('champ.challenge');
        Route::get('/champ/result', [ChampBattleController::class, 'result'])->name('champ.result');
        Route::post('/inn/rest', [\App\Http\Controllers\InnController::class, 'rest'])->name('inn.rest');
        Route::get('/inn/rescue', [\App\Http\Controllers\InnController::class, 'rescue'])->name('inn.rescue');
        Route::get('/inn/rescue-refused', [\App\Http\Controllers\InnController::class, 'rescueRefused'])->name('inn.rescue-refused');
        Route::get('/tavern', [TavernController::class, 'index'])->name('tavern.index');
        Route::get('/tavern/npcs/{npc}/talk', [TavernController::class, 'talk'])->name('tavern.talk');
        Route::get('/tavern/roster', [TavernController::class, 'roster'])->name('tavern.roster');
        Route::get('/tavern/roster/{npc}', [TavernController::class, 'rosterDetail'])->name('tavern.roster.detail');
        Route::get('/home', MainScreen::class)->name('home');
        Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::post('/profile/edit', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/frame/compress', [ProfileController::class, 'compressFrameMaterial'])->name('profile.frame.compress');
        Route::post('/profile/frame/unlock', [ProfileController::class, 'unlockFrame'])->name('profile.frame.unlock');

        // ヴァルモン
        Route::get('/valmons/starter', [\App\Http\Controllers\ValmonController::class, 'starter'])->name('valmons.starter');
        Route::post('/valmons/starter', [\App\Http\Controllers\ValmonController::class, 'chooseStarter'])->name('valmons.starter.choose');
        Route::get('/valmons', [\App\Http\Controllers\ValmonController::class, 'index'])->name('valmons.index');
        Route::post('/valmons/background', [\App\Http\Controllers\ValmonController::class, 'updateBackground'])->name('valmons.background');
        Route::post('/valmons/{valmon}/nickname', [\App\Http\Controllers\ValmonController::class, 'updateNickname'])->name('valmons.nickname');
        Route::post('/valmons/{valmon}/partner', [\App\Http\Controllers\ValmonController::class, 'setPartner'])->name('valmons.partner');
        Route::post('/valmons/{valmon}/feed/materials/{characterMaterial}', [\App\Http\Controllers\ValmonController::class, 'feedMaterial'])->name('valmons.feed.material');
        Route::post('/valmons/{valmon}/feed/equipment/{characterItem}', [\App\Http\Controllers\ValmonController::class, 'feedEquipment'])->name('valmons.feed.equipment');
        Route::post('/valmons/{valmon}/feed/equipment-bulk', [\App\Http\Controllers\ValmonController::class, 'feedEquipmentBulk'])->name('valmons.feed.equipment.bulk');

        // 武器屋
        Route::get('/shop/equipment', [ShopController::class, 'equipment'])->name('shop.equipment');
        Route::get('/shop/weapons', [ShopController::class, 'weapons'])->name('shop.weapons');
        Route::get('/shop/armors', [ShopController::class, 'armors'])->name('shop.armors');
        Route::get('/shop/accessories', [ShopController::class, 'accessories'])->name('shop.accessories');
        Route::get('/shop/items', [ShopController::class, 'items'])->name('shop.items');
        Route::post('/shop/items/claim-all', [ShopController::class, 'claimAllSupplies'])->name('shop.items.claim_all');
        Route::post('/shop/items/{item}/claim', [ShopController::class, 'claimSupply'])->name('shop.items.claim');
        Route::post('/shop/items/{item}/buy', [ShopController::class, 'buy'])->name('shop.buy');

        // 装備変更
        Route::get('/equipment', [EquipmentController::class, 'index'])->name('equipment.index');
        Route::post('/equipment/{characterItem}/equip', [EquipmentController::class, 'equip'])->name('equipment.equip');
        Route::post('/equipment/{characterItem}/unequip', [EquipmentController::class, 'unequip'])->name('equipment.unequip');
        Route::post('/equipment/{characterItem}/lock', [EquipmentController::class, 'toggleLock'])->name('equipment.lock');
        Route::post('/equipment/{characterItem}/store', [EquipmentController::class, 'store'])->name('equipment.store');
        Route::post('/equipment/{characterItem}/unstore', [EquipmentController::class, 'unstore'])->name('equipment.unstore');
        Route::post('/equipment/{characterItem}/sell', [EquipmentController::class, 'sellStored'])->name('equipment.sell');
        Route::post('/equipment/{characterItem}/disassemble', [EquipmentController::class, 'disassemble'])->name('equipment.disassemble');
        
        // 持ち物
        Route::get('/inventory', [App\Http\Controllers\InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/inventory/sell', [App\Http\Controllers\InventoryController::class, 'sell'])->name('inventory.sell');
        Route::post('/inventory/support-items/{itemKey}/use', [App\Http\Controllers\InventoryController::class, 'useSupportItem'])->name('inventory.support-items.use');
        Route::delete('/inventory/materials/{characterMaterial}', [App\Http\Controllers\InventoryController::class, 'discardMaterial'])->name('inventory.materials.discard');

        // 銀行
        Route::get('/bank', [BankController::class, 'index'])->name('bank.index');
        Route::post('/bank/deposit', [BankController::class, 'deposit'])->name('bank.deposit');
        Route::post('/bank/withdraw', [BankController::class, 'withdraw'])->name('bank.withdraw');

        // 印図鑑
        Route::get('/monster-marks', [\App\Http\Controllers\MonsterMarkController::class, 'index'])->name('monster-marks.index');
        Route::get('/item-book', [\App\Http\Controllers\ItemBookController::class, 'index'])->name('item-book.index');

        // 能力割振り
        Route::get('/bonus-points', [\App\Http\Controllers\BonusPointController::class, 'index'])->name('bonus-points.index');
        Route::post('/bonus-points/allocate', [\App\Http\Controllers\BonusPointController::class, 'allocate'])->name('bonus-points.allocate');

        // 転職所 (Livewire)
        Route::get('/jobs', \App\Livewire\JobChange::class)->name('jobs.index');
        Route::get('/job-arts', [JobArtController::class, 'index'])->name('job-arts.index');
        Route::post('/job-arts/set', [JobArtController::class, 'set'])->name('job-arts.set');
        Route::post('/job-arts/assign', [JobArtController::class, 'assign'])->name('job-arts.assign');
        Route::post('/job-arts/slot', [JobArtController::class, 'slotSet'])->name('job-arts.slot-set');
        Route::post('/job-arts/policy', [JobArtController::class, 'policy'])->name('job-arts.policy');

        // 鍛冶屋・合成屋
        Route::get('/blacksmith', [\App\Http\Controllers\SmithController::class, 'enhanceIndex'])->name('blacksmith.index');
        Route::post('/blacksmith/{characterItem}/enhance', [\App\Http\Controllers\SmithController::class, 'enhance'])->name('blacksmith.enhance');
        Route::get('/smith', [\App\Http\Controllers\SmithController::class, 'index'])->name('smith.index');
        Route::get('/smith/source-area/{area}', [\App\Http\Controllers\SmithController::class, 'sourceArea'])->name('smith.source-area');
        Route::post('/smith/craft', [\App\Http\Controllers\SmithController::class, 'craft'])->name('smith.craft');
        Route::get('/smith/disassemble', [\App\Http\Controllers\SmithController::class, 'disassembleIndex'])->name('smith.disassemble.index');
        Route::post('/smith/disassemble/{characterItem}', [\App\Http\Controllers\SmithController::class, 'disassemble'])->name('smith.disassemble');

        // 素材交換所
        Route::get('/material-exchange', [\App\Http\Controllers\MaterialExchangeController::class, 'index'])->name('material-exchange.index');
        Route::post('/material-exchange', [\App\Http\Controllers\MaterialExchangeController::class, 'exchange'])->name('material-exchange.exchange');
        Route::post('/material-exchange/bulk', [\App\Http\Controllers\MaterialExchangeController::class, 'bulkExchange'])->name('material-exchange.bulk');

        // 星樹の塔
        Route::get('/tower/star-tree', [StarTreeTowerController::class, 'index'])->name('tower.star-tree.index');
        Route::post('/tower/star-tree/start', [StarTreeTowerController::class, 'start'])->name('tower.star-tree.start');
        Route::post('/tower/star-tree/restart', [StarTreeTowerController::class, 'restart'])->name('tower.star-tree.restart');
        Route::post('/tower/star-tree/challenge', [StarTreeTowerController::class, 'challenge'])->name('tower.star-tree.challenge');
        Route::post('/tower/star-tree/return', [StarTreeTowerController::class, 'return'])->name('tower.star-tree.return');
        Route::post('/tower/star-tree/merchant/resume', [StarTreeTowerController::class, 'resumeMerchant'])->name('tower.star-tree.merchant.resume');
        Route::post('/tower/star-tree/merchant/buy', [StarTreeTowerController::class, 'buyMerchantItem'])->name('tower.star-tree.merchant.buy');
        Route::post('/tower/star-tree/merchant/items/{purchase}/use', [StarTreeTowerController::class, 'useMerchantItem'])->name('tower.star-tree.merchant.use');
        Route::post('/tower/star-tree/merchant/skip', [StarTreeTowerController::class, 'skipMerchant'])->name('tower.star-tree.merchant.skip');
        Route::get('/tower/star-tree/result/{event}', [StarTreeTowerController::class, 'result'])->name('tower.star-tree.result');
        Route::get('/tower/star-tree/ranking', [StarTreeTowerController::class, 'ranking'])->name('tower.star-tree.ranking');

        // 冒険者市場
        Route::get('/market', [MarketController::class, 'index'])->name('market.index');
        Route::get('/market/materials/{material}', [MarketController::class, 'showMaterial'])->name('market.materials.show');
        Route::post('/market/materials/list', [MarketController::class, 'listMaterial'])->name('market.materials.list');
        Route::post('/market/materials/buy', [MarketController::class, 'buyMaterial'])->name('market.materials.buy');
        Route::post('/market/listings/{listing}/cancel', [MarketController::class, 'cancelListing'])->name('market.listings.cancel');
        Route::get('/market/npc-requests', [NpcProcurementRequestController::class, 'index'])->name('market.npc-requests.index');
        Route::get('/market/npc-requests/{npcProcurementRequest}', [NpcProcurementRequestController::class, 'show'])->name('market.npc-requests.show');
        Route::post('/market/npc-requests/materials/{requestMaterial}/deliver', [NpcProcurementRequestController::class, 'deliver'])->name('market.npc-requests.deliver');

        // ランキング
        Route::get('/colosseum/ranking', \App\Livewire\ColosseumRanking::class)->name('colosseum.ranking');
        Route::get('/ranking', [\App\Http\Controllers\RankingController::class, 'index'])->name('ranking.index');

        // 称号一覧
        Route::get('/titles', [TitleController::class, 'index'])->name('titles.index');

        // 案内所
        Route::get('/guide', [\App\Http\Controllers\TownGuideController::class, 'index'])->name('town.guide');

        // 冒険者協会
        Route::get('/association', [GuildAssociationController::class, 'index'])->name('association.index');
        Route::post('/association/donate', [GuildAssociationController::class, 'donate'])->name('association.donate');

        // 手紙箱 (Livewire)
        Route::get('/message', \App\Livewire\MessageBox::class)->name('message.index');

        // 街の移動
        Route::get('/city', [CityController::class, 'index'])->name('city.index');
        Route::post('/city/{city}/travel', [CityController::class, 'travel'])->name('city.travel');
    });
});

// Stripe Webhook（CSRF除外済み）
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook');

// 輝石ショップ（認証必須）
Route::middleware('auth')->group(function () {
    Route::get('/kiseki/shop', [\App\Http\Controllers\KisekiShopController::class, 'index'])
        ->name('kiseki.shop');
    Route::get('/kiseki/support', [\App\Http\Controllers\KisekiShopController::class, 'supportShop'])
        ->name('kiseki.support');
    Route::post('/kiseki/checkout', [\App\Http\Controllers\KisekiShopController::class, 'createCheckout'])
        ->name('kiseki.checkout');
    Route::post('/kiseki/support/purchase', [\App\Http\Controllers\KisekiShopController::class, 'purchaseSupport'])
        ->name('kiseki.support.purchase');
    Route::post('/kiseki/support/rescue-insurance/use', [\App\Http\Controllers\KisekiShopController::class, 'useRescueInsurance'])
        ->name('kiseki.support.rescue-insurance.use');
    Route::get('/kiseki/success', [\App\Http\Controllers\KisekiShopController::class, 'success'])
        ->name('kiseki.success');
    Route::get('/kiseki/cancel', [\App\Http\Controllers\KisekiShopController::class, 'cancel'])
        ->name('kiseki.cancel');
});

// 管理者ルート
use App\Http\Controllers\AdminAuthController;

Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
Route::get('/admin/tools/{asset}', function (string $asset) {
    abort_unless(in_array($asset, ['style.css', 'script.js'], true), 404);

    $headers = [
        'style.css' => ['Content-Type' => 'text/css; charset=UTF-8'],
        'script.js' => ['Content-Type' => 'application/javascript; charset=UTF-8'],
    ];

    return response()->file(public_path('admin/tools/' . $asset), $headers[$asset]);
})->where('asset', 'style\.css|script\.js')->name('admin.tools.asset');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin', \App\Livewire\Admin\AdminDashboard::class)->name('admin.dashboard');
    Route::get('/admin/contact-messages/badge-count', function () {
        $imported = null;
        $importError = null;

        try {
            $imported = app(\App\Services\ContactMailboxImportService::class)->import();
        } catch (\Throwable $e) {
            $importError = $e->getMessage();
        }

        $newCount = \Illuminate\Support\Facades\Schema::hasTable('contact_messages')
            ? \App\Models\ContactMessage::where('status', 'new')->count()
            : 0;

        return response()->json([
            'new_count' => $newCount,
            'imported' => $imported,
            'import_error' => $importError,
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    })->name('admin.contact-messages.badge-count');
    Route::get('/admin/world-metrics', \App\Livewire\Admin\WorldMetricsManager::class)->name('admin.world-metrics');
    Route::get('/admin/world-activity-map', \App\Livewire\Admin\WorldActivityMapManager::class)->name('admin.world-activity-map');
    Route::get('/admin/inn-analytics', \App\Livewire\Admin\InnAnalyticsManager::class)->name('admin.inn-analytics');
    Route::get('/admin/operator-analytics', \App\Livewire\Admin\OperatorAnalyticsManager::class)->name('admin.operator-analytics');
    Route::get('/admin/growth-analytics', \App\Livewire\Admin\GrowthAnalyticsManager::class)->name('admin.growth-analytics');
    Route::get('/admin/testers', \App\Livewire\Admin\TesterManager::class)->name('admin.testers');
    Route::get('/admin/items', \App\Livewire\Admin\ItemManager::class)->name('admin.items');
    Route::get('/admin/jobs', \App\Livewire\Admin\JobManager::class)->name('admin.jobs');
    Route::get('/admin/job-affinity', \App\Livewire\Admin\JobAffinityChecker::class)->name('admin.job-affinity');
    Route::get('/admin/equipment-compatibility', \App\Livewire\Admin\EquipmentCompatibilityManager::class)->name('admin.equipment-compatibility');
    Route::get('/admin/dungeon-enemies', \App\Livewire\Admin\DungeonEnemyManager::class)->name('admin.dungeon-enemies');
    Route::get('/admin/players', \App\Livewire\Admin\PlayerLogs::class)->name('admin.players');
    Route::get('/admin/user-investigation', \App\Livewire\Admin\UserInvestigationManager::class)->name('admin.user-investigation');
    Route::get('/admin/player-controls', \App\Livewire\Admin\PlayerControlManager::class)->name('admin.player-controls');
    Route::get('/admin/battle-simulator', \App\Livewire\Admin\BattleSimulator::class)->name('admin.battle-simulator');
    Route::get('/admin/balance-battle-lab', \App\Livewire\Admin\BalanceBattleLab::class)->name('admin.balance-battle-lab');
    Route::get('/admin/skill-effect-lab', \App\Livewire\Admin\SkillEffectLab::class)->name('admin.skill-effect-lab');
    Route::get('/admin/action-logs', \App\Livewire\Admin\ActionLogManager::class)->name('admin.action-logs');
    Route::get('/admin/public-logs', \App\Livewire\Admin\PublicLogManager::class)->name('admin.public-logs');
    Route::get('/admin/chat', \App\Livewire\Admin\AdminChatManager::class)->name('admin.chat');
    Route::get('/admin/private-chat-logs', \App\Livewire\Admin\PrivateChatLogManager::class)->name('admin.private-chat-logs');
    Route::get('/admin/contact-messages', \App\Livewire\Admin\ContactMessageManager::class)->name('admin.contact-messages');
    Route::get('/admin/npc-market-analytics', \App\Livewire\Admin\NpcMarketAnalyticsManager::class)->name('admin.npc-market-analytics');
    Route::get('/admin/reward-settings', \App\Livewire\Admin\RewardSettingManager::class)->name('admin.reward-settings');
    Route::get('/admin/adventure-support-items', \App\Livewire\Admin\AdventureSupportItemManager::class)->name('admin.adventure-support-items');
    Route::get('/admin/extra-contents', \App\Livewire\Admin\ExtraContentManager::class)->name('admin.extra-contents');
    Route::get('/admin/kiseki-purchases', \App\Livewire\Admin\KisekiPurchaseManager::class)->name('admin.kiseki-purchases');
    Route::get('/admin/top-updates', \App\Livewire\Admin\TopUpdateManager::class)->name('admin.top-updates');
    Route::get('/admin/top-analytics', \App\Livewire\Admin\TopPageAnalyticsManager::class)->name('admin.top-analytics');
    Route::get('/admin/game-texts', \App\Livewire\Admin\GameTextManager::class)->name('admin.game-texts');
    Route::get('/admin/help-texts', \App\Livewire\Admin\HelpTextManager::class)->name('admin.help-texts');
    Route::get('/admin/facility-texts', \App\Livewire\Admin\FacilityTextManager::class)->name('admin.facility-texts');
    Route::get('/admin/tools/remover.html', function () {
        return response()->file(public_path('admin/tools/remover.html'));
    })->name('admin.tools.remover');
    Route::get('/admin/tools', \App\Livewire\Admin\ToolCollection::class)->name('admin.tools');
});

// 本本番環境初回DB構築用の一時ルートは削除済み

if (app()->environment('local')) {
Route::get('/dev/execute-update', function () {
    try {
        $out = "Running migrations...<br>";
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running WeaponsSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'WeaponsSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running JobSystemSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'JobSystemSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running ArmorsSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'ArmorsSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running AllDungeonsSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'AllDungeonsSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running basic jobs fix...<br>";
        if (file_exists(base_path('fix_basic_jobs.php'))) {
            ob_start();
            require_once base_path('fix_basic_jobs.php');
            $out .= nl2br(ob_get_clean()) . "<br>";
            $out .= "Basic jobs fix completed.<br>";
        } else {
            $out .= "fix_basic_jobs.php not found.<br>";
        }
        
        $out .= "Running EnemySeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'EnemySeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running BossSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'BossSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running MaterialSeeder...<br>";
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'MaterialSeeder', '--force' => true]);
        $out .= nl2br(\Illuminate\Support\Facades\Artisan::output()) . "<br>";

        $out .= "Running enemies data update...<br>";
        if (file_exists(base_path('update_enemies_data.php'))) {
            ob_start();
            require_once base_path('update_enemies_data.php');
            $out .= nl2br(ob_get_clean()) . "<br>";
            $out .= "Enemies data update completed.<br>";
        } else {
            $out .= "update_enemies_data.php not found.<br>";
        }

        $out .= "Running enemy drops update for armors...<br>";
        if (file_exists(base_path('update_enemy_drops.php'))) {
            ob_start();
            require_once base_path('update_enemy_drops.php');
            $out .= nl2br(ob_get_clean()) . "<br>";
            $out .= "Enemy drops update completed.<br>";
        } else {
            $out .= "update_enemy_drops.php not found.<br>";
        }

        // レシピのresult_item_idを自動修復（シーダー後にNULLになるのを防ぐ）
        $out .= "Fixing recipe links...<br>";
        $recipes = \App\Models\Recipe::whereNull('result_item_id')->get();
        $fixed = 0;
        foreach ($recipes as $r) {
            $item = \App\Models\Item::where('name', $r->result_item_name)->first();
            if ($item) {
                $r->result_item_id = $item->id;
                $r->save();
                $fixed++;
            }
        }
        $out .= "Recipe links fixed: {$fixed} items.<br>";

        $out .= "Update complete.<br>";

        return $out;
    } catch (\Exception $e) {
        return "Error occurred: " . $e->getMessage() . "<br>" . nl2br($e->getTraceAsString());
    }
});

Route::get('/dev/cleanup-materials', function () {
    $out = "=== Cleanup Materials ===<br>";
    $orphans = \App\Models\CharacterMaterial::whereDoesntHave('material')->get();
    $count = $orphans->count();

    if ($count > 0) {
        foreach($orphans as $orphan) {
            $out .= "Deleting orphaned character_material ID: {$orphan->id} (material_id: {$orphan->material_id})<br>";
            $orphan->delete();
        }
        $out .= "<br>Deleted {$count} orphaned records.<br>";
    } else {
        $out .= "No orphaned records found.<br>";
    }
    
    return $out;
});



Route::get('/dev/temp-delete-generics', function () {
    try {
        $validWeaponIds = [];
        foreach (file(base_path('docs/weapon_master.tsv')) as $i => $l) {
            if ($i === 0) continue;
            $p = explode("\t", trim($l));
            if (isset($p[0]) && is_numeric($p[0])) {
                $validWeaponIds[] = (int)$p[0];
            }
        }

        $validArmorIds = [];
        foreach (file(base_path('docs/armor_master.tsv')) as $i => $l) {
            if ($i === 0) continue;
            $p = explode("\t", trim($l));
            if (isset($p[0]) && is_numeric($p[0])) {
                $validArmorIds[] = (int)$p[0];
            }
        }

        // Weapons to delete
        $dbWeapons = \App\Models\Item::where('type', 'weapon')->pluck('id')->toArray();
        $weaponsToDelete = array_diff($dbWeapons, $validWeaponIds);

        // Armors to delete
        $dbArmors = \App\Models\Item::where('type', 'armor')->pluck('id')->toArray();
        $armorsToDelete = array_diff($dbArmors, $validArmorIds);

        $toDeleteIds = array_merge($weaponsToDelete, $armorsToDelete);

        if (!empty($toDeleteIds)) {
            $c = \App\Models\CharacterItem::whereIn('item_id', $toDeleteIds)->delete();
            $i = \App\Models\Item::whereIn('id', $toDeleteIds)->delete();
            return "Deleted $c character_items and $i items! Deleted IDs: " . implode(',', $toDeleteIds);
        } else {
            return "No items to delete!";
        }
    } catch (\Throwable $e) {
        return "Error: " . $e->getMessage();
    }
});

Route::get('/dev/debug-weapon-stats', function() {
    $item = \App\Models\Item::where('name', '庭園の騎士の剣')->first();
    if (!$item) return "Weapon not found";

    $out = "Weapon: {$item->name}<br>";
    $out .= "STR Bonus: {$item->str_bonus}<br>";
    $out .= "AGI Bonus: {$item->agi_bonus}<br>";
    $out .= "Price: {$item->price}<br>";
    $out .= "Required Level: {$item->required_level}<br>";

    // weapons_data.tsvの存在確認
    $tsvPath = base_path('weapons_data.tsv');
    $out .= "weapons_data.tsv exists: " . (file_exists($tsvPath) ? 'YES' : 'NO') . "<br>";
    if (file_exists($tsvPath)) {
        $out .= "weapons_data.tsv size: " . filesize($tsvPath) . " bytes<br>";
        $lines = file($tsvPath);
        $out .= "weapons_data.tsv lines: " . count($lines) . "<br>";
    }

    return $out;
});
}
