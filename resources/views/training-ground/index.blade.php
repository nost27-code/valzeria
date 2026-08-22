<x-layouts.facility
    title="冒険者訓練所"
    headerIconImage="images/icon/icon_005.webp"
    bgImage="images/bg-battle.webp"
    :showGameHeader="true"
    exitLabel="街へ戻る"
>
    <div class="mx-auto max-w-3xl space-y-4 py-3">
        @if(session('message'))
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-bold text-sky-900">
                {{ session('message') }}
            </div>
        @endif
        @if($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

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

        <section class="rounded-xl border border-indigo-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs font-black text-indigo-700">訓練前の準備</div>
                    <h2 class="mt-1 text-base font-black text-slate-950">戦技セットを整える</h2>
                </div>
                <span class="text-xs font-bold text-slate-500">変更は戦技セット画面で自動保存</span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2">
                <a href="{{ route('job-arts.index', ['context' => 'normal']) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-2 text-center text-xs font-black text-emerald-800">通常戦用</a>
                <a href="{{ route('job-arts.index', ['context' => 'boss']) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-2 text-center text-xs font-black text-rose-800">ボス戦用</a>
                <a href="{{ route('job-arts.index', ['context' => $pvpSetEnabled ? 'pvp' : 'boss']) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-violet-200 bg-violet-50 px-2 text-center text-xs font-black text-violet-800">{{ $pvpSetEnabled ? 'PvP戦用' : '対人用（ボス戦用）' }}</a>
            </div>
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
                    <a href="{{ route('job-arts.index', ['context' => $context === 'boss' ? 'boss' : 'normal']) }}" class="mt-2 inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-black text-slate-700">
                        {{ $loadout['label'] }}を整える
                    </a>
                </section>
            @endforeach
        </div>

        <section class="rounded-xl border border-violet-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-black text-violet-700">PLAYER VS PLAYER</div>
                    <h2 class="mt-1 text-xl font-black text-slate-950">対人模擬戦</h2>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                        冒険者を一人選び、通常の闘技場と同じルールで腕試しできる。実際のHP/SP、順位、勝敗、報酬、対戦履歴、実績計測には反映されない。
                    </p>
                </div>
                <a href="{{ route('job-arts.index', ['context' => $pvpSetEnabled ? 'pvp' : 'boss']) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-violet-300 bg-violet-50 px-3 text-xs font-black text-violet-800">
                    {{ $pvpSetEnabled ? 'PvP戦技セットを整える' : '対人用のボス戦技を整える' }}
                </a>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <h3 class="text-sm font-black text-slate-900">キャラクター名から探す</h3>
                    <form action="{{ route('training-ground.index') }}" method="GET" class="mt-2 flex gap-2">
                        <label for="opponent-search" class="sr-only">キャラクター名</label>
                        <input id="opponent-search" name="opponent_search" value="{{ $opponentSearch }}" maxlength="50" placeholder="名前の一部を入力" class="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-900">
                        <button type="submit" class="min-h-11 shrink-0 rounded-lg bg-slate-800 px-4 text-sm font-black text-white">検索</button>
                    </form>

                    @if($opponentSearch !== '')
                        <div class="mt-3 space-y-2">
                            @forelse($searchResults as $opponent)
                                <a href="{{ route('training-ground.index', ['opponent_search' => $opponentSearch, 'opponent_id' => $opponent->id]) }}" class="flex min-h-11 items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-800">
                                    <span class="min-w-0 truncate">{{ $opponent->name }}</span>
                                    <span class="shrink-0 text-xs text-slate-500">Lv{{ number_format((int) $opponent->level) }}{{ $opponent->arenaRanking ? '・'.$opponent->arenaRanking->rank.'位' : '' }}</span>
                                </a>
                            @empty
                                <p class="rounded-lg bg-white px-3 py-3 text-center text-xs font-bold text-slate-500">該当する冒険者は見つからなかった。</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <h3 class="text-sm font-black text-slate-900">闘技場ランキングから選ぶ</h3>
                    <form action="{{ route('training-ground.index') }}" method="GET" class="mt-2 space-y-2">
                        <label for="ranking-opponent" class="sr-only">ランキングの冒険者</label>
                        <select id="ranking-opponent" name="opponent_id" class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-900">
                            <option value="">対戦相手を選択</option>
                            @foreach($rankingOpponents as $ranking)
                                <option value="{{ $ranking->character_id }}" @selected((int) old('opponent_id', $selectedOpponent?->id) === (int) $ranking->character_id)>
                                    {{ number_format((int) $ranking->rank) }}位　{{ $ranking->character->name }}（Lv{{ number_format((int) $ranking->character->level) }}）
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="min-h-11 w-full rounded-lg border border-violet-300 bg-white px-3 text-sm font-black text-violet-800">この冒険者を選ぶ</button>
                    </form>
                    @if($rankingOpponents->isEmpty())
                        <p class="mt-2 text-center text-xs font-bold text-slate-500">選べるランキング参加者はまだいない。</p>
                    @endif
                </div>
            </div>

            @if($selectedOpponent)
                <div class="mt-4 rounded-xl border-2 border-violet-300 bg-violet-50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-black text-violet-700">選択中の対戦相手</div>
                            <div class="mt-1 text-lg font-black text-slate-950">{{ $selectedOpponent->name }}</div>
                            <div class="mt-1 text-xs font-bold text-slate-600">
                                Lv{{ number_format((int) $selectedOpponent->level) }}
                                @if($selectedOpponent->currentJob)・{{ $selectedOpponent->currentJob->name }}@endif
                                @if($selectedOpponent->arenaRanking)・闘技場 {{ number_format((int) $selectedOpponent->arenaRanking->rank) }}位@endif
                            </div>
                        </div>
                        <form action="{{ route('training-ground.battle') }}" method="POST" class="w-full sm:w-auto" data-submit-lock>
                            @csrf
                            <input type="hidden" name="context" value="pvp">
                            <input type="hidden" name="opponent_id" value="{{ $selectedOpponent->id }}">
                            <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-violet-700 px-5 py-3 text-sm font-black text-white shadow active:scale-[0.99] sm:w-auto">
                                {{ $selectedOpponent->name }}と模擬戦する
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <p class="mt-4 rounded-lg border border-dashed border-violet-300 bg-violet-50 px-3 py-3 text-center text-xs font-bold text-violet-800">名前検索かランキングから対戦相手を選ぼう。</p>
            @endif
        </section>
    </div>
</x-layouts.facility>
