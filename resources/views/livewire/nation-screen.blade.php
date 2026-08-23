<div class="mx-auto w-full max-w-5xl space-y-4 px-3 pb-24 pt-4 sm:px-5" data-nation-screen>
    <header class="rounded-xl border border-amber-300 bg-gradient-to-br from-amber-50 to-stone-100 p-5 shadow-sm">
        <p class="text-xs font-black tracking-[0.22em] text-amber-700">NATION</p>
        <h1 class="mt-1 text-2xl font-black text-stone-950">建国と国家</h1>
        <p class="mt-2 text-sm font-bold leading-relaxed text-stone-600">仲間と資材を持ち寄り、国の要塞を築こう。</p>
    </header>

    <div aria-live="polite" aria-atomic="true">
        @if($feedback)<div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800" role="status">{{ $feedback }}</div>@endif
        @if($error)<div class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800" role="alert">{{ $error }}</div>@endif
    </div>

    @if(!$membership)
        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国を興す</h2>
            <div class="mt-3 space-y-3">
                <input wire:model="nationName" maxlength="40" placeholder="国名" class="min-h-12 w-full rounded-lg border border-stone-300 px-3 text-base font-bold">
                <textarea wire:model="nationDescription" maxlength="1000" placeholder="国の紹介（任意）" class="min-h-24 w-full rounded-lg border border-stone-300 p-3 text-sm"></textarea>
                <button type="button" wire:click="createNation" wire:loading.attr="disabled" class="min-h-12 w-full rounded-lg bg-amber-600 px-4 py-3 font-black text-white disabled:opacity-50">建国する</button>
            </div>
        </section>
        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国家一覧</h2>
            <div class="mt-3 divide-y divide-stone-100">
                @forelse($nations as $nation)
                    <div class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0"><div class="truncate font-black text-stone-900">{{ $nation->name }}</div><div class="text-xs font-bold text-stone-500">国民 {{ $nation->memberships_count }}/100</div></div>
                        <button type="button" wire:click="joinNation({{ $nation->id }})" class="min-h-11 shrink-0 rounded-lg bg-stone-900 px-4 text-sm font-black text-white">参加する</button>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm font-bold text-stone-500">まだ国家は存在しない。</p>
                @endforelse
            </div>
        </section>
    @else
        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><p class="text-xs font-black text-amber-700">所属国家</p><h2 class="text-2xl font-black text-stone-950">{{ $membership->nation->name }}</h2><p class="mt-1 text-sm font-bold text-stone-500">{{ $membership->nation->description ?: '国の歩みは、これから刻まれていく。' }}</p></div>
                <div class="rounded-lg bg-amber-50 px-4 py-2 text-right"><div class="text-xs font-black text-amber-800">国家資材</div><div class="text-xl font-black text-amber-950">{{ number_format($membership->nation->treasury_points) }} pt</div></div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">要塞</h2>
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @foreach(['wall'=>'城壁','magic_cannon'=>'魔導砲','logistics'=>'兵站所','arsenal'=>'要塞工廠','headquarters'=>'本陣'] as $type=>$label)
                    @php($facility = $membership->nation->facilities->firstWhere('facility_type', $type))
                    <div class="rounded-lg border border-stone-200 bg-stone-50 p-3"><div class="font-black text-stone-900">{{ $label }}</div><div class="mt-1 text-sm font-bold text-stone-600">Lv{{ $facility?->level ?? 1 }} / 耐久{{ number_format(($facility?->condition_bps ?? 10000) / 100, 0) }}%</div></div>
                @endforeach
            </div>
            @unless($upgradesEnabled)<p class="mt-3 rounded-lg bg-stone-100 px-3 py-2 text-sm font-black text-stone-600">施設のレベル上げは現在停止中です。</p>@endunless
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国家資材を納める</h2>
            @if($rates->isEmpty())
                <p class="mt-2 text-sm font-bold text-stone-500">納品できる対象素材を所持していません。</p>
            @else
                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_8rem_auto]">
                    <select wire:model="donationMaterialId" class="min-h-12 rounded-lg border border-stone-300 px-3 text-sm font-bold"><option value="">素材を選択</option>@foreach($rates as $rate)<option value="{{ $rate['material_id'] }}">{{ $rate['name'] }}（{{ $rate['points'] }}pt / 所持{{ $rate['quantity'] }}）</option>@endforeach</select>
                    <input type="number" min="1" wire:model="donationQuantity" class="min-h-12 rounded-lg border border-stone-300 px-3 text-base font-bold">
                    <button type="button" wire:click="donate" wire:loading.attr="disabled" class="min-h-12 rounded-lg bg-emerald-700 px-5 font-black text-white disabled:opacity-50">納品する</button>
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国家戦</h2>
            @if(!$declarationEnabled)
                <p class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-3 text-sm font-black text-sky-900">宣戦布告は停止中です。要塞と国家資材の基礎を整えてお待ちください。</p>
            @elseif(!$calibrated)
                <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm font-black text-amber-900">戦闘基準の校正が完了していないため宣戦布告できません。</p>
            @endif
            @php($warStatusLabels = ['reserved'=>'開戦待ち','preparing'=>'開戦準備中','active'=>'交戦中','resolved'=>'終戦','cancelled'=>'中止'])
            <div class="mt-3 space-y-2">
                @forelse($wars as $war)
                    <div class="rounded-lg border border-stone-200 p-3 text-sm font-bold text-stone-700">
                        {{ $war->declaringNation?->name ?? '不明な国' }} 対 {{ $war->defendingNation?->name ?? '不明な国' }}
                        <span class="text-stone-400"> / </span>{{ $warStatusLabels[$war->status] ?? '状況不明' }}
                        @if($war->starts_at)<span class="text-stone-400"> / </span>{{ $war->starts_at->format('Y/m/d H:i') }}@endif
                    </div>
                @empty
                    <p class="text-sm font-bold text-stone-500">国家戦の記録はまだない。</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国家戦史</h2>
            <div class="mt-3 space-y-2">
                @forelse($histories as $history)
                    <div class="rounded-lg border border-stone-200 p-3 text-sm font-bold text-stone-700">
                        <div>{{ $history->declaringNation?->name ?? '不明な国' }} 対 {{ $history->defendingNation?->name ?? '不明な国' }}</div>
                        <div class="mt-1 text-xs text-stone-500">
                            @if($history->winnerNation)勝者 {{ $history->winnerNation->name }}@else引き分け@endif
                            @if($history->resolved_at)<span class="text-stone-300"> / </span>{{ $history->resolved_at->format('Y/m/d H:i') }}@endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm font-bold text-stone-500">まだ戦史に刻まれた戦いはない。</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-stone-950">国民</h2>
            @php($roleLabels = ['king'=>'国王','chancellor'=>'宰相','marshal'=>'軍務官','logistics_officer'=>'兵站官','citizen'=>'国民'])
            <div class="mt-2 divide-y divide-stone-100">@foreach($membership->nation->memberships as $member)<div class="flex justify-between py-2 text-sm"><span class="font-bold text-stone-800">{{ $member->character?->name ?? '冒険者' }}</span><span class="font-black text-stone-500">{{ $roleLabels[$member->role] ?? '国民' }}</span></div>@endforeach</div>
        </section>
    @endif
</div>
