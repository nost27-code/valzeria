<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** 通常装備と名もなき武具で共有する遺物の装着先・組み合わせ判定。 */
class NamelessRelicEquipmentService
{
    public function ordinarySchemaReady(): bool
    {
        return app(NamelessWorkshopService::class)->ready() && $this->storedRelicsSchemaReady();
    }

    /** 効果・画面を停止しても、既存装着資産の保護と継承は全環境で維持する。 */
    public function storedRelicsSchemaReady(): bool
    {
        return Schema::hasTable('player_relics') && Schema::hasColumn('player_relics', 'character_item_id');
    }

    public function rank(Item $item): string
    {
        return strtoupper((string) ($item->{$item->type.'_rank'} ?? $item->rarity ?? ''));
    }

    public function slotsForItem(?Item $item): int
    {
        if (! $item || ! in_array($item->type, NamelessEquipmentService::KINDS, true) || app(EquipmentService::class)->isMark($item)) {
            return 0;
        }

        return (int) config('nameless_relics.ordinary_equipment_slots.'.$this->rank($item), 0);
    }

    public function slotsFor(PlayerNamelessEquipment|CharacterItem $equipment): int
    {
        return $equipment instanceof PlayerNamelessEquipment
            ? (int) config('nameless_relics.slots_per_equipment', 3) : $this->slotsForItem($equipment->item);
    }

    public function kind(PlayerNamelessEquipment|CharacterItem $equipment): string
    {
        return $equipment instanceof PlayerNamelessEquipment ? $equipment->kind : (string) $equipment->item?->type;
    }

    public function ordinaryForDisplay(Character $character): Collection
    {
        if (! app(NamelessWorkshopService::class)->ready() || ! $this->ordinarySchemaReady()) {
            return collect();
        }

        return CharacterItem::query()->where('character_id', $character->id)->whereNull('market_listing_id')
            ->with(['item', 'affixPrefix', 'affixSuffix', 'relics'])->orderByDesc('is_equipped')->orderByDesc('id')->get()
            ->filter(fn ($equipment) => $this->slotsFor($equipment) > 0)->keyBy('id');
    }

    public function relicsFor(PlayerNamelessEquipment|CharacterItem $equipment): Collection
    {
        return $equipment->relics()->where('character_id', $equipment->character_id)->orderBy('slot_number')->get();
    }

    public function activeRelics(Character $character, ?string $kind = null, ?string $excludeKind = null): Collection
    {
        if (! app(NamelessWorkshopService::class)->ready()) {
            return collect();
        }
        $ordinary = $this->ordinarySchemaReady();
        $relations = $ordinary ? ['equipment', 'characterItem.item'] : ['equipment'];

        return PlayerRelic::query()->where('character_id', $character->id)
            ->where(function ($query) use ($character, $ordinary, $kind, $excludeKind): void {
                $query->whereHas('equipment', fn ($q) => $q->where('character_id', $character->id)->where('is_equipped', true)
                    ->when($kind, fn ($q) => $q->where('kind', $kind))->when($excludeKind, fn ($q) => $q->where('kind', '!=', $excludeKind)));
                if ($ordinary) {
                    $query->orWhereHas('characterItem', fn ($q) => $q->where('character_id', $character->id)->where('is_equipped', true)
                        ->where('is_stored', false)->whereNull('market_listing_id')
                        ->whereHas('item', fn ($q) => $q->when($kind, fn ($q) => $q->where('type', $kind))
                            ->when($excludeKind, fn ($q) => $q->where('type', '!=', $excludeKind))));
                }
            })->with($relations)->orderByDesc('rank')->orderBy('id')->get()
            ->filter(function (PlayerRelic $relic) use ($character, $kind, $excludeKind, $ordinary): bool {
                if ((bool) $relic->nameless_equipment_id === (bool) $relic->character_item_id) {
                    return false;
                }
                $equipment = $relic->nameless_equipment_id ? $relic->equipment : ($ordinary ? $relic->characterItem : null);
                if (! $equipment || (int) $equipment->character_id !== (int) $character->id || ! $equipment->is_equipped) {
                    return false;
                }
                if ($equipment instanceof CharacterItem && ($equipment->is_stored || $equipment->isMarketListed())) {
                    return false;
                }
                $equipmentKind = $this->kind($equipment);

                return $relic->slot_number >= 1 && $relic->slot_number <= $this->slotsFor($equipment)
                    && (! $kind || $kind === $equipmentKind) && (! $excludeKind || $excludeKind !== $equipmentKind);
            })->unique('effect_key')->values();
    }

    public function validateEffects(Collection $relics): void
    {
        $effects = $relics->pluck('effect_key')->all();
        if (count($effects) !== count(array_unique($effects))) {
            throw new RuntimeException('同じ遺物効果は一本の武具内と、装備中の武器・防具・装飾品を合わせて一つまでです。遺物を組み替えてください。');
        }
        app(NamelessRelicCatalog::class)->validateLoadout($effects);
    }

    public function assertCanEquip(Character $character, PlayerNamelessEquipment|CharacterItem $equipment): void
    {
        if (! app(NamelessWorkshopService::class)->ready()
            || ($equipment instanceof CharacterItem && ! $this->ordinarySchemaReady())) {
            return;
        }
        $relics = $this->relicsFor($equipment);
        if ($relics->contains(fn ($relic) => $relic->slot_number < 1 || $relic->slot_number > $this->slotsFor($equipment))) {
            throw new RuntimeException('装着先の遺物枠を確認し、遺物を取り外してください。');
        }
        $this->validateEffects($relics->concat($this->activeRelics($character, null, $this->kind($equipment))));
    }

    /** Characterの行ロックを取得した工房操作の中から呼ぶ。 */
    public function attach(Character $character, PlayerNamelessEquipment|CharacterItem $equipment, int $slot, int $relicId): PlayerRelic
    {
        if ((int) $equipment->character_id !== (int) $character->id || $slot < 1 || $slot > $this->slotsFor($equipment)) {
            throw new RuntimeException('遺物枠が不正です。SSSは2枠、EPICと名もなきシリーズは3枠です。');
        }
        if ($equipment instanceof CharacterItem && $equipment->isMarketListed()) {
            throw new RuntimeException('出品中の装備には遺物を付けられません。');
        }
        $relic = PlayerRelic::query()->where('character_id', $character->id)->whereKey($relicId)->lockForUpdate()->first();
        if (! $relic) {
            throw new RuntimeException('その遺物は所持していません。');
        }
        if ($relic->isAttached()) {
            throw new RuntimeException('装着中の遺物は先に外してください。');
        }
        $loadout = $this->relicsFor($equipment)->reject(fn ($attached) => $attached->slot_number === $slot);
        if ($equipment->is_equipped && ! ($equipment instanceof CharacterItem && $equipment->is_stored)) {
            $loadout = $loadout->concat($this->activeRelics($character, null, $this->kind($equipment)));
        }
        $this->validateEffects($loadout->push($relic));
        $column = $equipment instanceof CharacterItem ? 'character_item_id' : 'nameless_equipment_id';
        $cleared = ['nameless_equipment_id' => null, 'slot_number' => null];
        if ($this->ordinarySchemaReady()) {
            $cleared['character_item_id'] = null;
        }
        $equipment->relics()->where('character_id', $character->id)->where('slot_number', $slot)->update($cleared);
        $relic->forceFill(array_merge($cleared, [$column => $equipment->id, 'slot_number' => $slot]))->save();
        if ($equipment instanceof PlayerNamelessEquipment) {
            $equipment->increment('revision');
        }

        return $relic;
    }

    /** 機能フラグを切っても装着済み資産の消費・所有者変更は防ぐ。 */
    public function hasAttachedRelics(CharacterItem $equipment): bool
    {
        return $this->storedRelicsSchemaReady() && $equipment->relics()->exists();
    }

    public function assertDetached(CharacterItem $equipment): void
    {
        if ($this->hasAttachedRelics($equipment)) {
            throw new RuntimeException('遺物を取り外してから売却・出品・素材化してください。');
        }
    }

    public function releaseForLoss(CharacterItem $equipment): void
    {
        if ($this->storedRelicsSchemaReady()) {
            $equipment->relics()->update(['character_item_id' => null, 'slot_number' => null]);
        }
    }

    public function inheritEvolution(Character $character, CharacterItem $source, CharacterItem $destination): void
    {
        if (! $this->storedRelicsSchemaReady()) {
            return;
        }
        $relics = $source->relics()->lockForUpdate()->get();
        if ($relics->contains(fn ($relic) => (int) $relic->character_id !== (int) $character->id
            || $relic->nameless_equipment_id || $relic->slot_number < 1 || $relic->slot_number > $this->slotsFor($destination))) {
            throw new RuntimeException('進化先へ遺物を引き継げません。先に遺物を取り外してください。');
        }
        $source->relics()->update(['character_item_id' => $destination->id]);
        if ($destination->is_equipped) {
            $this->assertCanEquip($character, $destination);
        }
        CharacterStatusService::clearRequestCache((int) $character->id);
    }
}
