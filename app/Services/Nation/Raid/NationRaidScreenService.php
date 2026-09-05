<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\ExplorationStaminaService;
use Illuminate\Support\Facades\Log;

final readonly class NationRaidScreenService
{
    public function __construct(
        private NationRaidPlayerPreparationService $preparation,
        private NationRaidBattleViewService $view,
        private NationRaidSortieService $sorties,
        private NationRaidCoordinationService $coordination,
        private ExplorationStaminaService $stamina,
        private NationRaidRankingService $rankings,
    ) {}

    public function screen(NationRaidEvent $event, Character $character): array
    {
        $cycle = NationRaidBossCycle::query()->where('event_id', $event->id)->where('cycle_no', $event->current_cycle_no)->firstOrFail();
        $participation = NationRaidParticipation::query()->where('event_id', $event->id)->where('account_id', $character->user_id)->first();
        $day = $event->raidDayAt(now());
        $used = (int) NationRaidDailyUsage::query()
            ->where('event_id', $event->id)->where('account_id', $character->user_id)->where('raid_day', $day ?? 7)->value('used_count');
        $reason = null;
        $lineage = null;
        $vote = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', $day ?? 7)->first();
        $votePending = $vote?->determined_at === null;
        try {
            $lineage = $this->sorties->lineageForDay($event, $day ?? 7);
        } catch (\DomainException $exception) {
            $reason = $exception->getMessage();
        }
        try {
            $this->sorties->assertAdmission($event);
        } catch (\DomainException $exception) {
            $reason = $exception->getMessage();
        }
        $player = $this->preparation->capture($character);
        $encounter = $this->view->encounter($cycle->stage_no ?? $event->stage_count, $cycle->current_hp, $cycle->max_hp, $lineage);
        if ($cycle->cycle_kind === NationRaidBossCycle::KIND_ECHO) {
            $encounter['stage_name'] = '残響 '.$cycle->echo_no;
        }
        if ($votePending) {
            $encounter['dominant_lineage_label'] = '集計中';
        }
        $standings = null;
        try {
            $standings = $this->rankings->standings($event);
        } catch (\DomainException $exception) {
            Log::error('Raid standings unavailable', ['event_id' => $event->id, 'error_class' => $exception::class]);
        }
        $votes = [];
        foreach (($vote?->vote_counts ?? []) as $key => $count) {
            $votes[] = ['label' => $this->view->lineageLabel($key), 'count' => $count];
        }
        $nextSwitch = $day !== null && $day < 7 ? $event->starts_at->copy()->addDays($day)->format('n/j H:i') : null;

        return [
            'official' => true, 'event_id' => $event->id,
            'battle_url' => route('nation-raid.battle', $event), 'index_url' => route('nation-raid.show', $event),
            'battle_token' => bin2hex(random_bytes(32)),
            'can_challenge' => $reason === null, 'unavailable_reason' => $reason,
            'used_sorties' => $day === null ? 0 : $used,
            'ends_label' => $event->ends_at->format('n/j H:i').'まで',
            'completed_stages' => min($event->stage_count, $cycle->cycle_no - 1),
            'boss_name' => $event->boss_name, 'boss_species_label' => '竜', 'boss_max_hp' => $cycle->max_hp,
            'max_turns' => NationRaidRules::MAX_TURNS, 'stages' => $this->view->stages(),
            'strategies' => $this->view->strategies(), 'encounter' => $encounter,
            'character' => $player['character'], 'abilities' => $player['abilities'], 'equipment' => $player['equipment'],
            'boss_set' => $player['boss_set'], 'counterplay_enabled' => $player['counterplay_enabled'],
            'coordination' => $this->view->coordinationPresentation($character, $this->coordination->snapshot($event, $participation)),
            'sortie_stamina_cost' => (int) config('nation_raid.event.sortie_stamina_cost', 10),
            'exploration_stamina' => $this->stamina->summary($character),
            'standings' => $standings,
            'own_progress' => collect($standings['personal_total'] ?? [])->firstWhere('account_id', (int) $character->user_id),
            'lineage_vote' => ['pending' => $votePending, 'day' => $day ?? 7, 'votes' => $votes,
                'adopted_sets' => $vote?->votes_snapshot['adopted_set_count'] ?? null, 'next_switch_label' => $nextSwitch],
        ];
    }
}
