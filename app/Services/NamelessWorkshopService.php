<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class NamelessWorkshopService
{
    public function __construct(private readonly NamelessRelicCatalog $catalog) {}

    public function enabled(): bool
    {
        return (bool) config('nameless_relics.enabled', false);
    }

    public function ready(): bool
    {
        return $this->enabled() && $this->schemaReady();
    }

    /** OFFのまま移行・街登録を準備するためのschema判定。 */
    public function schemaReady(): bool
    {
        return app(NamelessSchemaService::class)->problems() === [];
    }

    public function assertAvailable(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('名もなき工房は現在利用できません。設定とmigrationを確認してください。');
        }
    }

    public function operation(Character $character, string $uuid, string $action, array $payload, Closure $callback): array
    {
        $this->assertAvailable();
        if (! Str::isUuid($uuid)) {
            throw new RuntimeException('操作番号が不正です。画面を開き直してください。');
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($character, $uuid, $action, $hash, $callback): array {
                $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
                $previous = NamelessWorkshopOperation::query()->where('character_id', $locked->id)->where('request_uuid', $uuid)->first();
                if ($previous) {
                    if ($previous->action !== $action || ! hash_equals($previous->payload_hash, $hash)) {
                        throw new RuntimeException('同じ操作番号で内容を変更することはできません。');
                    }

                    return $previous->result;
                }
                // HTTP以外の呼び出しでも、入場中の地図から資産を持ち出させない。
                app(NamelessTownService::class)->assertVisiting($locked);
                if (app(MapExplorationItemService::class)->restoreActiveSession($locked)) {
                    throw new RuntimeException('探索地図から帰還してから操作してください。');
                }
                $result = $callback($locked);
                NamelessWorkshopOperation::query()->create(['character_id' => $locked->id, 'request_uuid' => $uuid, 'action' => $action, 'payload_hash' => $hash, 'result' => $result]);

                return $result;
            }, 3);
        } finally {
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
    }

    public function needsIntroduction(Character $character): bool
    {
        return ! PlayerNamelessEquipment::query()->where('character_id', $character->id)
            ->where('kind', 'weapon')->where('acquisition_source', 'starter')->exists();
    }

    public function claim(Character $character, string $kind, string $type, string $uuid): array
    {
        NamelessEquipmentService::statFor($kind, $type);

        return $this->operation($character, $uuid, 'claim', compact('kind', 'type'), function ($locked) use ($kind, $type) {
            if ($kind !== 'weapon') {
                throw new RuntimeException('今は名もなき武器だけを受け取れます。');
            }
            $equipment = PlayerNamelessEquipment::query()->where('character_id', $locked->id)->where('kind', $kind)->where('acquisition_source', 'starter')->first();
            if (! $equipment) {
                if (app(NamelessEquipmentCollectionService::class)->freeSlots($locked) === 0) {
                    throw new RuntimeException('名もなき武具の所持枠がいっぱいです。');
                }
                $equipment = PlayerNamelessEquipment::query()->create(['character_id' => $locked->id, 'kind' => $kind, 'acquisition_source' => 'starter', 'equipment_type' => $type, 'forge_level' => 0, 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]);
            }

            return ['message' => $equipment->displayName().'を受け取りました。', 'equipment_id' => $equipment->id];
        });
    }

    public function changeEquipment(Character $character, int $equipmentId, bool $equipped, string $uuid): array
    {
        return $this->operation($character, $uuid, 'equip', compact('equipmentId', 'equipped'), function ($locked) use ($equipmentId, $equipped) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            if ($equipped) {
                app(NamelessRelicEquipmentService::class)->assertCanEquip($locked, $equipment);
                CharacterItem::query()->where('character_id', $locked->id)->where('is_equipped', true)->where('equipped_slot', $equipment->kind)->update(['is_equipped' => false, 'equipped_slot' => null]);
                PlayerNamelessEquipment::query()->where('character_id', $locked->id)->where('kind', $equipment->kind)->where('is_equipped', true)
                    ->whereKeyNot($equipmentId)->update(['is_equipped' => false, 'revision' => DB::raw('revision + 1')]);
            }
            $equipment->forceFill(['is_equipped' => $equipped, 'revision' => $equipment->revision + 1])->save();
            $this->clampResources($locked);

            return ['message' => $equipment->displayName().($equipped ? 'を装備しました。' : 'を外しました。')];
        });
    }

    public function configure(Character $character, int $equipmentId, string $type, ?string $name, string $uuid): array
    {
        $name = $this->normalizeEquipmentName($name);

        return $this->operation($character, $uuid, 'configure', compact('equipmentId', 'type', 'name'), function ($locked) use ($equipmentId, $type, $name) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            NamelessEquipmentService::statFor($equipment->kind, $type);
            if ($equipment->acquisition_source !== 'starter' && $equipment->equipment_type !== $type) {
                throw new RuntimeException('遺跡で拾った武具の種類は変更できません。名前は自由に変更できます。');
            }
            $equipment->forceFill(['equipment_type' => $type, 'custom_name' => $name !== '' ? $name : null, 'revision' => $equipment->revision + 1])->save();
            $this->clampResources($locked);

            return ['message' => $equipment->displayName().($equipment->acquisition_source === 'starter' ? 'の形と名前を整えました。' : 'の名前を変更しました。')];
        });
    }

    public function rename(Character $character, int $equipmentId, ?string $name, string $uuid): array
    {
        $name = $this->normalizeEquipmentName($name);
        return $this->operation($character, $uuid, 'rename', compact('equipmentId', 'name'), function ($locked) use ($equipmentId, $name) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            $equipment->forceFill(['custom_name' => $name !== '' ? $name : null, 'revision' => $equipment->revision + 1])->save();
            return ['message' => $equipment->displayName().'の名前を変更しました。'];
        });
    }

    private function normalizeEquipmentName(?string $name): string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) > 32 || preg_match('/[<>]/u', $name)) {
            throw new RuntimeException('名前は記号 < > を含まない32文字以内にしてください。');
        }
        return $name;
    }

    public function attach(Character $character, int $equipmentId, int $slot, int $relicId, string $uuid): array
    {
        return $this->operation($character, $uuid, 'attach', compact('equipmentId', 'slot', 'relicId'), function ($locked) use ($equipmentId, $slot, $relicId) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            $relic = app(NamelessRelicEquipmentService::class)->attach($locked, $equipment, $slot, $relicId);
            $this->clampResources($locked);

            return ['message' => $relic->displayName().'を装着しました。'];
        });
    }

    public function attachOrdinary(Character $character, int $characterItemId, int $slot, int $relicId, string $uuid): array
    {
        return $this->operation($character, $uuid, 'attach-ordinary', compact('characterItemId', 'slot', 'relicId'), function ($locked) use ($characterItemId, $slot, $relicId) {
            if (! app(NamelessRelicEquipmentService::class)->ordinarySchemaReady()) {
                throw new RuntimeException('通常装備への装着にはmigrationが必要です。');
            }
            $equipment = $this->ownedOrdinaryEquipment($locked, $characterItemId);
            $relic = app(NamelessRelicEquipmentService::class)->attach($locked, $equipment, $slot, $relicId);
            $this->clampResources($locked);

            return ['message' => $relic->displayName().'を装着しました。'];
        });
    }

    public function changeOrdinaryEquipment(Character $character, int $characterItemId, bool $equipped, string $uuid): array
    {
        return $this->operation($character, $uuid, 'equip-ordinary', compact('characterItemId', 'equipped'), function ($locked) use ($characterItemId, $equipped) {
            $equipment = $this->ownedOrdinaryEquipment($locked, $characterItemId);
            if (app(NamelessRelicEquipmentService::class)->slotsFor($equipment) === 0) {
                throw new RuntimeException('SSS・EPICの武器・防具・装飾品を選んでください。');
            }
            $service = app(EquipmentService::class);
            $result = $equipped ? $service->equip($locked, $equipment) : $service->unequip($locked, $equipment);
            if (! $result['success']) {
                throw new RuntimeException($result['message']);
            }

            return ['message' => $result['message']];
        });
    }

    private function ownedOrdinaryEquipment(Character $character, int $id): CharacterItem
    {
        return CharacterItem::query()->where('character_id', $character->id)->whereKey($id)->with('item')->lockForUpdate()->first()
            ?? throw new RuntimeException('その装備は所持していません。');
    }

    public function detach(Character $character, int $relicId, string $uuid): array
    {
        return $this->operation($character, $uuid, 'detach', compact('relicId'), function ($locked) use ($relicId) {
            $relic = PlayerRelic::query()->where('character_id', $locked->id)->whereKey($relicId)->lockForUpdate()->firstOrFail();
            if ($relic->nameless_equipment_id) {
                $this->ownedEquipment($locked, (int) $relic->nameless_equipment_id)->increment('revision');
            }
            $cleared = ['nameless_equipment_id' => null, 'slot_number' => null];
            if (app(NamelessRelicEquipmentService::class)->ordinarySchemaReady()) {
                if ($relic->character_item_id) {
                    $this->ownedOrdinaryEquipment($locked, (int) $relic->character_item_id);
                }
                $cleared['character_item_id'] = null;
            }
            $relic->forceFill($cleared)->save();
            $this->clampResources($locked);

            return ['message' => $relic->displayName().'を外しました。遺物は手元に残ります。'];
        });
    }

    public function protect(Character $character, int $relicId, bool $protect, string $uuid): array
    {
        return $this->operation($character, $uuid, 'protect', compact('relicId', 'protect'), function ($locked) use ($relicId, $protect) {
            $relic = PlayerRelic::query()->where('character_id', $locked->id)->whereKey($relicId)->lockForUpdate()->firstOrFail();
            $relic->update(['is_locked' => $protect]);

            return ['message' => $relic->displayName().($protect ? 'を保護しました。' : 'の保護を解除しました。')];
        });
    }

    /** 強化上限後も収集を続けられるよう、余剰品だけを明示操作で整理する。 */
    public function discard(Character $character, int $relicId, string $uuid, ?array $expectedState = null): array
    {
        // 旧フォームの進捗0の要求は、保存済みUUIDのpayloadを維持する。
        $payload = compact('relicId');
        if ($expectedState !== null) {
            $payload['expectedState'] = $expectedState;
        }
        return $this->operation($character, $uuid, 'discard', $payload, function ($locked) use ($relicId, $expectedState) {
            $relic = PlayerRelic::query()->where('character_id', $locked->id)->whereKey($relicId)->lockForUpdate()->first();
            if (! $relic || $relic->is_locked || $relic->isAttached()) {
                throw new RuntimeException('未所持・保護中・装着中の遺物は破棄できません。');
            }
            if ($expectedState !== null && (
                ($expectedState['rank'] ?? null) !== (int) $relic->rank
                || ($expectedState['growth_progress'] ?? null) !== (int) $relic->growth_progress
            )) {
                throw new RuntimeException('遺物のランクまたは育成進捗が変わりました。画面を開き直して確認してください。');
            }
            if ($relic->growth_progress && $expectedState === null) {
                throw new RuntimeException('育成途中の遺物を破棄するには、進捗を失うことを確認してください。');
            }
            if (in_array($relicId, $this->bestRelicIds($locked), true)) {
                throw new RuntimeException('各効果の最高ランクは一つ残してください。');
            }
            $result = ['message' => $relic->displayName().'を破棄しました。成長EXPは得られません。', 'discarded' => ['id' => $relic->id, 'name' => $relic->displayName(), 'effect_key' => $relic->effect_key, 'rank' => $relic->rank, 'growth_progress' => (int) $relic->growth_progress]];
            $relic->delete();

            return $result;
        });
    }

    public function protectEquipment(Character $character, int $equipmentId, bool $protect, string $uuid): array
    {
        return $this->operation($character, $uuid, 'protect-equipment', compact('equipmentId', 'protect'), function ($locked) use ($equipmentId, $protect) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            $equipment->forceFill(['is_locked' => $protect, 'revision' => $equipment->revision + 1])->save();
            return ['message' => $equipment->displayName().($protect ? 'を保護しました。' : 'の保護を解除しました。')];
        });
    }

    public function inventoryBlockReason(Character $character, ?array $storage = null): ?string
    {
        $storage ??= app(StorageCapacityService::class)->summary($character);
        if ($storage['equipment_full']) {
            return '装備倉庫の所持枠がいっぱいです。倉庫や鍛冶屋で不要な武具を整理してください。';
        }
        if ($storage['material_full']) {
            return '素材倉庫の所持枠がいっぱいです。素材を倉庫で、余剰遺物を鍛冶屋で整理してください。';
        }
        return null;
    }

    public function discardEquipment(Character $character, int $equipmentId, int $revision, string $uuid): array
    {
        return $this->operation($character, $uuid, 'discard-equipment', compact('equipmentId', 'revision'), function ($locked) use ($equipmentId, $revision) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            if ($equipment->revision !== $revision) {
                throw new RuntimeException('武具の状態が変わりました。画面を開き直してください。');
            }
            if ($equipment->acquisition_source !== 'ruin' || $equipment->is_locked || $equipment->is_equipped || $equipment->relics()->exists()) {
                throw new RuntimeException('初期配布・保護中・装備中・遺物装着中の武具は破棄できません。');
            }
            $result = ['message' => $equipment->displayName().'を破棄しました。発見記録は残ります。',
                'discarded_equipment' => ['id' => $equipment->id, 'name' => $equipment->displayName(), 'forge_level' => $equipment->forge_level, 'growth_exp' => $equipment->growth_exp]];
            $equipment->delete();
            return $result;
        });
    }

    public function materialFeedExp(Material $material): int
    {
        if ($material->is_key_item || $material->is_cash_item) {
            return 0;
        }
        if (preg_match('/導石|古代片|秘境晶|極印|進化証|討伐証|英雄の証|刻印|王印|神印/u', (string) $material->name)) {
            return 0;
        }

        $code = (string) $material->material_code;
        foreach (config('nameless_relics.continental_material_codes', []) as $exp => $codes) {
            if (in_array($code, $codes, true)) {
                return (int) $exp;
            }
        }
        $continental = ($material->material_type === 'common_drop' && str_starts_with($code, 'MAT_COMMON_'))
            || ($material->material_type === 'regional_drop' && str_starts_with($code, 'MAT_REGION_') && (int) $material->city_id >= 1 && (int) $material->city_id <= 10)
            || (in_array($material->material_type, ['city', 'city_high'], true) && preg_match('/^CITY_(0[1-9]|10)_(MATERIAL|HIGH)$/', $code));

        return $continental ? max(0, (int) config('nameless_relics.material_feed_exp.'.$material->material_type, 0)) : 0;
    }

    /** 消費を伴わない確認画面。確定時は同じ計算を行ロック下で再実行する。 */
    public function previewFeed(Character $character, int $equipmentId, array $materials, array $relicIds, int $keep, bool $protectBest, bool $lock = false): array
    {
        $this->assertAvailable();
        $equipment = $this->ownedEquipment($character, $equipmentId, $lock);
        if ($equipment->forge_level >= NamelessEquipmentService::MAX_FORGE_LEVEL) {
            throw new RuntimeException('本体は最大強化済みです。素材・遺物は消費できません。');
        }
        if ($keep < 0) {
            throw new RuntimeException('残す個数は0以上にしてください。');
        }
        $sources = [];
        $exp = 0;
        ksort($materials);
        foreach ($materials as $rowId => $quantity) {
            $quantity = (int) $quantity;
            if ($quantity === 0) {
                continue;
            }
            if ($quantity < 0) {
                throw new RuntimeException('投入数は0以上にしてください。');
            }
            $query = CharacterMaterial::query()->where('character_id', $character->id)->whereKey((int) $rowId)->with('material');
            $row = ($lock ? $query->lockForUpdate() : $query)->first();
            $unitExp = $row?->material ? $this->materialFeedExp($row->material) : 0;
            if (! $row || $unitExp <= 0) {
                throw new RuntimeException('吸収できない素材が選ばれています。');
            }
            if ($row->quantity - $quantity < $keep) {
                throw new RuntimeException($row->material->name.'の所持数または残す個数が不足しています。');
            }
            $sources[] = ['kind' => 'material', 'id' => $row->id, 'material_id' => $row->material_id, 'name' => $row->material->name, 'quantity' => $quantity, 'before' => (int) $row->quantity, 'exp' => $unitExp * $quantity];
            $exp += $unitExp * $quantity;
        }
        $ids = array_values(array_unique(array_map('intval', $relicIds)));
        sort($ids);
        $preserveIds = $protectBest ? $this->bestRelicIds($character) : [];
        foreach ($ids as $id) {
            $query = PlayerRelic::query()->where('character_id', $character->id)->whereKey($id);
            $relic = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $relic || $relic->is_locked || $relic->isAttached()) {
                throw new RuntimeException('未所持・保護中・装着中の遺物は吸収できません。');
            }
            if ($relic->growth_progress) {
                throw new RuntimeException('育成途中の遺物は吸収できません。進捗を残して育成を続けてください。');
            }
            if (in_array($id, $preserveIds, true)) {
                throw new RuntimeException($relic->displayName().'は各効果の最高ランクを残す設定で保護されています。');
            }
            $gain = $this->catalog->feedExp($relic->rank);
            $sources[] = ['kind' => 'relic', 'id' => $id, 'name' => $relic->displayName(), 'effect_key' => $relic->effect_key, 'rank' => $relic->rank, 'quantity' => 1, 'exp' => $gain];
            $exp += $gain;
        }
        if ($exp <= 0) {
            throw new RuntimeException('吸収する素材か遺物を選んでください。');
        }
        $result = ['equipment_id' => $equipment->id, 'equipment_name' => $equipment->displayName(), 'equipment_renamed' => $equipment->isRenamed(), 'revision' => $equipment->revision, 'exp_before' => $equipment->growth_exp, 'gained_exp' => $exp, 'exp_after' => $equipment->growth_exp + $exp, 'sources' => $sources];
        $result['confirmation_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function feed(Character $character, int $equipmentId, array $materials, array $relicIds, int $keep, bool $protectBest, string $confirmationHash, string $uuid): array
    {
        return $this->operation($character, $uuid, 'feed', compact('equipmentId', 'materials', 'relicIds', 'keep', 'protectBest', 'confirmationHash'), function ($locked) use ($equipmentId, $materials, $relicIds, $keep, $protectBest, $confirmationHash) {
            $preview = $this->previewFeed($locked, $equipmentId, $materials, $relicIds, $keep, $protectBest, true);
            if (! hash_equals($preview['confirmation_hash'], $confirmationHash)) {
                throw new RuntimeException('所持状態が変わりました。吸収内容を確認し直してください。');
            }
            foreach ($preview['sources'] as $source) {
                if ($source['kind'] === 'material') {
                    CharacterMaterial::query()->where('character_id', $locked->id)->whereKey($source['id'])->decrement('quantity', $source['quantity']);
                } else {
                    PlayerRelic::query()->where('character_id', $locked->id)->whereKey($source['id'])->delete();
                }
            }
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            $equipment->forceFill(['growth_exp' => $preview['exp_after'], 'revision' => $equipment->revision + 1])->save();

            return $preview + ['message' => $equipment->displayName().'へ成長EXP '.number_format($preview['gained_exp']).'を注ぎました。'];
        });
    }

    public function bestRelicIds(Character $character): array
    {
        return PlayerRelic::query()->where('character_id', $character->id)->orderByDesc('rank')->orderByDesc('is_locked')->orderBy('id')->get(['id', 'effect_key'])->unique('effect_key')->pluck('id')->all();
    }

    public function forgeCap(Character $character): int
    {
        $depth = (int) (NamelessRuinProgress::query()->where('character_id', $character->id)->max('unlocked_depth') ?? 1);

        return min(NamelessEquipmentService::MAX_FORGE_LEVEL, max((int) config('nameless_relics.initial_forge_cap'), $depth * (int) config('nameless_relics.forge_cap_per_depth')));
    }

    public function forgeSummary(Character $character, PlayerNamelessEquipment $equipment): array
    {
        $next = $equipment->forge_level + 1;

        return ['next_level' => $next, 'cap' => $this->forgeCap($character), 'exp' => $next * (int) config('nameless_relics.growth_exp_per_next_level'), 'gold' => $next * (int) config('nameless_relics.gold_per_next_level'), 'total_gold' => (int) (NamelessEquipmentService::MAX_FORGE_LEVEL * (NamelessEquipmentService::MAX_FORGE_LEVEL + 1) / 2) * (int) config('nameless_relics.gold_per_next_level'), 'payment' => app(BankService::class)->paymentSummary($character, $next * (int) config('nameless_relics.gold_per_next_level'))];
    }

    public function forgeMaterialQuantity(PlayerNamelessEquipment $equipment, Material $material): int
    {
        $unit = $this->materialFeedExp($material);
        if ($unit <= 0) {
            throw new RuntimeException('この素材は武具の強化に使えません。');
        }
        $required = ($equipment->forge_level + 1) * (int) config('nameless_relics.growth_exp_per_next_level');
        return (int) ceil(max(0, $required - $equipment->growth_exp) / $unit);
    }

    public function forge(Character $character, int $equipmentId, int $revision, bool $useBank, string $uuid, ?int $materialRowId = null): array
    {
        $payload = compact('equipmentId', 'revision', 'useBank');
        if ($materialRowId !== null) $payload['materialRowId'] = $materialRowId;
        return $this->operation($character, $uuid, 'forge', $payload, function ($locked) use ($equipmentId, $revision, $useBank, $materialRowId) {
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            if ($equipment->revision !== $revision) {
                throw new RuntimeException('武具の状態が変わりました。画面を開き直してください。');
            }
            $cost = $this->forgeSummary($locked, $equipment);
            if ($cost['next_level'] > $cost['cap']) {
                throw new RuntimeException('遺跡のボスを倒し、次の深度へ進むと育成上限が解放されます。');
            }
            $material = null;
            $quantity = 0;
            $gained = 0;
            if ($equipment->growth_exp < $cost['exp'] && $materialRowId !== null) {
                $material = CharacterMaterial::query()->where('character_id', $locked->id)->whereKey($materialRowId)
                    ->with('material')->lockForUpdate()->first();
                if (!$material?->material) throw new RuntimeException('強化に使う素材を所持していません。');
                $quantity = $this->forgeMaterialQuantity($equipment, $material->material);
                if ($material->quantity < $quantity) throw new RuntimeException('強化に必要な素材が不足しています。');
                $gained = $quantity * $this->materialFeedExp($material->material);
            }
            if ($equipment->growth_exp + $gained < $cost['exp']) {
                throw new RuntimeException('成長EXPが足りません。素材や不要な遺物を吸収させてください。');
            }
            if ($cost['gold'] > 0) {
                app(BankService::class)->spendForPayment($locked, $cost['gold'], $useBank, 'nameless_relic_forge', $equipment->displayName().'の育成', 'nameless_equipment', $equipment->id);
            }
            if ($quantity > 0) $material->decrement('quantity', $quantity);
            $equipment->forceFill(['growth_exp' => $equipment->growth_exp + $gained - $cost['exp'], 'forge_level' => $cost['next_level'], 'revision' => $equipment->revision + 1])->save();
            $this->clampResources($locked);

            return ['message' => $equipment->displayName().'を +'.$equipment->forge_level.' に鍛えました。', 'equipment_id' => $equipment->id, 'spent_exp' => $cost['exp'], 'spent_gold' => $cost['gold'],
                'spent_materials' => $quantity > 0 ? [['id' => $material->id, 'material_id' => $material->material_id, 'name' => $material->material->name, 'quantity' => $quantity]] : []];
        });
    }

    /** 素材・遺物の混合投入を、消費せずに検証する。確定時もロック下で再計算する。 */
    public function previewForge(Character $character, int $equipmentId, int $revision, array $materials, array $relicIds, bool $protectBest, bool $useBank, bool $lock = false): array
    {
        $this->assertAvailable();
        $materials = $this->normalizeForgeMaterials($materials);
        $equipment = $this->ownedEquipment($character, $equipmentId, $lock);
        if ($equipment->revision !== $revision) {
            throw new RuntimeException('武具の状態が変わりました。画面を開き直してください。');
        }
        $cost = $this->forgeSummary($character, $equipment);
        if ($cost['next_level'] > $cost['cap']) {
            throw new RuntimeException('遺跡のボスを倒し、次の深度へ進むと育成上限が解放されます。');
        }
        $sources = [];
        $gained = 0;
        if (array_filter($materials, fn ($quantity) => (int) $quantity !== 0) || $relicIds) {
            $feed = $this->previewFeed($character, $equipmentId, $materials, $relicIds, 0, $protectBest, $lock);
            $sources = $feed['sources'];
            $gained = $feed['gained_exp'];
        }
        if ($equipment->growth_exp + $gained < $cost['exp']) {
            throw new RuntimeException('強化に必要な素材量が不足しています。素材や遺物を追加してください。');
        }
        if (! $cost['payment']['can_pay']) {
            throw new RuntimeException('手持ちと預金を合わせてもゴールドが不足しています。');
        }
        if ($cost['payment']['requires_bank'] && ! $useBank) {
            throw new RuntimeException('手持ちが不足しています。預金を使う場合はチェックしてください。');
        }
        $preview = [
            'equipment_id' => $equipment->id, 'equipment_name' => $equipment->displayName(), 'equipment_renamed' => $equipment->isRenamed(),
            'revision' => $revision, 'next_level' => $cost['next_level'], 'gold' => $cost['gold'], 'use_bank' => $useBank,
            'exp_before' => $equipment->growth_exp, 'gained_exp' => $gained, 'spent_exp' => $cost['exp'],
            'exp_after' => $equipment->growth_exp + $gained - $cost['exp'], 'sources' => $sources,
            'performance_before' => $equipment->performanceStats(), 'performance_after' => $equipment->performanceStatsAt($cost['next_level']),
            'performance_label' => $equipment->kindLabel().'性能 '.$equipment->performanceLabel($cost['next_level']),
        ];
        $preview['confirmation_hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));
        return $preview;
    }

    public function forgeCombined(Character $character, int $equipmentId, int $revision, array $materials, array $relicIds, bool $protectBest, bool $useBank, string $confirmationHash, string $uuid): array
    {
        $materials = $this->normalizeForgeMaterials($materials);
        $relicIds = array_values(array_unique(array_map('intval', $relicIds)));
        sort($relicIds);
        return $this->operation($character, $uuid, 'forge', compact('equipmentId', 'revision', 'materials', 'relicIds', 'protectBest', 'useBank', 'confirmationHash'), function ($locked) use ($equipmentId, $revision, $materials, $relicIds, $protectBest, $useBank, $confirmationHash) {
            $preview = $this->previewForge($locked, $equipmentId, $revision, $materials, $relicIds, $protectBest, $useBank, true);
            if (! hash_equals($preview['confirmation_hash'], $confirmationHash)) {
                throw new RuntimeException('所持状態が変わりました。強化内容を確認し直してください。');
            }
            $equipment = $this->ownedEquipment($locked, $equipmentId);
            app(BankService::class)->spendForPayment($locked, $preview['gold'], $useBank, 'nameless_relic_forge', $equipment->displayName().'の育成', 'nameless_equipment', $equipment->id);
            foreach ($preview['sources'] as $source) {
                if ($source['kind'] === 'material') {
                    CharacterMaterial::query()->where('character_id', $locked->id)->whereKey($source['id'])->decrement('quantity', $source['quantity']);
                } else {
                    PlayerRelic::query()->where('character_id', $locked->id)->whereKey($source['id'])->delete();
                }
            }
            $equipment->forceFill(['growth_exp' => $preview['exp_after'], 'forge_level' => $preview['next_level'], 'revision' => $revision + 1])->save();
            $this->clampResources($locked);
            return [
                'message' => $equipment->displayName().'を +'.$equipment->forge_level.' に鍛えました。', 'equipment_id' => $equipment->id,
                'spent_exp' => $preview['spent_exp'], 'spent_gold' => $preview['gold'], 'growth_exp_remaining' => $preview['exp_after'],
                'spent_materials' => array_values(array_filter($preview['sources'], fn ($source) => $source['kind'] === 'material')),
                'spent_relics' => array_values(array_filter($preview['sources'], fn ($source) => $source['kind'] === 'relic')),
            ];
        });
    }

    /** 同じ所有行を別表記のキーで二度指定させず、再送payloadも整数に統一する。 */
    private function normalizeForgeMaterials(array $materials): array
    {
        $normalized = [];
        foreach ($materials as $id => $quantity) {
            if (! ctype_digit((string) $id) || (int) $id < 1 || (string) $id !== (string) (int) $id
                || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0) {
                throw new RuntimeException('素材の指定と個数は正しい整数（個数は0以上）にしてください。');
            }
            $normalized[(int) $id] = (int) $quantity;
        }
        ksort($normalized);
        return $normalized;
    }

    /** 表示用にも戦闘と同じローカル機能ゲートを適用する。 */
    public function ownedEquipmentForDisplay(Character $character)
    {
        if (! $this->ready()) {
            return collect();
        }

        return PlayerNamelessEquipment::query()->where('character_id', $character->id)
            ->orderBy('kind')->orderBy('id')->get();
    }

    public function equippedEquipmentForDisplay(Character $character)
    {
        return $this->ownedEquipmentForDisplay($character)->where('is_equipped', true)->keyBy('kind');
    }

    public function activeRelics(Character $character, ?string $kind = null, ?string $excludeKind = null)
    {
        return app(NamelessRelicEquipmentService::class)->activeRelics($character, $kind, $excludeKind);
    }

    public function equippedStatRates(Character $character, ?string $excludeKind = null, ?CharacterItem $incoming = null): array
    {
        $relics = $this->activeRelics($character, null, $excludeKind);
        if ($incoming && (int) $incoming->character_id === (int) $character->id && $this->ready()
            && app(NamelessRelicEquipmentService::class)->ordinarySchemaReady()) {
            $relics = $relics->concat(app(NamelessRelicEquipmentService::class)->relicsFor($incoming)
                ->filter(fn ($relic) => $relic->slot_number >= 1 && $relic->slot_number <= app(NamelessRelicEquipmentService::class)->slotsFor($incoming)));
        }

        return $this->catalog->aggregateStatRates($relics->unique('effect_key'));
    }

    /** 武具本体と遺物の表示用合計。本体は戦闘計算で武器・防具・装飾品性能へ分離する。 */
    public function equippedBonuses(Character $character, ?string $kind = null): array
    {
        $bonuses = array_fill_keys(['hp', 'mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'], 0);
        if (! $this->ready()) {
            return $bonuses;
        }
        foreach (PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('is_equipped', true)->when($kind, fn ($q) => $q->where('kind', $kind))->get() as $equipment) {
            foreach ($equipment->performanceStats() as $stat => $power) {
                $bonuses[$stat] += $power;
            }
        }
        foreach ($this->activeRelics($character, $kind) as $relic) {
            foreach ($this->catalog->statBonuses($relic->effect_key, $relic->rank) as $stat => $value) {
                $bonuses[$stat] += $value;
            }
        }

        return $bonuses;
    }

    public function equippedWeaponOffense(Character $character): array
    {
        $offense = ['str' => 0, 'mag' => 0];
        if (! $this->ready()) {
            return $offense;
        }
        foreach (PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', 'weapon')->where('is_equipped', true)->get() as $equipment) {
            $stat = NamelessEquipmentService::statFor('weapon', $equipment->equipment_type)['key'];
            $offense[$stat] += $equipment->power();
        }

        return $offense;
    }

    public function equippedArmorDefense(Character $character): array
    {
        $defense = ['def' => 0, 'spr' => 0];
        if (! $this->ready()) {
            return $defense;
        }
        foreach (PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', 'armor')->where('is_equipped', true)->get() as $equipment) {
            foreach ($equipment->performanceStats() as $stat => $power) {
                $defense[$stat] += $power;
            }
        }

        return $defense;
    }

    /** 装飾品本体は基礎能力へ固定加算。遺物の割合効果は武具の計算後に適用する。 */
    public function equippedFixedBonuses(Character $character, ?string $kind = null): array
    {
        $bonuses = array_fill_keys(['hp', 'mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'], 0);
        if (! $this->ready() || ($kind !== null && $kind !== 'accessory')) {
            return $bonuses;
        }
        foreach (PlayerNamelessEquipment::query()->where('character_id', $character->id)->where('kind', 'accessory')->where('is_equipped', true)->get() as $equipment) {
            foreach ($equipment->performanceStats() as $stat => $power) {
                $bonuses[$stat] += $power;
            }
        }

        return $bonuses;
    }

    private function ownedEquipment(Character $character, int $id, bool $lock = true): PlayerNamelessEquipment
    {
        $query = PlayerNamelessEquipment::query()->where('character_id', $character->id)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new RuntimeException('その名もなき武具は所持していません。');
    }

    private function clampResources(Character $character): void
    {
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $character->forceFill(['current_hp' => min((int) $character->current_hp, $stats['max_hp']), 'current_mp' => min((int) $character->current_mp, $stats['max_mp'])])->save();
    }
}
