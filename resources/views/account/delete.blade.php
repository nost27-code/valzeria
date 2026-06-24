<x-layouts.simple>
    <div class="w-full max-w-xl mx-auto bg-white border border-red-200 rounded-xl shadow-xl overflow-hidden">
        <div class="bg-red-700 px-5 py-4 text-white">
            <h1 class="text-lg font-extrabold">アカウント削除</h1>
            <p class="mt-1 text-xs font-semibold text-red-100">Google連携を含むログイン情報と作成データを削除します。</p>
        </div>

        <div class="p-5 space-y-4">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 font-semibold leading-relaxed">
                この操作は取り消せません。削除すると、キャラクター、装備、素材、進行状況、戦闘履歴など、このアカウントで作成したデータは復元できません。
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-bold text-slate-500">削除対象アカウント</div>
                <div class="mt-1 text-sm font-extrabold text-slate-900">{{ $user->name ?? '名称未設定' }}</div>
                <div class="mt-0.5 text-xs text-slate-600">{{ $user->email ?? 'メールアドレスなし' }}</div>
            </div>

            <div class="rounded-lg border border-slate-200 overflow-hidden">
                <div class="bg-slate-100 px-4 py-2 text-xs font-extrabold text-slate-700">削除されるキャラクター</div>
                <div class="divide-y divide-slate-100">
                    @forelse($characters as $character)
                        <div class="px-4 py-3 flex items-center justify-between gap-3 text-sm">
                            <div class="min-w-0">
                                <div class="font-extrabold text-slate-900 truncate">{{ $character->name }}</div>
                                <div class="text-xs text-slate-500">Lv {{ $character->level }} / {{ $character->jobClass->name ?? '職業なし' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-3 text-sm text-slate-500">キャラクターはありません。</div>
                    @endforelse
                </div>
            </div>

            @if($errors->any())
                <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('account.destroy') }}" class="space-y-3">
                @csrf
                @method('DELETE')

                <label class="block">
                    <span class="block text-xs font-extrabold text-slate-700 mb-1">確認のため「削除」と入力してください</span>
                    <input type="text" name="confirmation" autocomplete="off" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-base font-bold focus:border-red-500 focus:ring-red-500" placeholder="削除">
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    <a href="{{ route('character.select') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-extrabold text-slate-700 shadow-sm hover:bg-slate-50">
                        キャンセル
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-red-800 bg-red-700 px-4 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-red-800">
                        アカウントを完全に削除
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.simple>
