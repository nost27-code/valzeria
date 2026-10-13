@php($update = $art->job_art_update)
@once
    <style>
        @media (max-width: 359px) {
            [data-job-art-updated-card] [data-job-art-card-header-row] { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
@endonce
<details class="rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-3"
    data-job-art-update-explanation data-skill-id="{{ $art->id }}"
    data-revision="{{ $update['revision'] }}" data-unread="{{ $update['unread'] ? 'true' : 'false' }}"
    data-read-url="{{ route('job-arts.update-read', $art->id) }}">
    <summary class="cursor-pointer text-xs font-black text-slate-800">効果解説を見る
        <span class="ml-1 rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white {{ $update['unread'] ? '' : 'hidden' }}" data-job-art-update-unread>未読</span>
    </summary>
    <p class="mt-2 text-[10px] font-bold text-amber-800">{{ $update['date'] }} 更新</p>
    <p class="mt-1 break-words text-[12px] font-bold leading-6 text-slate-800">@include('job-arts.partials.effect-text', ['text' => $effectDescription])</p>
    <p class="mt-2 text-[10px] font-bold text-slate-500" data-job-art-update-read-status role="status"></p>
</details>
