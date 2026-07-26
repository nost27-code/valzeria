<?php

namespace App\Services;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterExplorationSupportPref;
use App\Models\CharacterItem;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\PlayerExplorationSupportEffect;
use App\Models\PlayerExplorationSupportItemState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExplorationSupportService
{
    public const BATTLES_PER_ITEM = 50;
    public const CONTENT_KEY = 'exploration_support';

    public const ITEMS = [
        'support_apothecary_charm' => ['name' => '薬屋のお守り', 'description' => '5戦ごとに戦闘後、最大HPの10%を回復する。'],
        'support_guard_incense' => ['name' => '守りの香', 'description' => '敵から受ける直接ダメージを8%軽減する。'],
        'support_first_aid_kit' => ['name' => '冒険者の救急包', 'description' => '火傷・毒・出血を短縮し、継続ダメージを半減する。'],
        'support_special_herbal' => ['name' => '薬屋の特製漢方', 'description' => '瀕死時に最大HPの20%を回復する。1個につき3回まで。'],
        'support_lure_beast' => ['name' => '誘魔香〈獣〉', 'description' => '通常探索で獣系の敵の出現しやすさが3倍になる。', 'species_key' => 'beast', 'species_label' => '獣'],
        'support_lure_undead' => ['name' => '誘魔香〈不死〉', 'description' => '通常探索で不死系の敵の出現しやすさが3倍になる。', 'species_key' => 'undead', 'species_label' => '不死'],
        'support_lure_dragon' => ['name' => '誘魔香〈竜〉', 'description' => '通常探索で竜系の敵の出現しやすさが3倍になる。', 'species_key' => 'dragon', 'species_label' => '竜'],
        'support_lure_demon' => ['name' => '誘魔香〈悪魔〉', 'description' => '通常探索で悪魔系の敵の出現しやすさが3倍になる。', 'species_key' => 'demon', 'species_label' => '悪魔'],
        'support_lure_aquatic' => ['name' => '誘魔香〈水棲〉', 'description' => '通常探索で水棲系の敵の出現しやすさが3倍になる。', 'species_key' => 'aquatic', 'species_label' => '水棲'],
        'support_lure_flying' => ['name' => '誘魔香〈飛行〉', 'description' => '通常探索で飛行系の敵の出現しやすさが3倍になる。', 'species_key' => 'flying', 'species_label' => '飛行'],
        'support_lure_insect' => ['name' => '誘魔香〈虫〉', 'description' => '通常探索で虫系の敵の出現しやすさが3倍になる。', 'species_key' => 'insect', 'species_label' => '虫'],
        'support_lure_machine' => ['name' => '誘魔香〈機械〉', 'description' => '通常探索で機械系の敵の出現しやすさが3倍になる。', 'species_key' => 'machine', 'species_label' => '機械'],
        'support_lure_slime' => ['name' => '誘魔香〈スライム〉', 'description' => '通常探索でスライム系の敵の出現しやすさが3倍になる。', 'species_key' => 'slime', 'species_label' => 'スライム'],
        'support_lure_soldier' => ['name' => '誘魔香〈人型〉', 'description' => '通常探索で人型系の敵の出現しやすさが3倍になる。', 'species_key' => 'soldier', 'species_label' => '人型'],
        'support_lure_mage' => ['name' => '誘魔香〈魔法型〉', 'description' => '通常探索で魔法型系の敵の出現しやすさが3倍になる。', 'species_key' => 'mage', 'species_label' => '魔法型'],
        'support_lure_spirit' => ['name' => '誘魔香〈精霊〉', 'description' => '通常探索で精霊系の敵の出現しやすさが3倍になる。', 'species_key' => 'spirit', 'species_label' => '精霊'],
    ];

    public function activeFor(Character $character): ?PlayerExplorationSupportEffect
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return PlayerExplorationSupportEffect::query()
            ->with('item')
            ->where('character_id', $character->id)
            ->first();
    }

    /**
     * 通常探索の敵抽選前に、装備中の誘魔香がこの抽選へ適用できるかを確定する。
     *
     * @return array{item_key:string,item_id:int,species_key:string,multiplier:int}|null
     */
    public function encounterModifierFor(Character $character, Collection $enemies): ?array
    {
        if (!$this->isEnabled() || $enemies->isEmpty()) {
            return null;
        }

        $effect = PlayerExplorationSupportEffect::query()
            ->where('character_id', $character->id)
            ->first();
        if (!$effect) {
            return null;
        }

        $itemKey = array_search((int) $effect->item_id, $this->itemIdsByKey(), true);
        $definition = $itemKey ? (self::ITEMS[$itemKey] ?? null) : null;
        $speciesKey = (string) ($definition['species_key'] ?? '');
        if ($speciesKey === '' || !$enemies->contains(fn (Enemy $enemy): bool => $this->enemySpeciesKey($enemy) === $speciesKey)) {
            return null;
        }

        $remaining = (int) (PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $effect->item_id)
            ->value('battles_remaining') ?? $effect->battles_remaining);
        if ($remaining <= 0) {
            $hasAutoRenewStock = (bool) $effect->auto_renew
                && CharacterItem::query()
                    ->where('character_id', $character->id)
                    ->where('item_id', $effect->item_id)
                    ->where('is_equipped', false)
                    ->exists();
            if (!$hasAutoRenewStock) {
                return null;
            }
        }

        return [
            'item_key' => $itemKey,
            'item_id' => (int) $effect->item_id,
            'species_key' => $speciesKey,
            'multiplier' => $this->lureWeightMultiplier(),
        ];
    }

    public function appearanceWeightFor(Enemy $enemy, ?array $modifier, bool $useMasterWeights): int
    {
        $weight = $useMasterWeights ? max(0, (int) $enemy->appearance_weight) : 1;
        if ($modifier && $this->enemySpeciesKey($enemy) === (string) ($modifier['species_key'] ?? '')) {
            $weight *= max(1, (int) ($modifier['multiplier'] ?? 1));
        }

        return $weight;
    }

    /** 戦闘開始直前の効果を固定する。 */
    public function beginBattle(Character $character, ?Enemy $enemy = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return DB::transaction(function () use ($character, $enemy): ?array {
            Character::query()->whereKey($character->id)->lockForUpdate()->first();
            $effect = PlayerExplorationSupportEffect::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            if (!$effect) {
                return null;
            }

            $itemKey = array_search((int) $effect->item_id, $this->itemIdsByKey(), true);
            if (!$itemKey) {
                return null;
            }

            $isLure = $this->isSpeciesLure($itemKey);
            $consumeBattle = !$isLure || (bool) $enemy?->getAttribute('exploration_support_encounter_applied');
            $state = $this->stateForEffect($effect, true);

            if ((int) $state->battles_remaining <= 0) {
                if (!$consumeBattle || !$effect->auto_renew || !$this->consumeOwnedItem($character, (int) $effect->item_id)) {
                    $this->syncEffectFromState($effect, $state);
                    return null;
                }
                $this->resetState($state);
                $this->syncEffectFromState($effect, $state);
            }

            return [
                'item_key' => $itemKey,
                'item_id' => (int) $effect->item_id,
                'battles_remaining' => (int) $state->battles_remaining,
                'battles_elapsed_in_period' => (int) $state->battles_elapsed_in_period,
                'proc_count' => (int) $state->proc_count,
                'consume_battle' => $consumeBattle,
            ];
        });
    }

    /** battle_logs.id を冪等キーにして、終了後の戦数とお守り回復を確定する。 */
    public function completeBattle(Character $character, BattleLog $battleLog, ?array $snapshot): array
    {
        if (!$this->isEnabled() || !$snapshot) {
            return ['active' => $this->payload($character), 'logs' => []];
        }
        if (($snapshot['consume_battle'] ?? true) !== true) {
            return ['active' => $this->payload($character), 'logs' => []];
        }

        return DB::transaction(function () use ($character, $battleLog, $snapshot): array {
            Character::query()->whereKey($character->id)->lockForUpdate()->first();
            $effect = PlayerExplorationSupportEffect::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            if (!$effect || (int) $effect->item_id !== (int) $snapshot['item_id']) {
                return ['active' => $this->payload($character), 'logs' => []];
            }

            $state = $this->stateForEffect($effect, true);
            if ((int) ($state->last_battle_log_id ?? 0) === (int) $battleLog->id) {
                return ['active' => $this->payload($character), 'logs' => []];
            }

            $elapsed = min($this->battlesPerItem(), (int) $state->battles_elapsed_in_period + 1);
            $remaining = max(0, (int) $state->battles_remaining - 1);
            $logs = [];
            if (($snapshot['item_key'] ?? '') === 'support_apothecary_charm'
                && $battleLog->result !== 'lose'
                && $elapsed % 5 === 0) {
                $stats = app(CharacterStatusService::class)->getFinalStats($character);
                $heal = max(1, (int) floor((int) $stats['max_hp'] * 0.10));
                $character->current_hp = min((int) $stats['max_hp'], (int) $character->current_hp + $heal);
                $character->save();
                $logs[] = "<span class=\"text-emerald-700 font-bold\">【薬屋のお守り】旅の節目に傷が{$heal}回復した！</span>";
            }

            $state->forceFill([
                'battles_remaining' => $remaining,
                'battles_elapsed_in_period' => $elapsed,
                'last_battle_log_id' => $battleLog->id,
                'lock_version' => (int) $state->lock_version + 1,
            ])->save();
            $this->syncEffectFromState($effect, $state);

            return ['active' => $this->payload($character), 'logs' => $logs];
        });
    }

    /**
     * 残数がある品は消費せず再装備し、未開封または使い切った品だけ所持品を1個消費する。
     */
    public function activate(Character $character, string $itemKey, ?bool $autoRenew = null): array
    {
        $this->ensureEnabled();

        if (!isset(self::ITEMS[$itemKey])) {
            throw new RuntimeException('選択できない探索補助品です。');
        }

        $this->ensureLureCanBeEquippedHere($character, $itemKey);

        DB::transaction(function () use ($character, $itemKey, $autoRenew): void {
            Character::query()->whereKey($character->id)->lockForUpdate()->first();
            $itemId = $this->itemIdsByKey()[$itemKey] ?? null;
            if (!$itemId) {
                throw new RuntimeException('探索補助品のマスタが見つかりません。');
            }

            $effect = PlayerExplorationSupportEffect::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            $state = PlayerExplorationSupportItemState::query()
                ->where('character_id', $character->id)
                ->where('item_id', $itemId)
                ->lockForUpdate()
                ->first();

            if (!$state || (int) $state->battles_remaining <= 0) {
                if (!$this->consumeOwnedItem($character, $itemId)) {
                    throw new RuntimeException('その探索補助品を所持していません。');
                }

                if (!$state) {
                    $state = PlayerExplorationSupportItemState::query()->create([
                        'character_id' => $character->id,
                        'item_id' => $itemId,
                        'battles_remaining' => $this->battlesPerItem(),
                        'battles_elapsed_in_period' => 0,
                        'proc_count' => 0,
                        'last_battle_log_id' => null,
                        'lock_version' => 1,
                    ]);
                } else {
                    $this->resetState($state);
                }
            }

            $resolvedAutoRenew = $autoRenew ?? $this->autoRenewPreference($character, $itemId);
            if (!$effect) {
                $effect = new PlayerExplorationSupportEffect(['character_id' => $character->id]);
            }
            $effect->forceFill([
                'item_id' => $itemId,
                'battles_remaining' => (int) $state->battles_remaining,
                'battles_elapsed_in_period' => (int) $state->battles_elapsed_in_period,
                'proc_count' => (int) $state->proc_count,
                'auto_renew' => $resolvedAutoRenew,
                'last_battle_log_id' => $state->last_battle_log_id,
                'lock_version' => (int) ($effect->lock_version ?? 0) + 1,
            ])->save();
        });

        return $this->payload($character) ?? throw new RuntimeException('探索補助品を装備できませんでした。');
    }

    /** 品目ごとの自動補充プリファレンスを保存し、それが現在有効中の効果なら即座に反映する。 */
    public function setAutoRenewPreference(Character $character, string $itemKey, bool $autoRenew): void
    {
        $this->ensureEnabled();

        $itemId = $this->itemIdsByKey()[$itemKey] ?? null;
        if (!$itemId) {
            throw new RuntimeException('選択できない探索補助品です。');
        }

        CharacterExplorationSupportPref::updateOrCreate(
            ['character_id' => $character->id, 'item_id' => $itemId],
            ['auto_renew' => $autoRenew],
        );

        PlayerExplorationSupportEffect::where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->update(['auto_renew' => $autoRenew]);
    }

    private function autoRenewPreference(Character $character, int $itemId): bool
    {
        return (bool) (CharacterExplorationSupportPref::query()
            ->where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->value('auto_renew') ?? false);
    }

    public function clear(Character $character): void
    {
        $this->ensureEnabled();

        PlayerExplorationSupportEffect::where('character_id', $character->id)->delete();
    }

    public function reduceDirectDamage(int $damage, ?array $snapshot): int
    {
        if (($snapshot['item_key'] ?? null) !== 'support_guard_incense') {
            return $damage;
        }

        return $damage === 0 ? 0 : max(1, (int) floor($damage * 0.92));
    }

    public function adjustedConditionDuration(int $duration, ?array $snapshot): int
    {
        return ($snapshot['item_key'] ?? null) === 'support_first_aid_kit'
            ? max(1, $duration - 1)
            : max(1, $duration);
    }

    public function adjustedDotDamage(int $damage, ?array $snapshot): int
    {
        if (($snapshot['item_key'] ?? null) !== 'support_first_aid_kit') {
            return $damage;
        }

        return $damage === 0 ? 0 : max(1, (int) floor($damage * 0.50));
    }

    /** 生存した瀕死時だけ漢方を発動する。 */
    public function trySpecialHerbal(Character $character, int &$hp, int $maxHp, ?array &$snapshot): ?int
    {
        if (!is_array($snapshot)
            || ($snapshot['item_key'] ?? null) !== 'support_special_herbal'
            || $hp <= 0 || $maxHp <= 0 || $hp * 100 > $maxHp * 30
            || (int) ($snapshot['proc_count'] ?? 0) >= 3
            || !empty($snapshot['proc_used_this_battle'])) {
            return null;
        }
        $heal = max(1, (int) floor($maxHp * 0.20));
        $hp = min($maxHp, $hp + $heal);
        $snapshot['proc_count'] = (int) $snapshot['proc_count'] + 1;
        $snapshot['proc_used_this_battle'] = true;

        return $heal;
    }

    public function persistBattleProcs(Character $character, ?array $snapshot): void
    {
        if (!$snapshot || ($snapshot['item_key'] ?? '') !== 'support_special_herbal') {
            return;
        }

        $procCount = min(3, (int) $snapshot['proc_count']);
        PlayerExplorationSupportItemState::where('character_id', $character->id)
            ->where('item_id', (int) $snapshot['item_id'])
            ->update(['proc_count' => $procCount]);
        PlayerExplorationSupportEffect::where('character_id', $character->id)
            ->where('item_id', (int) $snapshot['item_id'])
            ->update(['proc_count' => $procCount]);
    }

    public function payload(Character $character): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $effect = $this->activeFor($character);
        if (!$effect) {
            return null;
        }

        $key = array_search((int) $effect->item_id, $this->itemIdsByKey(), true);
        if (!$key) {
            return null;
        }

        $state = PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->where('item_id', $effect->item_id)
            ->first();
        $remaining = (int) ($state?->battles_remaining ?? $effect->battles_remaining);
        $elapsed = (int) ($state?->battles_elapsed_in_period ?? $effect->battles_elapsed_in_period);
        $procCount = (int) ($state?->proc_count ?? $effect->proc_count);

        return [
            'item_key' => $key,
            'name' => self::ITEMS[$key]['name'],
            'description' => self::ITEMS[$key]['description'],
            'remaining' => $remaining,
            'max_battles' => $this->battlesPerItem(),
            'elapsed' => $elapsed,
            'proc_count' => $procCount,
            'procs_remaining' => $key === 'support_special_herbal' ? max(0, 3 - $procCount) : null,
            'auto_renew' => (bool) $effect->auto_renew,
            'is_lure' => $this->isSpeciesLure($key),
            'species_label' => self::ITEMS[$key]['species_label'] ?? null,
        ];
    }

    /**
     * 装備変更画面と戦闘結果のもちものモーダル用に、所持数・品目別残数・現在地での有効性を返す。
     */
    public function belongingsFor(Character $character, ?int $areaId = null, bool $speciesLuresEligible = true): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if ($areaId === null) {
            $areaId = (int) (app(ExplorationStateService::class)->currentFor($character)?->area_id ?? 0);
        }
        $areaId = $areaId > 0 ? $areaId : null;

        $itemIds = $this->itemIdsByKey();
        $ownedCounts = CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('is_equipped', false)
            ->whereIn('item_id', array_values($itemIds))
            ->selectRaw('item_id, count(*) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');
        $prefs = CharacterExplorationSupportPref::query()
            ->where('character_id', $character->id)
            ->whereIn('item_id', array_values($itemIds))
            ->pluck('auto_renew', 'item_id');
        $states = PlayerExplorationSupportItemState::query()
            ->where('character_id', $character->id)
            ->whereIn('item_id', array_values($itemIds))
            ->get()
            ->keyBy('item_id');
        $active = $this->payload($character);

        return collect(self::ITEMS)
            ->map(function (array $definition, string $key) use ($itemIds, $ownedCounts, $prefs, $states, $active, $areaId, $speciesLuresEligible): array {
                $itemId = $itemIds[$key] ?? 0;
                $owned = (int) ($ownedCounts[$itemId] ?? 0);
                $state = $states->get($itemId);
                $remaining = (int) ($state?->battles_remaining ?? 0);
                $isActive = $active !== null && $active['item_key'] === $key;
                $isLure = $this->isSpeciesLure($key);
                $isEffectiveHere = !$isLure
                    || ($speciesLuresEligible
                        && ($areaId === null
                            || $this->areaContainsSpecies($areaId, (string) $definition['species_key'])));

                return [
                    'item_key' => $key,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'owned' => $owned,
                    'remaining' => $remaining,
                    'max_battles' => $this->battlesPerItem(),
                    'is_open' => $state !== null,
                    'is_active' => $isActive,
                    'auto_renew' => $isActive ? (bool) $active['auto_renew'] : (bool) ($prefs[$itemId] ?? false),
                    'is_lure' => $isLure,
                    'is_effective_here' => $isEffectiveHere,
                    'effectiveness_note' => $isLure
                        ? (!$speciesLuresEligible
                            ? '対象外：誘魔香は通常探索でのみ効果があります。'
                            : ($areaId === null
                            ? '通常探索中に対象種族を判定します。'
                            : ($isEffectiveHere
                                ? 'この探索地で効果があります。'
                                : "対象外：この探索地に{$definition['species_label']}系の敵はいません。")))
                        : null,
                    'can_activate' => (!$isActive || $remaining <= 0)
                        && $isEffectiveHere
                        && ($remaining > 0 || $owned > 0),
                    'activate_label' => $remaining > 0 ? '再装備' : ($isActive ? '補充する' : '使用する'),
                ];
            })
            ->filter(fn (array $row): bool => $row['owned'] > 0 || $row['remaining'] > 0 || $row['is_active'])
            ->values()
            ->all();
    }

    public function battlesPerItem(): int
    {
        return max(1, (int) config('exploration_support.battles_per_item', self::BATTLES_PER_ITEM));
    }

    public function lureWeightMultiplier(): int
    {
        return max(1, (int) config('exploration_support.species_lure_weight_multiplier', 3));
    }

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive(self::CONTENT_KEY);
    }

    private function ensureEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('探索補助品は現在公開していません。');
        }
    }

    private function ensureLureCanBeEquippedHere(Character $character, string $itemKey): void
    {
        if (!$this->isSpeciesLure($itemKey)) {
            return;
        }

        $areaId = (int) (app(ExplorationStateService::class)->currentFor($character)?->area_id ?? 0);
        $definition = self::ITEMS[$itemKey];
        if ($areaId > 0 && !$this->areaContainsSpecies($areaId, (string) $definition['species_key'])) {
            throw new RuntimeException("この探索地には{$definition['species_label']}系の敵がいないため装備できません。");
        }
    }

    private function areaContainsSpecies(int $areaId, string $speciesKey): bool
    {
        return Enemy::query()
            ->where('area_id', $areaId)
            ->where('is_boss', false)
            ->where(function ($query) use ($speciesKey): void {
                $query->where('species_key', $speciesKey)
                    ->orWhere(function ($fallback) use ($speciesKey): void {
                        $fallback->whereNull('species_key')->where('family_key', $speciesKey);
                    });
            })
            ->exists();
    }

    private function isSpeciesLure(string $itemKey): bool
    {
        return isset(self::ITEMS[$itemKey]['species_key']);
    }

    private function enemySpeciesKey(Enemy $enemy): string
    {
        return (string) ($enemy->species_key ?: $enemy->family_key ?: '');
    }

    private function itemIdsByKey(): array
    {
        $names = array_column(self::ITEMS, 'name');
        $items = Item::query()
            ->where('type', 'consumable')
            ->whereIn('name', $names)
            ->pluck('id', 'name');
        $result = [];
        foreach (self::ITEMS as $key => $definition) {
            $result[$key] = (int) ($items[$definition['name']] ?? 0);
        }

        return array_filter($result);
    }

    private function stateForEffect(PlayerExplorationSupportEffect $effect, bool $lock): PlayerExplorationSupportItemState
    {
        $query = PlayerExplorationSupportItemState::query()
            ->where('character_id', $effect->character_id)
            ->where('item_id', $effect->item_id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $state = $query->first();
        if ($state) {
            return $state;
        }

        return PlayerExplorationSupportItemState::query()->create([
            'character_id' => $effect->character_id,
            'item_id' => $effect->item_id,
            'battles_remaining' => (int) $effect->battles_remaining,
            'battles_elapsed_in_period' => (int) $effect->battles_elapsed_in_period,
            'proc_count' => (int) $effect->proc_count,
            'last_battle_log_id' => $effect->last_battle_log_id,
            'lock_version' => (int) $effect->lock_version,
        ]);
    }

    private function resetState(PlayerExplorationSupportItemState $state): void
    {
        $state->forceFill([
            'battles_remaining' => $this->battlesPerItem(),
            'battles_elapsed_in_period' => 0,
            'proc_count' => 0,
            'last_battle_log_id' => null,
            'lock_version' => (int) $state->lock_version + 1,
        ])->save();
    }

    private function syncEffectFromState(PlayerExplorationSupportEffect $effect, PlayerExplorationSupportItemState $state): void
    {
        $effect->forceFill([
            'battles_remaining' => (int) $state->battles_remaining,
            'battles_elapsed_in_period' => (int) $state->battles_elapsed_in_period,
            'proc_count' => (int) $state->proc_count,
            'last_battle_log_id' => $state->last_battle_log_id,
            'lock_version' => (int) $effect->lock_version + 1,
        ])->save();
    }

    private function consumeOwnedItem(Character $character, int $itemId): bool
    {
        $owned = CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->where('is_equipped', false)
            ->lockForUpdate()
            ->first();
        if (!$owned) {
            return false;
        }
        $owned->delete();

        return true;
    }
}
