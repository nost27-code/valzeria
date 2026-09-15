<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\ExplorationStaminaService;
use Illuminate\Support\Facades\DB;

/** 開催snapshotに従い、繰越無料枠を先に使ってから探索力を消費・返却する。 */
final readonly class NationRaidSortieCostService
{
    public const TYPE_FREE = 'daily_free';

    public const TYPE_STAMINA = 'voluntary_stamina';

    public function __construct(private ExplorationStaminaService $stamina, private NationRaidRules $rules) {}

    /** @return array{type:string,stamina_cost:int,stamina:?array,free_balance_before:?int,free_balance_after:?int,daily_grant:?int,balance_cap:?int} */
    public function consumeLocked(NationRaidEvent $event, NationRaidParticipation $participation, Character $character, int $day): array
    {
        throw_unless(DB::transactionLevel() > 0 && $participation->exists, \LogicException::class, 'Raid sortie cost requires locked participation.');
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)) {
            $cost = (int) config('nation_raid.event.sortie_stamina_cost', 10);

            return $this->consumeStamina($character, $cost, null, "出撃には探索力{$cost}が必要です。");
        }

        $state = $this->accruedState($participation, $day);
        $participation->free_sortie_balance = $state['balance'];
        $participation->free_sortie_last_granted_day = $day;
        if ($state['balance'] > 0) {
            $participation->free_sortie_balance = $state['balance'] - 1;
            $participation->free_sorties_used = (int) $participation->free_sorties_used + 1;
            $participation->save();

            return [
                'type' => self::TYPE_FREE,
                'stamina_cost' => 0,
                'stamina' => $this->stamina->summary($character),
                'free_balance_before' => $state['balance'],
                'free_balance_after' => $state['balance'] - 1,
                'daily_grant' => $state['daily_grant'],
                'balance_cap' => $state['balance_cap'],
            ];
        }

        $participation->save();
        $cost = (int) $event->ruleset_snapshot['raid_cycle']['free_sorties']['voluntary_stamina_cost'];

        return $this->consumeStamina($character, $cost, $state);
    }

    /** @param array<string,mixed> $admission */
    public function refundLocked(NationRaidParticipation $participation, Character $character, array $admission): void
    {
        throw_unless(DB::transactionLevel() > 0, \LogicException::class, 'Raid sortie refund requires a transaction.');
        $type = (string) ($admission['cost_type'] ?? self::TYPE_STAMINA);
        if ($type === self::TYPE_FREE) {
            $cap = max(1, (int) ($admission['free_balance_cap'] ?? $participation->free_sortie_balance_cap_snapshot));
            $participation->free_sortie_balance = min($cap, (int) $participation->free_sortie_balance + 1);
            $participation->free_sorties_refunded = (int) $participation->free_sorties_refunded + 1;
            $participation->save();

            return;
        }

        $cost = (int) ($admission['stamina_cost'] ?? 0);
        $refund = $this->stamina->refundForExplore($character, $cost);
        throw_unless($refund['refunded'] === $cost, \LogicException::class, 'Raid stamina refund is incomplete.');
    }

    /** @return array{enabled:bool,free_balance:int,daily_grant:int,balance_cap:int,next_cost_type:string,stamina_cost:int} */
    public function status(NationRaidEvent $event, ?NationRaidParticipation $participation, ?int $day): array
    {
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)) {
            return ['enabled' => false, 'free_balance' => 0, 'daily_grant' => 0, 'balance_cap' => 0,
                'next_cost_type' => self::TYPE_STAMINA, 'stamina_cost' => (int) config('nation_raid.event.sortie_stamina_cost', 10)];
        }
        $free = $event->ruleset_snapshot['raid_cycle']['free_sorties'];
        $dailyGrant = (int) ($participation?->free_sortie_daily_grant_snapshot ?? $free['daily_grant']);
        $balanceCap = (int) ($participation?->free_sortie_balance_cap_snapshot ?? $free['balance_cap']);
        $balance = (int) ($participation?->free_sortie_balance ?? 0);
        if ($participation && $day !== null) {
            $state = $this->accruedState($participation, $day);
            $dailyGrant = $state['daily_grant'];
            $balanceCap = $state['balance_cap'];
            $balance = $state['balance'];
        } elseif ($day !== null) {
            $balance = min($balanceCap, max(0, $day) * $dailyGrant);
        }

        return [
            'enabled' => true,
            'free_balance' => $balance,
            'daily_grant' => $dailyGrant,
            'balance_cap' => $balanceCap,
            'next_cost_type' => $balance > 0 ? self::TYPE_FREE : self::TYPE_STAMINA,
            'stamina_cost' => (int) $free['voluntary_stamina_cost'],
        ];
    }

    /** @return array{balance:int,daily_grant:int,balance_cap:int} */
    private function accruedState(NationRaidParticipation $participation, int $day): array
    {
        throw_if($day < 1 || $day > 7, \InvalidArgumentException::class, 'レイド開催日を確認できません。');
        $dailyGrant = max(1, (int) $participation->free_sortie_daily_grant_snapshot);
        $balanceCap = max(1, (int) $participation->free_sortie_balance_cap_snapshot);
        $lastDay = max(0, min($day, (int) $participation->free_sortie_last_granted_day));
        $days = max(0, $day - $lastDay);
        $balance = min($balanceCap, (int) $participation->free_sortie_balance + $days * $dailyGrant);

        return ['balance' => $balance, 'daily_grant' => $dailyGrant, 'balance_cap' => $balanceCap];
    }

    /** @param array<string,int>|null $freeState */
    private function consumeStamina(Character $character, int $cost, ?array $freeState = null, ?string $error = null): array
    {
        $result = $this->stamina->consumeRequired($character, $cost, $error ?? "自主出撃には探索力{$cost}が必要です。");
        throw_unless($result['ok'], \DomainException::class, $result['error'] ?? '探索力が足りません。');

        return [
            'type' => self::TYPE_STAMINA,
            'stamina_cost' => $cost,
            'stamina' => $result['stamina'],
            'free_balance_before' => $freeState['balance'] ?? null,
            'free_balance_after' => $freeState['balance'] ?? null,
            'daily_grant' => $freeState['daily_grant'] ?? null,
            'balance_cap' => $freeState['balance_cap'] ?? null,
        ];
    }
}
