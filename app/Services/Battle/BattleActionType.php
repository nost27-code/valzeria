<?php

namespace App\Services\Battle;

enum BattleActionType: string
{
    case JOB_ART = 'job_art';
    case CURRENT_JOB_SKILL = 'current_job_skill';
    case NORMAL_ATTACK = 'normal_attack';
    case NO_ACTION = 'no_action';
}
