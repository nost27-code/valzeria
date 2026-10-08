<?php

return [
    // 日本語はかな表記へ揃え、空白・記号を除いて部分一致で判定する。
    // 「エロ」「ホモ」「フェラ」など、普通の名前も巻き込みやすい短語は避ける。
    'blocked_fragments' => [
        'ちんこ', 'ちんぽ', 'ちんちん', 'まんこ', 'おっぱい', 'きんたま',
        'せっくす', '性交', '性器', '陰茎', '陰部', '陰核', '膣',
        'ふぇらちお', 'くんに', 'ぱいずり', 'おなにー', '自慰', '射精',
        '精液', '勃起', '中出し', 'やりまん', 'れいぷ', '強姦',
        '死ね', 'きちがい',
        'fuck', 'porn', 'penis', 'vagina', 'blowjob', 'handjob', 'masturbat',
    ],
    // 英字の短語は単語境界で判定し、Sussex / Dickinson / Scunthorpe等を保護する。
    'blocked_latin_words' => ['sex', 'shit', 'cunt', 'dick', 'bitch', 'asshole'],
];
