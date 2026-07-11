<x-layouts.facility title="不具合報告" headerIcon="!" bgImage="images/bg-castle.webp" :showGameHeader="true">
    <div class="mx-auto w-full max-w-3xl px-3 py-5 sm:px-6 sm:py-8">
        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="text-xl font-black text-slate-950">不具合を報告する</h2>
            <p class="mt-2 text-sm font-bold leading-7 text-slate-600">発生した画面、操作、表示内容などをできるだけ詳しく教えてください。</p>

            <section class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3" aria-label="送信時に記録される情報">
                <div class="text-xs font-black tracking-wide text-slate-500">今回の報告と一緒に記録される情報</div>
                <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
                    <dt class="font-black text-slate-700">キャラクター名</dt>
                    <dd class="font-bold text-slate-900">{{ $character?->name ?? '取得できませんでした' }}</dd>
                    <dt class="font-black text-slate-700">利用環境</dt>
                    <dd class="break-all text-xs font-semibold leading-relaxed text-slate-600">{{ $userAgent ?: '取得できませんでした' }}</dd>
                </dl>
                <p class="mt-2 text-xs font-bold text-slate-400">本文に入力しなくても、上記の情報は管理人が確認できます。</p>
            </section>

            @if($errors->any())
                <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">入力内容を確認してください。</div>
            @endif

            <form method="POST" action="{{ route('bug-reports.store') }}" enctype="multipart/form-data" class="mt-5 space-y-5">
                @csrf
                <div>
                    <label for="body" class="block text-sm font-black text-slate-800">不具合の内容</label>
                    <textarea id="body" name="body" rows="10" required minlength="10" maxlength="5000" placeholder="例：探索後に報酬画面でボタンを押すと、画面が進まなくなりました。&#10;発生した時間：&#10;操作手順：" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-3 text-sm font-semibold leading-7 text-slate-800 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('body') }}</textarea>
                    <div class="mt-1 flex justify-between text-xs font-bold text-slate-400"><span>発生時刻・操作手順・表示内容があると確認しやすくなります。</span><span>最大5,000文字</span></div>
                    @error('body')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>

                <div x-data="{
                    files: [],
                    pendingFile: null,
                    isUploading: false,
                    selectFile(event) {
                        const file = event.target.files[0] ?? null;
                        event.target.value = '';
                        if (!file || this.files.length >= 5) return;

                        this.pendingFile = file;
                        this.isUploading = true;
                        window.setTimeout(() => {
                            this.addPendingFile();
                            this.isUploading = false;
                        }, 180);
                    },
                    syncAttachments() {
                        const transfer = new DataTransfer();
                        this.files.forEach((entry) => transfer.items.add(entry.file));
                        this.$refs.attachmentInput.files = transfer.files;
                    },
                    addPendingFile() {
                        if (!this.pendingFile || this.files.length >= 5) return;

                        this.files.push({ file: this.pendingFile, name: this.pendingFile.name, size: this.pendingFile.size });
                        this.syncAttachments();
                        this.pendingFile = null;
                    },
                    removeFile(index) {
                        this.files.splice(index, 1);
                        this.syncAttachments();
                    },
                    formatSize(size) {
                        return size >= 1024 * 1024 ? `${(size / 1024 / 1024).toFixed(1)} MB` : `${Math.ceil(size / 1024)} KB`;
                    }
                }">
                    <label for="attachments" class="block text-sm font-black text-slate-800">画像添付 <span class="font-bold text-slate-400">（任意・最大5枚・各5MBまで）</span></label>
                    <input x-ref="attachmentInput" type="file" name="attachments[]" multiple class="hidden" tabindex="-1" aria-hidden="true">
                    <input id="attachments" x-ref="attachmentPicker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" @change="selectFile($event)" :disabled="files.length >= 5 || isUploading" class="mt-1.5 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-amber-100 file:px-4 file:py-2 file:text-sm file:font-black file:text-amber-900 hover:file:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-50">
                    <div x-show="isUploading" x-cloak class="mt-2 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-black text-amber-900">
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-amber-200 border-t-amber-700" aria-hidden="true"></span>
                        <span>アップロード中です…</span>
                    </div>
                    <div x-show="files.length > 0" x-cloak class="mt-3 space-y-2">
                        <template x-for="(file, index) in files" :key="`${file.name}-${index}`">
                            <div class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                                <span class="min-w-0 truncate font-bold text-slate-700" x-text="`${index + 1}. ${file.name}（${formatSize(file.size)}）`"></span>
                                <button type="button" @click="removeFile(index)" class="shrink-0 font-black text-rose-600 hover:text-rose-800">取り消す</button>
                            </div>
                        </template>
                        <p class="text-xs font-bold text-slate-400"><span x-text="files.length"></span> / 5 枚を追加済み</p>
                    </div>
                    <p class="mt-1 text-xs font-bold text-slate-400">PNG / JPG / WEBP / GIF を添付できます。画像は管理人だけが閲覧できます。</p>
                    @error('attachments')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                    @error('attachments.*')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>

                <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-black text-white shadow transition hover:bg-slate-800 sm:w-auto">
                    不具合を報告する
                </button>
            </form>
        </section>
    </div>
</x-layouts.facility>
