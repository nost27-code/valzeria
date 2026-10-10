<?php

namespace App\Services;

/** Return shares item use's bounded, fully rolled-back retry policy. */
class ExplorationReturnTransactionRunner extends ExplorationItemTransactionRunner
{
    protected function contentionLogMessage(): string
    {
        return 'Exploration return database contention handled.';
    }

    protected function contentionMessage(): string
    {
        return '探索処理が進行中です。少し待ってから、もう一度帰還してください。';
    }
}
