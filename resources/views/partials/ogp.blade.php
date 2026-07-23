@php
    $defaultTitle = 'ヴァルゼリアの冒険者 - 最強冒険者が集うFFA風RPG';
    $defaultDescription = 'ブラウザで遊べる本格FFA風ブラウザRPG。キャラクターを育て、装備を整え、最強の冒険者を目指せ。';
    $resolvedTitle = $ogTitle ?? $defaultTitle;
    $resolvedDescription = $ogDescription ?? $defaultDescription;
    $resolvedUrl = $ogUrl ?? url()->current();
    $resolvedImage = $ogImage ?? 'https://valzeria.com/images/ogp01.webp';
    $resolvedImageWidth = $ogImageWidth ?? 1733;
    $resolvedImageHeight = $ogImageHeight ?? 907;
@endphp
<meta name="description" content="{{ $resolvedDescription }}">
<meta property="og:title" content="{{ $resolvedTitle }}">
<meta property="og:description" content="{{ $resolvedDescription }}">
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
<meta property="og:url" content="{{ $resolvedUrl }}">
<meta property="og:image" content="{{ $resolvedImage }}">
<meta property="og:image:secure_url" content="{{ $resolvedImage }}">
<meta property="og:image:type" content="image/webp">
<meta property="og:image:width" content="{{ $resolvedImageWidth }}">
<meta property="og:image:height" content="{{ $resolvedImageHeight }}">
<meta property="og:site_name" content="ヴァルゼリアの冒険者">
<meta property="og:locale" content="ja_JP">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $resolvedTitle }}">
<meta name="twitter:description" content="{{ $resolvedDescription }}">
<meta name="twitter:image" content="{{ $resolvedImage }}">
