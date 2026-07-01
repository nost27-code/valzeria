<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Services\CooldownSettingService;
use App\Services\NewcomerRegistrationCampaignService;
use App\Services\StorageCapacityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PlayerControlManager extends Component
{
    public string $search = '';
    public ?int $selectedCharacterId = null;
    public int $materialStorageLimit = 300;
    public int $equipmentStorageLimit = 200;
    public string $grantType = 'material';
    public string $grantSearch = '';
    public string $grantTargetId = '';
    public int $grantQuantity = 1;
    public int $grantEnhanceLevel = 0;
    public string $freezeReason = '';

    public function selectCharacter(int $characterId): void
    {
        $character = Character::find($characterId);
        if (!$character) {
            return;
        }

        $this->selectedCharacterId = (int) $character->id;
        $this->materialStorageLimit = max(1, (int) ($character->material_storage_limit ?? 500));
        $this->equipmentStorageLimit = max(1, (int) ($character->equipment_storage_limit ?? 200));
    }

    public function updatedGrantType(): void
    {
        $this->grantTargetId = '';
        $this->grantSearch = '';
        $this->grantQuantity = 1;
        $this->grantEnhanceLevel = 0;
    }

    public function saveStorageLimits(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'materialStorageLimit' => ['required', 'integer', 'min:1', 'max:999999'],
            'equipmentStorageLimit' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $character = Character::findOrFail($this->selectedCharacterId);
        $character->forceFill([
            'material_storage_limit' => $this->materialStorageLimit,
            'equipment_storage_limit' => $this->equipmentStorageLimit,
        ])->save();

        session()->flash('message', "{$character->name} の倉庫上限を更新しました。");
    }

    public function clearExplorationCooldown(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
        ]);

        $character = Character::findOrFail($this->selectedCharacterId);
        $character->forceFill([
            'last_battle_at' => null,
            'exploration_cooldown_until' => null,
        ])->save();

        session()->flash('message', "{$character->name} の探索クールタイムを解除しました。");
    }

    public function freezeCharacter(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'freezeReason' => ['required', 'string', 'max:255'],
        ], [
            'freezeReason.required' => '凍結理由を入力してください。',
        ]);

        $character = Character::findOrFail($this->selectedCharacterId);
        $character->forceFill([
            'is_frozen'    => true,
            'freeze_reason' => $this->freezeReason,
            'frozen_at'    => now(),
        ])->save();

        $this->freezeReason = '';
        session()->flash('message', "{$character->name} を凍結しました。");
    }

    public function unfreezeCharacter(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
        ]);

        $character = Character::findOrFail($this->selectedCharacterId);
        $character->forceFill([
            'is_frozen'    => false,
            'freeze_reason' => null,
            'frozen_at'    => null,
        ])->save();

        session()->flash('message', "{$character->name} の凍結を解除しました。");
    }

    public function grantItem(): void
    {
        $this->validate([
            'selectedCharacterId' => ['required', 'integer', 'exists:characters,id'],
            'grantType' => ['required', 'in:material,equipment,exploration_item,support_item'],
            'grantTargetId' => ['required', 'string'],
            'grantQuantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'grantEnhanceLevel' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $character = Character::findOrFail($this->selectedCharacterId);
        $message = DB::transaction(function () use ($character) {
            return match ($this->grantType) {
                'material' => $this->grantMaterial($character),
                'equipment' => $this->grantEquipment($character),
                'exploration_item' => $this->grantExplorationItem($character),
                'support_item' => $this->grantSupportItem($character),
            };
        });

        session()->flash('message', $message);
        $this->grantQuantity = 1;
    }

    public function render(
        StorageCapacityService $storageCapacityService,
        NewcomerRegistrationCampaignService $newcomerRegistrationCampaignService
    )
    {
        $characters = $this->characters();
        $selectedCharacter = $this->selectedCharacterId
            ? Character::query()->with(['user', 'currentJob'])->find($this->selectedCharacterId)
            : null;

        return view('livewire.admin.player-control-manager', [
            'characters' => $characters,
            'selectedCharacter' => $selectedCharacter,
            'storageSummary' => $selectedCharacter ? $storageCapacityService->summary($selectedCharacter) : null,
            'cooldownSummary' => $selectedCharacter ? $this->cooldownSummary($selectedCharacter) : null,
            'grantCandidates' => $this->grantCandidates(),
            'grantTypeLabels' => $this->grantTypeLabels(),
            'controlIdeas' => $this->controlIdeas(),
            'newcomerGiftSummary' => $newcomerRegistrationCampaignService->summary(syncPending: true),
        ])->layout('components.layouts.admin');
    }

    private function characters(): Collection
    {
        $keyword = trim($this->search);

        return Character::query()
            ->with(['user', 'currentJob'])
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($inner) use ($keyword) {
                    $inner->where('name', 'like', "%{$keyword}%")
                        ->orWhereHas('user', function ($userQuery) use ($keyword) {
                            $userQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });

                    if (ctype_digit($keyword)) {
                        $inner->orWhere('id', (int) $keyword)
                            ->orWhereHas('user', fn ($userQuery) => $userQuery->where('id', (int) $keyword));
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
    }

    private function controlIdeas(): array
    {
        return [
            ['label' => '倉庫上限', 'state' => '実装済み', 'body' => '素材倉庫・装備倉庫の上限を個別に調整できます。'],
            ['label' => '探索クールタイム', 'state' => '実装済み', 'body' => '不具合救済やテスト用に探索待機を解除できます。'],
            ['label' => 'アイテム送付', 'state' => '実装済み', 'body' => '素材・装備・探索用アイテム・サポートアイテムを送付できます。'],
            ['label' => 'HP/SP回復', 'state' => '候補', 'body' => '問い合わせ対応や検証用に現在HP/SPを回復できます。'],
            ['label' => '輝石付与', 'state' => '既存監査と連携候補', 'body' => '課金監査ログとつなげて、手動補填を記録付きで行えます。'],
            ['label' => '進行復旧', 'state' => '候補', 'body' => '街・エリア解放・ボス討伐状態を個別に確認して復旧できます。'],
            ['label' => 'ヴァルモン救済', 'state' => '候補', 'body' => '卵・相棒・なつきなどを調査画面と連動して調整できます。'],
        ];
    }

    private function cooldownSummary(Character $character): array
    {
        $battleCooldownSeconds = app(CooldownSettingService::class)->explorationBattleSeconds();
        $battleReadyAt = $character->last_battle_at && $battleCooldownSeconds > 0
            ? $character->last_battle_at->copy()->addSeconds($battleCooldownSeconds)
            : null;
        $battleRemaining = $battleReadyAt && now()->lt($battleReadyAt)
            ? max(0, $battleReadyAt->getTimestamp() - now()->getTimestamp())
            : 0;
        $innRemaining = $character->exploration_cooldown_until && now()->lt($character->exploration_cooldown_until)
            ? max(0, $character->exploration_cooldown_until->getTimestamp() - now()->getTimestamp())
            : 0;

        return [
            'last_battle_at' => $character->last_battle_at?->format('Y-m-d H:i:s') ?? '-',
            'exploration_cooldown_until' => $character->exploration_cooldown_until?->format('Y-m-d H:i:s') ?? '-',
            'battle_remaining' => $battleRemaining,
            'inn_remaining' => $innRemaining,
            'is_blocked' => $battleRemaining > 0 || $innRemaining > 0,
        ];
    }

    private function grantTypeLabels(): array
    {
        return [
            'material' => '素材',
            'equipment' => '装備',
            'exploration_item' => '探索アイテム',
            'support_item' => 'サポートアイテム',
        ];
    }

    private function grantCandidates(): Collection
    {
        $keyword = trim($this->grantSearch);

        return match ($this->grantType) {
            'equipment' => Item::query()
                ->whereIn('type', ['weapon', 'armor', 'accessory'])
                ->when($keyword !== '', fn ($query) => $query->where(function ($inner) use ($keyword) {
                    $inner->where('name', 'like', "%{$keyword}%")
                        ->orWhere('external_item_id', 'like', "%{$keyword}%");
                    if (ctype_digit($keyword)) {
                        $inner->orWhere('id', (int) $keyword);
                    }
                }))
                ->orderBy('type')
                ->orderByDesc('rarity')
                ->orderBy('name')
                ->limit(40)
                ->get()
                ->map(fn (Item $item) => [
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'meta' => "#{$item->id} / {$item->type} / " . ($item->rarity ?? '-'),
                ]),
            'exploration_item' => Item::query()
                ->where('type', 'consumable')
                ->whereIn('name', ['薬草', '回復薬', '魔力水'])
                ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orderBy('id')
                ->get()
                ->map(fn (Item $item) => [
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'meta' => "#{$item->id} / 探索用",
                ]),
            'support_item' => collect(config('adventure_support.items', []))
                ->filter(fn (array $item, string $key) => in_array($key, ['rescue_insurance', 'emergency_rescue_request'], true)
                    || ($item['effect_type'] ?? null) === 'explore_stamina_recovery')
                ->filter(fn (array $item, string $key) => $keyword === ''
                    || str_contains(mb_strtolower($item['name'] ?? ''), mb_strtolower($keyword))
                    || str_contains(mb_strtolower($key), mb_strtolower($keyword)))
                ->map(fn (array $item, string $key) => [
                    'id' => $key,
                    'name' => $item['name'] ?? $key,
                    'meta' => $key . ' / ' . ($item['category'] ?? '-'),
                ])
                ->values(),
            default => Material::query()
                ->when($keyword !== '', fn ($query) => $query->where(function ($inner) use ($keyword) {
                    $inner->where('name', 'like', "%{$keyword}%")
                        ->orWhere('material_code', 'like', "%{$keyword}%");
                    if (ctype_digit($keyword)) {
                        $inner->orWhere('id', (int) $keyword);
                    }
                }))
                ->orderBy('category')
                ->orderBy('name')
                ->limit(40)
                ->get()
                ->map(fn (Material $material) => [
                    'id' => (string) $material->id,
                    'name' => $material->name,
                    'meta' => "#{$material->id} / " . ($material->category ?? '-') . ' / ' . ($material->material_code ?? '-'),
                ]),
        };
    }

    private function grantMaterial(Character $character): string
    {
        $material = Material::findOrFail((int) $this->grantTargetId);
        $row = CharacterMaterial::firstOrCreate(
            ['character_id' => $character->id, 'material_id' => $material->id],
            ['quantity' => 0]
        );
        $row->increment('quantity', $this->grantQuantity);

        return "{$character->name} に {$material->name} x{$this->grantQuantity} を送付しました。";
    }

    private function grantEquipment(Character $character): string
    {
        $item = Item::whereIn('type', ['weapon', 'armor', 'accessory'])->findOrFail((int) $this->grantTargetId);
        for ($i = 0; $i < $this->grantQuantity; $i++) {
            CharacterItem::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'is_equipped' => false,
                'is_stored' => false,
                'is_locked' => false,
                'enhance_level' => $this->grantEnhanceLevel,
                'acquired_from' => 'admin_grant',
            ]);
        }

        $enhanceText = $this->grantEnhanceLevel > 0 ? " +{$this->grantEnhanceLevel}" : '';

        return "{$character->name} に {$item->name}{$enhanceText} x{$this->grantQuantity} を送付しました。";
    }

    private function grantExplorationItem(Character $character): string
    {
        $item = Item::where('type', 'consumable')
            ->whereIn('name', ['薬草', '回復薬', '魔力水'])
            ->findOrFail((int) $this->grantTargetId);

        for ($i = 0; $i < $this->grantQuantity; $i++) {
            CharacterItem::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'is_equipped' => false,
                'is_stored' => false,
                'is_locked' => false,
                'acquired_from' => 'admin_grant',
            ]);
        }

        return "{$character->name} に {$item->name} x{$this->grantQuantity} を送付しました。";
    }

    private function grantSupportItem(Character $character): string
    {
        $items = config('adventure_support.items', []);
        $item = $items[$this->grantTargetId] ?? null;
        $isConsumableSupport = $item
            && (in_array($this->grantTargetId, ['rescue_insurance', 'emergency_rescue_request'], true)
                || ($item['effect_type'] ?? null) === 'explore_stamina_recovery');

        if (!$isConsumableSupport) {
            throw new \InvalidArgumentException('送付対象のサポートアイテムが見つかりません。');
        }

        $row = CharacterConsumableItem::firstOrCreate(
            ['character_id' => $character->id, 'item_key' => $this->grantTargetId],
            ['quantity' => 0]
        );
        $row->increment('quantity', $this->grantQuantity);

        return "{$character->name} に {$item['name']} x{$this->grantQuantity} を送付しました。";
    }

}
