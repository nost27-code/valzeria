<?php

$allowedCharacterIds = array_values(array_unique(array_filter(
    array_map(
        static function (string $value): int {
            $value = trim($value);

            return ctype_digit($value) ? (int) $value : 0;
        },
        explode(',', (string) env('WEB_PUSH_ALLOWED_CHARACTER_IDS', ''))
    ),
    static fn (int $characterId): bool => $characterId > 0
)));

return [
    'mode' => strtolower(trim((string) env('WEB_PUSH_MODE', 'off'))),
    'allowed_character_ids' => $allowedCharacterIds,
    'preview_mode' => strtolower(trim((string) env('WEB_PUSH_PREVIEW_MODE', 'generic'))),

    'vapid' => [
        'subject' => trim((string) env('WEB_PUSH_VAPID_SUBJECT', env('APP_URL', ''))),
        'public_key' => trim((string) env('WEB_PUSH_VAPID_PUBLIC_KEY', '')),
        'private_key' => trim((string) env('WEB_PUSH_VAPID_PRIVATE_KEY', '')),
    ],

    'ttl' => max(0, (int) env('WEB_PUSH_TTL', 3600)),
    'batch_size' => max(1, min(100, (int) env('WEB_PUSH_BATCH_SIZE', 20))),
];
