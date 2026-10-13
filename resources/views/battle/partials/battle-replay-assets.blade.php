@if(config('battle.replay.enabled'))
    @once('battle-replay-assets')
        <script>document.documentElement.classList.add('battle-replay-enabled');</script>
        <link rel="stylesheet" href="{{ asset('css/battle-replay.css') }}?v={{ filemtime(public_path('css/battle-replay.css')) }}">
        <link rel="preload" as="image" type="image/webp" href="{{ asset('images/battle-replay/painted-atlas.webp') }}">
        <script src="{{ asset('js/battle-replay.js') }}?v={{ filemtime(public_path('js/battle-replay.js')) }}" defer onerror="document.documentElement.classList.remove('battle-replay-enabled')"></script>
    @endonce
@endif
