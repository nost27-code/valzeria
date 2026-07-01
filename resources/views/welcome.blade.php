<!DOCTYPE html>
<html lang="ja">
<head>
    <!-- PWA -->
    <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
    <meta name="theme-color" content="#0a1628">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js?v=3').then(r => {}).catch(e => {});
            });
        }
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ヴァルゼリアの冒険者 - 最強冒険者が集うFFA風RPG</title>
    <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
    @include('partials.ogp', ['ogUrl' => 'https://valzeria.com/'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Noto Sans JP','Hiragino Kaku Gothic ProN',sans-serif; margin:0; padding:0; background:#fff; color:#1c1917; }
        [x-cloak] { display: none !important; }
        .sec-ttl {
            display:flex; align-items:center; justify-content:center;
            gap:14px; font-size:14px; font-weight:900; letter-spacing:.18em;
            color:#a16207; text-transform:uppercase; margin-bottom:22px;
        }
        .sec-ttl::before,.sec-ttl::after { content:''; display:block; flex:1; height:1px; background:#e7d98e; max-width:80px; }
        .sec-ttl-gold {
            display:flex; align-items:center; justify-content:center;
            gap:14px; font-size:14px; font-weight:900; letter-spacing:.18em;
            color:#d4af37; text-transform:uppercase; margin-bottom:22px;
        }
        .sec-ttl-gold::before,.sec-ttl-gold::after { content:''; display:block; flex:1; height:1px; background:rgba(212,175,55,0.35); max-width:80px; }
    </style>
</head>
<body>
    <x-pwa-install-banner />

    @php
        $topChamp = $champSummary['champ'] ?? null;
        $topChampHpPercent = $champSummary['hp_percent'] ?? 0;
        $champValmonImagePath = $champValmon?->master?->imageUrl();
        $champValmonName = $champValmon?->nickname ?: ($champValmon?->master?->name ?? null);
        $registrationOpen = $registrationOpen ?? true;
        $topVisitUuid = $topPageVisit?->visit_uuid ?? null;
    @endphp

    {{-- β版バナー --}}
    <div style="background:linear-gradient(90deg,#1e3a5f 0%,#0a1628 50%,#1e3a5f 100%);border-bottom:1px solid rgba(212,175,55,0.4);padding:10px 20px;text-align:center;">
        <div style="display:inline-flex;align-items:flex-start;justify-content:center;gap:10px;font-size:13px;font-weight:900;color:#d4af37;letter-spacing:.08em;line-height:1.7;max-width:960px;">
            <span style="background:rgba(212,175,55,0.2);border:1px solid rgba(212,175,55,0.5);border-radius:4px;padding:2px 8px;font-size:11px;letter-spacing:.12em;white-space:nowrap;">β版</span>
            <span style="text-align:left;">
                現在ベータ版として公開中です。不具合・ご意見は開発者までお知らせください。<br>
                β期間中は、ゲームバランス調整や不具合修正のため、能力値・装備・スキル・報酬・進行状況などを変更する場合があります。下方修正を含む調整が行われる可能性がありますので、あらかじめご了承ください。
            </span>
        </div>
    </div>

    {{-- ① HERO --}}
    <div style="background:linear-gradient(180deg,rgba(255,255,255,0.12) 0%,rgba(255,255,255,0.24) 48%,rgba(255,255,255,0.66) 100%),url('{{ asset('images/title_bg.webp') }}') center 18% / cover no-repeat;border-bottom:2px solid #e7d98e;padding:52px 20px 48px;text-align:center;">
        <div style="display:flex;align-items:center;justify-content:center;gap:10px;font-size:14px;font-weight:900;letter-spacing:.25em;color:#a16207;margin-bottom:26px;">
            <span style="display:block;width:36px;height:1px;background:#d4af37;"></span>
            FFA BROWSER RPG
            <span style="display:block;width:36px;height:1px;background:#d4af37;"></span>
        </div>
        <img src="{{ asset('images/title_logo.webp') }}" alt="ヴァルゼリアの冒険者"
             style="max-width:min(480px,90vw);height:auto;display:block;margin:0 auto 30px;">
        <p style="font-size:20px;font-weight:900;color:#1c1917;line-height:1.75;margin:0 0 10px;">
            モンスターを倒してレベルを上げ、装備を集め、転職。
        </p>
        <p style="font-size:17px;font-weight:700;color:#44403c;line-height:1.75;margin:0;">
            育てた冒険者でチャンプに挑み、最強冒険者の座を目指すブラウザRPG。
        </p>
        <p style="font-size:15px;color:#a8a29e;margin-top:14px;">昔ながらのブラウザRPGの面白さを、現代の画面で。</p>
    </div>

    {{-- ② 街にいる冒険者 + LOGIN --}}
    <div style="background:#fff;border-bottom:2px solid #e7d98e;padding:36px 20px;">
        <div style="max-width:440px;margin:0 auto;">
            @if(session('error'))
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px 14px;margin-bottom:18px;color:#991b1b;font-size:14px;font-weight:900;line-height:1.6;">
                    {{ session('error') }}
                </div>
            @endif

            @unless($registrationOpen)
                <div style="background:#fffbeb;border:1px solid #facc15;border-radius:12px;padding:12px 14px;margin-bottom:18px;color:#854d0e;font-size:14px;font-weight:900;line-height:1.6;">
                    現在、新規登録の受付を停止しています。既存アカウントのログインは利用できます。
                </div>
            @endunless

            {{-- 街にいる冒険者 --}}
            @if($onlineCharacters->count() > 0)
            <div style="background:#fafaf7;border:1px solid #e7d98e;border-radius:12px;padding:14px 16px;margin-bottom:24px;">
                <div style="font-size:13px;font-weight:900;color:#a16207;letter-spacing:.1em;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                    <span style="display:inline-block;width:8px;height:8px;background:#22c55e;border-radius:50%;flex-shrink:0;box-shadow:0 0 0 2px #bbf7d0;"></span>
                    いま街にいる冒険者（{{ $onlineCharacters->count() }}人）
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    @foreach($onlineCharacters as $char)
                    <span style="background:#fff;border:1px solid #e7d98e;border-radius:20px;padding:4px 14px;font-size:14px;color:#44403c;font-weight:700;">{{ $char->name }}</span>
                    @endforeach
                </div>
            </div>
            @else
            <div style="background:#fafaf7;border:1px solid #e7d98e;border-radius:12px;padding:14px 16px;margin-bottom:24px;">
                <div style="font-size:13px;font-weight:900;color:#a16207;letter-spacing:.1em;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                    <span style="display:inline-block;width:8px;height:8px;background:#94a3b8;border-radius:50%;flex-shrink:0;"></span>
                    いま街にいる冒険者
                </div>
                <p style="font-size:14px;color:#a8a29e;margin:0;">現在、街に冒険者はいません。最初の冒険者になりましょう！</p>
            </div>
            @endif

            {{-- Google ログイン --}}
            <a href="{{ route('auth.google') }}"
               data-top-event="{{ $registrationOpen ? 'google_start_click' : 'google_login_click' }}"
               data-top-label="{{ $registrationOpen ? 'Googleで冒険を始める' : 'Googleでログイン' }}"
               style="display:flex;align-items:center;justify-content:center;gap:12px;
                      background:linear-gradient(to bottom,#d4af37,#b8860b);
                      border-radius:12px;padding:18px 20px;color:#fff;
                      font-size:18px;font-weight:900;text-decoration:none;
                      box-shadow:0 4px 22px rgba(180,135,11,0.42);letter-spacing:.03em;transition:box-shadow .2s;"
               onmouseover="this.style.boxShadow='0 6px 30px rgba(180,135,11,0.58)'"
               onmouseout="this.style.boxShadow='0 4px 22px rgba(180,135,11,0.42)'">
                <div style="width:30px;height:30px;background:rgba(255,255,255,0.25);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/></svg>
                </div>
                {{ $registrationOpen ? 'Googleで冒険を始める' : 'Googleでログイン' }}
            </a>

            <div style="display:flex;align-items:center;gap:12px;margin:18px 0;">
                <span style="flex:1;height:1px;background:#e7e5e4;"></span>
                <span style="font-size:15px;color:#a8a29e;">または</span>
                <span style="flex:1;height:1px;background:#e7e5e4;"></span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                @if($registrationOpen)
                    <a href="{{ route('auth.email.register') }}"
                       data-top-event="email_register_click"
                       data-top-label="メールで新規登録"
                       style="display:flex;align-items:center;justify-content:center;background:#0a1628;border-radius:12px;padding:15px 10px;color:#d4af37;font-size:15px;font-weight:900;text-decoration:none;border:2px solid #d4af37;">
                        メールで新規登録
                    </a>
                @else
                    <div style="display:flex;align-items:center;justify-content:center;background:#e5e7eb;border-radius:12px;padding:15px 10px;color:#64748b;font-size:15px;font-weight:900;border:2px solid #cbd5e1;">
                        新規登録停止中
                    </div>
                @endif
                <a href="{{ route('auth.email.login') }}"
                   data-top-event="email_login_click"
                   data-top-label="メールでログイン"
                   style="display:flex;align-items:center;justify-content:center;background:#fff;border-radius:12px;padding:15px 10px;color:#57534e;font-size:15px;font-weight:900;text-decoration:none;border:2px solid #e7d98e;">
                    メールでログイン
                </a>
            </div>

            @if($registrationOpen)
                <form action="{{ route('auth.guest') }}" method="POST">
                    @csrf
                    <button type="submit"
                            data-top-event="guest_start_click"
                            data-top-label="アカウント連携せずにプレイ"
                            style="display:flex;align-items:center;justify-content:center;gap:8px;
                                   width:100%;background:#fff;border:2px solid #e7d98e;
                                   border-radius:12px;padding:16px 20px;color:#57534e;
                                   font-size:17px;font-weight:700;cursor:pointer;font-family:inherit;transition:border-color .15s,background .15s;"
                            onmouseover="this.style.background='#fffbeb';this.style.borderColor='#d4af37'"
                            onmouseout="this.style.background='#fff';this.style.borderColor='#e7d98e'">
                        👤 アカウント連携せずにプレイ
                    </button>
                </form>
            @else
                <div style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:#f1f5f9;border:2px solid #cbd5e1;border-radius:12px;padding:16px 20px;color:#64748b;font-size:17px;font-weight:900;">
                    👤 ゲスト開始停止中
                </div>
            @endif

            <p style="text-align:center;margin-top:12px;font-size:14px;color:#a8a29e;">
                ※ 既存アカウントはGoogleまたはメールでログインできます
            </p>
            <p style="text-align:center;margin-top:8px;font-size:15px;color:#a16207;display:flex;align-items:center;justify-content:center;gap:5px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ $registrationOpen ? '登録無料・ブラウザだけでプレイできます' : '既存アカウントでログインできます' }}
            </p>
        </div>
    </div>

    {{-- ④ ゲームの特徴（深いネイビー） --}}
    <div style="background:#0a1628 url('{{ asset('images/top/top_bg2.webp') }}') center/cover no-repeat;padding:60px 16px 72px;position:relative;overflow:hidden;">
        <div style="position:absolute;inset:0;background:rgba(8,16,34,0.72);pointer-events:none;"></div>
        <div style="position:absolute;top:-120px;right:-100px;width:420px;height:420px;background:radial-gradient(circle,rgba(212,175,55,0.10) 0%,transparent 65%);pointer-events:none;"></div>
        <div style="position:absolute;bottom:-100px;left:-80px;width:340px;height:340px;background:radial-gradient(circle,rgba(212,175,55,0.07) 0%,transparent 65%);pointer-events:none;"></div>
        <div style="max-width:680px;margin:0 auto;position:relative;">
            <div class="sec-ttl-gold">ゲームの特徴</div>
            <p style="text-align:center;font-size:14px;color:#8ba0ba;font-weight:700;margin:-10px 0 28px;letter-spacing:.04em;">冒険者が熱中する、6つの理由。</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                @foreach([
                    ['icon_042.webp', '育成', '倒すたびに強くなる。BPで自分だけのステータスを作り、誰にも真似できない冒険者へ。', '01'],
                    ['icon_055.webp', '転職', '10以上の職業が解放を待つ。力を引き継いで転職し、さらなる高みへ挑め。', '02'],
                    ['icon_006.webp', '装備収集', 'レアドロップを求めて何度でもダンジョンへ。最強装備を揃えるのが、このゲームの醍醐味。', '03'],
                    ['icon_009.webp', 'チャンプ戦', '頂点はひとつだけ。サーバー最強の冒険者に挑み、玉座を奪い取れ。', '04'],
                    ['icon_004.webp', '秘境探索', '探索を重ねた者だけが辿り着ける、地図にない秘境が存在する。', '05'],
                    ['icon_037.webp', 'ヴァルモン牧場', '卵から育てる相棒・ヴァルモン。絆を深めるほど、冒険がもっと面白くなる。', '06'],
                ] as [$icon, $title, $desc, $num])
                <div style="background:linear-gradient(145deg,rgba(255,255,255,0.06) 0%,rgba(255,255,255,0.02) 100%);border:1px solid rgba(212,175,55,0.22);border-top:3px solid rgba(212,175,55,0.7);border-radius:16px;padding:24px 18px 22px;position:relative;transition:transform .25s,box-shadow .25s,border-color .25s,background .25s;cursor:default;"
                     onmouseover="this.style.transform='translateY(-5px)';this.style.boxShadow='0 16px 40px rgba(0,0,0,0.45),0 0 24px rgba(212,175,55,0.12)';this.style.borderColor='rgba(212,175,55,0.55)';this.style.background='linear-gradient(145deg,rgba(255,255,255,0.09) 0%,rgba(212,175,55,0.04) 100%)'"
                     onmouseout="this.style.transform='';this.style.boxShadow='';this.style.borderColor='rgba(212,175,55,0.22)';this.style.background='linear-gradient(145deg,rgba(255,255,255,0.06) 0%,rgba(255,255,255,0.02) 100%)'">
                    <div style="position:absolute;top:14px;right:16px;font-size:10px;font-weight:900;color:rgba(212,175,55,0.3);letter-spacing:.12em;font-variant-numeric:tabular-nums;">{{ $num }}</div>
                    <div style="width:62px;height:62px;background:radial-gradient(circle,rgba(212,175,55,0.18) 0%,rgba(212,175,55,0.06) 100%);border:1px solid rgba(212,175,55,0.35);border-radius:16px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;flex-shrink:0;box-shadow:0 4px 16px rgba(0,0,0,0.3);">
                        <img src="{{ asset('images/icon/' . $icon) }}" alt="{{ $title }}" style="width:40px;height:40px;object-fit:contain;filter:drop-shadow(0 2px 6px rgba(212,175,55,0.4));">
                    </div>
                    <div style="font-size:16px;font-weight:900;color:#e8c84a;margin-bottom:4px;letter-spacing:.03em;">{{ $title }}</div>
                    <div style="width:28px;height:2px;background:linear-gradient(to right,rgba(212,175,55,0.7),transparent);margin-bottom:10px;border-radius:2px;"></div>
                    <div style="font-size:13px;color:#9bb2cc;line-height:1.85;font-weight:600;">{{ $desc }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ⑤ 現在のチャンプ（白） --}}
    @if($topChamp)
    <div style="background:#fff;padding:44px 16px;border-bottom:1px solid #f0e8c8;">
        <div style="max-width:680px;margin:0 auto;">
            <div class="sec-ttl">現在のチャンプ</div>
            <div style="border:2px solid #e7d98e;border-top:4px solid #d4af37;border-radius:14px;overflow:hidden;box-shadow:0 4px 28px rgba(180,135,11,0.10);">
                <div style="background:#0a1628;text-align:center;padding:11px 12px;font-size:15px;font-weight:900;color:#fbbf24;letter-spacing:.04em;">
                    <img src="{{ asset('images/icon/icon_043.webp') }}" alt="" style="width:16px;height:16px;object-fit:contain;display:inline;vertical-align:middle;margin-right:4px;"> {{ number_format((int)$topChamp->defense_count) }}連勝中 — チャンプに挑戦しよう！
                </div>
                <div style="display:flex;background:#fff;">
                    <div style="width:152px;flex-shrink:0;padding:20px 12px;display:flex;flex-direction:column;align-items:center;border-right:1px solid #f0e8c8;background:#fffbeb;">
                        <div style="font-size:11px;font-weight:900;letter-spacing:.12em;background:linear-gradient(to bottom,#d4af37,#b8860b);color:#fff;padding:4px 12px;border-radius:20px;margin-bottom:12px;">CHAMPION</div>
                        {{-- キャラ＋ヴァルモン セット枠 --}}
                        <div style="border:1.5px solid #e7d98e;border-radius:12px;background:#fef9ec;padding:10px 12px 8px;display:flex;flex-direction:column;align-items:center;gap:6px;">
                            <div style="display:flex;align-items:flex-end;justify-content:center;gap:0;">
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($topChamp->icon_path ?: '/images/chara/chara_001.webp') }}"
                                     alt="{{ $topChamp->player_name }}"
                                     style="width:80px;height:100px;object-fit:contain;margin-right:-8px;">
                                @if($champValmonImagePath)
                                <img src="{{ $champValmonImagePath }}"
                                     alt="{{ $champValmonName }}"
                                     style="width:60px;height:60px;object-fit:contain;">
                                @endif
                            </div>
                        </div>
                        <div style="font-size:17px;font-weight:900;color:#1c1917;margin-top:10px;text-align:center;">{{ $topChamp->player_name }}</div>
                        <div style="font-size:14px;color:#78716c;margin-top:4px;">Lv{{ number_format((int)$topChamp->level) }} / {{ $topChamp->job_name ?? '冒険者' }}</div>
                        <div style="margin-top:10px;font-size:14px;font-weight:900;color:#d4af37;background:#fff;border:1.5px solid #e7d98e;padding:5px 14px;border-radius:20px;">
                            <img src="{{ asset('images/icon/icon_043.webp') }}" alt="" style="width:14px;height:14px;object-fit:contain;display:inline;vertical-align:middle;margin-right:3px;"> {{ number_format((int)$topChamp->defense_count) }}連勝
                        </div>
                    </div>
                    <div style="flex:1;padding:20px 16px;min-width:0;">
                        <div style="margin-bottom:14px;">
                            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;color:#78716c;margin-bottom:5px;">
                                <span>HP {{ number_format((int)$topChamp->current_hp) }}</span>
                                <span>/ {{ number_format((int)$topChamp->max_hp) }}</span>
                            </div>
                            <div style="height:7px;background:#f0ede8;border-radius:99px;overflow:hidden;">
                                <div style="height:100%;width:{{ $topChampHpPercent }}%;background:linear-gradient(90deg,#ef4444,#f97316);border-radius:99px;"></div>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:14px;">
                            @foreach([['攻撃',$topChamp->atk],['防御',$topChamp->def],['魔法',$topChamp->mag],['精神',$topChamp->spr],['速さ',$topChamp->spd],['運',$topChamp->luk]] as [$lbl,$val])
                            <div style="background:#faf9f6;border:1px solid #e7e5e4;border-radius:7px;padding:7px 9px;display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:13px;color:#78716c;font-weight:700;">{{ $lbl }}</span>
                                <span style="font-size:15px;font-weight:900;color:#1c1917;">{{ number_format((int)$val) }}</span>
                            </div>
                            @endforeach
                        </div>
                        <div style="display:flex;flex-direction:column;gap:5px;">
                            @foreach([['武',$topChamp->weapon_name],['防',$topChamp->armor_name],['飾',$topChamp->accessory_name]] as [$type,$name])
                            <div style="display:flex;gap:8px;align-items:center;background:#faf9f6;border:1px solid #e7e5e4;border-radius:7px;padding:7px 10px;font-size:14px;">
                                <span style="color:#d4af37;font-weight:900;width:18px;flex-shrink:0;">{{ $type }}</span>
                                <span style="color:#44403c;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $name ?: 'なし' }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ⑥ 遊び方 3ステップ（白） --}}
    <div style="background:#fafaf7;padding:44px 16px;border-bottom:1px solid #f0e8c8;">
        <div style="max-width:680px;margin:0 auto;">
            <div class="sec-ttl">遊び方 3ステップ</div>
            <div style="display:flex;flex-direction:column;">
                @foreach([
                    ['1', 'images/icon/icon_005.webp', '冒険に出てモンスターと戦う', 'ダンジョンを探索し、モンスターを倒して経験値や装備素材を手に入れよう。'],
                    ['2', 'images/icon/icon_054.webp', 'レベル・装備・職業を強化する', 'BPで能力を伸ばし、ショップで装備を整え、転職で新たなジョブへ進化しよう。'],
                    ['3', 'images/icon/icon_010.webp', 'チャンプに挑み、最強冒険者を目指す', '十分に強くなったらチャンプに挑戦。勝利すれば自分がチャンプとしてTOPに刻まれる。'],
                ] as [$num, $iconImg, $title, $desc])
                <div style="display:flex;gap:0;">
                    <div style="display:flex;flex-direction:column;align-items:center;width:56px;flex-shrink:0;">
                        <div style="width:44px;height:44px;border-radius:50%;background:#0a1628;color:#d4af37;font-size:18px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid #d4af37;">{{ $num }}</div>
                        @if($num !== '3')
                        <div style="flex:1;width:2px;background:#e7d98e;margin:5px 0;min-height:20px;"></div>
                        @endif
                    </div>
                    <div style="flex:1;padding-bottom:28px;padding-left:14px;padding-top:9px;">
                        <div style="font-size:17px;font-weight:900;color:#1c1917;margin-bottom:7px;display:flex;align-items:center;gap:6px;"><img src="{{ asset($iconImg) }}" alt="" style="width:20px;height:20px;object-fit:contain;flex-shrink:0;"> {{ $title }}</div>
                        <div style="font-size:15px;color:#57534e;line-height:1.8;">{{ $desc }}</div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ⑦ ゲーム画面（白） --}}
    <div style="background:#fff;padding:44px 16px;border-bottom:1px solid #f0e8c8;">
        <div style="max-width:680px;margin:0 auto;">
            <div class="sec-ttl">ゲーム画面</div>
            <div style="display:inline-flex;align-items:center;gap:6px;background:#fef9ec;border:1px solid #e7d98e;border-radius:6px;padding:5px 12px;margin-bottom:16px;font-size:12px;font-weight:900;color:#92400e;">
                <img src="{{ asset('images/icon/icon_046.webp') }}" alt="" style="width:16px;height:16px;object-fit:contain;"> 開発中の画面です。実際のゲーム画面とは異なる場合があります。
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                @foreach([
                    ['screemshot01.webp', '街・施設'],
                    ['screemshot02.webp', '探索・戦闘'],
                    ['screemshot03.webp', 'ステータス'],
                    ['screemshot04.webp', 'チャンプ戦'],
                    ['screemshot05.webp', 'ヴァルモン'],
                    ['screemshot06.webp', '装備・強化'],
                ] as [$file, $label])
                @php $src = asset('images/top/' . $file); @endphp
                <div style="border:1px solid #e7d98e;border-radius:12px;overflow:hidden;aspect-ratio:9/16;position:relative;cursor:zoom-in;"
                     onclick="openLightbox('{{ $src }}', '{{ $label }}')">
                    <img src="{{ $src }}" alt="{{ $label }}"
                         style="width:100%;height:100%;object-fit:cover;display:block;">
                    <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top,rgba(10,22,40,0.85),transparent);padding:10px 8px 8px;text-align:center;">
                        <span style="font-size:12px;font-weight:900;color:#fff;letter-spacing:.05em;">{{ $label }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ライトボックス --}}
    <div id="lb-overlay"
         style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;padding:16px;"
         onclick="if(event.target===this)closeLightbox()">
        <div style="position:relative;max-height:90vh;display:flex;flex-direction:column;align-items:center;gap:10px;">
            <img id="lb-img" src="" alt=""
                 style="max-height:82vh;max-width:90vw;object-fit:contain;border-radius:12px;box-shadow:0 8px 48px rgba(0,0,0,0.6);">
            <div id="lb-label" style="font-size:14px;font-weight:900;color:#fff;letter-spacing:.06em;"></div>
            <button onclick="closeLightbox()"
                    style="position:absolute;top:-14px;right:-14px;width:36px;height:36px;border-radius:50%;background:#fff;border:none;cursor:pointer;font-size:18px;font-weight:900;color:#1c1917;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,0.3);">
                ×
            </button>
        </div>
    </div>
    <script>
        window.valzeriaTopAnalytics = {
            visitUuid: @js($topVisitUuid),
            endpoint: @js(route('top.analytics.event')),
            csrf: @js(csrf_token()),
            startedAt: Date.now(),
            sentDwell: false,
        };

        function sendTopAnalytics(eventName, metadata) {
            var analytics = window.valzeriaTopAnalytics || {};
            if (!analytics.visitUuid || !analytics.endpoint) return;

            var payload = new FormData();
            payload.append('_token', analytics.csrf || '');
            payload.append('visit_uuid', analytics.visitUuid);
            payload.append('event_name', eventName);

            Object.keys(metadata || {}).forEach(function(key) {
                if (metadata[key] !== undefined && metadata[key] !== null) {
                    payload.append(key, metadata[key]);
                }
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon(analytics.endpoint, payload);
                return;
            }

            fetch(analytics.endpoint, {
                method: 'POST',
                body: payload,
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});
        }

        document.addEventListener('click', function(event) {
            var target = event.target.closest('[data-top-event]');
            if (!target) return;

            sendTopAnalytics(target.dataset.topEvent, {
                label: target.dataset.topLabel || target.textContent.trim(),
                href: target.href || ''
            });
        });

        function sendTopDwell() {
            var analytics = window.valzeriaTopAnalytics || {};
            if (analytics.sentDwell) return;
            analytics.sentDwell = true;
            var duration = Math.max(1, Math.round((Date.now() - analytics.startedAt) / 1000));
            sendTopAnalytics('page_dwell', { duration_seconds: duration });
        }

        window.addEventListener('pagehide', sendTopDwell);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') sendTopDwell();
        });

        function openLightbox(src, label) {
            document.getElementById('lb-img').src = src;
            document.getElementById('lb-label').textContent = label;
            var el = document.getElementById('lb-overlay');
            el.style.display = 'flex';
        }
        function closeLightbox() {
            document.getElementById('lb-overlay').style.display = 'none';
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeLightbox();
        });
    </script>

    {{-- ⑧ 更新情報 --}}
    <div style="background:#fafaf7;padding:44px 16px;">
        <div style="max-width:680px;margin:0 auto;">
            <div class="sec-ttl">更新情報</div>
            <div style="background:#fff;border:1px solid #e7d98e;border-radius:12px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.06);">
                <a class="twitter-timeline"
                   data-height="520"
                   data-dnt="true"
                   data-chrome="noheader nofooter transparent"
                   href="https://x.com/valzeria_dev">
                    更新情報（@valzeria_dev）を見る
                </a>
            </div>
            <div style="margin-top:10px;text-align:right;">
                <a href="https://x.com/valzeria_dev" target="_blank" rel="noopener noreferrer"
                   style="font-size:13px;color:#b45309;font-weight:900;text-decoration:underline;text-underline-offset:3px;">
                    Xで更新情報を見る
                </a>
            </div>
        </div>
    </div>
    <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>

    {{-- ⑨ フッター（深いネイビー） --}}
    <div style="background:#0a1628;padding:30px 20px;">
        <div style="max-width:680px;margin:0 auto;text-align:center;">

            {{-- SNSリンク --}}
            <div style="display:flex;justify-content:center;gap:14px;margin-bottom:22px;">
                <a href="https://note.com/valzeria" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:7px;background:#41c9b4;color:#fff;font-size:13px;font-weight:900;text-decoration:none;padding:8px 16px;border-radius:8px;letter-spacing:.04em;transition:opacity .15s;"
                   onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
                    <svg style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h18v2H3V3zm0 4h12v2H3V7zm0 4h18v2H3v-2zm0 4h12v2H3v-2zm0 4h18v2H3v-2z"/></svg>
                    note
                </a>
                <a href="https://www.threads.com/@valzeria_dev" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:7px;background:#1c1917;color:#fff;font-size:13px;font-weight:900;text-decoration:none;padding:8px 16px;border-radius:8px;border:1px solid #3f3f46;letter-spacing:.04em;transition:opacity .15s;"
                   onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
                    <svg style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="currentColor"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.471 12.01v-.017c.029-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.74-1.75-.BlackBerry-.553-1.274-.842-2.234-.876l-.042-.001c-.915 0-1.978.341-2.678 1.03l-1.42-1.464C10.38 4.393 11.862 3.9 13.23 3.9h.06c1.4.046 2.56.504 3.345 1.325.786.822 1.24 1.997 1.35 3.495 1.068.57 1.905 1.372 2.448 2.384.905 1.674.977 3.856-.16 5.925-1.42 2.578-3.951 3.871-7.087 3.871Zm.974-8.083c-1.329.073-2.264.481-2.598.85-.236.263-.354.606-.334.964.022.392.257.74.655.999.492.319 1.178.489 1.94.448 1.037-.055 1.822-.445 2.333-1.156.378-.524.608-1.24.682-2.14a11.127 11.127 0 0 0-2.678.035Z"/></svg>
                    Threads
                </a>
            </div>

            <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px 26px;margin-bottom:18px;">
                <a href="{{ route('help') }}" style="font-size:15px;color:#64748b;text-decoration:none;font-weight:700;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#64748b'">ヘルプ</a>
                <span style="color:#1e3a5f;">|</span>
                <a href="{{ route('legal.terms') }}" style="font-size:15px;color:#64748b;text-decoration:none;font-weight:700;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#64748b'">利用規約</a>
                <span style="color:#1e3a5f;">|</span>
                <a href="{{ route('legal.privacy') }}" style="font-size:15px;color:#64748b;text-decoration:none;font-weight:700;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#64748b'">プライバシーポリシー</a>
                <span style="color:#1e3a5f;">|</span>
                <a href="{{ route('legal.contact') }}" style="font-size:15px;color:#64748b;text-decoration:none;font-weight:700;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#64748b'">お問い合わせ</a>
                <span style="color:#1e3a5f;">|</span>
                <a href="{{ route('legal.operator') }}" style="font-size:15px;color:#64748b;text-decoration:none;font-weight:700;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#64748b'">運営者情報</a>
            </div>
            <p style="font-size:13px;color:#334155;margin:0;">&copy; 2026 ヴァルゼリアの冒険者 Project. All rights reserved.</p>
        </div>
    </div>

</body>
</html>
