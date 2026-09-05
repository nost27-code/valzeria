@php
    $headerEyebrow = $headerEyebrow ?? 'NATION';
    $canChangeBackground = $canChangeBackground ?? false;
    $developmentLevel = $developmentLevel ?? null;
    $nationCapacityPercent = $maxMembers > 0
        ? min(100, round(($memberCount / $maxMembers) * 100, 1))
        : 0;
    $raidHonor = app(\App\Services\Nation\Raid\NationRaidHonorService::class)->forNation($nation);
@endphp

<img src="{{ asset($nation->header_background['path']) }}" alt="" width="600" height="232" class="absolute inset-0 h-full w-full object-cover" aria-hidden="true">
<div class="absolute inset-0 bg-gradient-to-r from-slate-950/65 via-slate-950/30 to-slate-950/60" aria-hidden="true"></div>
<div class="absolute inset-0 bg-gradient-to-b from-slate-950/10 via-slate-950/15 to-slate-950/80" aria-hidden="true"></div>
<div class="pointer-events-none absolute inset-1 rounded-xl border border-amber-200/70 shadow-[inset_0_0_24px_rgba(15,23,42,0.7)]" aria-hidden="true"></div>

@if($canChangeBackground)
    <button
        type="button"
        wire:click="openHeaderBackgroundModal"
        class="absolute right-2 top-2 z-20 min-h-5 rounded-full border border-amber-200/70 bg-slate-950/70 px-2 text-[8px] font-black leading-none text-amber-50 shadow-lg backdrop-blur-sm transition hover:bg-slate-900 sm:right-3 sm:top-3 sm:min-h-6 sm:text-[10px]"
        data-nation-header-background-open
        aria-label="国家ヘッダの背景を変更"
    >
        背景
    </button>
@endif

<div class="relative z-10 px-3 py-2.5 sm:px-5 sm:py-3" data-nation-header-layout="crest-details">
    <div class="mx-auto flex w-full max-w-xl items-center gap-2.5 sm:gap-4">
        <div class="flex w-20 shrink-0 justify-center sm:w-28" data-nation-header-emblem>
            <img src="{{ asset($nation->emblem['path']) }}" alt="{{ $nation->emblem['alt'] }}" width="128" height="128" class="h-20 w-20 object-contain drop-shadow-[0_4px_6px_rgba(0,0,0,0.75)] sm:h-28 sm:w-28">
        </div>

        <div class="min-w-0 flex-1 text-amber-50">
            <div class="flex min-w-0 items-start gap-1.5 pr-0.5">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1 text-amber-100 drop-shadow-lg">
                        <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 16h14l1-9-5 4-3-7-3 7-5-4 1 9Zm0 2h14v2H5v-2Z"/></svg>
                        <span class="text-[8px] font-black tracking-[0.2em] sm:text-[10px]">{{ $headerEyebrow }}</span>
                    </div>
                    <h1 class="mt-0.5 break-words text-xl font-black leading-tight tracking-[0.04em] text-amber-50 sm:text-3xl" style="text-shadow: 0 2px 4px rgba(0, 0, 0, .95);" data-nation-nameplate>
                        {{ $nation->display_name }}
                    </h1>
                    @if($raidHonor)
                        <p class="mt-1 text-xs font-bold text-amber-100" data-nation-raid-honor>{{ $raidHonor['label'] }}</p>
                    @endif
                    <p class="mt-0.5 flex min-w-0 items-center gap-1 text-[9px] font-bold text-amber-50/90 sm:text-xs" data-nation-header-ruler>
                        <svg class="h-3 w-3 shrink-0 text-amber-300 sm:h-3.5 sm:w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 16h14l1-9-5 4-3-7-3 7-5-4 1 9Zm0 2h14v2H5v-2Z"/></svg>
                        <span class="shrink-0">{{ $nation->ruler_title }}：</span>
                        <span class="min-w-0 truncate">{{ $nation->rulerMembership?->character?->name ?? '不明' }}</span>
                    </p>
                </div>

                <div class="mt-5 w-9 shrink-0 bg-amber-300 p-px drop-shadow-lg sm:mt-6 sm:w-11" style="clip-path: polygon(50% 0, 100% 18%, 88% 82%, 50% 100%, 12% 82%, 0 18%);" data-nation-header-level-badge>
                    <div class="flex min-h-10 flex-col items-center justify-center bg-slate-900 px-0.5 py-1 text-center sm:min-h-12" style="clip-path: polygon(50% 0, 100% 18%, 88% 82%, 50% 100%, 12% 82%, 0 18%);">
                        @if($developmentEnabled && $developmentLevel !== null)
                            <span class="text-[7px] font-black leading-none text-amber-100 sm:text-[9px]">国家Lv</span>
                            <span class="mt-0.5 text-sm font-black leading-none text-amber-300 sm:text-lg">{{ $developmentLevel }}</span>
                        @else
                            <span class="text-[7px] font-black leading-none text-amber-100 sm:text-[9px]">国号</span>
                            <span class="mt-0.5 max-w-full truncate text-[8px] font-black leading-none text-amber-300 sm:text-[10px]">{{ $nation->nation_type_label }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-1.5 flex min-w-0 items-end gap-2 border-t border-amber-100/25 pt-1.5 sm:mt-2 sm:gap-3 sm:pt-2" data-nation-header-member-summary>
                <div class="shrink-0">
                    <p class="flex items-center gap-1 text-[8px] font-bold leading-none text-amber-100/80 sm:text-[10px]">
                        <svg class="h-3 w-3 shrink-0 text-amber-300 sm:h-3.5 sm:w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM1 21v-3c0-3 3-5 7-5s7 2 7 5v3H1Zm14.5 0v-3c0-1.5-.6-2.8-1.7-3.8.7-.1 1.4-.2 2.2-.2 4 0 7 2 7 5v2h-7.5Z"/></svg>
                        国民数
                    </p>
                    <p class="mt-0.5 whitespace-nowrap text-[11px] font-black leading-none sm:text-sm">{{ $memberCount }} / {{ $maxMembers }}人</p>
                </div>

                <div class="min-w-0 flex-1" data-nation-header-capacity>
                    <div class="mb-1 flex justify-end">
                        <span class="inline-flex max-w-full items-center rounded-full px-1.5 py-0.5 text-center text-[7px] font-black sm:px-2 sm:text-[9px] {{ $nation->recruitment_enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-700' }}" data-nation-header-recruitment>
                            {{ $nation->recruitment_enabled ? '国民募集中' : '募集停止' }}
                        </span>
                    </div>
                    <div class="flex h-4 min-w-0 items-center gap-1 rounded-full border border-amber-100/25 bg-slate-950/75 px-1 shadow-inner sm:h-5 sm:px-1.5" role="img" aria-label="国民数 {{ $memberCount }} / {{ $maxMembers }}人">
                        <svg class="h-2.5 w-2.5 shrink-0 text-amber-50/80 sm:h-3 sm:w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM1 21v-3c0-3 3-5 7-5s7 2 7 5v3H1Zm14.5 0v-3c0-1.5-.6-2.8-1.7-3.8.7-.1 1.4-.2 2.2-.2 4 0 7 2 7 5v2h-7.5Z"/></svg>
                        <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-700 sm:h-2" aria-hidden="true">
                            <span class="block h-full rounded-full bg-gradient-to-r from-emerald-200 to-emerald-400" style="width: {{ $nationCapacityPercent }}%"></span>
                        </span>
                        <span class="shrink-0 text-[7px] font-black text-amber-50 sm:text-[9px]">{{ $memberCount }} / {{ $maxMembers }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
