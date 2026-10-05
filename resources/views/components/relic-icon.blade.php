@props(['effectKey' => null, 'size' => 48])
@php($relicImagePath = $effectKey ? app(\App\Services\NamelessRelicCatalog::class)->imagePath($effectKey) : null)
@if($relicImagePath)
    <img src="{{ asset($relicImagePath) }}" alt="" width="160" height="160" loading="lazy" decoding="async" {{ $attributes->merge(['style' => 'width: '.(int) $size.'px; height: '.(int) $size.'px; object-fit: contain; flex-shrink: 0;']) }}>
@endif
