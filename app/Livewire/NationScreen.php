<?php

namespace App\Livewire;

use App\Enums\NationType;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\Nation\NationActivityLogService;
use App\Services\Nation\NationChatService;
use App\Services\Nation\NationCommunitySettingsService;
use App\Services\Nation\NationDissolutionService;
use App\Services\Nation\NationEmblemCatalog;
use App\Services\Nation\NationJoinApplicationService;
use App\Services\Nation\NationMembershipCooldownService;
use App\Services\Nation\NationMembershipService;
use App\Services\Nation\NationProfileService;
use App\Services\Nation\NationRulerTransferService;
use App\Services\Nation\NationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class NationScreen extends Component
{
    use WithPagination;

    private const COMING_SOON_FEATURES = [
        'resource-management' => '国家資材管理',
        'fortress-upgrade' => '要塞強化',
        'declare-war' => '宣戦布告',
        'war-strategy' => '戦争方針設定',
    ];

    public string $page = 'home';

    public ?int $selectedNationId = null;

    public ?string $pendingFeature = null;

    public ?string $confirmationAction = null;

    public ?int $confirmationTargetId = null;

    public ?string $actionMessage = null;

    public string $foundingName = '';

    public string $foundingNationType = 'kingdom';

    public string $foundingDescription = '';

    public string $foundingEmblemKey = NationEmblemCatalog::DEFAULT_KEY;

    public bool $showFoundingEmblemModal = false;

    public bool $showFoundingConfirmationModal = false;

    public string $joinMessage = '';

    public string $profileDescription = '';

    public bool $profileRecruitmentEnabled = true;

    public string $profileRecruitmentMessage = '';

    public string $profileEmblemKey = NationEmblemCatalog::DEFAULT_KEY;

    public string $dissolutionConfirmation = '';

    public string $nationChatMessage = '';

    public string $nationChatRequestId = '';

    public function mount(): void
    {
        $this->rotateNationChatRequestId();

        if (session()->pull('nation_initial_page') === 'applications') {
            $this->showApplications();
        }
    }

    public function boot(): void
    {
        abort_unless(config('features.nation_community_enabled', false), 404);
    }

    public function showHome(): void
    {
        $this->navigate('home');
    }

    public function showNationList(): void
    {
        if ($this->currentMembership()) {
            $this->addError('nationAction', '所属中は自国の画面を利用してください。');

            return;
        }
        $this->navigate('nation-list');
    }

    public function showCreate(): void
    {
        if ($this->currentMembership()) {
            $this->addError('nationAction', 'すでに国家へ所属しています。');

            return;
        }
        $this->navigate('create');
    }

    public function openFoundingEmblemModal(): void
    {
        if ($this->page !== 'create' || $this->currentMembership()) {
            return;
        }

        $this->resetErrorBag('foundingEmblemKey');
        $this->showFoundingEmblemModal = true;
    }

    public function closeFoundingEmblemModal(): void
    {
        $this->showFoundingEmblemModal = false;
    }

    public function openFoundingConfirmation(): void
    {
        if ($this->page !== 'create' || $this->currentMembership()) {
            return;
        }

        $this->validateFoundingInput();
        $this->showFoundingEmblemModal = false;
        $this->showFoundingConfirmationModal = true;
    }

    public function closeFoundingConfirmation(): void
    {
        $this->showFoundingConfirmationModal = false;
    }

    public function selectFoundingEmblem(string $emblemKey): void
    {
        if (! app(NationEmblemCatalog::class)->exists($emblemKey)) {
            $this->addError('foundingEmblemKey', '選択した国家紋章は使用できません。');

            return;
        }

        $this->foundingEmblemKey = $emblemKey;
        $this->resetErrorBag('foundingEmblemKey');
        $this->showFoundingEmblemModal = false;
    }

    public function showNationDetail(int $nationId): void
    {
        $nation = Nation::active()->find($nationId);
        if (! $nation) {
            $this->addError('nationAction', '国家が見つかりません。');

            return;
        }

        $this->selectedNationId = $nation->id;
        $this->navigate('detail', keepSelection: true);
    }

    public function showApplications(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->navigate('applications');
    }

    public function showMemberManagement(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->navigate('members');
    }

    public function showProfileSettings(): void
    {
        $membership = $this->rulerOrError();
        if (! $membership) {
            return;
        }
        $nation = $membership->nation;
        $this->profileDescription = (string) ($nation->description ?? '');
        $this->profileRecruitmentEnabled = (bool) $nation->recruitment_enabled;
        $this->profileRecruitmentMessage = (string) ($nation->recruitment_message ?? '');
        $this->profileEmblemKey = app(NationEmblemCatalog::class)->selectableKey($nation->emblem_key);
        $this->navigate('profile');
    }

    public function showTransfer(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->navigate('transfer');
    }

    public function showDissolution(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->navigate('dissolution');
    }

    public function createNation(): void
    {
        if (! $this->showFoundingConfirmationModal || $this->page !== 'create') {
            return;
        }

        $this->showFoundingConfirmationModal = false;
        $validated = $this->validateFoundingInput();

        $nation = $this->perform(function () use ($validated): Nation {
            return app(NationService::class)->create(
                $this->character(),
                $validated['foundingName'],
                $validated['foundingDescription'] ?? null,
                $validated['foundingNationType'],
                $validated['foundingEmblemKey'],
            );
        }, '新たな国家が誕生しました！');

        if ($nation) {
            $this->reset('foundingName', 'foundingDescription');
            $this->foundingNationType = 'kingdom';
            $this->foundingEmblemKey = NationEmblemCatalog::DEFAULT_KEY;
            $this->showFoundingConfirmationModal = false;
            $this->page = 'home';
        }
    }

    public function submitJoinApplication(): void
    {
        $validated = $this->validate([
            'joinMessage' => ['nullable', 'string', 'max:100'],
        ], ['joinMessage.max' => '加入申請の一言は100文字以内で入力してください。']);
        $nation = $this->selectedActiveNation();
        if (! $nation) {
            return;
        }

        $application = $this->perform(
            fn () => app(NationJoinApplicationService::class)->submit(
                $this->character(),
                $nation,
                $validated['joinMessage'] ?? null,
            ),
            $nation->display_name.'へ加入申請を送りました。',
        );
        if ($application) {
            $this->joinMessage = '';
        }
    }

    public function cancelJoinApplication(int $applicationId): void
    {
        $application = NationJoinApplication::find($applicationId);
        if (! $application) {
            $this->addError('nationAction', '加入申請が見つかりません。');

            return;
        }

        $this->perform(
            fn () => app(NationJoinApplicationService::class)->cancel($this->character(), $application),
            '加入申請を取り消しました。',
        );
    }

    public function openApplicationApprovalConfirmation(int $applicationId): void
    {
        if ($this->page !== 'applications') {
            return;
        }

        $membership = $this->rulerOrError();
        if (! $membership) {
            return;
        }

        $application = NationJoinApplication::query()
            ->whereKey($applicationId)
            ->where('nation_id', $membership->nation_id)
            ->where('status', NationJoinApplication::STATUS_PENDING)
            ->first();
        if (! $application) {
            $this->addError('nationAction', '加入申請が見つかりません。');

            return;
        }

        $this->confirmationAction = 'approve-application';
        $this->confirmationTargetId = $application->id;
    }

    public function rejectApplication(int $applicationId): void
    {
        $application = NationJoinApplication::find($applicationId);
        if (! $application) {
            $this->addError('nationAction', '加入申請が見つかりません。');

            return;
        }

        $this->perform(
            fn () => app(NationJoinApplicationService::class)->reject($this->character(), $application),
            '加入申請を却下しました。',
        );
    }

    public function saveProfile(): void
    {
        $validated = $this->validate([
            'profileDescription' => ['nullable', 'string', 'max:200'],
            'profileRecruitmentEnabled' => ['boolean'],
            'profileRecruitmentMessage' => ['nullable', 'string', 'max:100'],
            'profileEmblemKey' => ['required', Rule::in(array_keys(app(NationEmblemCatalog::class)->all()))],
        ], [
            'profileDescription.max' => '国家紹介は200文字以内で入力してください。',
            'profileRecruitmentMessage.max' => '募集文は100文字以内で入力してください。',
        ]);
        $membership = $this->rulerOrError();
        if (! $membership) {
            return;
        }

        $nation = $this->perform(
            fn () => app(NationProfileService::class)->update(
                $membership,
                $validated['profileDescription'] ?? null,
                (bool) $validated['profileRecruitmentEnabled'],
                $validated['profileRecruitmentMessage'] ?? null,
                $validated['profileEmblemKey'],
            ),
            '国家プロフィールを更新しました。',
        );
        if ($nation) {
            $this->page = 'home';
        }
    }

    public function changeMemberRole(int $membershipId, string $role): void
    {
        $actor = $this->rulerOrError();
        if (! $actor) {
            return;
        }
        $target = NationMembership::find($membershipId);
        if (! $target) {
            $this->addError('nationAction', '対象の国民が見つかりません。');

            return;
        }

        $this->perform(
            fn () => app(NationMembershipService::class)->changeRole($actor, $target, $role),
            '役職を更新しました。',
        );
    }

    public function openLeaveConfirmation(): void
    {
        $membership = $this->currentMembership();
        if (! $membership) {
            $this->addError('nationAction', '国家へ所属していません。');

            return;
        }
        $this->confirmationAction = 'leave';
        $this->confirmationTargetId = $membership->id;
    }

    public function openExpelConfirmation(int $membershipId): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->confirmationAction = 'expel';
        $this->confirmationTargetId = $membershipId;
    }

    public function openTransferConfirmation(int $membershipId): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->confirmationAction = 'transfer';
        $this->confirmationTargetId = $membershipId;
    }

    public function openDissolutionConfirmation(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }
        $this->confirmationAction = 'dissolution';
        $this->confirmationTargetId = null;
    }

    public function closeConfirmation(): void
    {
        $this->confirmationAction = null;
        $this->confirmationTargetId = null;
    }

    public function confirmAction(): void
    {
        $action = $this->confirmationAction;
        $targetId = $this->confirmationTargetId;

        if ($action === 'dissolution') {
            $validated = $this->validate([
                'dissolutionConfirmation' => ['required', 'string', 'max:50'],
            ], ['dissolutionConfirmation.required' => '確認用の国家名を入力してください。']);
            $waitHours = app(NationCommunitySettingsService::class)->dissolutionWaitHours();
            $nation = $this->perform(
                fn () => app(NationDissolutionService::class)->request($this->character(), $validated['dissolutionConfirmation']),
                "国家解散を申請しました。{$waitHours}時間以内は取り消せます。",
            );
            if ($nation) {
                $this->dissolutionConfirmation = '';
                $this->page = 'home';
                $this->closeConfirmation();
            }

            return;
        }

        if ($action === 'approve-application') {
            $this->closeConfirmation();
            $application = $targetId ? NationJoinApplication::find($targetId) : null;
            if (! $application) {
                $this->addError('nationAction', '加入申請が見つかりません。');

                return;
            }

            $this->perform(
                fn () => app(NationJoinApplicationService::class)->approve($this->character(), $application),
                '加入申請を承認しました。',
            );

            return;
        }

        $this->closeConfirmation();

        if ($action === 'leave') {
            $hours = app(NationCommunitySettingsService::class)->leaveJoinCooldownHours();
            $result = $this->perform(
                fn () => app(NationMembershipService::class)->leave($this->character()),
                "国家を脱退しました。{$hours}時間はほかの国家へ加入申請できません。",
            );
            if ($result !== false) {
                $this->page = 'home';
            }

            return;
        }

        if ($action === 'expel' || $action === 'transfer') {
            $target = $targetId ? NationMembership::find($targetId) : null;
            if (! $target) {
                $this->addError('nationAction', '対象の国民が見つかりません。');

                return;
            }

            if ($action === 'expel') {
                $actor = $this->rulerOrError();
                if (! $actor) {
                    return;
                }
                $this->perform(
                    fn () => app(NationMembershipService::class)->expel($actor, $target),
                    '国民を追放しました。',
                );
            } else {
                $result = $this->perform(
                    fn () => app(NationRulerTransferService::class)->transfer($this->character(), $target),
                    '統治者の地位を譲渡しました。あなたは国民になりました。',
                );
                if ($result !== false) {
                    $this->page = 'home';
                }
            }

            return;
        }
    }

    public function cancelDissolution(): void
    {
        $nation = $this->perform(
            fn () => app(NationDissolutionService::class)->cancel($this->character()),
            '国家解散を取り消しました。',
        );
        if ($nation) {
            $this->page = 'home';
        }
    }

    public function sendNationChatMessage(): void
    {
        $validated = $this->validate([
            'nationChatMessage' => ['required', 'string', 'max:'.NationChatService::MAX_MESSAGE_LENGTH],
            'nationChatRequestId' => ['required', 'uuid'],
        ], [
            'nationChatMessage.required' => 'メッセージを入力してください。',
            'nationChatMessage.max' => 'メッセージは100文字以内で入力してください。',
            'nationChatRequestId.required' => '送信情報を更新するため、画面を再読み込みしてください。',
            'nationChatRequestId.uuid' => '送信情報を更新するため、画面を再読み込みしてください。',
        ]);

        $message = $this->perform(
            fn () => app(NationChatService::class)->send(
                $this->character(),
                $validated['nationChatMessage'],
                $validated['nationChatRequestId'],
            ),
            '国家チャットへ送信しました。',
        );

        if ($message) {
            $this->nationChatMessage = '';
            $this->rotateNationChatRequestId();
        }
    }

    public function showNotImplemented(string $feature): void
    {
        if (array_key_exists($feature, self::COMING_SOON_FEATURES)) {
            $this->pendingFeature = self::COMING_SOON_FEATURES[$feature];
        }
    }

    public function closeNotImplementedModal(): void
    {
        $this->pendingFeature = null;
    }

    public function render(): View
    {
        $character = $this->character();
        $membership = $this->currentMembership();
        $emblemCatalog = app(NationEmblemCatalog::class);
        $maxMembers = app(NationCommunitySettingsService::class)->maxMembers();
        $nationQuery = Nation::active()
            ->withCount('memberships')
            ->with(['rulerMembership.character'])
            ->orderByDesc('recruitment_enabled')
            ->orderByDesc('prestige')
            ->orderBy('id');
        $nations = $this->page === 'nation-list'
            ? $nationQuery->paginate(10, ['*'], 'nationPage')
            : $nationQuery->limit(3)->get();

        $selectedNation = null;
        $joinEligibility = null;
        if (! $membership && $this->selectedNationId) {
            $selectedNation = Nation::active()
                ->withCount('memberships')
                ->with([
                    'rulerMembership.character',
                    'memberships' => fn ($query) => $query->with(['character.jobClass'])
                        ->orderByRaw("CASE WHEN role = 'ruler' THEN 0 ELSE 1 END")
                        ->orderBy('joined_at'),
                ])
                ->find($this->selectedNationId);
            if ($selectedNation) {
                $joinEligibility = app(NationJoinApplicationService::class)->eligibility($character, $selectedNation);
            }
        }

        $ownPendingApplication = ! $membership
            ? NationJoinApplication::with('nation')
                ->where('character_id', $character->id)
                ->where('status', NationJoinApplication::STATUS_PENDING)
                ->first()
            : null;
        $pendingApplications = collect();
        $applicationPowers = [];
        $leaveEligibility = null;
        $activityDescriptions = [];
        $activityLogs = collect();
        $nationChatMessages = collect();
        if ($membership) {
            $membership->load([
                'nation.rulerMembership.character',
                'nation.memberships' => fn ($query) => $query->with(['character.jobClass'])
                    ->orderByRaw("CASE WHEN role = 'ruler' THEN 0 ELSE 1 END")
                    ->orderBy('joined_at'),
            ]);
            $leaveEligibility = app(NationMembershipService::class)->leaveEligibility($membership);
            if ($this->page === 'home') {
                $nationChatMessages = app(NationChatService::class)->recentFor($character);
            }

            if ($membership->isRuler()) {
                $pendingApplications = NationJoinApplication::with(['character.jobClass'])
                    ->where('nation_id', $membership->nation_id)
                    ->where('status', NationJoinApplication::STATUS_PENDING)
                    ->orderBy('requested_at')
                    ->get();
                $statusService = app(CharacterStatusService::class);
                $powerService = app(CharacterPowerService::class);
                foreach ($pendingApplications as $application) {
                    $applicationPowers[$application->id] = $powerService->fromFinalStats(
                        $statusService->getFinalStats($application->character),
                    );
                }

                $activityLogs = $membership->nation->activityLogs()
                    ->with(['actor', 'target'])
                    ->latest('id')
                    ->limit(20)
                    ->get();
                foreach ($activityLogs as $log) {
                    $activityDescriptions[$log->id] = app(NationActivityLogService::class)->description($log);
                }
            }
        }

        $confirmationTarget = $this->confirmationAction !== 'approve-application' && $this->confirmationTargetId
            ? NationMembership::with(['character', 'nation'])->find($this->confirmationTargetId)
            : null;
        $confirmationApplication = $this->confirmationAction === 'approve-application' && $this->confirmationTargetId
            ? NationJoinApplication::with(['character', 'nation'])->find($this->confirmationTargetId)
            : null;

        return view('livewire.nation-screen', [
            'character' => $character,
            'membership' => $membership,
            'nations' => $nations,
            'selectedNation' => $selectedNation,
            'joinEligibility' => $joinEligibility,
            'ownPendingApplication' => $ownPendingApplication,
            'pendingApplications' => $pendingApplications,
            'applicationPowers' => $applicationPowers,
            'leaveEligibility' => $leaveEligibility,
            'activityLogs' => $activityLogs,
            'activityDescriptions' => $activityDescriptions,
            'nationChatMessages' => $nationChatMessages,
            'confirmationTarget' => $confirmationTarget,
            'confirmationApplication' => $confirmationApplication,
            'nationTypes' => NationType::cases(),
            'foundingNationTypeOption' => NationType::tryFrom($this->foundingNationType) ?? NationType::KINGDOM,
            'emblems' => $emblemCatalog->all(),
            'foundingEmblem' => $emblemCatalog->get($this->foundingEmblemKey),
            'maxMembers' => $maxMembers,
            'cooldowns' => app(NationMembershipCooldownService::class),
            'minimumMembershipHours' => app(NationCommunitySettingsService::class)->minimumMembershipHours(),
            'leaveJoinCooldownHours' => app(NationCommunitySettingsService::class)->leaveJoinCooldownHours(),
            'expelJoinCooldownHours' => app(NationCommunitySettingsService::class)->expelJoinCooldownHours(),
            'expelSameNationCooldownDays' => app(NationCommunitySettingsService::class)->expelSameNationCooldownDays(),
            'dissolutionWaitHours' => app(NationCommunitySettingsService::class)->dissolutionWaitHours(),
        ]);
    }

    private function character(): Character
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character, 404);

        return $character;
    }

    private function currentMembership(): ?NationMembership
    {
        return NationMembership::with('nation')->where('character_id', $this->character()->id)->first();
    }

    private function rulerOrError(): ?NationMembership
    {
        $membership = $this->currentMembership();
        if (! $membership?->isRuler()) {
            $this->addError('nationAction', '統治者だけがこの操作を行えます。');

            return null;
        }

        return $membership;
    }

    private function selectedActiveNation(): ?Nation
    {
        $nation = $this->selectedNationId ? Nation::active()->find($this->selectedNationId) : null;
        if (! $nation) {
            $this->addError('nationAction', '国家が見つかりません。');
        }

        return $nation;
    }

    private function navigate(string $page, bool $keepSelection = false): void
    {
        $this->page = $page;
        $this->showFoundingEmblemModal = false;
        $this->showFoundingConfirmationModal = false;
        $this->actionMessage = null;
        $this->resetErrorBag();
        if (! $keepSelection) {
            $this->selectedNationId = null;
        }
    }

    /** @return array{foundingName: string, foundingNationType: string, foundingDescription?: string|null, foundingEmblemKey: string} */
    private function validateFoundingInput(): array
    {
        return $this->validate([
            'foundingName' => ['required', 'string', 'min:1', 'max:40'],
            'foundingNationType' => ['required', Rule::in(NationType::values())],
            'foundingDescription' => ['nullable', 'string', 'max:200'],
            'foundingEmblemKey' => ['required', Rule::in(array_keys(app(NationEmblemCatalog::class)->all()))],
        ], [
            'foundingName.required' => '国家名を入力してください。',
            'foundingName.max' => '国家名は40文字以内で入力してください。',
            'foundingDescription.max' => '国家紹介は200文字以内で入力してください。',
        ]);
    }

    private function perform(\Closure $action, string $successMessage): mixed
    {
        $this->actionMessage = null;
        $this->resetErrorBag('nationAction');

        try {
            $result = $action();
            $this->actionMessage = $successMessage;

            return $result ?? true;
        } catch (\DomainException $exception) {
            $this->addError('nationAction', $exception->getMessage());

            return false;
        }
    }

    private function rotateNationChatRequestId(): void
    {
        $this->nationChatRequestId = (string) Str::uuid();
    }
}
