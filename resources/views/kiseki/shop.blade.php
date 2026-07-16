<x-layouts.facility title="輝石ショップ" headerIconImage="images/icon/icon_011.webp" bgImage="images/bg-castle.webp">

    {{-- フラッシュメッセージ --}}
    @if(session('status'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-bold">
            {{ session('status') }}
        </div>
    @endif
    @if(session('info'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm">
            {{ session('info') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if($isGuestUser)
        <section class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-bold">輝石の購入にはメール連携が必要です。</p>
            <p class="mt-1 text-xs leading-relaxed">Googleと連携してメールアドレスを登録すると、冒険データを引き継いだまま購入できます。</p>
            <a href="{{ route('account.link.google') }}"
               class="mt-3 inline-flex items-center rounded-lg bg-blue-700 px-3 py-2 text-xs font-bold text-white hover:bg-blue-800">
                Google連携をする
            </a>
        </section>
    @endif

    {{-- 現在の輝石残高 --}}
    <div class="mb-6 bg-slate-800 rounded-xl px-6 py-4 flex items-center gap-6">
        <div class="text-center">
            <div class="text-xs text-slate-400 mb-1">有償輝石</div>
            <div class="flex items-center gap-1.5 text-2xl font-bold text-yellow-400">
                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-7 w-7 object-contain">
                {{ number_format($character->paid_kiseki ?? 0) }}
            </div>
        </div>
        <div class="w-px h-10 bg-slate-600"></div>
        <div class="text-center">
            <div class="text-xs text-slate-400 mb-1">無償輝石</div>
            <div class="flex items-center gap-1.5 text-2xl font-bold text-sky-400">
                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-7 w-7 object-contain">
                {{ number_format($character->free_kiseki ?? 0) }}
            </div>
        </div>
        <div class="w-px h-10 bg-slate-600"></div>
        <div class="text-center">
            <div class="text-xs text-slate-400 mb-1">合計</div>
            <div class="flex items-center gap-1.5 text-2xl font-bold text-white">
                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-7 w-7 object-contain">
                {{ number_format(($character->paid_kiseki ?? 0) + ($character->free_kiseki ?? 0)) }}
            </div>
        </div>
    </div>

    {{-- パック一覧 --}}
    <div class="space-y-3">
        @foreach($packs as $packKey => $pack)
            @php
                $perYen = $pack['price_jpy'] > 0 ? round($pack['kiseki_amount'] / $pack['price_jpy'] * 100, 1) : null;
            @endphp
            <div class="relative bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden
                        {{ $pack['tag'] === 'おすすめ' ? 'border-yellow-400 ring-2 ring-yellow-300' : '' }}
                        {{ $pack['tag'] === '最大お得' ? 'border-purple-400 ring-2 ring-purple-300' : '' }}">

                @if($pack['tag'])
                    <div class="absolute top-0 right-0 px-3 py-1 text-xs font-bold rounded-bl-xl
                                {{ $pack['tag'] === 'おすすめ' ? 'bg-yellow-400 text-yellow-900' : '' }}
                                {{ $pack['tag'] === 'お得' ? 'bg-green-500 text-white' : '' }}
                                {{ $pack['tag'] === '最大お得' ? 'bg-purple-500 text-white' : '' }}
                                {{ $pack['tag'] === 'テスト' ? 'bg-slate-400 text-white' : '' }}">
                        {{ $pack['tag'] }}
                    </div>
                @endif

                <div class="flex items-center gap-4 p-4">
                    <div class="text-center min-w-[72px]">
                        @if(!empty($pack['image']))
                            <img src="{{ asset($pack['image']) }}" alt="{{ $pack['name'] }}" class="h-14 w-14 object-contain mx-auto">
                        @else
                            <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-14 w-14 object-contain mx-auto">
                        @endif
                        <div class="text-lg font-bold text-slate-800">{{ number_format($pack['kiseki_amount']) }}</div>
                        <div class="text-xs text-slate-400">個</div>
                    </div>

                    <div class="flex-1">
                        <div class="font-semibold text-slate-800 text-sm">{{ $pack['name'] }}</div>
                        @if($perYen !== null)
                            <div class="text-xs text-slate-400 mt-0.5">100円あたり {{ $perYen }} 個</div>
                        @endif
                    </div>

                    <div class="text-right">
                        <div class="text-lg font-bold text-slate-800 mb-2">
                            ¥{{ number_format($pack['price_jpy']) }}
                        </div>
                        @if($isGuestUser)
                            <span class="inline-flex rounded-lg bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-500">メール連携が必要です</span>
                        @else
                            <form method="POST" action="{{ route('kiseki.checkout') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                                @csrf
                                <input type="hidden" name="pack_key" value="{{ $packKey }}">
                                <button type="submit"
                                        :disabled="submitting"
                                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition disabled:cursor-wait disabled:opacity-60
                                               {{ $pack['tag'] === 'おすすめ' ? 'bg-yellow-500 hover:bg-yellow-600' :
                                                  ($pack['tag'] === '最大お得' ? 'bg-purple-600 hover:bg-purple-700' : 'bg-blue-600 hover:bg-blue-700') }}">
                                    <x-loading-spinner x-show="submitting" style="display: none;" />
                                    <span x-show="!submitting">購入する</span>
                                    <span x-show="submitting" style="display: none;">処理中...</span>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- 注意書き --}}
    <div class="mt-6 text-xs text-slate-400 space-y-1 leading-relaxed">
        <p>・有償輝石はStripe決済（クレジットカード）にてご購入いただけます。</p>
        <p>・決済完了後、自動的に有償輝石が付与されます（数分かかる場合があります）。</p>
        <p>・有償輝石と無償輝石は分けて管理されます。消費は無償輝石が優先されます。</p>
        <p>・お支払いに関するお問い合わせはサポートまでご連絡ください。</p>
    </div>

    {{-- CTA: 補給商会 --}}
    <a href="{{ route('kiseki.support') }}" wire:navigate
       class="mt-8 flex items-center gap-4 rounded-2xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-yellow-50 px-5 py-4 shadow-sm hover:shadow-md hover:border-amber-400 transition group">
        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-amber-100 overflow-hidden group-hover:scale-105 transition-transform">
            <img src="{{ asset('images/icon/icon_007.webp') }}" alt="" class="w-10 h-10 object-contain">
        </div>
        <div class="flex-1 min-w-0">
            <div class="text-xs font-black text-amber-600 tracking-widest uppercase mb-0.5">New</div>
            <div class="text-base font-black text-slate-900 leading-tight">補給商会で冒険支援アイテムを入手！</div>
            <div class="text-xs font-bold text-slate-500 mt-0.5">救助保険・緊急支援など、ピンチを切り抜けるアイテムを輝石で購入できます</div>
        </div>
        <svg class="w-5 h-5 text-amber-400 shrink-0 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </a>

</x-layouts.facility>
