<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

class DatabaseContention
{
    public const MESSAGE = 'アクセスが集中しています。少し時間をおいて画面を開き直してください。探索や購入を行った場合は、結果や所持品を確認してから操作してください。';

    public static function details(Throwable $exception): ?array
    {
        do {
            if ($exception instanceof QueryException) {
                $error = $exception->errorInfo ?? [];
                $code = (int) ($error[1] ?? 0);
                $state = (string) ($error[0] ?? $exception->getCode());
                if (in_array($code, [1040, 1203], true)
                    || ($code === 1226 && str_contains($exception->getMessage(), 'max_user_connections'))) {
                    return ['reason' => 'connection_limit', 'database_error_code' => $code, 'sql_state' => $state];
                }
                if (in_array($code, [1205, 1213, 3572], true) || $state === '40001') {
                    return ['reason' => 'database_lock', 'database_error_code' => $code, 'sql_state' => $state];
                }
            }
        } while ($exception = $exception->getPrevious());

        return null;
    }
}
