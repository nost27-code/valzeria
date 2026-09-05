<?php

namespace App\Services\Nation\Raid;

/** Phase 1がplayer action選択前に公開する、読み取り専用の予告窓。 */
final readonly class NationRaidBossTurnPrompt
{
    public function __construct(
        public int $turn,
        public ?string $pendingEnemyActionKey,
        public ?string $pendingKind,
        public bool $canBeGuarded,
        public bool $preparationDestroyable,
        public bool $bossSpAvailable,
        public bool $bossResourceSlowAvailable,
        public int $bossVirtualHp,
    ) {}

    public function hasResponseWindow(): bool
    {
        return $this->pendingEnemyActionKey !== null && $this->pendingKind !== 'observation';
    }

    /**
     * BattleState::$pendingEnemyActionIdは既存契約上intなので、raidの文字列keyを直接代入しない。
     * 選択中のnon-null markerとしてturnを使い、正本keyはcontextへ保持する。
     */
    public function selectionPendingActionId(): ?int
    {
        return $this->hasResponseWindow() ? $this->turn : null;
    }

    /** @return array<string, mixed>|null */
    public function selectionContext(string $strategy): ?array
    {
        if (! $this->hasResponseWindow()) {
            return null;
        }

        return [
            'raid_selection_only' => true,
            'raid_pending_enemy_action_key' => $this->pendingEnemyActionKey,
            'raid_pending_kind' => $this->pendingKind,
            'raid_strategy' => $strategy,
            'can_be_guarded' => $this->canBeGuarded,
            'raid_preparation_destroyable' => $this->preparationDestroyable,
            'raid_boss_sp_available' => $this->bossSpAvailable,
            'raid_boss_resource_slow_available' => $this->bossResourceSlowAvailable,
        ];
    }
}
