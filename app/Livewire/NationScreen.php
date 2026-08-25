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
use App\Services\Nation\NationDevelopmentLevelService;
use App\Services\Nation\NationDevelopmentService;
use App\Services\Nation\NationDissolutionService;
use App\Services\Nation\NationEmblemCatalog;
use App\Services\Nation\NationHeaderBackgroundCatalog;
use App\Services\Nation\NationJoinApplicationService;
use App\Services\Nation\NationMembershipCooldownService;
use App\Services\Nation\NationMembershipService;
use App\Services\Nation\NationProfileService;
use App\Services\Nation\NationResourceService;
use App\Services\Nation\NationRulerTransferService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationShowcaseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class NationScreen extends Component
{
    use WithPagination;

    private const ACTIVITY_LOG_PREVIEW_LIMIT = 5;

    private const ACTIVITY_LOG_MODAL_LIMIT = 100;

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

    public string $profileHeaderBackgroundKey = NationHeaderBackgroundCatalog::DEFAULT_KEY;

    public bool $showHeaderBackgroundModal = false;

    public string $dissolutionConfirmation = '';

    public string $nationChatMessage = '';

    public string $nationChatRequestId = '';

    public ?int $donationMaterialId = null;

    public int $donationQuantity = 1;

    public string $donationRequestId = '';

    public bool $showDonationConfirmationModal = false;

    public bool $showActivityLogModal = false;

    /** @var array{material_id:int,quantity:int,request_id:string,name:string,remaining_quantity:int,points:int,development_exp:int}|array{} */
    #[Locked]
    public array $confirmedDonation = [];

    public function mount(): void
    {
        $this->rotateNationChatRequestId();
        $this->rotateDonationRequestId();
        app(NationChatService::class)->markRead($this->character());

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

    public function markNationChatRead(): void
    {
        app(NationChatService::class)->markRead($this->character());
        $this->dispatch('nationChatSeen');
    }

    public function openActivityLogModal(): void
    {
        if (! $this->rulerOrError()) {
            return;
        }

        $this->showActivityLogModal = true;
    }

    public function closeActivityLogModal(): void
    {
        $this->showActivityLogModal = false;
    }

    public function openHeaderBackgroundModal(): void
    {
        if ($this->page !== 'home') {
            return;
        }

        $membership = $this->rulerOrError();
        if (! $membership) {
            return;
        }

        $this->profileHeaderBackgroundKey = app(NationHeaderBackgroundCatalog::class)
            ->selectableKey($membership->nation->header_background_key);
        $this->resetErrorBag('profileHeaderBackgroundKey');
        $this->showHeaderBackgroundModal = true;
    }

    public function selectHeaderBackground(string $headerBackgroundKey): void
    {
        if (! $this->showHeaderBackgroundModal) {
            return;
        }

        if (! app(NationHeaderBackgroundCatalog::class)->exists($headerBackgroundKey)) {
            $this->addError('profileHeaderBackgroundKey', '選択した国家ヘッダ背景は使用できません。');

            return;
        }

        $this->profileHeaderBackgroundKey = $headerBackgroundKey;
        $this->resetErrorBag('profileHeaderBackgroundKey');
    }

    public function closeHeaderBackgroundModal(): void
    {
        $this->showHeaderBackgroundModal = false;
        $this->resetErrorBag('profileHeaderBackgroundKey');
    }

    public function saveHeaderBackground(): void
    {
        if (! $this->showHeaderBackgroundModal) {
            return;
        }

        $validated = $this->validate([
            'profileHeaderBackgroundKey' => [
                'required',
                Rule::in(array_keys(app(NationHeaderBackgroundCatalog::class)->all())),
            ],
        ]);
        $membership = $this->rulerOrError();
        if (! $membership) {
            return;
        }

        $nation = $this->perform(
            fn () => app(NationProfileService::class)->updateHeaderBackground(
                $membership,
                $validated['profileHeaderBackgroundKey'],
            ),
            '国家ヘッダ背景を更新しました。',
        );
        if ($nation) {
            $this->showHeaderBackgroundModal = false;
        }
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

    public function showResourceManagement(): void
    {
        if (! config('features.nation_development_enabled', false)) {
            $this->showNotImplemented('resource-management');

            return;
        }
        if (! $this->currentMembership()) {
            $this->addError('nationAction', '国家へ所属していません。');

            return;
        }

        $materials = app(NationResourceService::class)->donatableMaterials($this->character());
        $this->donationMaterialId = $materials->first()?->material_id;
        $this->donationQuantity = 1;
        $this->showDonationConfirmationModal = false;
        $this->navigate('resources');
    }

    public function openDonationConfirmation(): void
    {
        $this->confirmedDonation = [];
        if ($this->page !== 'resources' || ! config('features.nation_development_enabled', false)) {
            return;
        }

        $validated = $this->validateDonationInput();
        $material = app(NationResourceService::class)->donatableMaterial($this->character(), $validated['donationMaterialId']);
        if (! $material) {
            $this->addError('donationMaterialId', '納品できる素材が見つかりません。');

            return;
        }
        if ((int) $material->quantity < $validated['donationQuantity']) {
            $this->addError('donationQuantity', '素材の所持数が足りません。');

            return;
        }

        $this->confirmedDonation = [
            'material_id' => (int) $material->material_id,
            'quantity' => $validated['donationQuantity'],
            'request_id' => $validated['donationRequestId'],
            'name' => (string) $material->name,
            'remaining_quantity' => (int) $material->quantity - $validated['donationQuantity'],
            'points' => $validated['donationQuantity'] * (int) $material->points_per_unit,
            'development_exp' => $validated['donationQuantity'] * (int) $material->development_exp_per_unit,
        ];
        $this->showDonationConfirmationModal = true;
    }

    public function closeDonationConfirmation(): void
    {
        $this->showDonationConfirmationModal = false;
        $this->confirmedDonation = [];
    }

    public function donateMaterials(): void
    {
        if (! $this->showDonationConfirmationModal || $this->page !== 'resources' || $this->confirmedDonation === []) {
            return;
        }

        $confirmed = $this->confirmedDonation;
        $this->showDonationConfirmationModal = false;
        $transaction = $this->perform(
            fn () => app(NationResourceService::class)->donate(
                $this->character(),
                $confirmed['material_id'],
                $confirmed['quantity'],
                $confirmed['request_id'],
            ),
            '国家へ資材を納品しました。',
        );
        $this->confirmedDonation = [];
        if ($transaction) {
            $this->actionMessage = '国家へ資材を納品しました。国家資材 +'.number_format((int) $transaction->points_delta)
                .'pt / 国家発展EXP +'.number_format((int) $transaction->development_exp_delta);
            $this->donationQuantity = 1;
            $this->rotateDonationRequestId();
        }
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
            $this->markNationChatRead();
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
        $headerBackgroundCatalog = app(NationHeaderBackgroundCatalog::class);
        $maxMembers = app(NationCommunitySettingsService::class)->maxMembers();
        $developmentEnabled = (bool) config('features.nation_development_enabled', false);
        $levelService = app(NationDevelopmentLevelService::class);
        $nationQuery = Nation::active()
            ->withCount('memberships')
            ->with(['rulerMembership.character'])
            ->orderByDesc('recruitment_enabled')
            ->orderByDesc('prestige')
            ->orderBy('id');
        $activeNationCount = 0;
        if ($this->page === 'nation-list') {
            $nations = $nationQuery->paginate(10, ['*'], 'nationPage');
        } else {
            $showcase = app(NationShowcaseService::class)->dailySelection();
            $showcaseOrder = array_flip($showcase['nation_ids']);
            $activeNationCount = $showcase['total'];
            $nations = $nationQuery
                ->whereIn('id', $showcase['nation_ids'])
                ->get()
                ->sortBy(static fn (Nation $nation): int => $showcaseOrder[$nation->id] ?? PHP_INT_MAX)
                ->values();
        }
        $nationRows = method_exists($nations, 'items') ? $nations->items() : $nations->all();
        $nationLevels = collect($nationRows)
            ->mapWithKeys(fn (Nation $nation): array => [$nation->id => $levelService->levelFor((int) $nation->development_exp)])
            ->all();

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
                $nationLevels[$selectedNation->id] = $levelService->levelFor((int) $selectedNation->development_exp);
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
        $activityLogModalEntries = collect();
        $activityLogTotal = 0;
        $nationChatMessages = collect();
        $developmentProgress = null;
        $personalContribution = 0;
        $contributionRows = collect();
        $donatableMaterials = collect();
        $donationPreview = null;
        if ($membership) {
            $membership->load([
                'nation.rulerMembership.character',
                'nation.memberships' => fn ($query) => $query->with(['character.jobClass'])
                    ->orderByRaw("CASE WHEN role = 'ruler' THEN 0 ELSE 1 END")
                    ->orderBy('joined_at'),
            ]);
            $leaveEligibility = app(NationMembershipService::class)->leaveEligibility($membership);
            if ($developmentEnabled) {
                $development = app(NationDevelopmentService::class);
                $developmentProgress = $levelService->progress((int) $membership->nation->development_exp);
                $personalContribution = $development->personalContribution($membership->nation, $character);
                if ($this->page === 'resources') {
                    $resources = app(NationResourceService::class);
                    $donatableMaterials = $resources->donatableMaterials($character);
                    $contributionRows = $development->contributionRows($membership->nation);
                    if ($this->donationMaterialId) {
                        $donationPreview = $resources->donatableMaterial($character, $this->donationMaterialId);
                    }
                }
            }
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

                $activityLogTotal = $membership->nation->activityLogs()->count();
                $activityLogs = $membership->nation->activityLogs()
                    ->with(['actor', 'target'])
                    ->latest('id')
                    ->limit(self::ACTIVITY_LOG_PREVIEW_LIMIT)
                    ->get();
                if ($this->showActivityLogModal) {
                    $activityLogModalEntries = $membership->nation->activityLogs()
                        ->with(['actor', 'target'])
                        ->latest('id')
                        ->limit(self::ACTIVITY_LOG_MODAL_LIMIT)
                        ->get();
                }
                foreach ($activityLogs->concat($activityLogModalEntries)->unique('id') as $log) {
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
            'activityLogModalEntries' => $activityLogModalEntries,
            'activityDescriptions' => $activityDescriptions,
            'activityLogTotal' => $activityLogTotal,
            'activityLogPreviewLimit' => self::ACTIVITY_LOG_PREVIEW_LIMIT,
            'activityLogModalLimit' => self::ACTIVITY_LOG_MODAL_LIMIT,
            'nationChatMessages' => $nationChatMessages,
            'developmentEnabled' => $developmentEnabled,
            'developmentProgress' => $developmentProgress,
            'personalContribution' => $personalContribution,
            'contributionRows' => $contributionRows,
            'donatableMaterials' => $donatableMaterials,
            'donationPreview' => $donationPreview,
            'nationLevels' => $nationLevels,
            'confirmationTarget' => $confirmationTarget,
            'confirmationApplication' => $confirmationApplication,
            'nationTypes' => NationType::cases(),
            'foundingNationTypeOption' => NationType::tryFrom($this->foundingNationType) ?? NationType::KINGDOM,
            'emblems' => $emblemCatalog->all(),
            'foundingEmblem' => $emblemCatalog->get($this->foundingEmblemKey),
            'headerBackgrounds' => $headerBackgroundCatalog->all(),
            'maxMembers' => $maxMembers,
            'activeNationCount' => $activeNationCount,
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
        $this->showDonationConfirmationModal = false;
        $this->showActivityLogModal = false;
        $this->showHeaderBackgroundModal = false;
        $this->confirmedDonation = [];
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

    /** @return array{donationMaterialId:int,donationQuantity:int,donationRequestId:string} */
    private function validateDonationInput(): array
    {
        return $this->validate([
            'donationMaterialId' => ['required', 'integer'],
            'donationQuantity' => ['required', 'integer', 'min:1'],
            'donationRequestId' => ['required', 'uuid'],
        ], [
            'donationMaterialId.required' => '納品する素材を選んでください。',
            'donationQuantity.required' => '納品数を入力してください。',
            'donationQuantity.min' => '納品数は1以上で指定してください。',
            'donationRequestId.required' => '納品情報を更新するため、画面を再読み込みしてください。',
            'donationRequestId.uuid' => '納品情報を更新するため、画面を再読み込みしてください。',
        ]);
    }

    private function rotateDonationRequestId(): void
    {
        $this->donationRequestId = (string) Str::uuid();
    }
}
