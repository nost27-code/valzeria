<?php

namespace App\Livewire;

use App\Enums\SixHeroBattleMode;
use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroBattleSelectionException;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Services\SixHeroBattleResultPresenter;
use App\Services\SixHeroHallScreenService;
use App\Services\SixHeroOfficialBattleService;
use App\Services\SixHeroPracticeBattleService;
use App\Services\SixHeroRankingInitializationService;
use App\Services\SixHeroRankingService;
use App\Services\SixHeroSeasonService;
use App\Support\SixHeroCompetitionRules;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

final class SixHeroHallScreen extends Component
{
    #[Url(as: 'room', except: SixHeroRoomKey::SEAL_MAGIC->value)]
    public string $selectedRoom = SixHeroRoomKey::SEAL_MAGIC->value;

    public string $registrationNotice = '';

    public string $battleNotice = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $battleConfirmation = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $battleResult = [];

    #[Locked]
    public bool $battleSubmitting = false;

    /** @var array<int, string> */
    private array $battleLogs = [];

    public function mount(): void
    {
        $this->assertPreviewEnabled();
        $this->selectedRoom = $this->resolveRoom($this->selectedRoom)->value;
    }

    public function selectRoom(string $roomKey): void
    {
        $this->assertPreviewEnabled();
        if ($this->battleSubmitting) {
            return;
        }

        $this->selectedRoom = $this->resolveRoom($roomKey)->value;
        $this->registrationNotice = '';
        $this->battleNotice = '';
        $this->battleConfirmation = [];
        $this->battleResult = [];
        $this->battleLogs = [];
        $this->resetErrorBag('registration');
    }

    public function registerRoom(
        string $roomKey,
        SixHeroSeasonService $seasonService,
        SixHeroRankingService $rankingService,
    ): void {
        $this->assertPreviewEnabled();
        if ($this->battleSubmitting) {
            return;
        }

        $this->registrationNotice = '';
        $this->resetErrorBag('registration');

        $room = SixHeroRoomKey::tryFrom($roomKey);
        if ($room === null) {
            $this->addError('registration', '参加する間を選び直してください。');

            return;
        }

        $character = Auth::user()?->currentCharacter();
        if ($character === null) {
            abort(403);
        }

        try {
            $season = $seasonService->currentSeason();
            $ranking = $rankingService->register($season, $room, $character);
        } catch (SixHeroRankingNotReadyException) {
            $this->addError(
                'registration',
                '月次ランキング準備中です。少し後でもう一度お試しください。',
            );

            return;
        } catch (Throwable $exception) {
            if (app()->runningUnitTests()) {
                throw $exception;
            }
            report($exception);
            $this->addError(
                'registration',
                '参加登録を完了できませんでした。少し後でもう一度お試しください。',
            );

            return;
        }

        $this->selectedRoom = $room->value;
        $this->registrationNotice = $ranking->wasRecentlyCreated
            ? "{$room->label()}へ参加登録しました。現在{$ranking->rank}位です。"
            : "{$room->label()}には参加済みです。現在{$ranking->rank}位です。";
    }

    public function openBattleConfirmation(
        string $modeValue,
        int $opponentCharacterId,
        SixHeroSeasonService $seasonService,
        SixHeroRankingInitializationService $rankingInitializationService,
        SixHeroRankingService $rankingService,
        SixHeroHallScreenService $screenService,
    ): void {
        $this->assertPreviewEnabled();
        if ($this->battleSubmitting) {
            return;
        }

        $this->battleNotice = '';
        $this->battleResult = [];
        $this->battleLogs = [];
        $mode = SixHeroBattleMode::tryFrom($modeValue);
        if ($mode === null || $opponentCharacterId <= 0) {
            $this->rejectBattleSelection($mode);

            return;
        }

        try {
            $selection = $this->resolveBattleSelection(
                $mode,
                $opponentCharacterId,
                $seasonService,
                $rankingInitializationService,
                $rankingService,
            );
        } catch (SixHeroRankingNotReadyException) {
            $this->showRankingNotReady();

            return;
        } catch (SixHeroBattleSelectionException) {
            $this->rejectBattleSelection($mode);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->battleConfirmation = [];
            $this->battleNotice = '対戦確認を表示できませんでした。最新の状態を再読み込みしました。';

            return;
        }

        $remaining = $screenService->officialAttemptsRemaining(
            $selection['attacker'],
            $selection['room'],
        );
        if ($mode === SixHeroBattleMode::OFFICIAL && $remaining <= 0) {
            $this->battleConfirmation = [];
            $this->battleNotice = 'この間の本日の公式戦は終了しました。相性確認は引き続き利用できます。';

            return;
        }

        $this->battleConfirmation = [
            'mode' => $mode->value,
            'modeLabel' => $mode->label(),
            'opponentCharacterId' => (int) $selection['defender']->id,
            'opponentName' => (string) $selection['defender']->name,
            'opponentRank' => (int) $selection['defenderRanking']->rank,
            'seasonKey' => (string) $selection['season']->season_key,
            'roomKey' => $selection['room']->value,
            'roomLabel' => $selection['room']->label(),
            'seasonLabel' => $this->seasonLabel($selection['season']),
            'officialAttemptsRemaining' => $remaining,
            'officialAttemptLimit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
        ];
    }

    public function closeBattleConfirmation(): void
    {
        $this->assertPreviewEnabled();
        if (! $this->battleSubmitting) {
            $this->battleConfirmation = [];
        }
    }

    public function executeConfirmedBattle(
        SixHeroSeasonService $seasonService,
        SixHeroRankingInitializationService $rankingInitializationService,
        SixHeroRankingService $rankingService,
        SixHeroOfficialBattleService $officialBattleService,
        SixHeroPracticeBattleService $practiceBattleService,
        SixHeroBattleResultPresenter $presenter,
    ): void {
        $this->assertPreviewEnabled();
        if ($this->battleSubmitting) {
            return;
        }

        $mode = SixHeroBattleMode::tryFrom(
            (string) ($this->battleConfirmation['mode'] ?? ''),
        );
        $opponentCharacterId = (int) ($this->battleConfirmation['opponentCharacterId'] ?? 0);
        if ($mode === null || $opponentCharacterId <= 0) {
            $this->rejectBattleSelection($mode);

            return;
        }

        $this->battleSubmitting = true;
        $this->battleNotice = '';
        $this->battleResult = [];
        $this->battleLogs = [];

        try {
            $selection = $this->resolveBattleSelection(
                $mode,
                $opponentCharacterId,
                $seasonService,
                $rankingInitializationService,
                $rankingService,
            );
            if ((string) ($this->battleConfirmation['seasonKey'] ?? '')
                    !== (string) $selection['season']->season_key
                || (string) ($this->battleConfirmation['roomKey'] ?? '')
                    !== $selection['room']->value
            ) {
                throw new SixHeroBattleSelectionException(
                    'The confirmed season or room is no longer current.',
                );
            }

            if ($mode === SixHeroBattleMode::OFFICIAL) {
                $result = $officialBattleService->execute(
                    $selection['season'],
                    $selection['room'],
                    $selection['attacker'],
                    $selection['defender'],
                );
                $this->storeBattlePresentation($presenter->official(
                    $selection['season'],
                    $selection['room'],
                    $selection['attacker'],
                    $selection['defender'],
                    $result,
                ));
            } else {
                $result = $practiceBattleService->execute(
                    $selection['season'],
                    $selection['room'],
                    $selection['attacker'],
                    $selection['defender'],
                );
                $this->storeBattlePresentation($presenter->practice(
                    $selection['season'],
                    $selection['room'],
                    $selection['attacker'],
                    $selection['defender'],
                    $result,
                ));
            }

            $this->battleConfirmation = [];
        } catch (SixHeroRankingNotReadyException) {
            $this->showRankingNotReady();
        } catch (SixHeroBattleSelectionException) {
            $this->rejectBattleSelection($mode);
        } catch (DomainException) {
            $this->battleConfirmation = [];
            $this->battleNotice = $mode === SixHeroBattleMode::OFFICIAL
                ? '対戦条件が更新されたか、戦闘を開始できませんでした。最新の状態を再読み込みしました。公式戦回数は画面上部をご確認ください。'
                : '対戦条件が更新されたか、戦闘を開始できませんでした。最新の状態を再読み込みしました。';
        } catch (Throwable $exception) {
            report($exception);
            $this->battleConfirmation = [];
            $this->battleNotice = $mode === SixHeroBattleMode::OFFICIAL
                ? '戦闘処理中に問題が発生しました。最新の状態を再読み込みしました。公式戦回数は画面上部をご確認ください。'
                : '戦闘処理中に問題が発生しました。最新の状態を再読み込みしました。';
        } finally {
            $this->battleSubmitting = false;
        }
    }

    public function render(SixHeroHallScreenService $screenService): View
    {
        $this->assertPreviewEnabled();
        $room = $this->resolveRoom($this->selectedRoom);
        $this->selectedRoom = $room->value;
        $character = Auth::user()?->currentCharacter();
        if ($character === null) {
            abort(403);
        }

        try {
            $data = array_merge(
                ['screenError' => false],
                $screenService->screenData($character, $room),
            );
        } catch (Throwable $exception) {
            if (app()->runningUnitTests()) {
                throw $exception;
            }
            report($exception);
            $data = [
                'screenError' => true,
                'ready' => false,
                'seasonLabel' => '現在期',
                'seasonPeriodLabel' => '',
            ];
        }

        return view('livewire.six-hero-hall-screen', $data);
    }

    private function resolveRoom(string $roomKey): SixHeroRoomKey
    {
        return SixHeroRoomKey::tryFrom($roomKey) ?? SixHeroRoomKey::cases()[0];
    }

    /**
     * @return array{
     *     season: SixHeroSeason,
     *     room: SixHeroRoomKey,
     *     attacker: Character,
     *     defender: Character,
     *     attackerRanking: SixHeroRanking,
     *     defenderRanking: SixHeroRanking
     * }
     */
    private function resolveBattleSelection(
        SixHeroBattleMode $mode,
        int $opponentCharacterId,
        SixHeroSeasonService $seasonService,
        SixHeroRankingInitializationService $rankingInitializationService,
        SixHeroRankingService $rankingService,
    ): array {
        $attacker = Auth::user()?->currentCharacter();
        if ($attacker === null) {
            abort(403);
        }
        $attacker->refresh();

        if ((int) $attacker->id === $opponentCharacterId) {
            throw new SixHeroBattleSelectionException('A character cannot battle itself.');
        }

        $room = $this->resolveRoom($this->selectedRoom);
        $this->selectedRoom = $room->value;
        $season = $rankingInitializationService->requireInitialized(
            $seasonService->currentSeason(),
        );
        $rankings = SixHeroRanking::query()
            ->with('character')
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->whereIn('character_id', [(int) $attacker->id, $opponentCharacterId])
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): int => (int) $ranking->character_id);

        if (! $rankings->has((int) $attacker->id)
            || ! $rankings->has($opponentCharacterId)
        ) {
            throw new SixHeroBattleSelectionException(
                'Both characters must be registered in this room.',
            );
        }

        /** @var SixHeroRanking $attackerRanking */
        $attackerRanking = $rankings->get((int) $attacker->id);
        /** @var SixHeroRanking $defenderRanking */
        $defenderRanking = $rankings->get($opponentCharacterId);
        $defender = $defenderRanking->character;
        if ($defender === null) {
            throw new SixHeroBattleSelectionException('The opponent no longer exists.');
        }

        if ($mode === SixHeroBattleMode::OFFICIAL
            && ! $rankingService->isChallengeTarget($attackerRanking, $defenderRanking)
        ) {
            throw new SixHeroBattleSelectionException(
                'The defender is not an eligible challenge target.',
            );
        }

        return [
            'season' => $season,
            'room' => $room,
            'attacker' => $attacker,
            'defender' => $defender,
            'attackerRanking' => $attackerRanking,
            'defenderRanking' => $defenderRanking,
        ];
    }

    private function rejectBattleSelection(?SixHeroBattleMode $mode): void
    {
        $this->battleConfirmation = [];
        $this->battleResult = [];
        $this->battleLogs = [];
        $this->battleNotice = $mode === SixHeroBattleMode::PRACTICE
            ? '対戦相手または参加状況が更新されました。最新のランキングを表示しました。'
            : 'ランキングが更新されました。最新の挑戦候補を表示しました。';
    }

    private function showRankingNotReady(): void
    {
        $this->battleConfirmation = [];
        $this->battleResult = [];
        $this->battleLogs = [];
        $this->battleNotice = '月次ランキングを準備しています。準備完了後にもう一度お試しください。';
    }

    /** @param array<string, mixed> $presentation */
    private function storeBattlePresentation(array $presentation): void
    {
        $logs = $presentation['logs'] ?? [];
        unset($presentation['logs']);

        $this->battleLogs = is_array($logs) ? array_values($logs) : [];
        $this->battleResult = $presentation;
        session()->flash('six_hero_battle_result', [
            'battleResult' => $this->battleResult,
            'battleLogs' => $this->battleLogs,
        ]);
        $this->redirectRoute('six-heroes.battle-result', navigate: false);
    }

    private function seasonLabel(SixHeroSeason $season): string
    {
        return $season->starts_at
            ->copy()
            ->setTimezone((string) config('app.timezone'))
            ->format('Y年n月期');
    }

    private function assertPreviewEnabled(): void
    {
        abort_unless(
            (bool) config('features.six_hero_ui_enabled', false),
            404,
        );
    }
}
