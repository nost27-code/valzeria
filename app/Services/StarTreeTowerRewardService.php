<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\TowerRewardClaim;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class StarTreeTowerRewardService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const TYPE_WEAPON = 'weapon_chest';
    public const TYPE_CARD_BACKGROUND = 'card_background';
    public const TYPE_CARD_FRAME = 'card_frame';

    public function createPendingRewardsForClearedFloor(Character $character, int $clearedFloor, ?string $towerKey = null): array
    {
        if (! Schema::hasTable('tower_reward_claims')) {
            return [];
        }

        $towerKey ??= (string) config('star_tree_tower_rewards.tower_key', 'star_tree_tower');
        $created = [];

        foreach (array_keys((array) config('star_tree_tower_rewards.weapon_rewards', [])) as $floor) {
            $floor = (int) $floor;
            if ($clearedFloor < $floor) {
                continue;
            }

            $claim = $this->firstOrCreatePending($character, $towerKey, $floor, self::TYPE_WEAPON, [
                'display_rank' => (string) config("star_tree_tower_rewards.weapon_rewards.{$floor}.display_rank", ''),
            ]);
            if ($claim->wasRecentlyCreated) {
                $created[] = $this->summaryForClaim($claim);
            }
        }

        $backgroundFloor = (int) config('star_tree_tower_rewards.card_background.floor', 50);
        if ($clearedFloor >= $backgroundFloor) {
            $claim = $this->firstOrCreatePending($character, $towerKey, $backgroundFloor, self::TYPE_CARD_BACKGROUND, [
                'asset_name' => (string) config('star_tree_tower_rewards.card_background.name', 'エルフィア'),
                'asset_type' => (string) config('star_tree_tower_rewards.card_background.asset_type', 'background'),
                'asset_path' => (string) config('star_tree_tower_rewards.card_background.asset_path', 'images/profile/adventurer_card_bg03.webp'),
            ]);
            if ((string) $claim->status === self::STATUS_PENDING) {
                $this->claimProfileAsset($claim, $character, 'card_background');
                $created[] = $this->summaryForClaim($claim->refresh());
            }
        }

        $cardFrameFloor = (int) config('star_tree_tower_rewards.card_frame.floor', 100);
        if ($clearedFloor >= $cardFrameFloor) {
            $claim = $this->firstOrCreatePending($character, $towerKey, $cardFrameFloor, self::TYPE_CARD_FRAME, [
                'asset_name' => (string) config('star_tree_tower_rewards.card_frame.name', 'エルフィア'),
                'asset_type' => (string) config('star_tree_tower_rewards.card_frame.asset_type', 'card_frame'),
                'asset_path' => (string) config('star_tree_tower_rewards.card_frame.asset_path', 'images/profile/adventurer_card_frame03.webp'),
                'assets' => $this->profileAssets('card_frame', 'card_frame', 'images/profile/adventurer_card_frame03.webp'),
            ]);
            if ($claim->wasRecentlyCreated) {
                $created[] = $this->summaryForClaim($claim);
            }
        }

        return $created;
    }

    public function createPendingRewardsFromBestRecord(Character $character, int $bestClearedFloor, ?string $towerKey = null): void
    {
        $this->createPendingRewardsForClearedFloor($character, $bestClearedFloor, $towerKey);
    }

    public function pendingRewardsFor(Character $character, ?string $towerKey = null): Collection
    {
        if (! Schema::hasTable('tower_reward_claims')) {
            return collect();
        }

        $towerKey ??= (string) config('star_tree_tower_rewards.tower_key', 'star_tree_tower');
        $this->claimPendingBackgroundRewards($character, $towerKey);

        return TowerRewardClaim::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $towerKey)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('floor')
            ->get()
            ->map(fn (TowerRewardClaim $claim): array => $this->viewPayload($claim))
            ->values();
    }

    public function claim(TowerRewardClaim $claim, Character $character, ?string $weaponCategory = null): array
    {
        if ((int) $claim->character_id !== (int) $character->id) {
            throw new InvalidArgumentException('この宝箱は受け取れません。');
        }

        return match ((string) $claim->reward_type) {
            self::TYPE_WEAPON => $this->claimWeapon($claim, $character, (string) $weaponCategory),
            self::TYPE_CARD_BACKGROUND => $this->claimProfileAsset($claim, $character, 'card_background'),
            self::TYPE_CARD_FRAME => $this->claimProfileAsset($claim, $character, 'card_frame'),
            default => throw new InvalidArgumentException('未知の報酬です。'),
        };
    }

    private function claimWeapon(TowerRewardClaim $claim, Character $character, string $weaponCategory): array
    {
        $weaponCategory = trim($weaponCategory);
        if ($weaponCategory === '') {
            throw new InvalidArgumentException('受け取る武器種を選んでください。');
        }

        return DB::transaction(function () use ($claim, $character, $weaponCategory): array {
            $locked = TowerRewardClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $item = $this->rewardItem((int) $locked->floor, $weaponCategory);
            if (!$item) {
                throw new InvalidArgumentException('選択した武器が見つかりません。');
            }

            $rate = (float) config("star_tree_tower_rewards.weapon_rewards.{$locked->floor}.killer_damage_rate", 0);
            $characterItem = CharacterItem::query()->create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'affix_quality' => null,
                'killer_species_key' => 'plant',
                'killer_damage_rate' => $rate,
                'is_equipped' => false,
                'is_stored' => false,
                'is_locked' => true,
                'enhance_level' => 0,
                'acquired_from' => 'star_tree_tower_reward',
            ]);

            $locked->forceFill([
                'status' => self::STATUS_CLAIMED,
                'selected_item_id' => $item->id,
                'character_item_id' => $characterItem->id,
                'claimed_at' => now(),
                'metadata' => array_merge((array) $locked->metadata, [
                    'weapon_category' => $weaponCategory,
                    'item_name' => $item->name,
                ]),
            ])->save();

            return [
                'message' => "{$locked->floor}階初回到達報酬として「{$item->name}」を受け取りました。",
                'claim' => $locked->refresh(),
                'item' => $item,
                'character_item' => $characterItem,
            ];
        });
    }

    private function claimProfileAsset(TowerRewardClaim $claim, Character $character, string $configKey): array
    {
        return DB::transaction(function () use ($claim, $character, $configKey): array {
            $locked = TowerRewardClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $defaultType = $configKey === 'card_background' ? 'background' : 'card_frame';
            $defaultPath = $configKey === 'card_background'
                ? 'images/profile/adventurer_card_bg03.webp'
                : 'images/profile/adventurer_card_frame03.webp';
            $assetType = (string) config("star_tree_tower_rewards.{$configKey}.asset_type", $defaultType);
            $assetPath = (string) config("star_tree_tower_rewards.{$configKey}.asset_path", $defaultPath);
            $assetName = (string) config("star_tree_tower_rewards.{$configKey}.name", 'エルフィア');
            $assets = $this->profileAssets($configKey, $assetType, $assetPath);

            if (Schema::hasTable('character_adventurer_card_assets')) {
                foreach ($assets as $asset) {
                    DB::table('character_adventurer_card_assets')->updateOrInsert(
                        [
                            'character_id' => $character->id,
                            'asset_type' => (string) $asset['asset_type'],
                            'asset_path' => (string) $asset['asset_path'],
                        ],
                        [
                            'source' => (string) config("star_tree_tower_rewards.{$configKey}.source", 'star_tree_tower_reward'),
                            'obtained_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }

            $locked->forceFill([
                'status' => self::STATUS_CLAIMED,
                'asset_type' => $assetType,
                'asset_path' => $assetPath,
                'claimed_at' => now(),
                'metadata' => array_merge((array) $locked->metadata, [
                    'asset_name' => $assetName,
                    'assets' => $assets,
                ]),
            ])->save();

            $assetLabel = $configKey === 'card_background' ? '冒険者カード背景' : '冒険者カード装飾';

            if ($configKey !== 'card_background') {
                $logLabel = (string) config('star_tree_tower.star_tree.display.public_log_label', '星樹の塔');

                app(PublicLogService::class)->addLog(
                    'tower',
                    "【{$logLabel}】{$character->name}さんが{$assetLabel}「{$assetName}」を獲得しました！",
                    $character,
                    3
                );
            }

            return [
                'message' => "{$locked->floor}階初回到達報酬として{$assetLabel}「{$assetName}」を受け取りました。",
                'claim' => $locked->refresh(),
            ];
        });
    }

    private function firstOrCreatePending(Character $character, string $towerKey, int $floor, string $rewardType, array $metadata): TowerRewardClaim
    {
        return TowerRewardClaim::query()->firstOrCreate(
            [
                'character_id' => $character->id,
                'tower_key' => $towerKey,
                'floor' => $floor,
                'reward_type' => $rewardType,
            ],
            [
                'status' => self::STATUS_PENDING,
                'metadata' => $metadata,
            ]
        );
    }

    private function assertPending(TowerRewardClaim $claim): void
    {
        if ((string) $claim->status !== self::STATUS_PENDING) {
            throw new RuntimeException('この宝箱はすでに受け取り済みです。');
        }
    }

    private function claimPendingBackgroundRewards(Character $character, string $towerKey): void
    {
        TowerRewardClaim::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $towerKey)
            ->where('reward_type', self::TYPE_CARD_BACKGROUND)
            ->where('status', self::STATUS_PENDING)
            ->get()
            ->each(fn (TowerRewardClaim $claim) => $this->claimProfileAsset($claim, $character, 'card_background'));
    }

    private function viewPayload(TowerRewardClaim $claim): array
    {
        $payload = $this->summaryForClaim($claim);
        if ((string) $claim->reward_type === self::TYPE_WEAPON) {
            $payload['weapon_categories'] = (array) config('star_tree_tower_rewards.weapon_categories', []);
            $payload['options'] = $this->weaponOptions((int) $claim->floor);
        }

        return $payload;
    }

    private function summaryForClaim(TowerRewardClaim $claim): array
    {
        $name = match ((string) $claim->reward_type) {
            self::TYPE_WEAPON => ((string) ($claim->metadata['display_rank'] ?? '')).'武器宝箱',
            self::TYPE_CARD_BACKGROUND => '冒険者カード背景「'.((string) ($claim->metadata['asset_name'] ?? 'エルフィア')).'」',
            self::TYPE_CARD_FRAME => '冒険者カード装飾枠「'.((string) ($claim->metadata['asset_name'] ?? 'エルフィア')).'」',
            default => '星樹の塔報酬',
        };

        return [
            'id' => (int) $claim->id,
            'floor' => (int) $claim->floor,
            'reward_type' => (string) $claim->reward_type,
            'name' => $name,
            'metadata' => (array) $claim->metadata,
        ];
    }

    private function rewardItem(int $floor, string $weaponCategory): ?Item
    {
        return Item::query()
            ->where('external_item_id', 'STAR_TREE_TOWER_'.$floor.'_'.strtoupper($weaponCategory))
            ->where('type', 'weapon')
            ->where('is_active', true)
            ->first();
    }

    private function profileAssets(string $configKey, string $defaultType, string $defaultPath): array
    {
        $assets = config("star_tree_tower_rewards.{$configKey}.assets");
        if (!is_array($assets) || $assets === []) {
            return [[
                'asset_type' => $defaultType,
                'asset_path' => $defaultPath,
            ]];
        }

        return collect($assets)
            ->filter(fn ($asset): bool => is_array($asset) && !empty($asset['asset_type']) && !empty($asset['asset_path']))
            ->map(fn (array $asset): array => [
                'asset_type' => (string) $asset['asset_type'],
                'asset_path' => (string) $asset['asset_path'],
            ])
            ->values()
            ->all();
    }

    private function weaponOptions(int $floor): array
    {
        $options = [];
        foreach ((array) config("star_tree_tower_rewards.weapon_rewards.{$floor}.weapons", []) as $category => $weapon) {
            $item = $this->rewardItem($floor, (string) $category);
            $options[] = [
                'category' => (string) $category,
                'category_label' => (string) config("star_tree_tower_rewards.weapon_categories.{$category}", $category),
                'category_description' => $this->weaponCategoryDescription((string) $category),
                'name' => (string) ($weapon['name'] ?? ($item?->name ?? '報酬武器')),
                'item_id' => $item?->id,
                'stats' => [
                    'HP' => (int) ($weapon['hp'] ?? 0),
                    'SP' => (int) ($weapon['sp'] ?? 0),
                    'ATK' => (int) ($weapon['atk'] ?? 0),
                    'DEF' => (int) ($weapon['def'] ?? 0),
                    'MAG' => (int) ($weapon['mag'] ?? 0),
                    'SPR' => (int) ($weapon['spr'] ?? 0),
                    'SPD' => (int) ($weapon['spd'] ?? 0),
                    'LUK' => (int) ($weapon['luk'] ?? 0),
                ],
                'killer_label' => '植物+'.(int) round(((float) config("star_tree_tower_rewards.weapon_rewards.{$floor}.killer_damage_rate", 0)) * 100).'%',
            ];
        }

        return $options;
    }

    private function weaponCategoryDescription(string $category): string
    {
        return [
            'sword' => '剣士・騎士系が得意とする武器です。',
            'axe' => '戦士・狂戦士系が得意とする武器です。',
            'dagger' => '盗賊・忍者系が得意とする武器です。',
            'bow' => '弓使い・狙撃手系が得意とする武器です。',
            'staff' => '魔法使い・僧侶系が得意とする武器です。',
            'magic_device' => '魔法職・機工系が扱う武器です。',
            'gun' => '狙撃手・機工系が扱う武器です。',
            'spear' => '騎士・竜騎士系が得意とする武器です。',
            'fist' => '格闘家・武神系が得意とする武器です。',
            'katana' => '侍・剣聖系が得意とする武器です。',
        ][$category] ?? '対応する職業系が装備できる武器です。';
    }
}
