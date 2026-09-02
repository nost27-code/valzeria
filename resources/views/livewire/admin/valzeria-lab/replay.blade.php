<main class="w-full px-4 py-6 sm:px-6 lg:px-8 lg:py-8"
      x-data="{
          async importSnapshot(event) {
              const file = event.target.files?.[0];
              if (!file) return;
              if (file.size > {{ \App\Services\Admin\ValzeriaLabReplayService::MAX_JSON_BYTES }}) {
                  alert('JSONは512KB以下にしてください。');
                  event.target.value = '';
                  return;
              }
              $wire.set('snapshotJson', await file.text());
              event.target.value = '';
          },
          downloadSnapshot() {
              const text = document.getElementById('valzeria-lab-snapshot-json')?.value ?? '';
              if (!text.trim()) return;
              const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
              const url = URL.createObjectURL(blob);
              const anchor = document.createElement('a');
              anchor.href = url;
              anchor.download = 'valzeria-lab-battle-snapshot.json';
              anchor.hidden = true;
              document.body.appendChild(anchor);
              anchor.click();
              anchor.remove();
              setTimeout(() => URL.revokeObjectURL(url), 0);
          }
      }">
    <header class="mb-5 border-b border-slate-300 pb-5">
        <p class="text-xs font-black tracking-[0.18em] text-amber-700">ADMIN READ-ONLY LAB</p>
        <h1 class="mt-2 text-2xl font-black text-slate-950 sm:text-3xl">Valzeria Lab / 再現</h1>
        <p class="mt-2 max-w-3xl text-sm font-bold leading-6 text-slate-600">匿名化した戦闘開始状態とseedから、現在の戦闘処理を非永続で再実行します。</p>
    </header>

    @include('livewire.admin.valzeria-lab.tabs')

    <div class="mb-6 border-y border-amber-300 bg-amber-50/60 px-4 py-3 text-sm font-bold leading-6 text-amber-950">
        この画面は計算結果だけを返します。HP/SP、経験値、Gold、職業経験値、所持品、戦績、進行、ログDBは更新しません。
    </div>

    @if($notice)
        <p role="status" class="mb-5 border-l-4 border-emerald-600 pl-3 text-sm font-bold text-emerald-800">{{ $notice }}</p>
    @endif

    <div class="grid gap-8 2xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <section class="min-w-0">
            <div class="border-b border-slate-300 pb-3">
                <h2 class="text-lg font-black text-slate-950">1. 正本から開始状態を作る</h2>
                <p class="mt-1 text-xs font-bold leading-5 text-slate-500">実在・テスト用Characterの現在能力、装備、戦技と、選択した敵を読取ります。</p>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div class="min-w-0">
                    <div class="flex items-end gap-2">
                        <label class="min-w-0 flex-1">
                            <span class="text-xs font-black text-slate-700">Character検索</span>
                            <input type="search" wire:model.live.debounce.300ms="characterSearch" placeholder="名前で検索"
                                   class="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-bold">
                        </label>
                        <label class="w-28 shrink-0">
                            <span class="text-xs font-black text-slate-700">対象</span>
                            <select wire:model.live="characterKind" class="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-2 text-sm font-bold">
                                <option value="all">すべて</option>
                                <option value="tester">テスト用</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-2 max-h-72 overflow-y-auto border-y border-slate-200">
                        @forelse($characterCandidates as $candidate)
                            <button type="button" wire:click="selectCharacter({{ $candidate->id }})"
                                    class="flex min-h-11 w-full items-center justify-between gap-3 border-b border-slate-200 px-2 py-2 text-left text-sm transition last:border-b-0 {{ (int) $selectedCharacterId === (int) $candidate->id ? 'bg-amber-50 text-slate-950' : 'bg-white text-slate-700 hover:bg-slate-50' }}">
                                <span class="min-w-0 truncate font-black">{{ $candidate->name }}</span>
                                <span class="shrink-0 text-xs font-bold text-slate-500">Lv{{ $candidate->level }}{{ $candidate->isAdminTester() ? ' / テスト用' : '' }}</span>
                            </button>
                        @empty
                            <p class="px-3 py-5 text-sm font-bold text-slate-500">該当するCharacterがありません。</p>
                        @endforelse
                    </div>
                    @error('selectedCharacterId') <p class="mt-2 text-xs font-bold text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="min-w-0">
                    <label>
                        <span class="text-xs font-black text-slate-700">敵検索</span>
                        <input type="search" wire:model.live.debounce.300ms="enemySearch" placeholder="敵・街・エリア・ID"
                               class="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-bold">
                    </label>
                    <div class="mt-2 max-h-72 overflow-y-auto border-y border-slate-200">
                        @forelse($enemyCandidates as $candidate)
                            <button type="button" wire:click="selectEnemy({{ $candidate->id }})"
                                    class="block min-h-11 w-full border-b border-slate-200 px-2 py-2 text-left transition last:border-b-0 {{ (int) $selectedEnemyId === (int) $candidate->id ? 'bg-amber-50' : 'bg-white hover:bg-slate-50' }}">
                                <span class="flex items-start justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-black text-slate-950">{{ $candidate->name }}</span>
                                        <span class="mt-0.5 block truncate text-xs font-bold text-slate-500">{{ $candidate->area?->city?->name ?? '街なし' }} / {{ $candidate->area?->name ?? 'エリアなし' }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs font-black text-slate-500">Lv{{ $candidate->level }}{{ $candidate->is_boss ? ' / BOSS' : '' }}</span>
                                </span>
                            </button>
                        @empty
                            <p class="px-3 py-5 text-sm font-bold text-slate-500">該当する敵がありません。</p>
                        @endforelse
                    </div>
                    @error('selectedEnemyId') <p class="mt-2 text-xs font-bold text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($selectedCharacter || $selectedEnemy)
                <dl class="mt-5 grid gap-x-4 gap-y-2 border-y border-slate-200 py-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-black text-slate-500">選択Character</dt><dd class="mt-1 font-black text-slate-950">{{ $selectedCharacter?->name ?? '未選択' }}</dd></div>
                    <div><dt class="text-xs font-black text-slate-500">選択した敵</dt><dd class="mt-1 font-black text-slate-950">{{ $selectedEnemy?->name ?? '未選択' }}</dd></div>
                </dl>
            @endif

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label>
                    <span class="text-xs font-black text-slate-700">戦闘種別</span>
                    <select wire:model="battleType" class="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-bold">
                        <option value="pve">通常戦</option>
                        <option value="boss">ボス戦</option>
                    </select>
                </label>
                <label>
                    <span class="text-xs font-black text-slate-700">乱数seed</span>
                    <input type="number" min="0" max="{{ \App\Services\Admin\ValzeriaLabReplayService::MAX_SEED }}" wire:model="seed"
                           class="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-bold">
                </label>
            </div>
            @error('battleType') <p class="mt-2 text-xs font-bold text-red-700">{{ $message }}</p> @enderror
            @error('seed') <p class="mt-2 text-xs font-bold text-red-700">{{ $message }}</p> @enderror

            <button type="button" wire:click="captureAndRun" wire:loading.attr="disabled" wire:target="captureAndRun"
                    class="mt-5 min-h-11 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="captureAndRun">匿名化して戦闘を再現</span>
                <span wire:loading wire:target="captureAndRun">開始状態を作成中...</span>
            </button>

            <div class="mt-8 border-b border-slate-300 pb-3">
                <h2 class="text-lg font-black text-slate-950">2. JSONを保存・読込する</h2>
                <p class="mt-1 text-xs font-bold leading-5 text-slate-500">個人ID、メール、認証情報、Character名は出力しません。マスタIDは参照再現のため含みます。</p>
            </div>

            <textarea id="valzeria-lab-snapshot-json" wire:model.blur="snapshotJson" rows="15" spellcheck="false"
                      class="mt-4 w-full max-w-full rounded-md border border-slate-300 bg-slate-950 p-3 font-mono text-xs leading-5 text-slate-100"
                      placeholder="匿名スナップショットJSON"></textarea>
            @error('snapshotJson') <p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p> @enderror

            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <button type="button" @click="downloadSnapshot" @disabled($snapshotJson === '')
                        class="min-h-11 rounded-md border border-slate-400 bg-white px-3 text-sm font-black text-slate-800 disabled:opacity-40">JSONを保存</button>
                <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-md border border-slate-400 bg-white px-3 text-sm font-black text-slate-800">
                    ファイルを選ぶ
                    <input type="file" accept="application/json,.json" class="sr-only" @change="importSnapshot($event)">
                </label>
                <button type="button" wire:click="loadSnapshot" wire:loading.attr="disabled" wire:target="loadSnapshot"
                        class="min-h-11 rounded-md border border-slate-400 bg-white px-3 text-sm font-black text-slate-800 disabled:opacity-50">JSONを読込</button>
                <button type="button" wire:click="clearSnapshot"
                        class="min-h-11 rounded-md border border-slate-300 px-3 text-sm font-black text-slate-600">クリア</button>
            </div>

            @if($snapshot)
                <button type="button" wire:click="runLoadedSnapshot" wire:loading.attr="disabled" wire:target="runLoadedSnapshot"
                        class="mt-3 min-h-11 w-full rounded-md bg-amber-600 px-4 text-sm font-black text-white hover:bg-amber-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="runLoadedSnapshot">読込状態をこのseedで再現</span>
                    <span wire:loading wire:target="runLoadedSnapshot">再現中...</span>
                </button>
            @endif
        </section>

        <section class="min-w-0">
            <div class="border-b border-slate-300 pb-3">
                <h2 class="text-lg font-black text-slate-950">開始状態と結果</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">表示は匿名スナップショットから組み立てています。</p>
            </div>

            @if(!$snapshot)
                <p class="py-12 text-center text-sm font-bold text-slate-500">Characterと敵を選んで実行するか、JSONを読み込んでください。</p>
            @else
                @php
                    $statLabels = [
                        'max_hp' => 'HP', 'max_mp' => 'SP', 'str' => '攻撃', 'def' => '防御',
                        'mag' => '魔力', 'spr' => '精神', 'agi' => '敏捷', 'luk' => '運',
                    ];
                    $character = $snapshot['character'];
                    $enemy = $snapshot['enemy'];
                @endphp
                <div class="mt-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-base font-black text-slate-950">{{ $character['label'] }}</h3>
                        <p class="text-xs font-bold text-slate-500">Lv{{ $character['level'] }} / {{ $character['job']['name'] ?? '職業なし' }} / seed {{ $snapshot['seed'] }}</p>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-y border-slate-200 py-3 sm:grid-cols-4">
                        @foreach($statLabels as $key => $label)
                            <div>
                                <dt class="text-[11px] font-black text-slate-500">{{ $label }}</dt>
                                <dd class="text-base font-black text-slate-950">{{ number_format($character['stats'][$key]) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-2 text-xs font-bold text-slate-500">開始 HP {{ number_format($character['starting_hp']) }} / SP {{ number_format($character['starting_sp']) }}</p>
                </div>

                <div class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="border-b border-slate-200 pb-2 text-sm font-black text-slate-950">装備</h3>
                        <ul class="divide-y divide-slate-200 text-sm">
                            @forelse($character['equipment'] as $equipment)
                                <li class="py-3">
                                    <p class="font-black text-slate-900">{{ $equipment['name'] }}</p>
                                    <p class="mt-1 break-words text-xs font-bold text-slate-500">
                                        {{ collect($equipment['effective_stats'])->filter()->map(fn ($value, $key) => ($statLabels[$key] ?? $key).' '.number_format($value))->implode(' / ') ?: '能力補正なし' }}
                                    </p>
                                </li>
                            @empty
                                <li class="py-3 text-sm font-bold text-slate-500">装備なし</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <h3 class="border-b border-slate-200 pb-2 text-sm font-black text-slate-950">戦技</h3>
                        <ul class="divide-y divide-slate-200 text-sm">
                            @forelse($character['job_arts'] as $art)
                                <li class="py-3">
                                    <p class="font-black text-slate-900">{{ $art['attributes']['name'] }}</p>
                                    <p class="mt-1 text-xs font-bold text-slate-500">Cost {{ $art['runtime']['job_art_effective_cost'] ?? $art['attributes']['art_cost'] ?? 0 }} / {{ $art['runtime']['job_art_activation_policy'] ?? $character['activation_policy'] }}</p>
                                </li>
                            @empty
                                <li class="py-3 text-sm font-bold text-slate-500">選択中の戦技なし</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="mt-6 border-y border-slate-200 py-3">
                    <h3 class="text-sm font-black text-slate-950">対戦相手: {{ $enemy['attributes']['name'] }}</h3>
                    <p class="mt-1 text-xs font-bold text-slate-500">{{ $enemy['area']['city_name'] ?? '街なし' }} / {{ $enemy['area']['name'] ?? 'エリアなし' }} / {{ $snapshot['battle_type'] === 'boss' ? 'ボス戦' : '通常戦' }}</p>
                </div>

                @if($result)
                    <div class="mt-6 grid grid-cols-2 gap-x-4 gap-y-3 border-y-2 border-slate-400 py-4 sm:grid-cols-4">
                        @foreach([
                            '結果' => $result['result_label'],
                            'ターン' => number_format($result['turn_count']),
                            '与ダメージ' => number_format($result['damage_dealt']),
                            '被ダメージ' => number_format($result['damage_taken']),
                            '終了HP' => number_format($result['hp_after']),
                            '終了SP' => number_format($result['sp_after']),
                        ] as $label => $value)
                            <div><dt class="text-[11px] font-black text-slate-500">{{ $label }}</dt><dd class="mt-1 text-base font-black text-slate-950">{{ $value }}</dd></div>
                        @endforeach
                    </div>

                    <div class="mt-6">
                        <h3 class="border-b border-slate-300 pb-2 text-base font-black text-slate-950">戦闘ログ</h3>
                        <ol class="mt-3 space-y-2 overflow-x-auto text-sm font-bold leading-6 text-slate-800">
                            @foreach($result['logs'] as $index => $line)
                                <li wire:key="replay-log-{{ $index }}" class="min-w-0 break-words">{!! $line !!}</li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="mt-6 border-t-2 border-slate-400 pt-4">
                        <h3 class="text-base font-black text-slate-950">算出報酬（未付与）</h3>
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div><dt class="text-xs font-black text-slate-500">経験値</dt><dd class="text-lg font-black">{{ number_format($result['exp']) }}</dd></div>
                            <div><dt class="text-xs font-black text-slate-500">Gold</dt><dd class="text-lg font-black">{{ number_format($result['gold']) }}</dd></div>
                            <div><dt class="text-xs font-black text-slate-500">職業経験値</dt><dd class="text-lg font-black">{{ number_format($result['job_exp']) }}</dd></div>
                            <div><dt class="text-xs font-black text-slate-500">ドロップ</dt><dd class="text-sm font-black">{{ count($result['drops']) ? implode(' / ', $result['drops']) : '付与経路を実行しない' }}</dd></div>
                        </dl>
                        <p class="mt-3 text-xs font-bold leading-5 text-slate-500">BattleServiceが返す数値だけを表示しています。LevelService、GoldService、DropService、進行更新は呼びません。</p>
                    </div>
                @endif
            @endif
        </section>
    </div>
</main>
