@props(['sortModel', 'sortOptions'])
<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
    <div class="grid grid-cols-2 gap-2 sm:gap-3">
        <label class="col-span-2 sm:col-span-1">
            <span class="mb-1 block text-xs font-bold text-amber-900">装備を探す</span>
            <input type="search" x-model.debounce.150ms="browseQuery" placeholder="装備名・装備種・ランク・銘を入力" class="w-full rounded-lg border border-amber-200 bg-white px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-100">
        </label>
        <label class="col-span-2 sm:col-span-1">
            <span class="mb-1 block text-xs font-bold text-amber-900">並び替え</span>
            <select x-model="{{ $sortModel }}" class="w-full rounded-lg border border-amber-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-amber-500 focus:ring-amber-100">
                @foreach($sortOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs font-bold text-amber-900">状態</span>
            <select x-model="browseStatus" class="w-full rounded-lg border border-amber-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-amber-500 focus:ring-amber-100">
                <option value="all">すべて</option>
                <option value="equipped">装備中</option>
                <option value="locked">保護中</option>
                <option value="ready">未装備・未保護</option>
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs font-bold text-amber-900">品質</span>
            <select x-model="browseQuality" class="w-full rounded-lg border border-amber-200 bg-white px-3 py-2.5 text-sm font-bold focus:border-amber-500 focus:ring-amber-100">
                <option value="all">すべて</option>
                <option value="normal">通常</option>
                <option value="good">良品</option>
                <option value="excellent">逸品</option>
            </select>
        </label>
    </div>
    <button type="button" x-show="browseQuery || browseStatus !== 'all' || browseQuality !== 'all'" x-cloak @click="browseQuery = ''; browseStatus = 'all'; browseQuality = 'all'" class="mt-3 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-amber-900">絞り込みを解除</button>
</div>
