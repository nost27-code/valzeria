<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationWantedMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NationWantedMaterialService
{
    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationLevelBenefitSettingsService $settings,
    ) {}

    /**
     * @param  list<array{material_id:int|string,purpose_note?:string|null}>  $materials
     * @return Collection<int, NationWantedMaterial>
     */
    public function replace(NationMembership $actor, array $materials): Collection
    {
        $this->settings->assertEnabled();
        $normalized = $this->normalize($materials);

        return DB::transaction(function () use ($actor, $normalized): Collection {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            throw_unless($lockedActor, \DomainException::class, '国家の所属情報が変更されました。');
            $this->roles->authorize($lockedActor, 'manage_wanted_materials');

            $slots = $this->levels->benefitsForLevel(
                $this->levels->levelFor((int) $nation->development_exp),
            )['wanted_material_slots'];
            throw_if(count($normalized) > $slots, \DomainException::class, "現在の国家Lvでは募集素材を{$slots}種類まで設定できます。");

            $materialIds = array_column($normalized, 'material_id');
            if ($materialIds !== []) {
                $activeRateCount = NationMaterialConversionRate::query()
                    ->whereIn('material_id', $materialIds)
                    ->where('is_active', true)
                    ->sharedLock()
                    ->count();
                throw_unless($activeRateCount === count($materialIds), \DomainException::class, '国家納品の対象外素材が含まれています。');
            }

            NationWantedMaterial::where('nation_id', $nation->id)
                ->where('is_active', true)
                ->whereNotIn('material_id', $materialIds === [] ? [-1] : $materialIds)
                ->update([
                    'is_active' => false,
                    'deactivated_at' => now(),
                    'updated_by_character_id' => $lockedActor->character_id,
                    'updated_at' => now(),
                ]);

            foreach ($normalized as $index => $item) {
                NationWantedMaterial::updateOrCreate(
                    ['nation_id' => $nation->id, 'material_id' => $item['material_id']],
                    [
                        'purpose_note' => $item['purpose_note'],
                        'display_order' => $index + 1,
                        'is_active' => true,
                        'deactivated_at' => null,
                        'updated_by_character_id' => $lockedActor->character_id,
                    ],
                );
            }

            $this->activityLogs->record($nation, 'wanted_materials_changed', $lockedActor->character, null, [
                'material_ids' => $materialIds,
            ]);

            return $this->activeFor($nation);
        }, 3);
    }

    /** @return Collection<int, NationWantedMaterial> */
    public function activeFor(Nation $nation): Collection
    {
        return NationWantedMaterial::query()
            ->with('material')
            ->where('nation_id', $nation->id)
            ->where('is_active', true)
            ->whereHas('material')
            ->whereIn('material_id', NationMaterialConversionRate::query()
                ->where('is_active', true)
                ->select('material_id'))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /** @param list<array{material_id:int|string,purpose_note?:string|null}> $materials @return list<array{material_id:int,purpose_note:?string}> */
    private function normalize(array $materials): array
    {
        $normalized = [];
        foreach ($materials as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $note = trim((string) ($item['purpose_note'] ?? ''));
            throw_if($materialId < 1, \DomainException::class, '募集素材を選択してください。');
            throw_if(isset($normalized[$materialId]), \DomainException::class, '同じ募集素材が重複しています。');
            throw_if(mb_strlen($note) > 100, \DomainException::class, '募集素材の用途コメントは100文字以内で入力してください。');
            $normalized[$materialId] = [
                'material_id' => $materialId,
                'purpose_note' => $note === '' ? null : $note,
            ];
        }

        return array_values($normalized);
    }
}
