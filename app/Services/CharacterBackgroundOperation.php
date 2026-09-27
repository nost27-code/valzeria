<?php

namespace App\Services;

use App\Models\Character;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Optional maintenance must never queue behind an ongoing battle. */
class CharacterBackgroundOperation
{
    public function run(int $characterId, Closure $operation): bool
    {
        try {
            return DB::transaction(function () use ($characterId, $operation): bool {
                try {
                    $query = Character::query()->whereKey($characterId);
                    $character = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                        ? $query->lock('for update nowait')->first()
                        : $query->lockForUpdate()->first();
                } catch (QueryException $exception) {
                    // MariaDB NOWAIT=1205, MySQL NOWAIT=3572. Only the initial
                    // lock failure may be skipped; callback failures must propagate.
                    if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 3572], true)) {
                        throw new CharacterBackgroundBusyException(previous: $exception);
                    }
                    throw $exception;
                }
                if (! $character) {
                    return false;
                }
                $operation($character);

                return true;
            });
        } catch (CharacterBackgroundBusyException) {
            return false;
        }
    }
}
