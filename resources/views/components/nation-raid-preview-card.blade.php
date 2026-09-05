@if(config('features.nation_competitive_raid_preview_enabled', false))
    <a href="{{ route('nation-raid.preview') }}" class="my-3 flex min-h-16 items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 text-slate-800 shadow-sm hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700" data-nation-raid-preview-entry>
        <img src="{{ asset(config('nation_raid_preview.boss_image')) }}" alt="" width="64" height="64" loading="lazy" class="h-16 w-16 shrink-0 object-contain">
        <span class="min-w-0 flex-1">
            <span class="block text-xs font-bold text-sky-800">事前公開・開催準備中</span>
            <span class="mt-1 block text-base font-black">国家対抗レイド</span>
            <span class="mt-1 block text-xs leading-relaxed text-slate-500">黒天竜との戦いと予定報酬を見る</span>
        </span>
        <span aria-hidden="true" class="text-slate-400">›</span>
    </a>
@endif
