<x-layouts.facility
    title="英雄試練殿"
    subtitle="魔王城ヴァルゼリア"
    headerIconImage="images/symbol/hero_trial_hall.webp"
    bgImage="images/bg-battle.webp"
    pageBackgroundClass="bg-slate-950"
    exitLabel="魔王城へ戻る"
>
    <div class="mx-auto w-full max-w-4xl space-y-4 px-3 py-4 sm:px-6">
        <section class="rounded-xl border border-amber-300/70 bg-slate-950 px-4 py-4 text-center text-white shadow-lg">
            <div class="text-xs font-black tracking-[0.25em] text-amber-300">HERO TRIAL HALL</div>
            <h1 class="mt-1 text-xl font-black">挑む試練を選べ</h1>
            <p class="mt-2 text-xs leading-relaxed text-slate-300">冠位を極めた者だけに、英雄へ至る道が姿を現す。</p>
            <p class="mt-2 text-xs font-bold leading-relaxed text-amber-100">試練を越えると対応する英雄職が神殿に解放されます。勝利しただけで自動的に転職することはありません。</p>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach($trials as $trial)
                <article class="relative overflow-hidden rounded-xl border border-amber-300/60 bg-white shadow-lg">
                    @if(! empty($trial['bg_image']))
                        <div class="absolute inset-0 bg-cover bg-right opacity-15" style="background-image: url('{{ asset('images/'.ltrim($trial['bg_image'], '/')) }}');"></div>
                    @endif

                    <div class="relative flex h-full flex-col p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full border border-amber-300 bg-white/90 p-1 shadow">
                                <img src="{{ asset('images/'.ltrim($trial['symbol_image'], '/')) }}" alt="{{ $trial['name'] }}" class="h-full w-full object-contain">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[10px] font-black tracking-wider text-amber-700">{{ $trial['badge'] ?? '英雄試練' }}</div>
                                <h2 class="text-lg font-black text-slate-950">{{ $trial['name'] }}</h2>
                                <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $trial['desc'] }}</p>
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            @if(
                                ! empty($trial['challenge_requirements'])
                                && ! ($trial['challenge_requirements']['ready'] ?? false)
                            )
                                <div x-data="heroTrialRequirementModal(@js(route('hero-trials.rest', ['trialKey' => $trial['params']['trialKey']])), @js(csrf_token()))">
                                    <button
                                        type="button"
                                        @click="open = true"
                                        class="inline-flex w-full items-center justify-center rounded-lg border-2 border-red-950 bg-red-900 px-4 py-2 text-sm font-black text-white shadow transition hover:bg-red-800 active:scale-[0.98]"
                                    >
                                        {{ $trial['action'] }}
                                    </button>

                                    <template x-teleport="body">
                                        <div
                                            x-cloak
                                            x-show="open"
                                            @keydown.escape.window="if (!resting) open = false"
                                            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 sm:p-6"
                                            role="presentation"
                                        >
                                            <section
                                                @click.outside="if (!resting) open = false"
                                                role="dialog"
                                                aria-modal="true"
                                                aria-labelledby="hero-trial-requirements-{{ $trial['params']['trialKey'] }}"
                                                class="flex max-h-[90dvh] w-full max-w-md flex-col overflow-hidden rounded-2xl border-2 border-amber-400 bg-white shadow-2xl"
                                            >
                                                <header class="flex items-start justify-between gap-3 border-b border-amber-200 bg-slate-950 px-4 py-4 text-white">
                                                    <div>
                                                        <p class="text-[10px] font-black tracking-[0.2em] text-amber-300">HERO TRIAL</p>
                                                        <h2 id="hero-trial-requirements-{{ $trial['params']['trialKey'] }}" class="mt-1 text-lg font-black">挑戦条件</h2>
                                                        <p class="mt-1 text-xs text-slate-300">{{ $trial['name'] }}</p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        @click="open = false"
                                                        :disabled="resting"
                                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/20 text-xl font-black text-white transition hover:bg-white/10 disabled:opacity-40"
                                                        aria-label="挑戦条件を閉じる"
                                                    >×</button>
                                                </header>

                                                <div class="overflow-y-auto p-4">
                                                    <div class="space-y-2">
                                                        @foreach($trial['challenge_requirements']['items'] as $requirement)
                                                            <div class="flex items-center gap-3 rounded-xl border px-3 py-2.5 {{ $requirement['met'] ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
                                                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-black text-white {{ $requirement['met'] ? 'bg-emerald-600' : 'bg-rose-600' }}">
                                                                    {{ $requirement['met'] ? '✓' : '!' }}
                                                                </span>
                                                                <div class="min-w-0 flex-1">
                                                                    <p class="text-sm font-black text-slate-800">{{ $requirement['label'] }}</p>
                                                                    @if(array_key_exists('current', $requirement) && array_key_exists('required', $requirement))
                                                                        <p class="mt-0.5 text-xs font-bold text-slate-500">
                                                                            現在 {{ number_format((int) $requirement['current']) }} / 最大 {{ number_format((int) $requirement['required']) }}
                                                                        </p>
                                                                    @endif
                                                                </div>
                                                                <span class="shrink-0 rounded-full px-2 py-1 text-[10px] font-black {{ $requirement['met'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                                                    {{ $requirement['met'] ? '達成' : '未達' }}
                                                                </span>
                                                            </div>
                                                        @endforeach
                                                    </div>

                                                    @if($trial['challenge_requirements']['only_hp_sp_missing'] ?? false)
                                                        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-center">
                                                            <h3 class="text-base font-black text-amber-950">宿屋に泊まりますか？</h3>
                                                            <p class="mt-1 text-xs font-bold leading-relaxed text-amber-900">
                                                                宿泊料金は{{ number_format((int) ($trial['inn_fee'] ?? 0)) }}Gです。<br>
                                                                この場で休むとHP/SPが全回復します。
                                                            </p>
                                                            <button
                                                                type="button"
                                                                @click="restAtInn"
                                                                :disabled="resting"
                                                                class="mt-3 inline-flex w-full items-center justify-center rounded-lg border-2 border-amber-800 bg-amber-500 px-4 py-2.5 text-sm font-black text-slate-950 shadow transition hover:bg-amber-400 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60"
                                                            >
                                                                <span x-show="!resting">宿屋で休む</span>
                                                                <span x-cloak x-show="resting">宿屋で休んでいます...</span>
                                                            </button>
                                                        </div>
                                                    @else
                                                        <p class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-center text-xs font-bold leading-relaxed text-slate-600">
                                                            未達の条件を満たしてから、もう一度確認してください。
                                                        </p>
                                                    @endif

                                                    <p
                                                        x-cloak
                                                        x-show="feedback"
                                                        x-text="feedback"
                                                        class="mt-3 rounded-lg px-3 py-2 text-center text-xs font-black"
                                                        :class="feedbackError ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
                                                        role="status"
                                                    ></p>
                                                </div>
                                            </section>
                                        </div>
                                    </template>
                                </div>
                            @elseif(! empty($trial['is_post']))
                                <form action="{{ route($trial['route'], $trial['params'] ?? []) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                    @csrf
                                    <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full items-center justify-center rounded-lg border-2 border-red-950 bg-red-900 px-4 py-2 text-sm font-black text-white shadow transition hover:bg-red-800 active:scale-[0.98] disabled:cursor-wait disabled:opacity-70">
                                        <span x-show="!submitting">{{ $trial['action'] }}</span>
                                        <span x-show="submitting" style="display: none;">試練へ向かっている...</span>
                                    </button>
                                </form>
                            @else
                                @if(! empty($trial['route']))
                                    <a href="{{ route($trial['route'], $trial['params'] ?? []) }}" class="inline-flex w-full items-center justify-center rounded-lg border-2 border-amber-700 bg-amber-500 px-4 py-2 text-sm font-black text-slate-950 shadow transition hover:bg-amber-400 active:scale-[0.98]">
                                        {{ $trial['action'] }}
                                    </a>
                                @else
                                    <button type="button" disabled class="inline-flex w-full cursor-not-allowed items-center justify-center rounded-lg border-2 border-slate-400 bg-slate-200 px-4 py-2 text-sm font-black text-slate-500 shadow">
                                        {{ $trial['action'] }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>

    @once
        <script>
        (() => {
            const registerHeroTrialRequirementModal = () => {
                Alpine.data('heroTrialRequirementModal', (restUrl, csrfToken) => ({
                    open: false,
                    resting: false,
                    feedback: '',
                    feedbackError: false,
                    async restAtInn() {
                        if (this.resting) return;

                        this.resting = true;
                        this.feedback = '';
                        this.feedbackError = false;
                        const formData = new FormData();
                        formData.append('_token', csrfToken);

                        try {
                            const response = await fetch(restUrl, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: formData
                            });
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok || data.success !== true) {
                                throw new Error(data.message || '宿屋を利用できませんでした。');
                            }

                            this.feedback = `${data.message} 画面を更新しています...`;
                            window.setTimeout(() => window.location.reload(), 1200);
                        } catch (error) {
                            this.feedbackError = true;
                            this.feedback = error instanceof Error
                                ? error.message
                                : '通信に失敗しました。もう一度お試しください。';
                        } finally {
                            this.resting = false;
                        }
                    }
                }));
            };

            if (window.Alpine) {
                registerHeroTrialRequirementModal();
            } else {
                document.addEventListener('alpine:init', registerHeroTrialRequirementModal, { once: true });
            }
        })();
        </script>
    @endonce
</x-layouts.facility>
