<?php

namespace App\Services\Nation\Raid;

use DomainException;
use Illuminate\Database\QueryException;

/** A NOWAIT owner-lock refusal, before any sortie cost or admission is committed. */
final class NationRaidAdmissionBusyException extends DomainException
{
    public function __construct(QueryException $previous)
    {
        parent::__construct('ほかの操作を処理中です。完了してからもう一度出撃してください。', 0, $previous);
    }
}
