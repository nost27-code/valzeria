<x-layouts.facility title="国家対抗レイド" subtitle="全国家共闘イベント" :exit-url="route('home')" exitLabel="街へ戻る">
    <p class="rounded-lg border border-slate-200 bg-white p-6 text-sm font-bold text-slate-700">現在開催中のレイドはありません。</p>
    <a href="{{ route('nation-raid.history') }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">過去の戦果・未受取報酬</a>
</x-layouts.facility>
