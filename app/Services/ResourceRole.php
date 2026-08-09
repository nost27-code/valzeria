<?php

namespace App\Services;

enum ResourceRole: string
{
    case PRODUCER = 'producer';
    case CONSUMER = 'consumer';
    case NEUTRAL = 'neutral';
    case FINISHER = 'finisher';
}
