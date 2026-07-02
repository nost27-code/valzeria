<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\NpcMaster;
use App\Models\PlayerNpcEncounter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NpcFieldEncounterService
{
    public function roll(Character $character, Area $area): ?array
    {
        $candidates = $this->candidates($character, $area);
        if ($candidates->isEmpty()) {
            return null;
        }

        $npc = $this->pickWeighted($candidates);
        if (!$npc) {
            return null;
        }

        $isFirst = $this->recordEncounter($character, $npc);
        app(TavernNpcService::class)->addTodayReunionNpc($character, $npc);
        $message = $this->messageFor($npc, $area, $isFirst);
        $line = $this->lineFor($npc);

        return [
            'type' => 'npc',
            'npc_id' => (int) $npc->npc_id,
            'name' => (string) $npc->npc_name,
            'job_name' => (string) ($npc->npc_title ?: '冒険者'),
            'area_name' => (string) $area->name,
            'level' => null,
            'icon_url' => asset($npc->image_path),
            'avatar_frame_url' => null,
            'message' => $message,
            'line' => $line,
            'gift' => null,
            'is_first_encounter' => $isFirst,
        ];
    }

    private function candidates(Character $character, Area $area): Collection
    {
        $eligibleRanks = config('npc_field_encounters.eligible_ranks', ['common', 'skilled', 'hero']);

        return NpcMaster::query()
            ->where('is_active', true)
            ->whereIn('npc_rank', $eligibleRanks)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (NpcMaster $npc): bool => $this->matchesLocation($npc, $area))
            ->filter(fn (NpcMaster $npc): bool => app(TavernNpcService::class)->meetsCondition($character, $npc))
            ->values();
    }

    private function matchesLocation(NpcMaster $npc, Area $area): bool
    {
        $locations = config('npc_field_encounters.locations', []);
        $location = $locations[(int) $npc->npc_id] ?? null;
        if (!is_array($location)) {
            return false;
        }

        $areaId = (int) ($location['area_id'] ?? 0);
        if ($areaId > 0) {
            return (int) $area->id === $areaId;
        }

        $cityId = (int) ($location['city_id'] ?? 0);

        return $cityId > 0 && (int) ($area->city_id ?? 0) === $cityId;
    }

    private function pickWeighted(Collection $candidates): ?NpcMaster
    {
        $weighted = $candidates->map(fn (NpcMaster $npc): array => [
            'npc' => $npc,
            'weight' => max(1, (int) ($npc->base_weight ?? 1)),
        ]);

        $total = $weighted->sum('weight');
        if ($total <= 0) {
            return $candidates->first();
        }

        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($weighted as $entry) {
            $cursor += (int) $entry['weight'];
            if ($roll <= $cursor) {
                return $entry['npc'];
            }
        }

        return $candidates->first();
    }

    private function recordEncounter(Character $character, NpcMaster $npc): bool
    {
        return DB::transaction(function () use ($character, $npc): bool {
            $encounter = PlayerNpcEncounter::lockForUpdate()
                ->where('character_id', $character->id)
                ->where('npc_id', $npc->npc_id)
                ->first();

            if (!$encounter) {
                PlayerNpcEncounter::create([
                    'character_id' => $character->id,
                    'npc_id' => $npc->npc_id,
                    'first_encountered_at' => now(),
                    'encounter_count' => 1,
                    'last_encountered_at' => now(),
                ]);

                return true;
            }

            $encounter->encounter_count++;
            $encounter->last_encountered_at = now();
            $encounter->save();

            return false;
        });
    }

    private function messageFor(NpcMaster $npc, Area $area, bool $isFirst): string
    {
        $name = (string) $npc->npc_name;
        $title = (string) ($npc->npc_title ?: '冒険者');

        if ($isFirst) {
            return "{$area->name}の道中で、{$title}「{$name}」とすれ違った。名簿にその姿が記録された。";
        }

        $patterns = [
            "{$area->name}の道中で、{$title}「{$name}」とすれ違った。短く会釈を交わし、探索を続けた。",
            "{$name}が少し先の道を確かめていた。どうやら、このあたりをよく知っているようだ。",
            "遠くに{$name}の姿が見えた。ヴァルゼリアの道には、今日も冒険者たちの気配がある。",
        ];

        return $patterns[array_rand($patterns)];
    }

    private function lineFor(NpcMaster $npc): ?string
    {
        $line = trim(strip_tags((string) ($npc->talk_text ?: $npc->hint_text ?: '')));
        if ($line === '') {
            return null;
        }

        $line = preg_replace('/\s+/u', ' ', $line) ?: $line;

        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($line) > 90) {
            return rtrim(mb_substr($line, 0, 90), " \t\n\r\0\x0B、。") . '……';
        }

        if (!function_exists('mb_strlen') && strlen($line) > 180) {
            return rtrim(substr($line, 0, 180), " \t\n\r\0\x0B、。") . '...';
        }

        return $line;
    }
}
