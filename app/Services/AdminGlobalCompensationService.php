<?php

namespace App\Services;

use App\Models\AdminItemGrantLog;
use App\Models\Character;
use App\Models\CharacterConsumableItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminGlobalCompensationService
{
    public const NOTIFICATION_TYPE = 'admin_global_compensation';

    private const SUPPORTED_ITEM_KEYS = [
        'explore_stamina_small_bottle',
        'explore_stamina_potion',
    ];

    /**
     * @return array<string, array{name: string, effect_value: int}>
     */
    public function supportedItems(): array
    {
        return collect(self::SUPPORTED_ITEM_KEYS)
            ->mapWithKeys(function (string $itemKey): array {
                $item = (array) config("adventure_support.items.{$itemKey}", []);

                return [
                    $itemKey => [
                        'name' => (string) ($item['name'] ?? $itemKey),
                        'effect_value' => (int) ($item['effect_value'] ?? 0),
                    ],
                ];
            })
            ->all();
    }

    public function targetCount(): int
    {
        return (int) $this->targetQuery()->count();
    }

    /**
     * @return array{target_count: int, granted_count: int, skipped_count: int, item_name: string}
     */
    public function grant(
        string $requestUuid,
        string $itemKey,
        int $quantity,
        string $notificationTitle,
        string $notificationBody,
        ?int $adminUserId
    ): array {
        if (!Str::isUuid($requestUuid)) {
            throw new \InvalidArgumentException('配布操作IDが不正です。画面を再読み込みしてください。');
        }

        $items = $this->supportedItems();
        $item = $items[$itemKey] ?? null;
        if (!$item) {
            throw new \InvalidArgumentException('配布対象にできないアイテムです。');
        }
        if ($quantity < 1 || $quantity > 9999) {
            throw new \InvalidArgumentException('配布個数は1〜9999個で指定してください。');
        }

        $notificationTitle = trim($notificationTitle);
        $notificationBody = trim($notificationBody);
        if ($notificationTitle === '' || $notificationBody === '') {
            throw new \InvalidArgumentException('通知タイトルと通知メッセージを入力してください。');
        }

        $maxCharacterId = (int) ($this->targetQuery()->max('characters.id') ?? 0);
        $targetQuery = $this->targetQuery()
            ->when($maxCharacterId > 0, fn (Builder $query) => $query->where('characters.id', '<=', $maxCharacterId));
        $targetCount = (int) (clone $targetQuery)->count();
        $grantedCount = 0;
        $skippedCount = 0;

        $targetQuery
            ->select('characters.id')
            ->orderBy('characters.id')
            ->chunkById(100, function ($characters) use (
                $requestUuid,
                $itemKey,
                $item,
                $quantity,
                $notificationTitle,
                $notificationBody,
                $adminUserId,
                &$grantedCount,
                &$skippedCount
            ): void {
                foreach ($characters as $target) {
                    $granted = $this->grantToCharacter(
                        (int) $target->id,
                        $requestUuid,
                        $itemKey,
                        $item['name'],
                        $quantity,
                        $notificationTitle,
                        $notificationBody,
                        $adminUserId
                    );

                    $granted ? $grantedCount++ : $skippedCount++;
                }
            }, 'characters.id', 'id');

        return [
            'target_count' => $targetCount,
            'granted_count' => $grantedCount,
            'skipped_count' => $skippedCount,
            'item_name' => $item['name'],
        ];
    }

    private function targetQuery(): Builder
    {
        return Character::query()
            ->whereHas('user', function (Builder $query): void {
                $query
                    ->where(function (Builder $roleQuery): void {
                        $roleQuery->whereNull('role')->orWhere('role', '!=', 'admin');
                    })
                    ->where(function (Builder $emailQuery): void {
                        $emailQuery
                            ->whereNull('email')
                            ->orWhere('email', 'not like', 'tester_%@valzeria.local');
                    });
            });
    }

    private function grantToCharacter(
        int $characterId,
        string $requestUuid,
        string $itemKey,
        string $itemName,
        int $quantity,
        string $notificationTitle,
        string $notificationBody,
        ?int $adminUserId
    ): bool {
        return DB::transaction(function () use (
            $characterId,
            $requestUuid,
            $itemKey,
            $itemName,
            $quantity,
            $notificationTitle,
            $notificationBody,
            $adminUserId
        ): bool {
            $character = Character::query()->whereKey($characterId)->lockForUpdate()->first();
            if (!$character) {
                return false;
            }

            $alreadyGranted = AdminItemGrantLog::query()
                ->where('character_id', $character->id)
                ->where('grant_type', 'global_compensation')
                ->where('metadata->request_uuid', $requestUuid)
                ->exists();
            if ($alreadyGranted) {
                return false;
            }

            $inventory = CharacterConsumableItem::firstOrCreate(
                ['character_id' => $character->id, 'item_key' => $itemKey],
                ['quantity' => 0]
            );
            $inventory->increment('quantity', $quantity);

            app(CharacterNotificationService::class)->create(
                $character,
                'system',
                self::NOTIFICATION_TYPE,
                $notificationTitle,
                $notificationBody,
                '倉庫を確認する',
                route('inventory.index'),
                [
                    'request_uuid' => $requestUuid,
                    'item_key' => $itemKey,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'granted_by' => 'admin_global_compensation',
                ],
                10
            );

            AdminItemGrantLog::create([
                'character_id' => $character->id,
                'admin_user_id' => $adminUserId,
                'grant_type' => 'global_compensation',
                'target_type' => 'support_item',
                'target_id' => $itemKey,
                'target_name' => $itemName,
                'quantity' => $quantity,
                'metadata' => [
                    'request_uuid' => $requestUuid,
                    'scope' => 'all_players',
                    'notification_title' => $notificationTitle,
                    'notification_body' => $notificationBody,
                ],
            ]);

            return true;
        });
    }
}
