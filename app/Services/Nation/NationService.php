<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationService
{
    public function create(Character $character, string $name, ?string $description = null): Nation
    {
        $name = trim($name);
        throw_if($name === '' || mb_strlen($name) > 40, \DomainException::class, '国名は1〜40文字で入力してください。');

        return DB::transaction(function () use ($character, $name, $description): Nation {
            throw_if(NationMembership::where('character_id', $character->id)->lockForUpdate()->exists(), \DomainException::class, 'すでに国家へ所属しています。');
            throw_if(Nation::where('name', $name)->exists(), \DomainException::class, 'その国名はすでに使われています。');

            $nation = Nation::create([
                'name' => $name,
                'description' => trim((string) $description) ?: null,
                'founded_at' => now(),
            ]);
            NationMembership::create(['nation_id' => $nation->id, 'character_id' => $character->id, 'role' => 'king', 'joined_at' => now()]);
            foreach (NationFacility::TYPES as $type) {
                NationFacility::create(['nation_id' => $nation->id, 'facility_type' => $type, 'level' => 1, 'condition_bps' => 10000]);
            }

            return $nation->load(['memberships.character', 'facilities']);
        }, 3);
    }
}
