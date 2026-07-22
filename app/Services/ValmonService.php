<?php

namespace App\Services;

use App\Models\Area;
use App\Models\CharacterTitle;
use App\Models\Character;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\PlayerValmonEgg;
use App\Models\Title;
use App\Models\ValmonFeedLog;
use App\Models\ValmonMaterialFindLog;
use App\Models\ValmonMaster;
use App\Models\ValmonSpawnRegion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ValmonService
{
    public const MAX_LEVEL = 100;
    private const BASE_EGG_RATE = 0.02;
    private const ROLE_ATTACK = 'attack';
    private const ROLE_STANDARD = 'standard';
    private const ROLE_GATHER = 'gather';
    private const ROLE_SCOUT = 'scout';
    private const ROLE_HEAL = 'heal';
    private const BLOCKED_FIND_MATERIAL_CODES = [
        'WEV0002',
        'WEV0008',
        'WEV0011',
        'WEV0014',
        'WEV0017',
        'WEV0020',
        'WEV0030',
        'ACC0002',
        'ACC0010',
        'ACC0013',
        'ACC0016',
        'ACC0019',
        'ACC0022',
        'ACC0025',
        'ACC0028',
        'ACC0031',
        'ACC0034',
        'ACC0037',
        'MAT_WEAPON_EVOLUTION_STONE',
        'MAT_ARMOR_EVOLUTION_STONE',
        'MAT_ACCESSORY_EVOLUTION_STONE',
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_BREW_BEAST_FANG',
        'MAT_BREW_TOXIN',
        'MAT_BREW_HERB',
        'MAT_BREW_MAGIC_POWDER',
        'MAT_BREW_LOW_MONSTER',
    ];

    public function starters()
    {
        return ValmonMaster::where('is_starter', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function needsStarter(Character $character): bool
    {
        return !PlayerValmon::where('character_id', $character->id)->exists();
    }

    public function chooseStarter(Character $character, int $valmonMasterId): array
    {
        return DB::transaction(function () use ($character, $valmonMasterId) {
            if (!$this->needsStarter($character)) {
                return ['success' => false, 'message' => 'すでに最初のヴァルモンを選択済みです。'];
            }

            $master = ValmonMaster::whereKey($valmonMasterId)
                ->where('is_starter', true)
                ->where('is_active', true)
                ->first();

            if (!$master) {
                return ['success' => false, 'message' => '選択できないヴァルモンです。'];
            }

            PlayerValmon::create([
                'character_id' => $character->id,
                'valmon_master_id' => $master->id,
                'level' => 1,
                'exp' => 0,
                'affection' => 0,
                'evolution_stage' => 'child',
                'is_partner' => true,
                'obtained_source' => 'starter',
                'obtained_at' => now(),
            ]);

            return ['success' => true, 'message' => "{$master->name}が最初の相棒になりました。"];
        });
    }

    public function setPartner(Character $character, PlayerValmon $valmon): array
    {
        if ((int) $valmon->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => 'このヴァルモンは所持していません。'];
        }

        DB::transaction(function () use ($character, $valmon) {
            PlayerValmon::where('character_id', $character->id)->update(['is_partner' => false]);
            $valmon->forceFill(['is_partner' => true])->save();
        });

        return ['success' => true, 'message' => "{$valmon->displayName()}を相棒にしました。"];
    }

    public function tryFindEgg(Character $character, Area $area, ?CharacterExplorationState $state): ?array
    {
        if ($this->foundEggToday($character)) {
            return null;
        }

        $rate = $this->eggRate((int) ($state->exploration_point ?? 0));
        if (!$this->rollPercent($rate)) {
            return null;
        }

        $spawn = $this->weightedSpawnForArea($area, $character);
        if (!$spawn?->valmonMaster) {
            return null;
        }

        $egg = PlayerValmonEgg::create([
            'character_id' => $character->id,
            'valmon_master_id' => $spawn->valmonMaster->id,
            'found_city_id' => $area->city_id,
            'found_area_id' => $area->id,
            // 探索の地図は通常探索の連鎖・探索度を進めないため、地図内で見つけた卵には
            // 通常探索状態をひも付けない。抽選条件そのものは通常探索と共通にする。
            'found_exploration_state_id' => $state?->id,
            'found_at' => now(),
        ]);

        app(PublicLogService::class)->addLog(
            'valmon',
            "【ヴァルモンの卵】{$character->name}さんが{$area->name}でふしぎな卵を見つけました！",
            $character,
            2
        );

        return [
            'egg_id' => $egg->id,
            'name' => $spawn->valmonMaster->name,
            'rarity' => $spawn->valmonMaster->rarity,
            'message' => "草むらの奥で、{$spawn->valmonMaster->name}の卵を見つけた！ ヴァルモンの卵を手に入れた！",
        ];
    }

    public function tryPartnerFindMaterial(Character $character, Area $area, ?CharacterExplorationState $state, DropService $dropService, ?\App\Models\Enemy $enemy = null): ?array
    {
        if (!$state || (bool) ($state->valmon_material_found ?? false)) {
            return null;
        }

        $partner = PlayerValmon::with('master')
            ->where('character_id', $character->id)
            ->where('is_partner', true)
            ->first();

        if (!$partner || !$this->rollPercent($this->materialFindRate($partner))) {
            return null;
        }

        $material = $this->selectFindMaterial($partner, $area);
        if (!$material) {
            return null;
        }

        $drop = $dropService->grantMaterialReward($character, $material, 'valmon_find', $enemy);
        $state->forceFill(['valmon_material_found' => true])->save();

        ValmonMaterialFindLog::create([
            'character_id' => $character->id,
            'player_valmon_id' => $partner->id,
            'character_exploration_state_id' => $state->id,
            'material_id' => $material->id,
            'quantity' => 1,
        ]);

        return [
            'valmon_id' => $partner->id,
            'valmon_name' => $partner->displayName(),
            'valmon_image_path' => $partner->master?->imageUrl(),
            'valmon_rarity' => $partner->master?->rarity ?? 'normal',
            'material_name' => $drop['name'] ?? $material->displayName(),
            'material_code' => $drop['material_code'] ?? $material->material_code,
            'material_icon_image' => $drop['icon_image'] ?? $material->iconImagePath(),
            'quantity' => 1,
        ];
    }

    public function tryPartnerRecovery(Character $character, ?CharacterExplorationState $state): ?array
    {
        if (!$state || (bool) ($state->valmon_heal_used ?? false)) {
            return null;
        }

        $partner = $this->partnerFor($character);
        $spec = $partner ? $this->recoverySpec($partner) : null;
        if (!$partner || !$spec) {
            return null;
        }

        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base ?? 1));
        $currentHp = max(0, (int) ($character->current_hp ?? 0));
        $hpRate = $currentHp / $maxHp;
        if ($hpRate > (float) $spec['threshold']) {
            return null;
        }

        if (!$this->rollPercent((float) $spec['rate'])) {
            return null;
        }

        $healAmount = max(1, (int) floor($maxHp * (float) $spec['heal_rate']));
        $afterHp = min($maxHp, $currentHp + $healAmount);
        $actualHeal = max(0, $afterHp - $currentHp);
        if ($actualHeal <= 0) {
            return null;
        }

        $character->current_hp = $afterHp;
        $character->save();
        $state->forceFill(['valmon_heal_used' => true])->save();

        return [
            'valmon_id' => $partner->id,
            'valmon_name' => $partner->displayName(),
            'valmon_image_path' => $partner->master?->imageUrl(),
            'heal_amount' => $actualHeal,
        ];
    }

    public function tryDiscoveryHint(Character $character, Area $area): ?array
    {
        $partner = $this->partnerFor($character);
        if (!$partner || (int) $partner->level < 50 || !$this->rollPercent(15.0)) {
            return null;
        }

        $hint = app(DiscoveryService::class)->valmonHintForArea($character, $area);
        if (!$hint) {
            return null;
        }

        return [
            'valmon_id' => $partner->id,
            'valmon_name' => $partner->displayName(),
            'valmon_image_path' => $partner->master?->imageUrl(),
            'hint' => $hint['text'] ?? 'このあたりに、まだ見つけていない気配があるようです。',
        ];
    }

    public function hatchActiveEggs(Character $character): array
    {
        $resolved = [];

        DB::transaction(function () use ($character, &$resolved) {
            $eggs = PlayerValmonEgg::with('master')
                ->where('character_id', $character->id)
                ->where('is_hatched', false)
                ->where('is_lost', false)
                ->whereNull('stored_at')
                ->lockForUpdate()
                ->get();

            foreach ($eggs as $egg) {
                $alreadyHad = PlayerValmon::where('character_id', $character->id)
                    ->where('valmon_master_id', $egg->valmon_master_id)
                    ->exists();

                if ($alreadyHad) {
                    $egg->forceFill(['stored_at' => now()])->save();
                    $resolved[] = [
                        'name' => $egg->master?->name ?? 'ヴァルモン',
                        'rarity' => $egg->master?->rarity ?? 'normal',
                        'stored' => true,
                    ];
                    continue;
                }

                $valmon = PlayerValmon::create([
                    'character_id' => $character->id,
                    'valmon_master_id' => $egg->valmon_master_id,
                    'level' => 1,
                    'exp' => 0,
                    'affection' => 0,
                    'evolution_stage' => 'child',
                    'is_partner' => false,
                    'obtained_source' => 'egg',
                    'obtained_at' => now(),
                ]);

                if (!PlayerValmon::where('character_id', $character->id)->where('is_partner', true)->where('id', '!=', $valmon->id)->exists()) {
                    $valmon->forceFill(['is_partner' => true])->save();
                }
                $egg->forceFill(['is_hatched' => true, 'hatched_at' => now()])->save();

                $resolved[] = [
                    'name' => $egg->master?->name ?? 'ヴァルモン',
                    'rarity' => $egg->master?->rarity ?? 'normal',
                    'stored' => false,
                ];
            }
        });

        return $resolved;
    }

    public function loseActiveEggs(Character $character): array
    {
        $lost = [];

        PlayerValmonEgg::with('master')
            ->where('character_id', $character->id)
            ->where('is_hatched', false)
            ->where('is_lost', false)
            ->whereNull('stored_at')
            ->get()
            ->each(function (PlayerValmonEgg $egg) use (&$lost) {
                $lost[] = ['name' => $egg->master?->name ?? 'ヴァルモンの卵'];
                $egg->forceFill(['is_lost' => true, 'lost_at' => now()])->save();
            });

        return $lost;
    }

    public function feedMaterial(Character $character, PlayerValmon $valmon, CharacterMaterial $characterMaterial, int $quantity): array
    {
        if ((int) $valmon->character_id !== (int) $character->id || (int) $characterMaterial->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $quantity = max(1, min($quantity, (int) $characterMaterial->quantity));
        $expPer = $this->materialFeedExp($characterMaterial->material);
        if ($expPer <= 0) {
            return ['success' => false, 'message' => 'この素材はヴァルモンの餌にできません。'];
        }

        return DB::transaction(function () use ($valmon, $characterMaterial, $quantity, $expPer) {
            $characterMaterial->decrement('quantity', $quantity);
            if ((int) $characterMaterial->fresh()?->quantity <= 0) {
                $characterMaterial->delete();
            }

            return $this->grantFeedExp($valmon, 'material', (int) $characterMaterial->material_id, $quantity, $expPer * $quantity);
        });
    }

    public function feedEquipment(Character $character, PlayerValmon $valmon, CharacterItem $characterItem): array
    {
        if ((int) $valmon->character_id !== (int) $character->id || (int) $characterItem->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $exp = $this->equipmentFeedExp($characterItem);
        if ($exp <= 0) {
            return ['success' => false, 'message' => 'この装備はヴァルモンの餌にできません。'];
        }

        return DB::transaction(function () use ($valmon, $characterItem, $exp) {
            $feedId = (int) $characterItem->id;
            $characterItem->delete();

            return $this->grantFeedExp($valmon, 'equipment', $feedId, 1, $exp);
        });
    }

    public function feedEquipmentBulk(Character $character, PlayerValmon $valmon, array $characterItemIds): array
    {
        if ((int) $valmon->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $ids = collect($characterItemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return ['success' => false, 'message' => '餌にする装備を選んでください。'];
        }

        $items = CharacterItem::with('item')
            ->where('character_id', $character->id)
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        if ($items->count() !== $ids->count()) {
            return ['success' => false, 'message' => '対象外の装備が含まれています。'];
        }

        $totalExp = 0;
        foreach ($ids as $id) {
            $exp = $this->equipmentFeedExp($items[$id]);
            if ($exp <= 0) {
                return ['success' => false, 'message' => '餌にできない装備が含まれています。'];
            }
            $totalExp += $exp;
        }

        return DB::transaction(function () use ($valmon, $items, $ids, $totalExp) {
            CharacterItem::where('character_id', $valmon->character_id)
                ->whereIn('id', $ids->all())
                ->delete();

            return $this->grantFeedExp($valmon, 'equipment_bulk', (int) $ids->first(), $items->count(), $totalExp);
        });
    }

    public function materialFeedExp(?Material $material): int
    {
        if (!$material || $this->isBlockedMaterial($material)) {
            return 0;
        }

        return match ((string) $material->name) {
            '装備の欠片' => 2,
            '上質な装備の欠片' => 8,
            '強装備の欠片' => 20,
            default => match (strtoupper((string) $material->rarity)) {
                'N', 'NORMAL' => 1,
                'N+' => 3,
                'R' => 10,
                default => 0,
            },
        };
    }

    public function equipmentFeedExp(CharacterItem $characterItem): int
    {
        $item = $characterItem->item;
        if (!$item || $characterItem->is_equipped || $characterItem->is_locked) {
            return 0;
        }

        $rank = strtoupper((string) ($item->weapon_rank ?? $item->armor_rank ?? $item->accessory_rank ?? $item->rarity ?? ''));

        return match ($rank) {
            'G', 'F' => 2,
            'E', 'D' => 5,
            'C', 'B' => 15,
            'A' => 40,
            'S' => 70,
            'SS' => 120,
            'SSS' => 200,
            'EPIC' => 320,
            default => 0,
        };
    }

    public function nextLevelExp(int $level): int
    {
        $level = max(1, min(self::MAX_LEVEL, $level));

        return (int) round(20 + (5 * $level) + (0.68 * $level * $level));
    }

    public function nextLevelRemaining(PlayerValmon $valmon): ?int
    {
        if ((int) $valmon->level >= self::MAX_LEVEL) {
            return null;
        }

        return max(0, $this->nextLevelExp((int) $valmon->level) - (int) $valmon->exp);
    }

    public function partnerFor(Character $character): ?PlayerValmon
    {
        return PlayerValmon::with('master')
            ->where('character_id', $character->id)
            ->where('is_partner', true)
            ->first();
    }

    public function materialFindRate(PlayerValmon $valmon): float
    {
        $level = (int) $valmon->level;

        return match (true) {
            $level >= 20 => 3.0,
            $level >= 10 => 2.5,
            default => 2.0,
        };
    }

    public function role(PlayerValmon $valmon): string
    {
        $key = (string) ($valmon->master?->valmon_key ?? '');
        $category = (string) ($valmon->master?->base_find_material_category ?? '');

        if (in_array($key, ['abysslim', 'shellx', 'bolt_nya', 'dracol'], true)) {
            return self::ROLE_ATTACK;
        }

        if (in_array($key, ['miramy'], true) || str_contains($category, '回復')) {
            return self::ROLE_HEAL;
        }

        if (in_array($key, ['leafy', 'aquaron', 'tsubasaur', 'lumi_cube'], true)
            || str_contains($category, '卵')
            || str_contains($category, '宝箱')
            || str_contains($category, '危険')
            || str_contains($category, 'レア')) {
            return self::ROLE_SCOUT;
        }

        if (in_array($key, ['rapil', 'piyoram'], true)) {
            return self::ROLE_STANDARD;
        }

        return self::ROLE_GATHER;
    }

    public function roleLabel(PlayerValmon $valmon): string
    {
        return match ($this->role($valmon)) {
            self::ROLE_ATTACK => '攻撃型',
            self::ROLE_STANDARD => '標準型',
            self::ROLE_SCOUT => '探知型',
            self::ROLE_HEAL => '回復型',
            default => '採集型',
        };
    }

    public function assistAttackSpec(PlayerValmon $valmon): ?array
    {
        if ((int) $valmon->level < 40) {
            return null;
        }

        return match ($this->role($valmon)) {
            self::ROLE_ATTACK => ['rate' => 5.0, 'power_rate' => 0.20],
            self::ROLE_STANDARD => ['rate' => 4.0, 'power_rate' => 0.15],
            default => ['rate' => 3.0, 'power_rate' => 0.10],
        };
    }

    public function recoverySpec(PlayerValmon $valmon): ?array
    {
        if ((int) $valmon->level < 75) {
            return null;
        }

        return match ($this->role($valmon)) {
            self::ROLE_HEAL => ['threshold' => 0.60, 'heal_rate' => 0.25, 'rate' => 25.0],
            self::ROLE_STANDARD => ['threshold' => 0.50, 'heal_rate' => 0.22, 'rate' => 25.0],
            default => ['threshold' => 0.40, 'heal_rate' => 0.20, 'rate' => 25.0],
        };
    }

    public function effectSummary(PlayerValmon $valmon): array
    {
        $level = (int) $valmon->level;
        $effects = [
            '素材発見 ' . rtrim(rtrim(number_format($this->materialFindRate($valmon), 1), '0'), '.') . '%',
        ];

        if ($level >= 30) {
            $effects[] = '得意素材補正';
        }
        if ($level >= 40) {
            $spec = $this->assistAttackSpec($valmon);
            $effects[] = '追撃 ' . rtrim(rtrim(number_format((float) ($spec['rate'] ?? 0), 1), '0'), '.') . '%';
        }
        if ($level >= 50) {
            $effects[] = '未発見ヒント';
        }
        if ($level >= 75) {
            $effects[] = '応急回復';
        }
        if ($level >= self::MAX_LEVEL) {
            $effects[] = '名相棒';
        }

        return $effects;
    }

    private function grantFeedExp(PlayerValmon $valmon, string $feedType, int $feedId, int $quantity, int $gainedExp): array
    {
        $levelUps = 0;
        $valmon->refresh();
        $oldLevel = (int) $valmon->level;
        $valmon->exp += $gainedExp;
        $valmon->affection = min(100, (int) $valmon->affection + max(1, $quantity));

        while ((int) $valmon->level < self::MAX_LEVEL && (int) $valmon->exp >= $this->nextLevelExp((int) $valmon->level)) {
            $valmon->exp -= $this->nextLevelExp((int) $valmon->level);
            $valmon->level += 1;
            $levelUps++;
        }

        $valmon->save();
        if ($oldLevel < self::MAX_LEVEL && (int) $valmon->level >= self::MAX_LEVEL) {
            $this->unlockBestPartnerTitle($valmon);
        }

        ValmonFeedLog::create([
            'character_id' => $valmon->character_id,
            'player_valmon_id' => $valmon->id,
            'feed_type' => $feedType,
            'feed_id' => $feedId,
            'quantity' => $quantity,
            'gained_exp' => $gainedExp,
            'gained_affection' => max(1, $quantity),
        ]);

        return [
            'success' => true,
            'message' => $levelUps > 0
                ? "{$valmon->displayName()}に餌を与えました。EXP +{$gainedExp}、Lvが{$valmon->level}になりました！"
                : "{$valmon->displayName()}に餌を与えました。EXP +{$gainedExp}。",
        ];
    }

    private function selectFindMaterial(PlayerValmon $partner, Area $area): ?Material
    {
        $query = $this->findableMaterialsQuery();

        $categoryHint = (string) ($partner->master?->base_find_material_category ?? '');
        if ((int) $partner->level >= 30 && $categoryHint !== '') {
            $query->orderByRaw('CASE WHEN category LIKE ? OR name LIKE ? OR main_use LIKE ? THEN 0 ELSE 1 END', [
                '%' . $categoryHint . '%',
                '%' . $categoryHint . '%',
                '%' . $categoryHint . '%',
            ]);
        }

        return $query
            ->where(function ($query) use ($area) {
                $query->whereNull('city_id')->orWhere('city_id', $area->city_id);
            })
            ->inRandomOrder()
            ->limit(30)
            ->get()
            ->first(fn (Material $material) => $this->hasUsablePurpose($material) && !$this->isBlockedMaterial($material));
    }

    public function findableMaterialCodes(): array
    {
        return $this->findableMaterialsQuery()
            ->get()
            ->filter(fn (Material $material) => $this->hasUsablePurpose($material) && !$this->isBlockedMaterial($material))
            ->pluck('material_code')
            ->map(fn ($code): string => (string) $code)
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function findableMaterialsQuery(): Builder
    {
        return Material::query()
            ->whereIn('rarity', ['N', 'N+', 'R'])
            ->whereNotIn('material_code', self::BLOCKED_FIND_MATERIAL_CODES)
            ->where(function ($query) {
                $query->whereNotNull('usage_tags')
                    ->where('usage_tags', '<>', '[]');
            })
            ->where(function ($query) {
                $query->whereNull('material_type')
                    ->orWhereNotIn('material_type', ['legacy_normal_drop']);
            })
            ->where(function ($query) {
                $query->whereNull('category_id')
                    ->orWhere('category_id', '<>', 'legacy_normal_drop');
            })
            ->where(function ($query) {
                $query->whereNull('category')
                    ->orWhere(function ($query) {
                        $query->where('category', 'not like', '%旧通常素材%')
                            ->where('category', 'not like', '%討伐証%');
                    });
            })
            ->where('name', 'not like', '%の刻印')
            ->where('name', 'not like', '%の王印')
            ->where('name', 'not like', '%の神印')
            ->where(function ($query) {
                $query->whereNull('drop_first_clear_only')->orWhere('drop_first_clear_only', false);
            })
            ->where(function ($query) {
                $query->whereNull('main_use')
                    ->orWhere(function ($query) {
                        $query->where('main_use', 'not like', '%秘境晶%')
                            ->where('main_use', 'not like', '%極印%')
                            ->where('main_use', 'not like', '%EPIC%')
                            ->where('main_use', 'not like', '%統合済%')
                            ->where('main_use', 'not like', '%廃止%')
                            ->where('main_use', 'not like', '%未使用%');
                    });
            })
            ->where(function ($query) {
                $query->whereNull('obtain_method')
                    ->orWhere(function ($query) {
                        $query->where('obtain_method', 'not like', '%統合済%')
                            ->where('obtain_method', 'not like', '%廃止%')
                            ->where('obtain_method', 'not like', '%未使用%');
                    });
            });
    }

    private function unlockBestPartnerTitle(PlayerValmon $valmon): void
    {
        $character = $valmon->character()->first();
        if (!$character) {
            return;
        }

        $title = Title::where('unlock_type', 'valmon_level')
            ->where('target_type', 'valmon_level')
            ->where('target_id', '100')
            ->first();
        if ($title) {
            CharacterTitle::firstOrCreate([
                'character_id' => $character->id,
                'title_id' => $title->id,
            ], [
                'is_equipped' => false,
            ]);
        }

        app(PublicLogService::class)->addLog(
            'valmon',
            "【名相棒】{$character->name}さんの相棒「{$valmon->displayName()}」がLv100に到達しました！",
            $character,
            3
        );
    }

    private function foundEggToday(Character $character): bool
    {
        return PlayerValmonEgg::where('character_id', $character->id)
            ->whereDate('found_at', today('Asia/Tokyo')->toDateString())
            ->exists();
    }

    private function eggRate(int $explorationPoint): float
    {
        $multiplier = max(0, min(5, app(GameSettingService::class)->getFloat('valmon.egg_rate_multiplier', 1.0)));

        return min(100, self::BASE_EGG_RATE * $multiplier);
    }

    private function weightedSpawnForArea(Area $area, ?Character $character = null): ?ValmonSpawnRegion
    {
        $spawns = ValmonSpawnRegion::with('valmonMaster')
            ->where('city_id', $area->city_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($spawn) => $spawn->valmonMaster && $spawn->valmonMaster->is_active);

        if (! config('features.duplicate_valmon_egg_discovery_enabled', false) && $character) {
            $knownMasterIds = PlayerValmon::query()
                ->where('character_id', $character->id)
                ->pluck('valmon_master_id')
                ->all();

            $storedEggMasterIds = PlayerValmonEgg::query()
                ->where('character_id', $character->id)
                ->where('is_hatched', false)
                ->where('is_lost', false)
                ->pluck('valmon_master_id')
                ->all();

            $excludedMasterIds = array_unique(array_merge($knownMasterIds, $storedEggMasterIds));
            $spawns = $spawns->reject(fn ($spawn) => in_array($spawn->valmon_master_id, $excludedMasterIds, true));
        }

        $total = (int) $spawns->sum('spawn_weight');
        if ($total <= 0) {
            return $spawns->first();
        }

        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($spawns as $spawn) {
            $cursor += (int) $spawn->spawn_weight;
            if ($roll <= $cursor) {
                return $spawn;
            }
        }

        return $spawns->first();
    }

    private function isBlockedMaterial(Material $material): bool
    {
        $name = (string) $material->name;
        $mainUse = (string) $material->main_use;
        $category = (string) $material->category;
        $categoryId = (string) $material->category_id;
        $materialType = (string) $material->material_type;
        $obtainMethod = (string) $material->obtain_method;

        return str_contains($name, '秘境晶')
            || str_contains($name, '極印')
            || str_contains($mainUse, '秘境晶')
            || str_contains($mainUse, '極印')
            || str_contains($mainUse, 'EPIC')
            || str_contains($mainUse, '統合済')
            || str_contains($mainUse, '廃止')
            || str_contains($mainUse, '未使用')
            || str_contains($obtainMethod, '統合済')
            || str_contains($obtainMethod, '廃止')
            || str_contains($obtainMethod, '未使用')
            || str_contains($category, '討伐証')
            || str_contains($category, '旧通常素材')
            || $categoryId === 'legacy_normal_drop'
            || $materialType === 'legacy_normal_drop'
            || $materialType === 'accessory_city'
            || $category === 'accessory_city'
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function hasUsablePurpose(Material $material): bool
    {
        $usageTags = $material->usage_tags ?? [];
        if (is_string($usageTags)) {
            $decoded = json_decode($usageTags, true);
            $usageTags = is_array($decoded) ? $decoded : [];
        }

        $usageTags = array_filter((array) $usageTags, fn ($tag) => trim((string) $tag) !== '');
        if ($usageTags !== []) {
            return true;
        }

        $usageText = trim((string) ($material->usage_summary ?? ''));
        if ($usageText !== '') {
            return true;
        }

        $mainUse = trim((string) ($material->main_use ?? ''));
        if ($mainUse === '') {
            return false;
        }

        foreach (['統合済', '廃止', '未使用'] as $blocked) {
            if (str_contains($mainUse, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function rollPercent(float $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }

        return random_int(1, 10000) <= (int) round($percent * 100);
    }
}
