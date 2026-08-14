@php
    $escapedEffectText = e((string) ($text ?? ''));
    $highlightedEffectText = preg_replace_callback(
        '/([+-]\d+(?:\.\d+)?(?:%|ポイント)?)/u',
        static function (array $matches): string {
            $isGain = str_starts_with($matches[1], '+');
            $kind = $isGain ? 'gain' : 'spend';
            $class = $isGain
                ? 'bg-emerald-50 text-emerald-700'
                : 'bg-rose-50 text-rose-700';

            return '<span data-job-art-effect-value="'.$kind.'" class="whitespace-nowrap rounded-sm px-0.5 font-black '.$class.'">'.$matches[1].'</span>';
        },
        $escapedEffectText,
    ) ?? $escapedEffectText;
@endphp
{!! $highlightedEffectText !!}
