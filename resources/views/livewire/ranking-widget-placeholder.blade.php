<section
    class="w-full overflow-hidden rounded-lg border border-[#d4af37] bg-white shadow-[0_4px_14px_rgba(126,96,28,0.12)]"
    role="status"
    aria-label="週間番付を準備中"
>
    <div class="flex bg-[#0a1628]">
        <div class="flex-1 bg-[#1b2c47] px-2 py-1.5 text-center text-[11px] font-black tracking-wider text-[#d4af37]">週間勝利</div>
        <div class="flex-1 px-2 py-1.5 text-center text-[11px] font-black tracking-wider text-amber-100/50">闘技場</div>
    </div>

    <div>
        <div class="border-b border-amber-100 bg-amber-50 px-3 py-2">
            <div class="h-2.5 w-2/3 rounded-full bg-amber-200/60"></div>
            <div class="mt-2 h-3 w-1/2 rounded-full bg-slate-200"></div>
        </div>
        <div class="divide-y divide-slate-100 px-3">
            @foreach(range(1, 3) as $row)
                <div class="flex items-center gap-2 py-1.5" aria-hidden="true">
                    <div class="h-3 w-5 rounded bg-slate-200"></div>
                    <div class="h-8 w-8 rounded-full bg-amber-100"></div>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-1/2 rounded-full bg-slate-200"></div>
                        <div class="h-2 w-1/3 rounded-full bg-slate-100"></div>
                    </div>
                    <div class="h-3 w-12 rounded-full bg-amber-100"></div>
                </div>
            @endforeach
        </div>
    </div>
</section>
