<?php

namespace App\Services;

use App\Models\Character;
use App\Models\NationMembership;
use App\Models\TownMapRegistration;
use Illuminate\Database\Eloquent\Builder;

class MapPublicationVisibilityService
{
    /** @return array<int, array{value: string, label: string, description: string, enabled: bool}> */
    public function optionsFor(Character $character): array
    {
        $membership = (bool) config('features.nation_community_enabled', false)
            ? NationMembership::query()->with('nation')->where('character_id', $character->id)->first()
            : null;

        return [
            [
                'value' => TownMapRegistration::VISIBILITY_OWNER,
                'label' => '自分だけ',
                'description' => '発見者だけが探索できます。入場料は無料です。',
                'enabled' => true,
            ],
            [
                'value' => TownMapRegistration::VISIBILITY_NATION,
                'label' => '国家限定',
                'description' => $membership?->nation
                    ? $membership->nation->display_name.'の仲間だけが探索できます。'
                    : '国家に所属すると選べます。',
                'enabled' => $membership !== null,
            ],
            [
                'value' => TownMapRegistration::VISIBILITY_ALL,
                'label' => 'すべての冒険者',
                'description' => 'これまでどおり、誰でも探索できます。',
                'enabled' => true,
            ],
        ];
    }

    public function nationIdForPublication(Character $character, string $scope): ?int
    {
        if (!in_array($scope, TownMapRegistration::VISIBILITY_SCOPES, true)) {
            throw new \RuntimeException('地図の公開範囲が正しくありません。');
        }

        if ($scope !== TownMapRegistration::VISIBILITY_NATION) {
            return null;
        }

        $nationId = (bool) config('features.nation_community_enabled', false)
            ? NationMembership::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->value('nation_id')
            : null;

        if (!$nationId) {
            throw new \RuntimeException('国家に所属している冒険者だけが、国家限定で公開できます。');
        }

        return (int) $nationId;
    }

    public function applyVisibleTo(Builder $query, Character $character): Builder
    {
        $nationId = $this->currentNationId($character);

        return $query->where(function (Builder $visible) use ($character, $nationId): void {
            $visible
                ->whereHas('map', fn (Builder $map) => $map->where('owner_character_id', $character->id))
                ->orWhere('visibility_scope', TownMapRegistration::VISIBILITY_ALL);

            if ($nationId !== null) {
                $visible->orWhere(function (Builder $nation) use ($nationId): void {
                    $nation->where('visibility_scope', TownMapRegistration::VISIBILITY_NATION)
                        ->where('nation_id_snapshot', $nationId);
                });
            }
        });
    }

    public function canAccess(Character $character, TownMapRegistration $registration, bool $hasActiveEntry = false): bool
    {
        if ($hasActiveEntry || (int) $registration->map->owner_character_id === (int) $character->id) {
            return true;
        }

        $currentNationId = $this->currentNationId($character);

        return match ($registration->visibilityScope()) {
            TownMapRegistration::VISIBILITY_ALL => true,
            TownMapRegistration::VISIBILITY_NATION => $currentNationId !== null
                && $currentNationId === (int) $registration->nation_id_snapshot,
            default => false,
        };
    }

    private function currentNationId(Character $character): ?int
    {
        if (!(bool) config('features.nation_community_enabled', false)) {
            return null;
        }

        $nationId = NationMembership::query()
            ->where('character_id', $character->id)
            ->value('nation_id');

        return $nationId ? (int) $nationId : null;
    }
}
