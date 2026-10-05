<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class NamelessRelicGrowthService
{
    public function __construct(private readonly NamelessWorkshopService $workshop, private readonly NamelessRelicCatalog $catalog) {}

    public function ready(): bool
    {
        return $this->workshop->ready() && Schema::hasColumn('player_relics', 'growth_progress');
    }

    public function summary(PlayerRelic $relic): array
    {
        $this->catalog->value($relic->effect_key, $relic->rank);
        $required = $relic->rank < NamelessRelicCatalog::MAX_RANK ? (int) config('nameless_relics.growth_copies.'.$relic->rank) - 1 : 0;
        $progress = (int) $relic->growth_progress;
        if ($progress < 0 || ($relic->rank < NamelessRelicCatalog::MAX_RANK && ($required < 1 || $progress >= $required)) || ($required === 0 && $progress !== 0)) {
            throw new RuntimeException('遺物の育成状態を確認してください。');
        }

        return [
            'can_grow' => $required > 0, 'required' => $required, 'progress' => $progress, 'remaining' => $required - $progress,
            'percent' => $required > 0 ? (int) floor(100 * $progress / $required) : 100,
            'next_label' => $required > 0 ? $this->catalog->rankLabel($relic->rank + 1) : null,
            'next_summary' => $required > 0 ? $this->catalog->summary($relic->effect_key, $relic->rank + 1) : null,
        ];
    }

    public function candidates(PlayerRelic $target, Collection $relics): Collection
    {
        return $relics->filter(fn ($relic) => $relic->id !== $target->id && $relic->effect_key === $target->effect_key
            && $relic->rank === $target->rank && ! $relic->is_locked && ! $relic->isAttached() && ! $relic->growth_progress);
    }

    public function preview(Character $character, int $relicId, array $sourceIds, bool $lock = false): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('遺物育成は現在利用できません。設定とmigrationを確認してください。');
        }
        $ids = array_map('intval', array_values($sourceIds));
        if (! $ids || count($ids) !== count(array_unique($ids)) || min($ids) < 1 || in_array($relicId, $ids, true)) {
            throw new RuntimeException('育成する本体とは別の素材遺物を、重複せず選んでください。');
        }
        sort($ids);
        $query = PlayerRelic::query()->where('character_id', $character->id)->whereIn('id', array_merge([$relicId], $ids))->orderBy('id');
        $owned = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $target = $owned->get($relicId);
        if (! $target || $owned->count() !== count($ids) + 1) {
            throw new RuntimeException('その遺物は所持していません。素材を確認し直してください。');
        }
        $growth = $this->summary($target);
        if (! $growth['can_grow']) {
            throw new RuntimeException('ランクIXの遺物は最大育成済みです。');
        }
        if (count($ids) > $growth['remaining']) {
            throw new RuntimeException('次のランクまでに必要な素材は、あと'.$growth['remaining'].'個です。');
        }
        $equipment = null;
        if ($target->nameless_equipment_id) {
            $query = PlayerNamelessEquipment::query()->where('character_id', $character->id)->whereKey($target->nameless_equipment_id);
            $body = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $body) {
                throw new RuntimeException('装着先の武具を確認してください。');
            }
            $equipment = ['id' => $body->id, 'revision' => $body->revision, 'is_equipped' => $body->is_equipped];
        }
        if ($target->character_item_id) {
            $query = CharacterItem::query()->where('character_id', $character->id)->whereKey($target->character_item_id)->with('item');
            $body = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $body || $body->isMarketListed() || $target->nameless_equipment_id) {
                throw new RuntimeException('装着先の装備を確認してください。');
            }
            $equipment = ['ordinary_id' => $body->id, 'item_id' => $body->item_id, 'is_equipped' => $body->is_equipped,
                'is_stored' => $body->is_stored, 'enhance_level' => $body->enhance_level, 'updated_at' => (string) $body->updated_at,
                'slots' => app(NamelessRelicEquipmentService::class)->slotsFor($body)];
        }
        $sources = [];
        foreach ($ids as $id) {
            $source = $owned->get($id);
            if ($source->effect_key !== $target->effect_key || $source->rank !== $target->rank) {
                throw new RuntimeException('同じ効果・同じランクの遺物だけを育成素材に使えます。');
            }
            if ($source->is_locked || $source->isAttached() || $source->growth_progress) {
                throw new RuntimeException('保護中・装着中・育成途中の遺物は素材に使えません。');
            }
            $sources[] = $this->state($source) + ['name' => $source->displayName()];
        }
        $filled = $growth['progress'] + count($ids);
        $rankUp = $filled === $growth['required'];
        $rankAfter = $target->rank + ($rankUp ? 1 : 0);
        $preview = [
            'relic_id' => $target->id, 'effect_key' => $target->effect_key, 'name_before' => $target->displayName(),
            'name_after' => $this->catalog->definition($target->effect_key)['name'].' '.$this->catalog->rankLabel($rankAfter),
            'summary_before' => $target->effectSummary(), 'summary_after' => $this->catalog->summary($target->effect_key, $rankAfter),
            'rank_before' => $target->rank, 'rank_after' => $rankAfter, 'rank_up' => $rankUp,
            'required' => $growth['required'], 'progress_before' => $growth['progress'], 'filled_progress' => $filled,
            'progress_after' => $rankUp ? 0 : $filled, 'source_count' => count($ids), 'sources' => $sources,
        ];
        $preview['confirmation_hash'] = hash('sha256', json_encode([$this->state($target), $equipment, $preview], JSON_THROW_ON_ERROR));

        return $preview;
    }

    public function grow(Character $character, int $relicId, array $sourceIds, string $confirmationHash, string $uuid): array
    {
        return $this->workshop->operation($character, $uuid, 'relic-grow', compact('relicId', 'sourceIds', 'confirmationHash'), function (Character $locked) use ($relicId, $sourceIds, $confirmationHash): array {
            $preview = $this->preview($locked, $relicId, $sourceIds, true);
            if (! hash_equals($preview['confirmation_hash'], $confirmationHash)) {
                throw new RuntimeException('所持状態が変わりました。育成内容を確認し直してください。');
            }
            PlayerRelic::query()->where('character_id', $locked->id)->whereIn('id', array_column($preview['sources'], 'id'))->delete();
            $target = PlayerRelic::query()->where('character_id', $locked->id)->whereKey($relicId)->firstOrFail();
            $target->forceFill(['rank' => $preview['rank_after'], 'growth_progress' => $preview['progress_after']])->save();
            if ($preview['rank_up'] && $target->isAttached()) {
                if ($target->nameless_equipment_id) {
                    PlayerNamelessEquipment::query()->where('character_id', $locked->id)->whereKey($target->nameless_equipment_id)->increment('revision');
                }
                CharacterStatusService::clearRequestCache((int) $locked->id);
                $stats = app(CharacterStatusService::class)->getFinalStats($locked);
                $locked->forceFill(['current_hp' => min((int) $locked->current_hp, $stats['max_hp']), 'current_mp' => min((int) $locked->current_mp, $stats['max_mp'])])->save();
            }

            return $preview + ['message' => $preview['rank_up']
                ? $preview['name_before'].'を育て、'.$preview['name_after'].'になりました。'
                : $preview['name_before'].'へ素材を'.$preview['source_count'].'個投入しました。育成進捗 '.$preview['progress_after'].'／'.$preview['required'].'。'];
        });
    }

    private function state(PlayerRelic $relic): array
    {
        return ['id' => $relic->id, 'character_id' => $relic->character_id, 'effect_key' => $relic->effect_key, 'rank' => $relic->rank,
            'growth_progress' => (int) $relic->growth_progress, 'is_locked' => $relic->is_locked,
            'nameless_equipment_id' => $relic->nameless_equipment_id, 'character_item_id' => $relic->character_item_id, 'slot_number' => $relic->slot_number];
    }
}
