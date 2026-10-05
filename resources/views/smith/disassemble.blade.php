@php
    $title = '武具分解 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
    $types = ['weapon' => '武器', 'armor' => '防具', 'accessory' => '装飾品'];
@endphp
<x-layouts.facility :title="$title" header-icon-image="images/icon/icon_034.webp" bg-image="images/card_bg/shop_blacksmith.webp">
    <div class="mx-auto max-w-2xl space-y-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-lg font-bold">使わなくなった強化武具を分解する</h2>
            <p class="mt-2 text-sm leading-relaxed">武具を失う代わりに、現行の強化レシピで+0から現在の強化値までに必要な素材の75%を回収します。素材ごとに累計し、端数は切り捨てます。分解は無料です。</p>
            <p class="mt-2 text-sm text-slate-600">強化に使ったGold、武具の売却代金、進化・銘・特攻・耐性加工の材料は戻りません。名もなき武具と未強化品、売却不可の特殊装備は対象外です。</p>
            <a href="{{ route('blacksmith.index') }}" class="mt-3 inline-block py-2 text-sm font-bold underline">鍛冶屋へ戻る</a>
        </div>
        <nav class="grid grid-cols-3 gap-2" aria-label="分解する武具の種類">
            @foreach($types as $type => $label)
                <a href="{{ route('smith.disassemble.index', ['type' => $type]) }}" @if($equipmentType === $type) aria-current="page" @endif class="rounded-lg border px-2 py-3 text-center text-sm font-bold {{ $equipmentType === $type ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700' }}">{{ $label }}</a>
            @endforeach
        </nav>
        @forelse($decompositionCandidates as $candidate)
            <article class="rounded-lg border border-slate-200 bg-white p-4">
                <h3 class="break-words text-sm font-bold">{{ $candidate['equipment_name'] }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $candidate['equipment_type_label'] }} / {{ $candidate['rank'] }} / 個体 #{{ $candidate['equipment_instance_id'] }}{{ $candidate['is_stored'] ? ' / 倉庫保管中' : '' }}</p>
                @if($candidate['can_disassemble'])
                    <p class="mt-2 text-sm">回収素材：合計 {{ number_format($candidate['returned_total']) }}個</p>
                    <a href="{{ route('smith.disassemble.confirm', $candidate['equipment_instance_id']) }}" class="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-center text-sm font-bold text-red-800">返却素材を確認する</a>
                @else
                    <p class="mt-2 text-sm text-slate-600">{{ $candidate['unavailable_reason'] }}</p>
                @endif
            </article>
        @empty
            <p class="rounded-lg border border-slate-200 bg-white p-4 text-sm">強化済みの{{ $types[$equipmentType] }}を所持していません。</p>
        @endforelse
        {{ $decompositionCandidates->links() }}
    </div>
</x-layouts.facility>
