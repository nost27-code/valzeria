<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationMembership;
use App\Models\NationWarPreparationPreset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NationWarPreparationPresetService
{
    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationRoleService $roles,
        private readonly NationWarSettingsService $warSettings,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationLevelBenefitSettingsService $settings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function save(NationMembership $actor, array $attributes, ?NationWarPreparationPreset $preset = null): NationWarPreparationPreset
    {
        $this->settings->assertEnabled();
        throw_unless($this->warSettings->featureEnabled(), \DomainException::class, '戦争準備プリセットは国家戦公開後に利用できます。');
        $normalized = $this->normalize($attributes);

        return DB::transaction(function () use ($actor, $normalized, $preset): NationWarPreparationPreset {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            throw_unless($lockedActor, \DomainException::class, '国家の所属情報が変更されました。');
            $this->roles->authorize($lockedActor, 'manage_war_presets');
            $slotLimit = $this->levels->benefitsForLevel(
                $this->levels->levelFor((int) $nation->development_exp),
            )['war_preset_slots'];
            throw_if($slotLimit < 1, \DomainException::class, '戦争準備プリセットは国家Lv20で開放されます。');

            $lockedPreset = null;
            if ($preset) {
                $lockedPreset = NationWarPreparationPreset::whereKey($preset->id)
                    ->where('nation_id', $nation->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $usedOrders = NationWarPreparationPreset::where('nation_id', $nation->id)
                    ->lockForUpdate()
                    ->pluck('display_order')
                    ->map(static fn ($value): int => (int) $value)
                    ->all();
                $freeOrder = collect(range(1, $slotLimit))->first(fn (int $order): bool => ! in_array($order, $usedOrders, true));
                throw_unless($freeOrder, \DomainException::class, "現在の国家Lvではプリセットを{$slotLimit}件まで保存できます。");
                $normalized['display_order'] = $freeOrder;
            }

            $values = [
                ...$normalized,
                'nation_id' => $nation->id,
                'updated_by_character_id' => $lockedActor->character_id,
            ];
            if ($lockedPreset) {
                unset($values['display_order']);
                $lockedPreset->update($values);
                $saved = $lockedPreset->fresh();
            } else {
                $saved = NationWarPreparationPreset::create($values);
            }

            $this->activityLogs->record($nation, 'war_preparation_preset_saved', $lockedActor->character, null, [
                'preset_id' => $saved->id,
                'preset_name' => $saved->name,
            ]);

            return $saved;
        }, 3);
    }

    public function delete(NationMembership $actor, NationWarPreparationPreset $preset): void
    {
        $this->settings->assertEnabled();
        DB::transaction(function () use ($actor, $preset): void {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)->where('nation_id', $nation->id)->lockForUpdate()->first();
            throw_unless($lockedActor, \DomainException::class, '国家の所属情報が変更されました。');
            $this->roles->authorize($lockedActor, 'manage_war_presets');
            $lockedPreset = NationWarPreparationPreset::whereKey($preset->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $name = $lockedPreset->name;
            $lockedPreset->delete();
            $this->activityLogs->record($nation, 'war_preparation_preset_deleted', $lockedActor->character, null, [
                'preset_name' => $name,
            ]);
        }, 3);
    }

    /** @return Collection<int, NationWarPreparationPreset> */
    public function forNation(Nation $nation): Collection
    {
        return NationWarPreparationPreset::where('nation_id', $nation->id)->orderBy('display_order')->get();
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalize(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        throw_if($name === '' || mb_strlen($name) > 60, \DomainException::class, 'プリセット名は1〜60文字で入力してください。');
        $priority = array_values(array_unique(array_map('strval', (array) ($attributes['facility_priority'] ?? []))));
        throw_if($priority === [] || array_diff($priority, NationFacility::TYPES) !== [], \DomainException::class, '施設の優先順が不正です。');

        return [
            'name' => $name,
            'pool_contribution_points' => max(0, (int) ($attributes['pool_contribution_points'] ?? 0)),
            'facility_upgrade_limit_points' => max(0, (int) ($attributes['facility_upgrade_limit_points'] ?? 0)),
            'facility_priority' => $priority,
            'repair_reserve_warning_points' => max(0, (int) ($attributes['repair_reserve_warning_points'] ?? 0)),
        ];
    }
}
