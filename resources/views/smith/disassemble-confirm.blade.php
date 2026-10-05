@php
    $title = '武具分解の確認';
@endphp
<x-layouts.facility :title="$title" header-icon-image="images/icon/icon_034.webp" bg-image="images/card_bg/shop_blacksmith.webp" :show-exit="false">
    <div class="mx-auto max-w-2xl space-y-4" x-data="{ submitting: false }">
        <section class="rounded-lg border border-red-200 bg-white p-4 sm:p-6">
            <h2 class="text-lg font-bold text-red-800">この武具を分解しますか？</h2>
            <p class="mt-3 break-words font-bold">{{ $candidate['equipment_name'] }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $candidate['equipment_type_label'] }} / {{ $candidate['rank'] }} / 個体 #{{ $candidate['equipment_instance_id'] }}</p>
            <p class="mt-3 text-sm font-bold text-red-800">この個体と、強化・品質・銘・特攻・耐性を失います。分解後は元に戻せません。</p>
            <p class="mt-2 text-sm">費用は無料です。Gold・輝石は消費しません。強化に使ったGold、武具の売却代金、進化・銘・特攻・耐性加工の材料は戻りません。</p>
            <h3 class="mt-5 font-bold">回収する素材</h3>
            <p class="mt-1 text-xs text-slate-600">現行レシピの累計素材の75%・素材ごとに端数切り捨て。過去に実際に使った素材とは異なる場合があります。</p>
            <ul class="mt-3 divide-y divide-slate-100">
                @foreach($candidate['expected_materials'] as $material)
                    <li class="flex items-start justify-between gap-3 py-2 text-sm"><span class="min-w-0 break-words">{{ $material['name'] }}</span><strong class="shrink-0">{{ number_format($material['quantity']) }}個</strong></li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm text-slate-600">素材倉庫：{{ number_format($candidate['storage']['material_total']) }} / {{ number_format($candidate['storage']['material_limit']) }}個 → 分解後 {{ number_format($candidate['storage']['material_total'] + $candidate['returned_total']) }}個</p>
            @if($candidate['can_receive'])
                <form x-ref="decompositionForm" method="POST" action="{{ route('smith.disassemble', $candidate['equipment_instance_id']) }}" class="mt-4 space-y-4" :inert="submitting" @submit.prevent="if (submitting) return; submitting = true; $nextTick(() => requestAnimationFrame(() => HTMLFormElement.prototype.submit.call($refs.decompositionForm)))">
                    @csrf
                    <input type="hidden" name="confirmation_hash" value="{{ $candidate['confirmation_hash'] }}">
                    <label class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1 h-5 w-5 shrink-0"><span>この武具と育成状態を失うこと、返却素材の内容を確認しました。</span></label>
                    <button type="submit" :disabled="submitting" class="flex w-full items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-3 text-sm font-bold text-white disabled:opacity-60"><span x-show="submitting" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true"></span><span x-text="submitting ? '分解中…' : 'この武具を分解する'">この武具を分解する</span></button>
                </form>
            @else
                <p class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800">返却素材が素材倉庫に入りきりません。倉庫を整理してから分解してください。</p>
            @endif
            <a href="{{ route('smith.disassemble.index', ['type' => $candidate['equipment_type']]) }}" @click="if (submitting) $event.preventDefault()" :aria-disabled="submitting" class="mt-3 block rounded-lg border border-slate-300 px-4 py-3 text-center text-sm font-bold" :class="{ 'pointer-events-none opacity-50': submitting }">分解せずに戻る</a>
        </section>
    </div>
</x-layouts.facility>
