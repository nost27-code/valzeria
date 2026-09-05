<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use DomainException;
use Illuminate\Support\Facades\Log;

/** TOPとランキングのread model。戦闘準備・参加登録・報酬生成は行わない。 */
final readonly class NationRaidPortalService
{
    public function __construct(
        private NationRaidRankingService $rankings,
        private NationRaidCoordinationService $coordination,
        private NationRaidBattleViewService $battleViews,
        private NationRaidSortieService $sorties,
    ) {}

    public function build(NationRaidEvent $event, Character $character): array
    {
        $at = now();
        $canPrepare = false;
        try {
            $this->sorties->assertAdmission($event);
            $canPrepare = true;
        } catch (DomainException) {
            // 出撃不可でも戦況と受取済み/未受取報酬への導線は残す。
        }
        $status = match (true) {
            $event->status === NationRaidEvent::STATUS_COMPLETED => '戦果確定',
            $event->status === NationRaidEvent::STATUS_FINALIZING => '戦果集計中',
            $event->ends_at->lte($at) => '出撃受付終了',
            $event->sorties_paused_at !== null => '出撃一時停止',
            $canPrepare => '開催中',
            default => '準備中',
        };
        try {
            $standings = $this->rankings->standings($event);
        } catch (DomainException $exception) {
            Log::warning('Raid portal standings unavailable', ['event_id' => $event->id, 'exception' => $exception::class]);
            $standings = null;
        }
        $participation = NationRaidParticipation::query()->where('event_id', $event->id)
            ->where('account_id', $character->user_id)->first();
        $ownNationId = $participation?->is_nation_eligible
            && (int) ($participation->character_id_snapshot ?? $participation->character_id) === (int) $character->id
            ? ($participation->nation_id_snapshot ?? $participation->nation_id) : null;
        $nations = $standings['nation_total'] ?? [];
        $live = $canPrepare ? $this->coordination->liveForNations($event, array_column($nations, 'nation_id')) : [];
        $ownNation = null;
        $previousDamage = null;
        $higherDamage = null;
        foreach ($nations as &$nation) {
            if ($previousDamage !== null && $previousDamage > $nation['damage']) {
                $higherDamage = $previousDamage;
            }
            $nation['is_own'] = $ownNationId !== null && (int) $nation['nation_id'] === (int) $ownNationId;
            $nation['damage_gap'] = $higherDamage === null ? null : $higherDamage - $nation['damage'];
            $nation['coordination'] = $live[$nation['nation_id']] ?? null;
            if ($nation['is_own']) {
                $ownNation = $nation;
            }
            $previousDamage = $nation['damage'];
        }
        unset($nation);
        $cycle = $event->cycles()->where('cycle_no', $event->current_cycle_no)->first();
        $encounter = $cycle === null ? null : $this->battleViews->encounter($cycle->stage_no, $cycle->current_hp, $cycle->max_hp, null);
        if ($cycle?->cycle_kind === NationRaidBossCycle::KIND_ECHO) {
            $encounter['stage_name'] = '残響';
        }
        return [
            'status_label' => $status, 'can_prepare' => $canPrepare, 'encounter' => $encounter,
            'hp_percent' => $cycle !== null && $cycle->max_hp > 0 ? round(100 * $cycle->current_hp / $cycle->max_hp, 2) : 0,
            'as_of' => $at->format('n/j H:i'), 'standings' => $standings, 'nations' => $nations,
            'own_nation' => $ownNation,
            'own_nation_name' => $ownNationId === null ? null : $participation->nation_name_snapshot,
            'own_progress' => collect($standings['personal_total'] ?? [])->first(
                fn ($row) => (int) $row['account_id'] === (int) $character->user_id
                    && (int) $row['character_id'] === (int) $character->id,
            ),
        ];
    }
}
