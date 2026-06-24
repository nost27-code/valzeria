<?php

return [
    'address' => env('CONTACT_MAIL_ADDRESS', 'info@valzeria.com'),
    'host' => env('CONTACT_MAIL_HOST'),
    'port' => (int) env('CONTACT_MAIL_PORT', 995),
    'username' => env('CONTACT_MAIL_USERNAME'),
    'password' => env('CONTACT_MAIL_PASSWORD'),
    'encryption' => env('CONTACT_MAIL_ENCRYPTION', 'ssl'),
    'limit' => (int) env('CONTACT_MAIL_FETCH_LIMIT', 30),
    'smtp_host' => env('CONTACT_MAIL_SMTP_HOST', env('CONTACT_MAIL_HOST')),
    'smtp_port' => (int) env('CONTACT_MAIL_SMTP_PORT', 465),
    'smtp_encryption' => env('CONTACT_MAIL_SMTP_ENCRYPTION', 'ssl'),
    'from_name' => env('CONTACT_MAIL_FROM_NAME', 'ヴァルゼリアの冒険者 運営'),
];
