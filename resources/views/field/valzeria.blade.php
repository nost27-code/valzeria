@php
    // モジュールはすべて更新時刻つきの URL で読む（相対 import も importmap で版を付ける）
    $fieldModules = [];
    foreach (glob(public_path('js/field/*.js')) as $file) {
        $url = asset('js/field/'.basename($file));
        $fieldModules[$url] = $url.'?v='.filemtime($file);
    }
    // 画像素材（public/images/field/*.webp）。更新した画像だけ新しいURLで読み込む
    $fieldAssetKeys = [];
    $fieldAssetVersions = [];
    foreach (glob(public_path('images/field/*.webp')) ?: [] as $path) {
        $key = pathinfo($path, PATHINFO_FILENAME);
        if (str_starts_with($key, 'terrain-')) continue; // 地形原画は別途まとめて読む
        $fieldAssetKeys[] = $key;
        $fieldAssetVersions[$key] = filemtime($path);
    }
    $terrainAtlases = [];
    foreach (['plains', 'climate', 'settlement', 'cliff'] as $group) {
        $path = public_path("images/field/terrain-{$group}.webp");
        if (is_file($path)) {
            $terrainAtlases[$group] = asset("images/field/terrain-{$group}.webp").'?v='.filemtime($path);
        }
    }
    $spectator = (bool) ($spectator ?? false);
    $walkingOnly = (bool) ($walkingOnly ?? false);
    $interactive = ! $spectator && ! $walkingOnly;
    $fieldFlash = array_values(array_filter([$fieldNotice ?? null, session('error'), session('success'), session('message')], fn ($m) => is_string($m) && $m !== ''));
    $fieldBoot = [
        'world' => $world,
        'player' => $player,
        'csrf' => csrf_token(),
        'assetKeys' => $fieldAssetKeys,
        'assetVersions' => $fieldAssetVersions,
        'terrainAtlases' => $terrainAtlases,
        'spectator' => $spectator,
        'walkingOnly' => $walkingOnly,
        'nationBuilding' => $interactive && (bool) config('features.nation_community_enabled'),
        'flash' => array_map(fn (string $m): string => strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $m)), $fieldFlash),
        'urls' => [
            'sync' => $spectator ? route('admin.field.presence') : route('field.sync'),
            'nations' => $interactive ? route('field.nations') : '',
            'nationEditor' => $interactive ? route('field.nation.editor') : '',
            'nationSite' => $interactive ? route('field.nation.site') : '',
            'nationPreview' => $interactive ? route('field.nation.preview') : '',
            'nationSave' => $interactive ? route('field.nation.save') : '',
            'facility' => $interactive ? url('/field/cities') : '',
            'area' => $interactive ? url('/field/areas') : '',
            'teleport' => $interactive ? url('/field/teleporters') : '',
            'spot' => $interactive ? url('/field/spots') : '',
            'chat' => $interactive ? route('field.chat') : '',
            'home' => $spectator ? route('admin.dashboard') : route('home'),
            'assets' => asset('images/field'),
            'worldMap' => asset('images/field/valzeria-worldmap.webp').'?v='.(is_file(public_path('images/field/valzeria-worldmap.webp')) ? filemtime(public_path('images/field/valzeria-worldmap.webp')) : 0),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $spectator ? 'フィールド観察' : 'ヴァルゼリア大陸' }} - ヴァルゼリアの冒険者</title>
    <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
    <script type="importmap">{!! json_encode(['imports' => $fieldModules], JSON_UNESCAPED_SLASHES) !!}</script>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; overflow: hidden; background: #10141c; font-family: "Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", sans-serif; color: #f4efe0; touch-action: none; -webkit-user-select: none; user-select: none; }
        #field-canvas { position: fixed; inset: 0; display: block; }
        .panel { background: rgba(16, 20, 32, 0.78); border: 2px solid #c8a860; border-radius: 8px; box-shadow: 0 0 0 2px rgba(0,0,0,0.5), 0 6px 18px rgba(0,0,0,0.4); }
        #hud { position: fixed; top: max(10px, env(safe-area-inset-top)); left: 10px; padding: 8px 14px; min-width: 180px; }
        #hud-place { font-size: 17px; font-weight: bold; letter-spacing: 0.08em; color: #fff4c8; }
        #hud-coords { font-size: 11px; color: #b8c0d0; margin-top: 2px; }
        #minimap-wrap { position: fixed; top: max(10px, env(safe-area-inset-top)); right: 10px; padding: 4px; }
        #minimap { display: block; width: 132px; height: 132px; border-radius: 4px; image-rendering: pixelated; }
        #buttons { position: fixed; right: 10px; top: calc(max(10px, env(safe-area-inset-top)) + 152px); display: flex; flex-direction: column; gap: 8px; transition: opacity 0.25s; }
        #minimap-wrap { transition: opacity 0.25s; }
        body.is-walking #buttons, body.is-walking #minimap-wrap { opacity: 0.04; pointer-events: none; }
        .btn { appearance: none; border: 2px solid #c8a860; background: rgba(16, 20, 32, 0.82); color: #fff4c8; font: inherit; font-size: 13px; font-weight: bold; padding: 8px 12px; border-radius: 8px; cursor: pointer; text-decoration: none; text-align: center; min-width: 88px; }
        .btn:hover { background: rgba(60, 50, 30, 0.9); }
        .zoom-buttons { display: flex; gap: 6px; }
        .zoom-buttons .btn { min-width: 0; flex: 1; font-size: 18px; padding: 4px 0; }
        .btn.is-on { background: #c8a860; color: #1a1410; }
        #prompt { position: fixed; left: 50%; bottom: calc(max(24px, env(safe-area-inset-bottom)) + 12px); transform: translateX(-50%); padding: 10px 18px; font-size: 15px; font-weight: bold; cursor: pointer; white-space: nowrap; }
        #prompt kbd { display: inline-block; margin-right: 8px; padding: 1px 6px; border: 1px solid #c8a860; border-radius: 4px; font-size: 11px; color: #c8a860; }
        #toast { position: fixed; left: 50%; top: 22%; transform: translateX(-50%); max-width: min(560px, 90vw); padding: 14px 20px; font-size: 15px; line-height: 1.7; }
        #dialog { position: fixed; inset: 0; z-index: 12; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.35); }
        #dialog[hidden], #worldmap[hidden], #loading[hidden], #toast[hidden], #prompt[hidden], #spectator-roster[hidden] { display: none; }
        #dialog .panel { width: min(420px, 90vw); padding: 18px 20px; }
        #dialog-title { font-size: 18px; font-weight: bold; color: #fff4c8; margin: 0 0 10px; }
        #dialog-body { font-size: 15px; line-height: 1.7; white-space: pre-line; margin: 0 0 16px; }
        .dialog-actions { display: flex; gap: 10px; justify-content: flex-end; }
        #worldmap { position: fixed; inset: 0; z-index: 10; overflow-y: auto; touch-action: pan-y; background: rgba(8, 10, 16, 0.96); display: flex; flex-direction: column; align-items: center; padding: 16px max(12px, env(safe-area-inset-right)) calc(16px + env(safe-area-inset-bottom)) max(12px, env(safe-area-inset-left)); gap: 10px; }
        .worldmap-header, .worldmap-layout { width: min(100%, 900px); }
        .worldmap-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .worldmap-header h2 { margin: 0; color: #fff4c8; font-size: 19px; }
        .worldmap-layout { display: grid; grid-template-columns: minmax(0, auto) minmax(260px, 1fr); justify-content: center; align-items: start; gap: 14px; }
        .worldmap-map { display: flex; flex-direction: column; align-items: center; gap: 8px; min-width: 0; }
        #worldmap-canvas { display: block; height: min(74vh, 680px); width: auto; max-width: 100%; aspect-ratio: 3 / 4; border: 2px solid #c8a860; border-radius: 8px; background: #24508e; cursor: grab; touch-action: none; }
        #worldmap-canvas.is-dragging { cursor: grabbing; }
        .worldmap-zoom { display: flex; align-items: center; gap: 6px; }
        .worldmap-zoom .btn { min-width: 48px; min-height: 40px; padding: 5px 10px; }
        #worldmap-zoom-label { min-width: 44px; text-align: center; font-size: 13px; color: #fff4c8; }
        .worldmap-panel { min-width: 0; display: flex; flex-direction: column; gap: 9px; }
        #worldmap-help { font-size: 12px; line-height: 1.5; color: #c8c0b0; }
        #worldmap-here { font-size: 15px; font-weight: bold; color: #fff4c8; }
        #worldmap-here-name { color: #ff8a8a; }
        .worldmap-filters { display: flex; gap: 6px; flex-wrap: wrap; }
        .worldmap-filter { min-width: 0; padding: 7px 10px; }
        .worldmap-filter[aria-pressed="true"] { background: #c8a860; color: #1a1410; }
        #worldmap-search { width: 100%; min-height: 40px; padding: 8px 10px; border: 1px solid #c8a860; border-radius: 8px; background: #161b27; color: #fff; font: inherit; }
        #worldmap-list { display: flex; flex-direction: column; gap: 5px; max-height: min(40vh, 330px); overflow-y: auto; touch-action: pan-y; padding: 2px; }
        .worldmap-destination { width: 100%; min-height: 40px; padding: 8px 10px; border: 1px solid #6f6245; border-radius: 6px; background: #1a2030; color: #fff4c8; text-align: left; font: inherit; cursor: pointer; }
        .worldmap-destination[aria-current="true"] { border-color: #ffe0a0; background: #50432a; }
        #worldmap-selected { min-height: 22px; color: #fff4c8; }
        #worldmap-travel { width: 100%; min-height: 44px; }
        #worldmap-travel:disabled { opacity: 0.5; cursor: default; }
        @media (max-width: 680px) {
            .worldmap-layout { grid-template-columns: minmax(0, 1fr); }
            #worldmap-canvas { width: min(100%, 600px); height: auto; }
            #worldmap-list { max-height: min(27vh, 250px); }
        }
        #loading { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; background: #10141c; font-size: 16px; letter-spacing: 0.1em; }
        #fade { position: fixed; inset: 0; background: #fff; opacity: 0; pointer-events: none; transition: opacity 0.35s; }
        #fade.is-on { opacity: 1; }
        /* スマホの A・B ボタン（タッチできる端末だけ） */
        #pad-buttons { position: fixed; right: max(16px, env(safe-area-inset-right)); bottom: calc(max(22px, env(safe-area-inset-bottom)) + 10px); display: none; align-items: flex-end; gap: 14px; }
        .pad-btn { appearance: none; width: 68px; height: 68px; border-radius: 50%; border: 2px solid #c8a860; background: rgba(24, 26, 40, 0.85); color: #fff4c8; font: inherit; font-size: 22px; font-weight: bold; line-height: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; touch-action: none; -webkit-touch-callout: none; }
        .pad-btn small { font-size: 10px; font-weight: normal; }
        .pad-btn.pad-a { margin-bottom: 34px; background: rgba(120, 40, 40, 0.88); }
        .pad-btn.pad-b { background: rgba(40, 60, 120, 0.88); }
        .pad-btn.is-on { filter: brightness(1.5); transform: scale(0.95); }
        body.is-touch #pad-buttons { display: flex; }
        body.is-touch #prompt { bottom: calc(max(24px, env(safe-area-inset-bottom)) + 112px); }
        body.is-touch #prompt kbd, body.is-touch #help { display: none; }
        body.is-touch #minimap { width: 104px; height: 104px; }
        body.is-touch #buttons { top: calc(max(10px, env(safe-area-inset-top)) + 124px); }
        .hud-vitals { margin-top: 6px; }
        .hud-hp { width: 100%; height: 7px; background: rgba(0,0,0,0.5); border-radius: 4px; overflow: hidden; }
        #hud-hp-bar { height: 100%; width: 100%; background: linear-gradient(90deg, #4ade80, #22c55e); transition: width 0.4s; }
        #hud-hp-bar.is-low { background: linear-gradient(90deg, #f87171, #ef4444); }
        .hud-vitals-text { display: flex; justify-content: space-between; gap: 10px; font-size: 11px; color: #d8dce8; margin-top: 3px; }
        #battle { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 12px; padding: 16px 12px calc(max(16px, env(safe-area-inset-bottom)) + 8px); background: radial-gradient(ellipse at 50% 35%, rgba(40,30,60,0.55), rgba(5,5,12,0.88)); }
        #battle[hidden], #battle-result[hidden], #battle-controls[hidden] { display: none; }
        #battle.is-flash { animation: battle-flash 0.5s ease-out; }
        @keyframes battle-flash { 0% { background-color: rgba(255,255,255,0.9); } 100% { background-color: transparent; } }
        .battle-scene { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 0; }
        #battle-enemy { max-height: min(38vh, 260px); max-width: 80vw; object-fit: contain; filter: drop-shadow(0 8px 12px rgba(0,0,0,0.6)); }
        #battle-enemy.is-hit { animation: battle-hit 0.35s; }
        @keyframes battle-hit { 0%, 100% { transform: translateX(0); filter: none; } 25% { transform: translateX(-8px); filter: brightness(2.2); } 60% { transform: translateX(6px); } }
        #battle-enemy-name { margin-top: 8px; font-size: 18px; font-weight: bold; color: #fff4c8; text-shadow: 0 2px 4px #000; }
        #battle-window, #battle-result { width: min(640px, 96vw); padding: 12px 16px; background: rgba(8, 10, 24, 0.94); border-color: #e8e4d8; }
        #battle-window { cursor: pointer; }
        .battle-head { display: flex; justify-content: space-between; font-size: 12px; color: #c8a860; margin-bottom: 6px; }
        #battle-log { min-height: 7.5em; max-height: 34vh; overflow-y: auto; font-size: 14px; line-height: 1.75; color: #f4f2ea; }
        #battle-log [class*="font-bold"], #battle-log [class*="font-extrabold"], #battle-log b, #battle-log strong { font-weight: bold; }
        #battle-log [class*="text-xl"], #battle-log [class*="text-lg"] { font-size: 1.12em; }
        #battle-log [class*="text-amber"], #battle-log [class*="text-yellow"], #battle-log [class*="text-orange"] { color: #ffd070; }
        #battle-log [class*="text-red"], #battle-log [class*="text-rose"], #battle-log .battle-log-enemy-action, #battle-log .battle-log-percent { color: #ff8a9a; }
        #battle-log [class*="text-blue"], #battle-log [class*="text-sky"], #battle-log [class*="text-cyan"] { color: #8ac8ff; }
        #battle-log [class*="text-green"], #battle-log [class*="text-emerald"], #battle-log [class*="text-teal"] { color: #8ae8a8; }
        #battle-log [class*="text-purple"], #battle-log [class*="text-violet"], #battle-log [class*="text-indigo"], #battle-log .battle-log-special-title { color: #c8a8ff; }
        #battle-log [class*="text-slate"], #battle-log [class*="text-gray"] { color: #b8bcc8; }
        #battle-log .text-black { color: #ffffff; }
        #battle-controls { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
        #battle-result-title { margin: 0 0 8px; font-size: 20px; font-weight: bold; color: #fff4c8; }
        #battle-result.is-lose #battle-result-title { color: #ff9a9a; }
        #battle-result ul { list-style: none; margin: 0 0 12px; padding: 0; font-size: 14px; line-height: 1.8; max-height: 36vh; overflow-y: auto; }
        #toast { white-space: pre-line; }
        /* 近くの人とのチャット */
        #chat-log { position: fixed; left: 10px; bottom: 34px; width: min(360px, 60vw); max-height: 30vh; overflow-y: auto; padding: 6px 10px; font-size: 13px; line-height: 1.6; background: rgba(12, 14, 24, 0.45); border-radius: 8px; opacity: 0.55; transition: opacity 0.4s; pointer-events: auto; }
        #chat-log.is-fresh, #chat-log:hover { opacity: 1; background: rgba(12, 14, 24, 0.72); }
        #chat-log[hidden], #chat-bar[hidden] { display: none; }
        .chat-line { overflow-wrap: anywhere; }
        .chat-name { color: #9ad8ff; font-weight: bold; margin-right: 6px; }
        .chat-name.is-self { color: #fff4a0; }
        .chat-tag { color: #ffc878; margin-right: 4px; font-size: 12px; }
        #chat-scope { min-width: 0; padding: 6px 10px; white-space: nowrap; }
        #chat-bar { position: fixed; left: 50%; bottom: calc(max(20px, env(safe-area-inset-bottom)) + 6px); transform: translateX(-50%); width: min(560px, 94vw); display: flex; gap: 8px; padding: 8px; z-index: 5; }
        #chat-input { flex: 1; min-width: 0; font: inherit; font-size: 16px; padding: 8px 10px; border-radius: 6px; border: 1px solid #c8a860; background: #0e1020; color: #fff; user-select: text; -webkit-user-select: text; }
        body.is-touch #chat-log { bottom: calc(max(18px, env(safe-area-inset-bottom)) + 112px); width: min(300px, 70vw); }
        body.is-touch #chat-bar { bottom: auto; top: calc(max(10px, env(safe-area-inset-top)) + 110px); }
        #help { position: fixed; left: 10px; bottom: 10px; font-size: 11px; color: rgba(230,225,210,0.75); text-shadow: 0 1px 2px #000; }
        #spectator-roster { position: fixed; z-index: 8; left: 10px; bottom: max(10px, env(safe-area-inset-bottom)); width: min(380px, calc(100vw - 20px)); max-height: min(62vh, 560px); overflow: hidden; padding: 12px; }
        .spectator-roster-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .spectator-roster-head h2 { margin: 0; font-size: 17px; color: #fff4c8; }
        #spectator-summary { margin-top: 4px; font-size: 11px; color: #d7c58e; }
        #spectator-list { display: flex; flex-direction: column; gap: 6px; max-height: min(48vh, 430px); overflow-y: auto; touch-action: pan-y; margin-top: 10px; }
        .spectator-player { appearance: none; width: 100%; display: grid; grid-template-columns: 44px minmax(0, 1fr) auto; align-items: center; gap: 8px; border: 1px solid rgba(200,168,96,0.55); border-radius: 7px; background: rgba(18,23,34,0.92); color: #f4efe0; padding: 7px; text-align: left; cursor: pointer; }
        .spectator-player:hover { background: rgba(65,53,31,0.94); }
        .spectator-player img { width: 44px; height: 44px; object-fit: contain; }
        .spectator-player strong, .spectator-player small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .spectator-player small { margin-top: 2px; color: #bbc3d2; }
        .spectator-player time { font-size: 11px; color: #d7c58e; white-space: nowrap; }
        body.is-spectator #hud-hp-bar, body.is-spectator .hud-vitals-text, body.is-spectator #btn-chat, body.is-spectator #btn-dash, body.is-spectator #pad-buttons, body.is-spectator #chat-log, body.is-spectator #chat-bar, body.is-spectator #prompt,
        body.is-walking-only .hud-vitals, body.is-walking-only #btn-chat, body.is-walking-only #btn-action, body.is-walking-only #chat-log, body.is-walking-only #chat-bar { display: none !important; }
        body.is-spectator #help { bottom: max(10px, env(safe-area-inset-bottom)); }
    </style>
</head>
<body @class(['is-spectator' => $spectator, 'is-walking-only' => $walkingOnly])>
    <canvas id="field-canvas"></canvas>

    <div id="hud" class="panel">
        <div id="hud-place">{{ $spectator ? '管理者観察モード' : 'ヴァルゼリア大陸' }}</div>
        <div id="hud-coords"></div>
        <div class="hud-vitals">
            <div class="hud-hp"><div id="hud-hp-bar"></div></div>
            <div class="hud-vitals-text"><span id="hud-hp-text"></span><span id="hud-stamina" hidden></span></div>
        </div>
    </div>

    <div id="minimap-wrap" class="panel"><canvas id="minimap" width="132" height="132"></canvas></div>

    <div id="buttons">
        <div class="zoom-buttons">
            <button type="button" id="btn-zoom-in" class="btn" aria-label="近づける">＋</button>
            <button type="button" id="btn-zoom-out" class="btn" aria-label="引いて広く見る">－</button>
        </div>
        <button type="button" id="btn-map" class="btn">地図 (M)</button>
        @if($spectator)
            <button type="button" id="btn-spectator-plane" class="btn">天空へ</button>
            <button type="button" id="btn-spectator-roster" class="btn">滞在者 <span id="spectator-count">0</span></button>
            <a href="{{ route('admin.dashboard') }}" class="btn">管理画面へ</a>
        @else
            @unless($walkingOnly)
                <button type="button" id="btn-chat" class="btn">話す (T)</button>
            @endunless
            <button type="button" id="btn-dash" class="btn">走る</button>
            @if($nationCanBuild ?? false)
                <button type="button"
                        id="btn-nation"
                        class="btn"
                        data-coming-soon-title="国づくり"
                        data-coming-soon-message="国づくりは現在準備中です。今後のアップデートで公開予定です。">国づくり</button>
            @endif
            <a href="{{ route('home') }}" class="btn">街の画面へ</a>
        @endif
    </div>

    @if($spectator)
        <section id="spectator-roster" class="panel" aria-label="フィールド滞在者" hidden>
            <div class="spectator-roster-head">
                <div><h2>フィールド滞在者</h2><div id="spectator-summary">取得中…</div></div>
                <button type="button" id="spectator-roster-close" class="btn">閉じる</button>
            </div>
            <div id="spectator-list"></div>
        </section>
    @endif

    <div id="prompt" class="panel" hidden><kbd>Space</kbd><span id="prompt-text"></span></div>
    <div id="toast" class="panel" hidden></div>

    <div id="chat-log" hidden aria-live="polite"></div>
    <form id="chat-form">
        <div id="chat-bar" class="panel" hidden>
            <button type="button" id="chat-scope" class="btn" hidden title="宛先を切り替える">周り</button>
            <input id="chat-input" type="text" maxlength="100" autocomplete="off" enterkeyhint="send" placeholder="近くの人に話す（100文字まで）">
            <button type="submit" class="btn">送る</button>
        </div>
    </form>

    <div id="pad-buttons">
        <button type="button" id="btn-dash-hold" class="pad-btn pad-b">B<small>走る</small></button>
        <button type="button" id="btn-action" class="pad-btn pad-a">A<small>調べる</small></button>
    </div>

    <div id="help">{{ $spectator
        ? '観察移動: タップ / 矢印 / WASD（障害物を通過）　地図: M　視点: ホイール / + -'
        : ($walkingOnly
            ? '移動: タップ / 矢印 / WASD　走る: Shift　地図: M　宝箱・戦闘などは未実装です'
            : '移動: タップ / 矢印 / WASD　走る: Shift　調べる: Space・Enter　地図: M　視点: ホイール / + -') }}</div>

    <div id="dialog" hidden>
        <div class="panel">
            <p id="dialog-title"></p>
            <p id="dialog-body"></p>
            <div class="dialog-actions">
                <button type="button" id="dialog-cancel" class="btn">やめる</button>
                <button type="button" id="dialog-ok" class="btn"></button>
            </div>
        </div>
    </div>

    <div id="worldmap" role="dialog" aria-modal="true" aria-label="世界地図" hidden>
        <div class="worldmap-header"><h2>世界地図</h2><button type="button" id="worldmap-close" class="btn">閉じる (M)</button></div>
        <div class="worldmap-layout">
            <div class="worldmap-map">
                <canvas id="worldmap-canvas" aria-label="行き先を選べる地図"></canvas>
                <div class="worldmap-zoom" role="group" aria-label="地図の拡大縮小">
                    <button type="button" id="worldmap-zoom-out" class="btn" aria-label="地図を縮小">−</button>
                    <span id="worldmap-zoom-label" aria-live="polite">100%</span>
                    <button type="button" id="worldmap-zoom-in" class="btn" aria-label="地図を拡大">＋</button>
                    <button type="button" id="worldmap-zoom-reset" class="btn">全体</button>
                </div>
            </div>
            <div class="worldmap-panel">
                <div id="worldmap-here">現在地：<span id="worldmap-here-name"></span></div>
                <div id="worldmap-help">2本指で拡大縮小、1本指で地図を移動できます。印か一覧から行き先を選んでください。天空へは転移陣から渡れます。</div>
                <div class="worldmap-filters" role="group" aria-label="行き先の種類">
                    <button type="button" class="btn worldmap-filter" data-kind="all" aria-pressed="true">すべて</button>
                    <button type="button" class="btn worldmap-filter" data-kind="city" aria-pressed="false">街</button>
                    <button type="button" class="btn worldmap-filter" data-kind="area" aria-pressed="false">探索地</button>
                    <button type="button" class="btn worldmap-filter" data-kind="wayside" aria-pressed="false">街道</button>
                </div>
                <input id="worldmap-search" type="search" placeholder="行き先を検索" aria-label="行き先を検索">
                <div id="worldmap-selected" aria-live="polite">行き先を選択してください</div>
                <button type="button" id="worldmap-travel" class="btn" disabled>ここへ飛ぶ</button>
                <div id="worldmap-list" aria-label="行き先一覧"></div>
            </div>
        </div>
    </div>

    <div id="battle" hidden>
        <div class="battle-scene">
            <img id="battle-enemy" alt="" hidden>
            <div id="battle-enemy-name"></div>
        </div>
        <div id="battle-window" class="panel">
            <div class="battle-head"><span id="battle-turn-title"></span><span id="battle-progress"></span></div>
            <div id="battle-log"></div>
            <div id="battle-controls" hidden>
                <button type="button" id="battle-skip" class="btn">結果へ ≫</button>
                <button type="button" id="battle-next" class="btn">次へ ▶</button>
            </div>
        </div>
        <div id="battle-result" class="panel" hidden>
            <p id="battle-result-title"></p>
            <ul id="battle-result-list"></ul>
            <button type="button" id="battle-close" class="btn">フィールドへ戻る</button>
        </div>
    </div>

    <div id="fade"></div>
    <div id="loading"><span id="loading-text">地図を広げています…</span></div>

    <script type="application/json" id="field-boot">{!! json_encode($fieldBoot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <script type="module" src="{{ $fieldModules[asset('js/field/main.js')] ?? asset('js/field/main.js') }}"></script>
</body>
</html>
