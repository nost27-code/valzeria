@php
    $headerIconImage = 'images/icon/icon_020.webp';
    $bgImage = 'images/facilities/association.webp';
    $title = '冒険者協会';
@endphp

<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10">
        <div class="bg-white p-5 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50 space-y-5">
            <div>
                <h2 class="text-xl font-extrabold text-slate-800 flex items-center gap-2">
                    <img src="{{ asset('images/icon/icon_020.webp') }}" alt="" class="w-6 h-6 object-contain"> 冒険者協会
                </h2>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    冒険者協会の救助支援システムは、新しい仕様に合わせて調整中です。
                </p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div class="text-sm font-extrabold text-slate-700">準備中</div>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    敗北時の支援や探索の補助は、素材・印・戦利品の仕組みと合わせて再設計します。
                </p>
            </div>
        </div>
    </div>
</x-layouts.facility>
