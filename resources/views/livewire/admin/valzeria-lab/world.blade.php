<main class="w-full px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <header class="mb-5 border-b border-slate-300 pb-5">
        <p class="text-xs font-black tracking-[0.18em] text-amber-700">ADMIN READ-ONLY LAB</p>
        <h1 class="mt-2 text-2xl font-black text-slate-950 sm:text-3xl">Valzeria Lab / 世界グラフ</h1>
        <p class="mt-2 max-w-3xl text-sm font-bold leading-6 text-slate-600">既存マスタと設定を横断し、参照元・参照先・根拠を読み取り専用で確認します。</p>
    </header>

    @include('livewire.admin.valzeria-lab.tabs')

    <section class="border-b border-slate-300 pb-6">
        <h2 class="text-lg font-black text-slate-950">読取結果</h2>
        <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 text-sm sm:grid-cols-4">
            <div><dt class="font-bold text-slate-500">ノード</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ number_format($graphCounts['nodes']) }}</dd></div>
            <div><dt class="font-bold text-slate-500">関係</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ number_format($graphCounts['edges']) }}</dd></div>
            <div><dt class="font-bold text-slate-500">明示参照</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ number_format($graphCounts['by_certainty']['confirmed'] ?? 0) }}</dd></div>
            <div><dt class="font-bold text-slate-500">確認候補</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ number_format($graphCounts['issues']) }}</dd></div>
        </dl>
        <p class="mt-3 text-xs font-semibold leading-5 text-slate-500">「明示参照」は外部キー等で直接結ばれた関係、「宣言参照」は文字列・JSON・設定に記載された関係です。「候補」は機械判定であり、不具合と断定しません。</p>
    </section>

    <div class="grid gap-8 py-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(24rem,.95fr)]">
        <section class="min-w-0" aria-labelledby="world-node-heading">
            <h2 id="world-node-heading" class="text-lg font-black text-slate-950">マスタを探す</h2>
            <form wire:submit.prevent="applyFilters" class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_13rem_auto]">
                <label class="block">
                    <span class="sr-only">名前・ID・根拠で検索</span>
                    <input type="search" wire:model="search" placeholder="名前・ID・テーブル名で検索"
                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-900 focus:border-amber-500 focus:ring-amber-200">
                </label>
                <label class="block">
                    <span class="sr-only">種別</span>
                    <select wire:model.live="type" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-900 focus:border-amber-500 focus:ring-amber-200">
                        <option value="all">すべての種別</option>
                        @foreach($typeLabels as $key => $label)
                            <option value="{{ $key }}">{{ $label }} ({{ number_format($graphCounts['by_type'][$key] ?? 0) }})</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="min-h-11 rounded-md border border-slate-400 bg-white px-4 text-sm font-black text-slate-800 hover:bg-slate-50">検索</button>
            </form>

            <p class="mt-3 text-xs font-bold text-slate-500">{{ number_format($nodes->total()) }}件に一致</p>
            <div class="mt-2 divide-y divide-slate-200 border-y border-slate-300">
                @forelse($nodes as $node)
                    <button type="button" wire:click="selectNode('{{ $node['key'] }}')" wire:key="world-node-{{ $node['key'] }}"
                            class="block w-full px-1 py-3 text-left transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 {{ $selectedNodeKey === $node['key'] ? 'bg-amber-50/70' : '' }}">
                        <span class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block break-words text-sm font-black text-slate-950">{{ $node['name'] }}</span>
                                <span class="mt-1 block break-all text-xs font-semibold text-slate-500">{{ $node['key'] }} / {{ $node['source'] }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-black text-slate-600">{{ $node['type_label'] }}</span>
                        </span>
                    </button>
                @empty
                    <p class="py-8 text-center text-sm font-bold text-slate-500">条件に合うマスタはありません。</p>
                @endforelse
            </div>
            @if($nodes->hasPages())
                <div class="mt-4">{{ $nodes->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </section>

        <section class="min-w-0 border-t border-slate-300 pt-6 xl:border-l xl:border-t-0 xl:pl-8 xl:pt-0" aria-labelledby="world-detail-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="world-detail-heading" class="text-lg font-black text-slate-950">参照の詳細</h2>
                @if($selectedDetail)
                    <button type="button" wire:click="clearSelection" class="inline-flex min-h-11 items-center px-1 text-xs font-black text-slate-500 underline decoration-slate-300 underline-offset-4 hover:text-slate-900">閉じる</button>
                @endif
            </div>

            @if($selectedDetail)
                @php
                    $selected = $selectedDetail['node'];
                @endphp
                <div class="mt-4 border-b border-slate-300 pb-5">
                    <p class="text-xs font-black text-amber-700">{{ $selected['type_label'] }} / {{ $selected['key'] }}</p>
                    <h3 class="mt-1 break-words text-xl font-black text-slate-950">{{ $selected['name'] }}</h3>
                    <p class="mt-1 break-all text-xs font-bold text-slate-500">正本: {{ $selected['source'] }}</p>
                    <dl class="mt-4 grid gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
                        @foreach($selected['attributes'] as $label => $value)
                            <div>
                                <dt class="text-xs font-bold text-slate-500">{{ $label }}</dt>
                                <dd class="mt-0.5 break-words font-black text-slate-900">
                                    @if(is_bool($value))
                                        {{ $value ? 'はい' : 'いいえ' }}
                                    @elseif(is_array($value))
                                        {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                @if($selectedDetail['issues'] !== [])
                    <div class="border-b border-slate-300 py-4">
                        <h3 class="text-sm font-black text-slate-950">このマスタの整合性確認</h3>
                        <div class="mt-2 space-y-3">
                            @foreach($selectedDetail['issues'] as $issue)
                                <article>
                                    <p class="text-xs font-black {{ $issue['certainty'] === 'candidate' ? 'text-amber-800' : 'text-rose-700' }}">
                                        {{ $issue['type_label'] }} / {{ $issue['certainty'] === 'candidate' ? '候補' : '明示参照の確認結果' }}
                                    </p>
                                    <p class="mt-1 text-sm font-bold leading-6 text-slate-800">{{ $issue['detail'] }}</p>
                                    <p class="mt-1 break-all text-xs font-semibold text-slate-500">根拠: {{ $issue['evidence'] }}</p>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach(['incoming' => '参照元', 'outgoing' => '参照先'] as $direction => $heading)
                    <div class="border-b border-slate-300 py-4 last:border-b-0">
                        <h3 class="text-sm font-black text-slate-950">{{ $heading }}（{{ count($selectedDetail[$direction]) }}）</h3>
                        <div class="mt-2 divide-y divide-slate-200">
                            @forelse($selectedDetail[$direction] as $edge)
                                @php
                                    $otherKey = $direction === 'incoming' ? $edge['from'] : $edge['to'];
                                    $otherName = $direction === 'incoming' ? $edge['from_name'] : $edge['to_name'];
                                    $metadata = collect($edge['metadata'])->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])->map(fn ($value, $key) => $key.': '.(is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value)))->implode(' / ');
                                @endphp
                                <article class="py-3">
                                    <button type="button" wire:click="selectNode('{{ $otherKey }}')" class="inline-flex min-h-11 items-center break-words text-left text-sm font-black text-slate-950 underline decoration-slate-300 underline-offset-4 hover:text-amber-800">{{ $otherName }}</button>
                                    <p class="mt-1 text-xs font-bold text-slate-600">{{ $edge['label'] }} / {{ $edge['certainty_label'] }}</p>
                                    <p class="mt-1 break-all text-xs font-semibold leading-5 text-slate-500">
                                        根拠: {{ $edge['evidence'] }}
                                        @if($metadata !== '')
                                            <br>条件: {{ $metadata }}
                                        @endif
                                    </p>
                                </article>
                            @empty
                                <p class="py-3 text-xs font-bold text-slate-500">表示できる{{ $heading }}はありません。</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            @else
                <p class="mt-4 text-sm font-bold leading-6 text-slate-500">左の一覧からマスタを選ぶと、前後の参照と根拠を表示します。</p>
            @endif
        </section>
    </div>

    <section class="border-t border-slate-300 pt-6" aria-labelledby="world-issues-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="world-issues-heading" class="text-lg font-black text-slate-950">整合性の確認候補</h2>
                <p class="mt-1 text-xs font-semibold leading-5 text-slate-500">参照切れは明示値の解決可否、その他は既知の経路だけを使った候補判定です。候補だけで不具合とは断定しません。</p>
            </div>
            <label class="block sm:w-64">
                <span class="sr-only">確認候補の種別</span>
                <select wire:model.live="issueType" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-900 focus:border-amber-500 focus:ring-amber-200">
                    <option value="all">すべての候補 ({{ number_format($graphCounts['issues']) }})</option>
                    @foreach($issueTypeLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }} ({{ number_format($graphCounts['by_issue'][$key] ?? 0) }})</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-4 divide-y divide-slate-200 border-y border-slate-300">
            @forelse($issues as $issue)
                <article class="py-4" wire:key="world-issue-{{ $issue['id'] }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-black {{ $issue['certainty'] === 'candidate' ? 'text-amber-800' : 'text-rose-700' }}">{{ $issue['type_label'] }} / {{ $issue['certainty'] === 'candidate' ? '候補' : '明示参照の確認結果' }}</p>
                            <h3 class="mt-1 break-words text-sm font-black text-slate-950">{{ $issue['title'] }}</h3>
                            <p class="mt-1 break-words text-sm font-semibold leading-6 text-slate-700">{{ $issue['detail'] }}</p>
                            <p class="mt-1 break-all text-xs font-semibold text-slate-500">根拠: {{ $issue['evidence'] }}</p>
                        </div>
                        @if($issue['node_key'])
                            <button type="button" wire:click="selectNode('{{ $issue['node_key'] }}')" class="inline-flex min-h-11 shrink-0 items-center px-1 text-xs font-black text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">マスタを見る</button>
                        @endif
                    </div>
                </article>
            @empty
                <p class="py-8 text-center text-sm font-bold text-slate-500">この種別の確認候補はありません。</p>
            @endforelse
        </div>
        @if($issues->hasPages())
            <div class="mt-4">{{ $issues->links(data: ['scrollTo' => false]) }}</div>
        @endif
    </section>

    <p class="mt-6 text-xs font-bold leading-5 text-slate-500">この画面はマスタ・設定を読むだけです。Character、所持品、進行、報酬、ランキングは更新しません。</p>
</main>
