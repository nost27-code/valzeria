<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use Illuminate\Support\Collection;

/** 装備カードで共用する、現在装備の比較基準を画面内で一度だけ作る。 */
class EquipmentComparisonService
{
    public function forDisplay(Character $character, Collection $items, ?array $relicRates = null): array
    {
        $status = app(CharacterStatusService::class);
        $previews = [];
        foreach (['weapon', 'armor'] as $slot) {
            if (!$items->contains(fn (CharacterItem $item): bool => !$item->is_equipped && $item->item?->type === $slot)) {
                continue;
            }
            $equipped = $items->first(fn (CharacterItem $item): bool => (bool) $item->is_equipped && $item->equipped_slot === $slot);
            if (! $equipped) {
                $previews[$slot] = $status->getFinalStats($character);
                continue;
            }
            $method = $slot.'EffectivePreview';
            $previews[$slot] = $relicRates === null
                ? $status->{$method}($character, $equipped)
                : $status->{$method}($character, $equipped, $relicRates[$equipped->id] ?? []);
        }

        return $previews;
    }

    /** One read-only display calculation; action/battle gates always inspect their own current schema. */
    public function relicRatesForDisplay(Character $character, Collection $items): array
    {
        $candidates = $items->filter(fn (CharacterItem $item) => in_array($item->item?->type, ['weapon', 'armor'], true));
        $rates = $candidates->mapWithKeys(fn (CharacterItem $item) => [$item->id => []])->all();
        if ($candidates->isEmpty() || ! app(NamelessRelicEquipmentService::class)->ordinarySchemaReady()) {
            return $rates;
        }
        (new \Illuminate\Database\Eloquent\Collection($candidates->all()))->loadMissing('relics');
        $equipment = app(NamelessRelicEquipmentService::class);
        $catalog = app(NamelessRelicCatalog::class);
        $remaining = [];
        foreach (['weapon', 'armor'] as $slot) {
            if ($candidates->contains(fn (CharacterItem $item) => $item->item->type === $slot)) {
                $remaining[$slot] = $equipment->activeRelics($character, null, $slot);
            }
        }
        foreach ($candidates as $item) {
            $incoming = $item->relics->filter(fn ($relic) => (int) $relic->character_id === (int) $character->id
                && (int) $item->character_id === (int) $character->id
                && $relic->slot_number >= 1 && $relic->slot_number <= $equipment->slotsFor($item))
                ->sortBy('slot_number')->values();
            $rates[$item->id] = $catalog->aggregateStatRates($remaining[$item->item->type]->concat($incoming)->unique('effect_key'));
        }
        return $rates;
    }

}
