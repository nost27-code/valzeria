@php
    $finalStatKeys = ['hp' => 'max_hp', 'mp' => 'max_mp', 'str' => 'str', 'def' => 'def', 'agi' => 'agi', 'mag' => 'mag', 'spr' => 'spr', 'luk' => 'luk'];
@endphp

<x-layouts.facility title="印錬成所" headerIconImage="images/icon/icon_mark_alchemy.webp" bgImage="images/bg-castle.webp">
    <div class="mx-auto w-full space-y-3 py-3 sm:px-6 lg:px-8" x-data="{ selectedStat: null, selectedLabel: '', selectedGain: 0 }">
        @if(session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700">{{ session('error') }}</div>
        @endif

        <section class="rounded-xl border border-[#d4af37]/50 bg-white p-3 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-black text-slate-900">余剰印を永続能力へ</h2>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">
                        同じ魔物の印は最初の{{ number_format($summary['protected_quantity']) }}個を保護します。錬成しても印図鑑の解放段階と永続効果は失われません。
                    </p>
                </div>
                <a href="{{ route('monster-marks.index') }}" class="shrink-0 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-[11px] font-black text-slate-700">印図鑑</a>
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-2 py-2">
                    <div class="text-[10px] font-bold text-amber-700">使用可能</div>
                    <div class="mt-0.5 text-lg font-black text-slate-900">{{ number_format($summary['surplus_total']) }}<span class="ml-0.5 text-[10px]">個</span></div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-2">
                    <div class="text-[10px] font-bold text-slate-500">錬成回数</div>
                    <div class="mt-0.5 text-lg font-black text-slate-900">{{ number_format($summary['total_points']) }}<span class="ml-0.5 text-[10px]">回</span></div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-2">
                    <div class="text-[10px] font-bold text-slate-500">次に必要</div>
                    <div class="mt-0.5 text-lg font-black text-slate-900">
                        {{ number_format($summary['next_cost']) }}<span class="ml-0.5 text-[10px]">個</span>
                    </div>
                </div>
            </div>

            <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-bold leading-relaxed text-slate-600">
                1〜10回目は20個、11〜20回目は30個、21回目以降は40個で1ptを錬成します。合計回数の上限はありません。1能力の上限は20ptで、割り振り後の変更はできません。
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-2 flex items-end justify-between gap-2">
                <div>
                    <h2 class="text-sm font-black text-slate-900">伸ばす能力を選ぶ</h2>
                    <p class="mt-0.5 text-[11px] font-bold text-slate-400">選択後、消費数と上昇量を確認して確定します。</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach($statOptions as $stat => $option)
                    @php
                        $allocatedPoints = (int) ($summary['points_by_stat'][$stat] ?? 0);
                        $finalKey = $finalStatKeys[$stat];
                        $gain = (int) $option['gain'];
                        $isStatMaxed = $allocatedPoints >= (int) $option['max_points'];
                        $disabled = !$summary['can_refine'] || $isStatMaxed;
                    @endphp
                    <button
                        type="button"
                        @click="selectedStat = '{{ $stat }}'; selectedLabel = '{{ $option['label'] }}'; selectedGain = {{ $gain }}"
                        @disabled($disabled)
                        class="min-h-[96px] rounded-lg border px-2 py-2.5 text-left transition {{ $disabled ? 'cursor-not-allowed border-slate-200 bg-slate-100 opacity-60' : 'border-amber-200 bg-amber-50/60 hover:border-amber-400 hover:bg-amber-50' }}"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span class="text-sm font-black text-slate-900">{{ $option['label'] }}</span>
                            <span class="text-[10px] font-black text-amber-700">+{{ $gain }}</span>
                        </div>
                        <div class="mt-2 text-xs font-bold text-slate-500">
                            現在 {{ number_format((int) ($finalStats[$finalKey] ?? 0)) }}
                            @unless($disabled)
                                <span class="text-amber-700">→ {{ number_format((int) ($finalStats[$finalKey] ?? 0) + $gain) }}</span>
                            @endunless
                        </div>
                        <div class="mt-1 text-[10px] font-bold text-slate-400">錬成 {{ $allocatedPoints }}/{{ $option['max_points'] }}pt</div>
                    </button>
                @endforeach
            </div>

            @if($summary['all_stats_maxed'])
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-center text-xs font-black text-amber-800">すべての能力が個別の錬成上限20ptに到達しています。</div>
            @elseif(!$summary['can_refine'])
                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-center text-xs font-bold text-slate-500">次の錬成には余剰印があと{{ number_format(max(0, $summary['next_cost'] - $summary['surplus_total'])) }}個必要です。</div>
            @endif
        </section>

        <details class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <summary class="flex min-h-12 cursor-pointer items-center justify-between px-3 py-2 text-sm font-black text-slate-800">
                <span>余剰印の内訳</span>
                <span class="text-xs text-slate-400">{{ number_format($summary['surplus_total']) }}個</span>
            </summary>
            <div class="space-y-1.5 border-t border-slate-100 p-3">
                @forelse($summary['surplus_groups'] as $group)
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50 px-2.5 py-2 text-xs">
                        <div class="min-w-0">
                            <div class="truncate font-black text-slate-800">{{ $group['mark_name'] }}</div>
                            <div class="mt-0.5 text-[10px] font-bold text-slate-400">累計{{ number_format($group['owned_quantity']) }}個・錬成済み{{ number_format($group['spent_quantity']) }}個</div>
                        </div>
                        <div class="shrink-0 font-black text-amber-700">余剰 {{ number_format($group['surplus_quantity']) }}</div>
                    </div>
                @empty
                    <div class="py-3 text-center text-xs font-bold text-slate-400">現在、錬成に使える余剰印はありません。</div>
                @endforelse
                @if($summary['hidden_surplus_group_count'] > 0)
                    <div class="text-center text-[10px] font-bold text-slate-400">ほか{{ number_format($summary['hidden_surplus_group_count']) }}種類</div>
                @endif
            </div>
        </details>

        <div x-show="selectedStat !== null" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/55 p-3 sm:items-center" @keydown.escape.window="selectedStat = null">
            <div class="w-full max-w-sm rounded-2xl border border-amber-200 bg-white p-4 shadow-2xl" @click.outside="selectedStat = null">
                <h2 class="text-base font-black text-slate-900">印錬成を確定しますか？</h2>
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm font-bold text-slate-700">
                    余剰印 <span class="font-black text-amber-800">{{ number_format((int) $summary['next_cost']) }}個</span>を消費し、
                    <span class="font-black text-slate-900" x-text="selectedLabel"></span>を
                    <span class="font-black text-amber-800">+<span x-text="selectedGain"></span></span>します。
                </div>
                <p class="mt-2 text-[11px] font-bold leading-relaxed text-red-600">錬成後に印や能力を元へ戻すことはできません。最初の15個と印図鑑の効果は保護されます。</p>
                <form method="POST" action="{{ route('monster-mark-alchemy.refine') }}" class="mt-4 flex gap-2">
                    @csrf
                    <input type="hidden" name="stat" :value="selectedStat">
                    <input type="hidden" name="request_token" value="{{ $requestToken }}">
                    <button type="button" @click="selectedStat = null" class="min-h-11 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-700">戻る</button>
                    <button type="submit" class="min-h-11 flex-1 rounded-lg bg-slate-900 px-3 py-2 text-sm font-black text-white">錬成する</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.facility>
