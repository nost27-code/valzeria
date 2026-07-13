@php
    $badges = [];
    foreach (($base ?? []) as $line) { $badges[] = ['text' => $line, 'class' => 'bg-slate-100 text-slate-700']; }
    foreach (($engraving ?? []) as $line) { $badges[] = ['text' => $line, 'class' => 'bg-amber-100 text-amber-800']; }
    foreach (($slayer ?? []) as $line) { $badges[] = ['text' => $line, 'class' => 'bg-rose-100 text-rose-800']; }
@endphp
<div class="mt-2 flex flex-wrap gap-1 text-[11px] font-bold">
    @forelse($badges as $badge)
        <span class="rounded-full px-2 py-1 {{ $badge['class'] }}">{{ $badge['text'] }}</span>
    @empty
        <span class="rounded-full bg-slate-100 px-2 py-1 text-slate-400">表示できる補正なし</span>
    @endforelse
</div>
