<?php

namespace App\Services;

use App\Models\Area;
use App\Models\ArenaLog;
use App\Models\Character;
use App\Models\NpcMaster;
use App\Models\PlayerNpcEncounter;
use App\Models\PlayerTavernDailyNpc;
use App\Models\PlayerTavernVisit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TavernNpcService
{
    public function visit(Character $character): PlayerTavernVisit
    {
        return DB::transaction(function () use ($character) {
            $visit = PlayerTavernVisit::lockForUpdate()->firstOrCreate(
                ['character_id' => $character->id],
                ['visit_count' => 0]
            );
            $visit->visit_count++;
            $visit->last_visited_at = now();
            $visit->save();

            return $visit;
        });
    }

    public function dailyNpcs(Character $character): Collection
    {
        $date = now()->toDateString();
        $this->removeDuplicateReunionNpc($character, $date);

        $saved = PlayerTavernDailyNpc::with('npc')
            ->where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->orderBy('slot_no')
            ->get();

        $normalSaved = $saved->filter(fn (PlayerTavernDailyNpc $dailyNpc): bool => (int) $dailyNpc->slot_no <= 3);
        if ($normalSaved->count() >= 3) {
            return $saved->pluck('npc')->filter()->values();
        }

        $missingSlots = collect([1, 2, 3])
            ->reject(fn (int $slotNo): bool => $normalSaved->contains(fn (PlayerTavernDailyNpc $dailyNpc): bool => (int) $dailyNpc->slot_no === $slotNo))
            ->values();
        $excludeNpcIds = $saved->pluck('npc_id')->map(fn ($npcId): int => (int) $npcId)->all();
        $selected = $this->drawNpcs($character, $missingSlots->count(), $excludeNpcIds);

        DB::transaction(function () use ($character, $date, $selected, $missingSlots) {
            foreach ($selected as $index => $npc) {
                PlayerTavernDailyNpc::updateOrCreate(
                    [
                        'character_id' => $character->id,
                        'tavern_date' => $date,
                        'slot_no' => (int) $missingSlots[$index],
                    ],
                    ['npc_id' => $npc->npc_id]
                );
            }
        });

        return PlayerTavernDailyNpc::with('npc')
            ->where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->orderBy('slot_no')
            ->get()
            ->pluck('npc')
            ->filter()
            ->values();
    }

    public function addTodayReunionNpc(Character $character, NpcMaster $npc): void
    {
        $date = now()->toDateString();
        $npcId = (int) $npc->npc_id;

        $alreadyInTavern = PlayerTavernDailyNpc::where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->where('npc_id', $npcId)
            ->exists();

        if ($alreadyInTavern) {
            PlayerTavernDailyNpc::where('character_id', $character->id)
                ->whereDate('tavern_date', $date)
                ->where('slot_no', 4)
                ->where('npc_id', $npcId)
                ->delete();
            return;
        }

        PlayerTavernDailyNpc::updateOrCreate(
            [
                'character_id' => $character->id,
                'tavern_date' => $date,
                'slot_no' => 4,
            ],
            ['npc_id' => $npcId]
        );
    }

    public function isTodayReunionNpc(Character $character, NpcMaster $npc): bool
    {
        return PlayerTavernDailyNpc::where('character_id', $character->id)
            ->whereDate('tavern_date', now()->toDateString())
            ->where('slot_no', 4)
            ->where('npc_id', $npc->npc_id)
            ->exists();
    }

    public function talk(Character $character, NpcMaster $npc): array
    {
        $isFirst = false;

        $encounter = DB::transaction(function () use ($character, $npc, &$isFirst) {
            $encounter = PlayerNpcEncounter::lockForUpdate()
                ->where('character_id', $character->id)
                ->where('npc_id', $npc->npc_id)
                ->first();

            if (!$encounter) {
                $isFirst = true;
                return PlayerNpcEncounter::create([
                    'character_id' => $character->id,
                    'npc_id' => $npc->npc_id,
                    'first_encountered_at' => now(),
                    'encounter_count' => 1,
                    'last_encountered_at' => now(),
                ]);
            }

            $encounter->encounter_count++;
            $encounter->last_encountered_at = now();
            $encounter->save();

            return $encounter;
        });

        return [
            'encounter' => $encounter,
            'is_first' => $isFirst,
        ];
    }

    public function roster(Character $character): array
    {
        $npcs = NpcMaster::where('is_active', true)->orderBy('sort_order')->get();
        $encounters = PlayerNpcEncounter::where('character_id', $character->id)
            ->get()
            ->keyBy('npc_id');

        return [
            'npcs' => $npcs,
            'encounters' => $encounters,
            'registered' => $encounters->count(),
            'total' => $npcs->count(),
        ];
    }

    private function drawNpcs(Character $character, int $limit, array $excludeNpcIds = []): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        $encounteredIds = PlayerNpcEncounter::where('character_id', $character->id)->pluck('npc_id')->all();
        $candidates = NpcMaster::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->reject(fn (NpcMaster $npc): bool => in_array((int) $npc->npc_id, $excludeNpcIds, true))
            ->filter(fn (NpcMaster $npc) => $this->meetsCondition($character, $npc))
            ->values();

        $selected = collect();

        while ($selected->count() < $limit && $candidates->isNotEmpty()) {
            $weighted = $candidates->map(function (NpcMaster $npc) use ($encounteredIds) {
                $weight = (int) $npc->base_weight;
                if (!in_array($npc->npc_id, $encounteredIds, true)) {
                    $weight = (int) ceil($weight * 1.5);
                }

                return ['npc' => $npc, 'weight' => max(1, $weight)];
            });

            $total = $weighted->sum('weight');
            $roll = random_int(1, max(1, $total));
            $cursor = 0;
            $picked = null;

            foreach ($weighted as $entry) {
                $cursor += $entry['weight'];
                if ($roll <= $cursor) {
                    $picked = $entry['npc'];
                    break;
                }
            }

            if (!$picked) {
                break;
            }

            $selected->push($picked);
            $candidates = $candidates->reject(fn (NpcMaster $npc) => $npc->npc_id === $picked->npc_id)->values();
        }

        return $selected;
    }

    private function removeDuplicateReunionNpc(Character $character, string $date): void
    {
        $normalNpcIds = PlayerTavernDailyNpc::where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->where('slot_no', '<=', 3)
            ->pluck('npc_id')
            ->map(fn ($npcId): int => (int) $npcId)
            ->all();

        if ($normalNpcIds === []) {
            return;
        }

        PlayerTavernDailyNpc::where('character_id', $character->id)
            ->whereDate('tavern_date', $date)
            ->where('slot_no', 4)
            ->whereIn('npc_id', $normalNpcIds)
            ->delete();
    }

    public function meetsCondition(Character $character, NpcMaster $npc): bool
    {
        $type = (string) $npc->appear_condition_type;
        $value = (string) $npc->appear_condition_value;

        return match ($type) {
            'always' => true,
            'total_exploration_count' => ($character->wins + $character->losses) >= (int) $value,
            'tavern_visit_count' => (int) optional(PlayerTavernVisit::where('character_id', $character->id)->first())->visit_count >= (int) $value,
            'player_level' => $character->level >= (int) $value,
            'gold' => $character->money >= (int) $value,
            'chain_count' => (int) optional($character->explorationState)->chain_count >= (int) $value,
            'reached_city_id' => $this->hasReachedCity($character, (int) $value),
            'cleared_city_id' => $this->hasClearedCity($character, (int) $value),
            'cleared_area_id' => $character->areaProgresses()->where('area_id', (int) $value)->where('boss_defeated', true)->exists(),
            'mastered_job_id' => $this->hasMasteredJob($character, (int) $value),
            'job_rank' => $this->hasJobRank($character, $value),
            'total_job_master_count' => $character->jobHistories()->where(function ($query) {
                $query->where('is_mastered', true)->orWhere('job_level', '>=', 10);
            })->count() >= (int) $value,
            'title_count' => $character->titles()->count() >= (int) $value,
            'hidden_area_count' => $character->areaProgresses()->where('area_id', '>=', 71)->where('boss_defeated', true)->count() >= (int) $value,
            'rare_drop_count' => $character->characterItems()->where('acquired_from', 'drop')->count() >= (int) $value,
            'defeat_count' => $character->losses >= (int) $value,
            'arena_win_count' => class_exists(ArenaLog::class)
                ? ArenaLog::where('attacker_id', $character->id)->where('is_attacker_win', true)->count() >= (int) $value
                : false,
            'defeated_final_boss' => $this->hasClearedCity($character, 10),
            'npc_encounter_count' => PlayerNpcEncounter::where('character_id', $character->id)->count() >= (int) $value,
            default => false,
        };
    }

    private function hasReachedCity(Character $character, int $cityId): bool
    {
        if (!$character->highestCity) {
            return (int) $character->highest_city_id >= $cityId;
        }

        $targetOrder = DB::table('cities')->where('id', $cityId)->value('sort_order');
        return $targetOrder ? $character->highestCity->sort_order >= $targetOrder : false;
    }

    private function hasClearedCity(Character $character, int $cityId): bool
    {
        $areaIds = Area::where('city_id', $cityId)->where('id', '<=', 70)->pluck('id');
        if ($areaIds->isEmpty()) {
            return false;
        }

        $cleared = $character->areaProgresses()
            ->whereIn('area_id', $areaIds)
            ->where('boss_defeated', true)
            ->count();

        return $cleared >= $areaIds->count();
    }

    private function hasMasteredJob(Character $character, int $jobId): bool
    {
        return $character->jobHistories()
            ->where('job_class_id', $jobId)
            ->where(function ($query) {
                $query->where('is_mastered', true)->orWhere('job_level', '>=', 10);
            })
            ->exists();
    }

    private function hasJobRank(Character $character, string $value): bool
    {
        [$jobId, $rank] = array_pad(explode(':', $value, 2), 2, 0);

        return $character->jobHistories()
            ->where('job_class_id', (int) $jobId)
            ->where('job_level', '>=', (int) $rank)
            ->exists();
    }
}
