<x-layouts.facility
    title="冒険者訓練所"
    headerIconImage="images/icon/icon_005.webp"
    bgImage="images/bg-battle.webp"
    :showGameHeader="true"
    exitLabel="街へ戻る"
>
    <div class="mx-auto max-w-3xl space-y-4 py-3">
        <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 shadow-sm">
            <h2 class="text-base font-black text-sky-950">奥義を{{ number_format($maxTurns) }}ターン試せる模擬訓練</h2>
            <p class="mt-2 text-sm font-bold leading-relaxed text-sky-900">
                訓練人形は倒れず、こちらもHP1で踏みとどまる。相手の攻撃は直接物理攻撃だけで、1回のダメージは最大HPの{{ rtrim(rtrim(number_format($damageCapPercent, 2), '0'), '.') }}%以下だ。
            </p>
            <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs font-black text-sky-900 sm:grid-cols-4">
                <div class="rounded-lg border border-sky-200 bg-white px-2 py-2">HP/SP全快で開始</div>
                <div class="rounded-lg border border-sky-200 bg-white px-2 py-2">{{ number_format($maxTurns) }}ターン固定</div>
                <div class="rounded-lg border border-sky-200 bg-white px-2 py-2">何度でも挑戦可</div>
                <div class="rounded-lg border border-sky-200 bg-white px-2 py-2">報酬・戦績なし</div>
            </div>
            <p class="mt-3 text-xs font-bold leading-relaxed text-slate-600">
                訓練後も実際のHP/SP、勝敗、探索支援品、EXP、職業EXP、Gold、ドロップ、各種待機時間は変わらない。
            </p>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach($loadouts as $context => $loadout)
                <section class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-black {{ $context === 'boss' ? 'text-rose-700' : 'text-emerald-700' }}">使用する奥義</div>
                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $loadout['label'] }}</h2>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">{{ $loadout['description'] }}</p>

                    <div class="mt-3 flex-1 rounded-lg border border-slate-100 bg-slate-50 p-3">
                        @forelse($loadout['arts'] as $art)
                            <div class="flex items-center justify-between gap-2 border-b border-slate-200 py-1.5 text-xs font-bold last:border-b-0">
                                <span class="min-w-0 truncate text-slate-800">枠{{ (int) $art->getAttribute('slot_no') }}　{{ $art->name }}</span>
                                <span class="shrink-0 text-slate-500">Cost {{ (int) $art->getAttribute('job_art_effective_cost') }}</span>
                            </div>
                        @empty
                            <div class="py-2 text-center text-xs font-bold text-slate-500">このセットに奥義は登録されていない。</div>
                        @endforelse
                    </div>

                    <form action="{{ route('training-ground.battle') }}" method="POST" class="mt-4" data-submit-lock>
                        @csrf
                        <input type="hidden" name="context" value="{{ $context }}">
                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-4 py-3 text-sm font-black text-white shadow active:scale-[0.99] {{ $context === 'boss' ? 'bg-rose-700' : 'bg-emerald-700' }}">
                            {{ $loadout['label'] }}で訓練する
                        </button>
                    </form>
                </section>
            @endforeach
        </div>
    </div>
</x-layouts.facility>
