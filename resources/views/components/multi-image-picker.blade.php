@props([
    'label' => '画像',
    'name' => 'attachments[]',
    'maxFiles' => 4,
    'maxKilobytes' => 5120,
])

@php
    $maxMegabytes = number_format(((int) $maxKilobytes) / 1024);
@endphp

<div
    class="block"
    data-multi-image-picker
    data-max-files="{{ (int) $maxFiles }}"
>
    <span class="text-sm font-black text-slate-800">
        {{ $label }}
        <span class="text-xs text-slate-400">（任意・最大{{ number_format((int) $maxFiles) }}枚・各{{ $maxMegabytes }}MB）</span>
    </span>
    <div class="mt-1.5">
        <label class="inline-flex min-h-11 cursor-pointer select-none items-center justify-center rounded-lg bg-indigo-100 px-4 text-sm font-black text-indigo-800 shadow-sm ring-1 ring-indigo-200 transition hover:bg-indigo-200 active:scale-[0.97] active:bg-indigo-300 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2">
            <input
                type="file"
                name="{{ $name }}"
                multiple
                accept="image/png,image/jpeg,image/webp,image/gif"
                class="sr-only"
                data-multi-image-input
            >
            <span data-multi-image-button-label>画像を選択</span>
        </label>
    </div>
    <p class="mt-2 text-xs font-bold leading-5 text-slate-500" data-multi-image-feedback role="status" aria-live="polite">
        画像は1枚ずつ追加できます。選択中 0 / {{ number_format((int) $maxFiles) }}枚
    </p>
    <ul class="mt-2 hidden space-y-1.5" data-multi-image-list></ul>
</div>
