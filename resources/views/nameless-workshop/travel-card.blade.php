@if($namelessTown)
<section class="my-4 overflow-hidden rounded-xl border border-amber-300 bg-white shadow" data-nameless-town-entry>
    <img src="{{ asset(\App\Services\NamelessTownService::IMAGE) }}" alt="遺跡のふもとに築かれた無もなき工房街の街" class="w-full object-cover" style="height:160px">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="min-w-0"><h2 class="text-lg font-black text-amber-900">無もなき工房街</h2><p class="mt-1 text-sm text-slate-600">遺跡で拾い、工房で育てる。6つの遺跡へ続く探索者の街。</p></div>
        @if((int) $character->current_city_id === (int) $namelessTown->id)
            <span class="rounded bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900">滞在中</span>
        @else
            <form method="post" action="{{ route('city.travel', $namelessTown) }}">@csrf<button type="submit" class="rounded-lg bg-amber-800 px-5 py-3 text-sm font-bold text-white">無もなき工房街へ移動</button></form>
        @endif
    </div>
</section>
@endif
