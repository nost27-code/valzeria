<?php

namespace App\Services;

enum ResourceEvent: string
{
    case JOB_ART_CAST = 'job_art_cast';
    case JOB_ART_HIT = 'job_art_hit';
    case SELF_DAMAGE = 'self_damage';
    case NORMAL_ATTACK_HIT = 'normal_attack_hit';
    case NORMAL_ATTACK_MISS = 'normal_attack_miss';
    case NON_JOB_ART_ACTION = 'non_job_art_action';
    case HP_SP_CONVERSION_SUCCESS = 'hp_sp_conversion_success';
    case PHYSICAL_ATTACK_RECEIVED = 'physical_attack_received';
    case PARRY_SUCCESS = 'parry_success';
    case DAMAGE_MITIGATED = 'damage_mitigated';
    case CLEANSE_SUCCESS = 'cleanse_success';
}
