<div class="max-w-2xl mx-auto">
    {{-- ヘッダー --}}
    <div class="mb-6 text-center">
        <img src="{{ asset('images/title_logo.webp') }}" alt="ヴァルゼリアの冒険者" class="mx-auto mb-3 h-16 w-auto object-contain">
        <h1 class="text-2xl font-black tracking-wide text-slate-900">キャラクター作成</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">
            ヴァルゼリアの冒険者へようこそ。<br>
            あなたの分身となるキャラクターを作成してください。
        </p>
    </div>

    <div class="rounded-2xl border border-[#d4af37]/50 bg-white p-6 shadow-sm sm:p-8">
        <form wire:submit="create" class="space-y-7">

            {{-- キャラクター名 --}}
            <div>
                <label class="mb-1.5 block text-xs font-black uppercase tracking-widest text-amber-700">キャラクター名</label>
                <input type="text" wire:model="name" maxlength="10" placeholder="2〜10文字"
                       class="w-full rounded-lg border-slate-300 bg-slate-50 px-4 py-2.5 text-slate-900 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                @error('name') <span class="mt-1 block text-xs font-bold text-red-500">{{ $message }}</span> @enderror
            </div>

            {{-- 性別 --}}
            <div>
                <label class="mb-1.5 block text-xs font-black uppercase tracking-widest text-amber-700">性別</label>
                <div class="flex flex-wrap gap-2">
                    @foreach(['男性', '女性', 'その他', '未設定'] as $g)
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-3.5 py-2 text-sm font-bold transition
                            {{ $gender === $g
                                ? 'border-amber-500 bg-amber-50 text-amber-700'
                                : 'border-slate-200 text-slate-600 hover:border-amber-300 hover:bg-amber-50/50' }}">
                            <input type="radio" wire:model.live="gender" value="{{ $g }}" class="hidden">
                            {{ $g }}
                        </label>
                    @endforeach
                </div>
                @error('gender') <span class="mt-1 block text-xs font-bold text-red-500">{{ $message }}</span> @enderror
            </div>

            {{-- 初期職業 --}}
            <div>
                <label class="mb-1.5 block text-xs font-black uppercase tracking-widest text-amber-700">初期職業</label>
                <div class="grid grid-cols-2 gap-3">
                    @php
                        $jobs = [
                            'warrior' => ['name' => '戦士',    'icon_image' => 'images/icon/icon_006.webp', 'desc' => 'HP・攻撃・防御が高い安定型', 'skill' => '会心斬り'],
                            'mage'    => ['name' => '魔法使い', 'icon_image' => 'images/icon/icon_056.webp', 'desc' => '魔力が高く魔法攻撃が得意',   'skill' => 'ファイア'],
                            'priest'  => ['name' => '僧侶',    'icon_image' => 'images/icon/icon_042.webp', 'desc' => '回復系スキルを持つ安定型',   'skill' => 'ヒール'],
                            'thief'   => ['name' => '盗賊',    'icon_image' => 'images/icon/icon_057.webp', 'desc' => '素早さ・運が高く回避に優れる','skill' => '急所突き'],
                        ];
                    @endphp
                    @foreach($jobs as $key => $job)
                        <label class="cursor-pointer rounded-xl border p-4 transition
                            {{ $job_key === $key
                                ? 'border-amber-500 bg-amber-50 ring-1 ring-amber-300'
                                : 'border-slate-200 bg-white hover:border-amber-300 hover:bg-amber-50/40' }}">
                            <input type="radio" wire:model.live="job_key" value="{{ $key }}" class="hidden">
                            <div class="mb-1.5 flex items-center gap-2">
                                <img src="{{ asset($job['icon_image']) }}" alt="" class="w-6 h-6 object-contain">
                                <span class="font-black {{ $job_key === $key ? 'text-amber-800' : 'text-slate-800' }}">{{ $job['name'] }}</span>
                            </div>
                            <p class="text-xs leading-relaxed text-slate-500">{{ $job['desc'] }}</p>
                            <p class="mt-1.5 text-[11px] font-bold {{ $job_key === $key ? 'text-amber-600' : 'text-slate-400' }}">
                                スキル: {{ $job['skill'] }}
                            </p>
                        </label>
                    @endforeach
                </div>
                @error('job_key') <span class="mt-1 block text-xs font-bold text-red-500">{{ $message }}</span> @enderror
            </div>

            {{-- アイコン選択 --}}
            <div>
                <label class="mb-1.5 block text-xs font-black uppercase tracking-widest text-amber-700">キャラクターアイコン</label>
                <div class="grid grid-cols-4 gap-2.5 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3 shadow-inner sm:grid-cols-5 md:grid-cols-7">
                    @foreach($characterIconPaths as $path)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model.live="icon_path" value="{{ $path }}" class="hidden">
                            <div class="flex aspect-square items-center justify-center overflow-hidden rounded-lg border-2 p-1 transition-all
                                {{ $icon_path === $path
                                    ? 'border-[#d4af37] bg-amber-50 ring-1 ring-[#d4af37]/40'
                                    : 'border-slate-200 bg-white hover:border-[#d4af37]/60' }}">
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($path) }}" alt=""
                                     class="max-h-full max-w-full object-contain transition-transform duration-200 {{ $icon_path === $path ? 'scale-105' : '' }}">
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- 作成ボタン --}}
            <div class="pt-1">
                <button type="submit"
                        class="w-full rounded-xl bg-amber-600 py-3.5 text-sm font-black text-white shadow-sm transition hover:bg-amber-700 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                    この内容でキャラクターを作成する
                </button>
            </div>

        </form>
    </div>
</div>
