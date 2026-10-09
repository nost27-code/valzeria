<?php

namespace App\Services;

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use RuntimeException;

class NamelessEquipmentBulkDiscardService
{
    public const MAX_SELECTION = 300;

    public function __construct(private NamelessWorkshopService $workshop) {}

    public function canDiscard(PlayerNamelessEquipment $body): bool
    {
        return $body->acquisition_source === 'ruin' && ! $body->is_locked && ! $body->is_equipped
            && ($body->relationLoaded('relics') ? $body->relics->isEmpty() : $body->relics_count !== null && (int) $body->relics_count === 0);
    }

    public function preview(Character $character, array $ids, bool $lock = false): array
    {
        $this->workshop->assertAvailable();
        $ids = $this->normalizeIds($ids);
        $query = PlayerNamelessEquipment::query()->where('character_id', $character->id)
            ->whereIn('id', $ids)->withCount('relics')->orderBy('id');
        $bodies = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($bodies->count() !== count($ids)) {
            throw new RuntimeException('選んだ武具を所持していません。画面を開き直してください。');
        }
        $items = [];
        foreach ($bodies as $body) {
            if (! $this->canDiscard($body)) {
                throw new RuntimeException('初期配布・保護中・装備中・遺物装着中の武具は破棄できません。選び直してください。');
            }
            $items[] = ['id' => $body->id, 'name' => $body->displayName(), 'kind' => $body->kind,
                'equipment_type' => $body->equipment_type, 'forge_level' => $body->forge_level,
                'growth_exp' => $body->growth_exp, 'revision' => $body->revision,
                'base_power' => $body->base_power, 'power_per_level' => $body->power_per_level];
        }
        $preview = ['character_id' => $character->id, 'items' => $items, 'count' => count($items),
            'growth_exp' => array_sum(array_column($items, 'growth_exp'))];
        $preview['confirmation_hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));

        return $preview;
    }

    public function discard(Character $character, array $ids, string $confirmationHash, string $uuid): array
    {
        $ids = $this->normalizeIds($ids);
        return $this->workshop->operation($character, $uuid, 'discard-equipment-bulk', compact('ids', 'confirmationHash'), function ($locked) use ($ids, $confirmationHash) {
            $preview = $this->preview($locked, $ids, true);
            if (! hash_equals($preview['confirmation_hash'], $confirmationHash)) {
                throw new RuntimeException('武具の状態が変わりました。選び直して破棄内容を確認してください。');
            }
            PlayerNamelessEquipment::query()->where('character_id', $locked->id)->whereIn('id', $ids)->delete();

            return ['message' => $preview['count'].'個の名もなき武具を破棄しました。発見記録は残ります。',
                'discarded_equipment' => $preview['items'], 'count' => $preview['count']];
        });
    }

    private function normalizeIds(array $ids): array
    {
        if (count($ids) < 1 || count($ids) > self::MAX_SELECTION) {
            throw new RuntimeException('破棄する武具を1〜'.self::MAX_SELECTION.'個選んでください。');
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (! is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1
                || in_array((int) $id, $normalized, true)) {
                throw new RuntimeException('武具は重複せず、正しい個体番号で選んでください。');
            }
            $normalized[] = (int) $id;
        }
        sort($normalized);

        return $normalized;
    }
}
