<?php

declare(strict_types=1);

http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
echo "管理画面限定デプロイは廃止されました。安全な通常デプロイを使用してください。\n";
