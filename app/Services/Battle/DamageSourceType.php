<?php

namespace App\Services\Battle;

enum DamageSourceType: string
{
    case NORMAL_ATTACK = 'normal_attack';
    case JOB_SKILL = 'job_skill';
    case JOB_ART = 'job_art';
    case DOT = 'dot';
    case SELF_DAMAGE = 'self_damage';
    case RECOIL = 'recoil';
    case COUNTER = 'counter';
    case REFLECT = 'reflect';
    case FIXED = 'fixed';
    case PURE = 'pure';
    case OTHER = 'other';
}
