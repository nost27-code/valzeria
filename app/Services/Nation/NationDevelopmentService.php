<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationResourceTransaction;
use Illuminate\Support\Collection;

final class NationDevelopmentService
{
    public function ledgerTotal(Nation|int $nation): int
    {
        $nationId = $nation instanceof Nation ? $nation->id : $nation;

        return (int) NationResourceTransaction::query()
            ->where('nation_id', $nationId)
            ->sum('development_exp_delta');
    }

    public function personalContribution(Nation|int $nation, Character|int $character): int
    {
        $nationId = $nation instanceof Nation ? $nation->id : $nation;
        $characterId = $character instanceof Character ? $character->id : $character;

        return (int) NationResourceTransaction::query()
            ->where('nation_id', $nationId)
            ->where('character_id', $characterId)
            ->where('transaction_type', 'donation')
            ->sum('development_exp_delta');
    }

    /** @return Collection<int, array{character_id:?int,name:string,development_exp:int}> */
    public function contributionRows(Nation|int $nation): Collection
    {
        $nationId = $nation instanceof Nation ? $nation->id : $nation;

        return NationResourceTransaction::query()
            ->leftJoin('characters', 'characters.id', '=', 'nation_resource_transactions.character_id')
            ->where('nation_resource_transactions.nation_id', $nationId)
            ->where('nation_resource_transactions.transaction_type', 'donation')
            ->selectRaw('nation_resource_transactions.character_id, characters.name, SUM(nation_resource_transactions.development_exp_delta) AS development_exp')
            ->groupBy('nation_resource_transactions.character_id', 'characters.name')
            ->orderByDesc('development_exp')
            ->orderBy('nation_resource_transactions.character_id')
            ->get()
            ->map(static fn ($row): array => [
                'character_id' => $row->character_id === null ? null : (int) $row->character_id,
                'name' => $row->character_id === null ? '退会した冒険者' : (string) $row->name,
                'development_exp' => (int) $row->development_exp,
            ]);
    }
}
