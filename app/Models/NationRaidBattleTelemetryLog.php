<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 国家対抗レイドの1出撃1行テレメトリ。 */
final class NationRaidBattleTelemetryLog extends Model
{
    protected $table = 'nation_raid_battle_telemetry';

    protected $fillable = [
        'battle_token_hash',
        'event_key',
        'telemetry_schema_version',
        'ruleset_version',
        'raid_day',
        'day_sortie_no',
        'event_sortie_no',
        'boss_cycle_no',
        'character_id',
        'nation_id',
        'is_nation_eligible',
        'nation_active_count',
        'player_level',
        'player_job_id',
        'player_power',
        'boss_phase',
        'adaptive_lineage',
        'result_status',
        'end_reason',
        'turn_count',
        'reached_turn_twenty',
        'boss_hp_before',
        'boss_hp_after',
        'calculated_damage_total',
        'applied_damage_total',
        'max_action_damage',
        'damage_taken_total',
        'healing_total',
        'player_hp_ratio_end',
        'duration_ms',
        'battle_started_at',
        'battle_resolved_at',
        'loadout_lineages',
        'loadout_snapshot',
        'damage_by_source',
        'counterplay_metrics',
        'turns',
        'event_snapshot',
        'player_snapshot',
        'quality_flags',
    ];

    protected $casts = [
        'is_nation_eligible' => 'boolean',
        'reached_turn_twenty' => 'boolean',
        'player_hp_ratio_end' => 'float',
        'battle_started_at' => 'datetime',
        'battle_resolved_at' => 'datetime',
        'loadout_lineages' => 'array',
        'loadout_snapshot' => 'array',
        'damage_by_source' => 'array',
        'counterplay_metrics' => 'array',
        'turns' => 'array',
        'event_snapshot' => 'array',
        'player_snapshot' => 'array',
        'quality_flags' => 'array',
    ];
}
