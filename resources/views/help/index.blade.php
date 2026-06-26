<x-layouts.facility title="ヘルプ" headerIconImage="images/icon/icon_013.webp" :showExit="false">
    <div class="mx-auto w-full max-w-[600px] px-3 pb-24 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <a href="{{ url('/') }}" class="text-sm font-bold text-slate-500 hover:text-slate-800">← トップへ</a>
        </div>

        @include('help._sections', ['helpContent' => $helpContent])

        <x-back-button href="{{ url('/') }}" label="トップへ戻る" icon="🏠" />
    </div>
</x-layouts.facility>
