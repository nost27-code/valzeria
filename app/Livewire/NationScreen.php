<?php

namespace App\Livewire;

use App\Enums\NationType;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationAchievement;
use App\Models\NationFacility;
use App\Models\NationGoal;
use App\Models\NationJoinApplication;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationWarPreparationPreset;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\Nation\NationAchievementService;
use App\Services\Nation\NationActivityLogService;
use App\Services\Nation\NationChatService;
use App\Services\Nation\NationCommunitySettingsService;
use App\Services\Nation\NationDecorationCatalog;
use App\Services\Nation\NationDecorationService;
use App\Services\Nation\NationDevelopmentLevelService;
use App\Services\Nation\NationDevelopmentService;
use App\Services\Nation\NationDissolutionService;
use App\Services\Nation\NationDonationAnalyticsService;
use App\Services\Nation\NationEmblemCatalog;
use App\Services\Nation\NationGoalService;
use App\Services\Nation\NationHeaderBackgroundCatalog;
use App\Services\Nation\NationJoinApplicationService;
use App\Services\Nation\NationLevelBenefitSettingsService;
use App\Services\Nation\NationMembershipCooldownService;
use App\Services\Nation\NationMembershipService;
use App\Services\Nation\NationProfileService;
use App\Services\Nation\NationResourceService;
use App\Services\Nation\NationRoleService;
use App\Services\Nation\NationRulerTransferService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationShowcaseService;
use App\Services\Nation\NationTimelineService;
use App\Services\Nation\NationWantedMaterialService;
use App\Services\Nation\NationWarPreparationPresetService;
use App\Services\Nation\NationWarSettingsService;
use Illuminate\Support\Collection;
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

    private const MEMBER_PREVIEW_LIMIT = 9;

    private const MEMBER_SORT_DEFAULT = 'ruler_joined';

    private const MEMBER_SORT_OPTIONS = [
        'ruler_joined' => '国王優先・加入順',
        'joined_desc' => '加入が新しい順',
        'level_desc' => 'Lvが高い順',
        'level_asc' => 'Lvが低い順',
        'name_asc' => '名前順',
    ];

    private const NATION_CHAT_PREVIEW_LIMIT = 3;

    private const ACTIVITY_LOG_PREVIEW_LIMIT = 2;

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

    public bool $showMemberListModal = false;

    public bool $showNationChatModal = false;

    public bool $showNationMenuModal = false;

    public string $memberSort = self::MEMBER_SORT_DEFAULT;

    public string $dissolutionConfirmation = '';

    public string $nationChatMessage = '';

    public string $nationChatRequestId = '';

    /** @var array<int|string, int> */
    public array $donationQuantities = [];

    public string $donationRequestId = '';

    public bool $showDonationMaterialModal = false;

    public bool $showDonationConfirmationModal = false;

    public bool $showActivityLogModal = false;

    public string $goalTitle = '';

    public string $goalDescription = '';

    public string $goalMetricType = 'material_quantity';

    public ?int $goalMaterialId = null;

    public string $goalFacilityType = '';

    public ?int $goalTargetValue = null;

    public string $goalDeadlineAt = '';

    /** @var array<int, int|string|null> */
    public array $wantedMaterialIds = [];

    /** @var array<int, string> */
    public array $wantedMaterialNotes = [];

    /** @var list<string> */
    public array $showcaseAchievementKeys = [];

    /** @var array<string, string|null> */
    public array $decorationSettings = [];

    public string $warPresetName = '';

    public int $warPresetPoolPoints = 0;

    public int $warPresetFacilityPoints = 0;

    public int $warPresetReservePoints = 0;

    /** @var list<string> */
    public array $warPresetFacilityPriority = [];

    /** @var array{request_id:string,items:list<array{material_id:int,quantity:int,name:string,remaining_quantity:int,points:int,development_exp:int}>,material_count:int,total_quantity:int,points:int,development_exp:int}|array{} */
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

    public function openNationChatModal(): void
    {
        if ($this->page === 'home' && $this->currentMembership()) {
            $this->showNationChatModal = true;
        }
    }

    public function closeNationChatModal(): void
    {
        $this->showNationChatModal = false;
    }

    public function openNationMenuModal(): void
    {
        if ($this->page === 'home' && $this->currentMembership()) {
            $this->showNationMenuModal = true;
        }
    }

    public function closeNationMenuModal(): void
    {
        $this->showNationMenuModal = false;
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

    public function openMemberListModal(): void
    {
        $nation = $this->memberListNation();
        if (! $nation || $nation->memberships()->count() <= self::MEMBER_PREVIEW_LIMIT) {
            return;
        }

        $this->showMemberListModal = true;
    }

    public function closeMemberListModal(): void
    {
        $this->showMemberListModal = false;
    }

    public function updatedMemberSort(string $memberSort): void
    {
        if (! array_key_exists($memberSort, self::MEMBER_SORT_OPTIONS)) {
            $this->memberSort = self::MEMBER_SORT_DEFAULT;
        }
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
        $nation = Nation::active()->publiclyVisible()->find($nationId);
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

        $this->donationQuantities = [];
        $this->showDonationMaterialModal = false;
        $this->showDonationConfirmationModal = false;
        $this->navigate('resources');
    }

    public function showDevelopmentBenefits(): void
    {
        if ($this->levelBenefitsEnabledOrError() && $this->memberOrError()) {
            $this->navigate('benefits');
        }
    }

    public function showNationGoals(): void
    {
        if ($this->levelBenefitsEnabledOrError() && $this->memberOrError()) {
            $this->navigate('goals');
        }
    }

    public function createNationGoal(): void
    {
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $goal = $this->perform(fn () => app(NationGoalService::class)->create($membership, [
            'title' => $this->goalTitle,
            'description' => $this->goalDescription,
            'metric_type' => $this->goalMetricType,
            'material_id' => $this->goalMaterialId,
            'facility_type' => $this->goalFacilityType,
            'target_value' => $this->goalTargetValue,
            'deadline_at' => $this->goalDeadlineAt,
        ]), '共同目標を設定しました。');
        if ($goal) {
            $this->goalTitle = '';
            $this->goalDescription = '';
            $this->goalTargetValue = null;
            $this->goalDeadlineAt = '';
        }
    }

    public function completeManualGoal(int $goalId): void
    {
        $membership = $this->memberOrError();
        $goal = NationGoal::find($goalId);
        if (! $membership || ! $goal) {
            return;
        }
        $this->perform(fn () => app(NationGoalService::class)->completeManual($membership, $goal), '共同目標を達成済みにしました。');
    }

    public function cancelNationGoal(int $goalId): void
    {
        $membership = $this->memberOrError();
        $goal = NationGoal::find($goalId);
        if (! $membership || ! $goal) {
            return;
        }
        $this->perform(fn () => app(NationGoalService::class)->cancel($membership, $goal), '共同目標を取り下げました。');
    }

    public function showWantedMaterials(): void
    {
        if (! $this->levelBenefitsEnabledOrError()) {
            return;
        }
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $wanted = app(NationWantedMaterialService::class)->activeFor($membership->nation);
        $this->wantedMaterialIds = $wanted->pluck('material_id')->map(static fn ($id): int => (int) $id)->all();
        $this->wantedMaterialNotes = $wanted->pluck('purpose_note')->map(static fn ($note): string => (string) $note)->all();
        $level = app(NationDevelopmentLevelService::class)->levelFor((int) $membership->nation->development_exp);
        $slots = app(NationDevelopmentLevelService::class)->benefitsForLevel($level)['wanted_material_slots'];
        while (count($this->wantedMaterialIds) < $slots) {
            $this->wantedMaterialIds[] = null;
            $this->wantedMaterialNotes[] = '';
        }
        $this->navigate('wanted-materials');
    }

    public function saveWantedMaterials(): void
    {
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $materials = [];
        foreach ($this->wantedMaterialIds as $index => $materialId) {
            if ((int) $materialId < 1) {
                continue;
            }
            $materials[] = [
                'material_id' => (int) $materialId,
                'purpose_note' => (string) ($this->wantedMaterialNotes[$index] ?? ''),
            ];
        }
        $this->perform(fn () => app(NationWantedMaterialService::class)->replace($membership, $materials), '募集素材を更新しました。');
    }

    public function showNationAchievements(): void
    {
        if (! $this->levelBenefitsEnabledOrError()) {
            return;
        }
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $this->showcaseAchievementKeys = app(NationAchievementService::class)
            ->displayedFor($membership->nation)
            ->pluck('achievement_key')
            ->all();
        $this->navigate('achievements');
    }

    public function saveAchievementShowcase(): void
    {
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $this->perform(
            fn () => app(NationAchievementService::class)->setShowcase($membership, $this->showcaseAchievementKeys),
            '国家実績の展示を更新しました。',
        );
    }

    public function showNationDecorations(): void
    {
        if (! $this->levelBenefitsEnabledOrError()) {
            return;
        }
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $this->decorationSettings = is_array($membership->nation->decoration_settings)
            ? $membership->nation->decoration_settings
            : [];
        $this->navigate('decorations');
    }

    public function saveNationDecorations(): void
    {
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $this->perform(
            fn () => app(NationDecorationService::class)->save($membership, $this->decorationSettings),
            '国家装飾を更新しました。',
        );
    }

    public function showDonationAnalytics(): void
    {
        if ($this->levelBenefitsEnabledOrError() && $this->memberOrError()) {
            $this->navigate('analytics');
        }
    }

    public function showNationTimeline(): void
    {
        if ($this->levelBenefitsEnabledOrError() && $this->memberOrError()) {
            $this->navigate('timeline');
        }
    }

    public function showWarPreparationPresets(): void
    {
        if (! $this->levelBenefitsEnabledOrError()) {
            return;
        }
        if (! app(NationWarSettingsService::class)->featureEnabled()) {
            $this->showNotImplemented('war-strategy');

            return;
        }
        if ($this->memberOrError()) {
            $this->warPresetFacilityPriority = NationFacility::TYPES;
            $this->navigate('war-presets');
        }
    }

    public function saveWarPreparationPreset(): void
    {
        $membership = $this->memberOrError();
        if (! $membership) {
            return;
        }
        $preset = $this->perform(fn () => app(NationWarPreparationPresetService::class)->save($membership, [
            'name' => $this->warPresetName,
            'pool_contribution_points' => $this->warPresetPoolPoints,
            'facility_upgrade_limit_points' => $this->warPresetFacilityPoints,
            'facility_priority' => $this->warPresetFacilityPriority,
            'repair_reserve_warning_points' => $this->warPresetReservePoints,
        ]), '戦争準備プリセットを保存しました。');
        if ($preset) {
            $this->warPresetName = '';
            $this->warPresetPoolPoints = 0;
            $this->warPresetFacilityPoints = 0;
            $this->warPresetReservePoints = 0;
        }
    }

    public function moveWarPresetFacilityPriority(string $facilityType, string $direction): void
    {
        if ($this->page !== 'war-presets'
            || ! in_array($facilityType, NationFacility::TYPES, true)
            || ! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $priority = array_values(array_unique(array_filter(
            array_map('strval', $this->warPresetFacilityPriority),
            static fn (string $type): bool => in_array($type, NationFacility::TYPES, true),
        )));
        foreach (NationFacility::TYPES as $type) {
            if (! in_array($type, $priority, true)) {
                $priority[] = $type;
            }
        }
        $index = array_search($facilityType, $priority, true);
        if ($index === false) {
            return;
        }
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($priority[$targetIndex])) {
            return;
        }
        [$priority[$index], $priority[$targetIndex]] = [$priority[$targetIndex], $priority[$index]];
        $this->warPresetFacilityPriority = $priority;
    }

    public function deleteWarPreparationPreset(int $presetId): void
    {
        $membership = $this->memberOrError();
        $preset = NationWarPreparationPreset::find($presetId);
        if (! $membership || ! $preset) {
            return;
        }
        $this->perform(fn () => app(NationWarPreparationPresetService::class)->delete($membership, $preset), '戦争準備プリセットを削除しました。');
    }

    public function openDonationMaterialModal(): void
    {
        if ($this->page !== 'resources' || ! config('features.nation_development_enabled', false)) {
            return;
        }

        $this->resetErrorBag(['donationQuantities', 'donationQuantities.*']);
        $this->showDonationMaterialModal = true;
    }

    public function closeDonationMaterialModal(): void
    {
        $this->showDonationMaterialModal = false;
    }

    public function selectDonationMaterial(int $materialId): void
    {
        $material = $this->donatableMaterialForSelection($materialId);
        if (! $material) {
            return;
        }

        $materialId = (int) $material->material_id;
        if ((int) ($this->donationQuantities[$materialId] ?? 0) < 1) {
            $this->donationQuantities[$materialId] = 1;
        }
    }

    public function incrementDonationQuantity(int $materialId): void
    {
        $material = $this->donatableMaterialForSelection($materialId);
        if (! $material) {
            return;
        }

        $materialId = (int) $material->material_id;
        $currentQuantity = max(0, (int) ($this->donationQuantities[$materialId] ?? 0));
        $this->donationQuantities[$materialId] = min((int) $material->quantity, $currentQuantity + 1);
    }

    public function decrementDonationQuantity(int $materialId): void
    {
        $currentQuantity = max(0, (int) ($this->donationQuantities[$materialId] ?? 0));
        if ($currentQuantity < 1) {
            return;
        }

        if ($currentQuantity === 1) {
            unset($this->donationQuantities[$materialId]);

            return;
        }

        $this->donationQuantities[$materialId] = $currentQuantity - 1;
    }

    public function openDonationConfirmation(): void
    {
        $this->confirmedDonation = [];
        if ($this->page !== 'resources' || ! config('features.nation_development_enabled', false)) {
            return;
        }

        $validated = $this->validateDonationInput();
        $selectedQuantities = [];
        foreach ($validated['donationQuantities'] as $materialId => $quantity) {
            $materialIdText = (string) $materialId;
            if (preg_match('/^[1-9][0-9]*$/D', $materialIdText) !== 1) {
                $this->addError('donationQuantities', '納品する素材が不正です。');

                return;
            }
            $selectedQuantities[(int) $materialIdText] = (int) $quantity;
        }
        ksort($selectedQuantities, SORT_NUMERIC);

        $materials = app(NationResourceService::class)->donatableMaterials($this->character())->keyBy('material_id');
        $items = [];
        $totalQuantity = 0;
        $totalPoints = 0;
        $totalDevelopmentExp = 0;
        foreach ($selectedQuantities as $materialId => $quantity) {
            $material = $materials->get($materialId);
            if (! $material) {
                $this->addError('donationQuantities', '納品できない素材が含まれています。');

                return;
            }
            if ((int) $material->quantity < $quantity) {
                $this->addError('donationQuantities', $material->name.'の所持数が足りません。');

                return;
            }

            $points = $quantity * (int) $material->points_per_unit;
            $developmentExp = $quantity * (int) $material->development_exp_per_unit;
            $items[] = [
                'material_id' => $materialId,
                'quantity' => $quantity,
                'name' => (string) $material->name,
                'remaining_quantity' => (int) $material->quantity - $quantity,
                'points' => $points,
                'development_exp' => $developmentExp,
            ];
            $totalQuantity += $quantity;
            $totalPoints += $points;
            $totalDevelopmentExp += $developmentExp;
        }

        $this->confirmedDonation = [
            'request_id' => $validated['donationRequestId'],
            'items' => $items,
            'material_count' => count($items),
            'total_quantity' => $totalQuantity,
            'points' => $totalPoints,
            'development_exp' => $totalDevelopmentExp,
        ];
        $this->showDonationMaterialModal = false;
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
        $donations = [];
        foreach ($confirmed['items'] as $item) {
            $donations[$item['material_id']] = $item['quantity'];
        }
        $transactions = $this->perform(
            fn () => app(NationResourceService::class)->donateBatch($this->character(), $donations, $confirmed['request_id']),
            '国家へ資材を納品しました。',
        );
        $this->confirmedDonation = [];
        if ($transactions) {
            $membership = $this->currentMembership();
            if ($membership && app(NationLevelBenefitSettingsService::class)->enabled()) {
                app(NationGoalService::class)->sync($membership->nation);
            }
            $this->actionMessage = '国家へ資材を納品しました。国家資材 +'.number_format($confirmed['points'])
                .'pt / 国家発展EXP +'.number_format($confirmed['development_exp']);
            $this->donationQuantities = [];
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
            $this->showNationMenuModal = false;
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
        $communitySettings = app(NationCommunitySettingsService::class);
        $maxMembers = $communitySettings->maxMembers();
        $developmentEnabled = (bool) config('features.nation_development_enabled', false);
        $levelBenefitsEnabled = $developmentEnabled
            && app(NationLevelBenefitSettingsService::class)->enabled();
        if (! $levelBenefitsEnabled && in_array($this->page, [
            'benefits',
            'goals',
            'wanted-materials',
            'achievements',
            'decorations',
            'analytics',
            'timeline',
            'war-presets',
        ], true)) {
            $this->page = 'home';
        }
        $developmentLevelService = app(NationDevelopmentLevelService::class);
        $nationQuery = Nation::active()
            ->publiclyVisible()
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

        $selectedNation = null;
        $joinEligibility = null;
        if ($this->selectedNationId) {
            $selectedNation = Nation::active()
                ->publiclyVisible()
                ->withCount('memberships')
                ->with([
                    'rulerMembership.character',
                    'memberships' => fn ($query) => $query->with(['character.jobClass'])
                        ->orderByRaw("CASE WHEN role = 'ruler' THEN 0 ELSE 1 END")
                        ->orderBy('joined_at'),
                ])
                ->find($this->selectedNationId);
            if ($selectedNation) {
                $selectedNation->setRelation(
                    'memberships',
                    $this->sortMemberships($selectedNation->memberships),
                );
            }
            if ($selectedNation && ! $membership) {
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
        $donationPreviews = collect();
        $donationSummary = [
            'material_count' => 0,
            'total_quantity' => 0,
            'points' => 0,
            'development_exp' => 0,
        ];
        $developmentBenefits = null;
        $nextDevelopmentBenefit = null;
        $developmentBenefitMilestones = collect();
        $activeGoals = collect();
        $goalHistory = null;
        $wantedMaterials = collect();
        $allAchievements = collect();
        $displayedAchievements = collect();
        $donationAnalytics = null;
        $donationMaterialBreakdown = collect();
        $donationTierSummary = null;
        $donationDailyTrend = collect();
        $donationWeeklyTrend = collect();
        $wantedMaterialProgress = collect();
        $timelineEntries = collect();
        $timelineDescriptions = [];
        $warPreparationPresets = collect();
        $canManageGoals = false;
        $canManageWantedMaterials = false;
        $canManageShowcase = false;
        $canManageDecorations = false;
        $canManageWarPresets = false;
        $selectedNationGoals = collect();
        $selectedNationWantedMaterials = collect();
        $selectedNationAchievements = collect();
        $selectedNationTimeline = collect();
        $selectedNationTimelineDescriptions = [];
        if ($membership) {
            $membership->load([
                'nation.rulerMembership.character',
                'nation.memberships' => fn ($query) => $query->with(['character.jobClass'])
                    ->orderByRaw("CASE WHEN role = 'ruler' THEN 0 ELSE 1 END")
                    ->orderBy('joined_at'),
            ]);
            if ($this->page === 'home') {
                $membership->nation->setRelation(
                    'memberships',
                    $this->sortMemberships($membership->nation->memberships),
                );
            }
            $leaveEligibility = app(NationMembershipService::class)->leaveEligibility($membership);
            if ($developmentEnabled) {
                $development = app(NationDevelopmentService::class);
                $developmentProgress = $developmentLevelService->progress((int) $membership->nation->development_exp);
                $maxMembers = $communitySettings->maxMembersFor($membership->nation);
                $personalContribution = $development->personalContribution($membership->nation, $character);
                if ($levelBenefitsEnabled) {
                    $developmentBenefits = $developmentLevelService->benefitsForLevel($developmentProgress['level']);
                    $nextDevelopmentBenefit = $developmentLevelService->nextBenefitAfterLevel($developmentProgress['level']);
                    $developmentBenefitMilestones = collect(config('nation_development.benefit_milestones', []))
                        ->mapWithKeys(fn (array $benefit, int|string $level): array => [
                            (int) $level => [
                                ...$developmentLevelService->benefitsForLevel((int) $level),
                                'label' => (string) ($benefit['label'] ?? ''),
                            ],
                        ]);
                    $roles = app(NationRoleService::class);
                    $canManageGoals = $roles->allows($membership, 'manage_nation_goals');
                    $canManageWantedMaterials = $roles->allows($membership, 'manage_wanted_materials');
                    $canManageShowcase = $roles->allows($membership, 'manage_showcase');
                    $canManageDecorations = $roles->allows($membership, 'manage_decorations');
                    $canManageWarPresets = $roles->allows($membership, 'manage_war_presets');

                    if (in_array($this->page, ['home', 'goals'], true)) {
                        $activeGoals = app(NationGoalService::class)->activeWithProgress($membership->nation);
                    }
                    if ($this->page === 'goals') {
                        $goalHistory = app(NationGoalService::class)->historyQuery($membership->nation)
                            ->paginate(20, ['*'], 'goalHistoryPage');
                    }
                    if (in_array($this->page, ['home', 'resources', 'wanted-materials', 'analytics'], true)) {
                        $wantedMaterials = app(NationWantedMaterialService::class)->activeFor($membership->nation);
                    }
                    if (in_array($this->page, ['home', 'achievements'], true)) {
                        $displayedAchievements = app(NationAchievementService::class)->displayedFor($membership->nation);
                    }
                    if ($this->page === 'achievements') {
                        $allAchievements = NationAchievement::where('nation_id', $membership->nation_id)
                            ->orderByDesc('unlocked_at')
                            ->orderByDesc('id')
                            ->get();
                    }
                    if ($this->page === 'analytics' && $developmentProgress['level'] >= 20) {
                        $analytics = app(NationDonationAnalyticsService::class);
                        $donationAnalytics = $analytics->summary($membership->nation);
                        if ($developmentProgress['level'] >= 35) {
                            $donationMaterialBreakdown = $analytics->materialBreakdown($membership->nation);
                            $donationTierSummary = $analytics->tierSummary($membership->nation);
                            $donationDailyTrend = $analytics->dailyTrend($membership->nation);
                            $donationWeeklyTrend = $analytics->weeklyTrend($membership->nation);
                            $wantedMaterialProgress = $analytics->wantedMaterialProgress($membership->nation);
                        }
                    }
                    if (in_array($this->page, ['home', 'timeline'], true)) {
                        $timeline = app(NationTimelineService::class);
                        $timelineEntries = $timeline->entries($membership->nation, $this->page === 'timeline' ? 100 : 1);
                        foreach ($timelineEntries as $entry) {
                            $timelineDescriptions[$entry->id] = $timeline->description($entry);
                        }
                    }
                    if ($this->page === 'war-presets' && app(NationWarSettingsService::class)->featureEnabled()) {
                        $warPreparationPresets = app(NationWarPreparationPresetService::class)->forNation($membership->nation);
                    }
                }
                if ($this->page === 'resources') {
                    $resources = app(NationResourceService::class);
                    $donatableMaterials = $resources->donatableMaterials($character);
                    $contributionRows = $development->contributionRows($membership->nation);
                    foreach ($donatableMaterials as $material) {
                        $selectedQuantity = max(0, (int) ($this->donationQuantities[$material->material_id] ?? 0));
                        if ($selectedQuantity < 1) {
                            continue;
                        }

                        $donationPreviews->push($material);
                        $donationSummary['material_count']++;
                        $donationSummary['total_quantity'] += $selectedQuantity;
                        $donationSummary['points'] += $selectedQuantity * (int) $material->points_per_unit;
                        $donationSummary['development_exp'] += $selectedQuantity * (int) $material->development_exp_per_unit;
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

        $nationLevels = [];
        $nationCapacities = [];
        if ($developmentEnabled) {
            foreach ($nations as $listedNation) {
                $nationLevels[$listedNation->id] = $developmentLevelService->levelFor((int) $listedNation->development_exp);
                $nationCapacities[$listedNation->id] = $communitySettings->maxMembersFor($listedNation);
            }
            if ($selectedNation) {
                $nationLevels[$selectedNation->id] = $developmentLevelService->levelFor((int) $selectedNation->development_exp);
                $nationCapacities[$selectedNation->id] = $communitySettings->maxMembersFor($selectedNation);
                if ($levelBenefitsEnabled && $this->page === 'detail') {
                    $selectedNationGoals = app(NationGoalService::class)->activeWithProgress($selectedNation);
                    $selectedNationWantedMaterials = app(NationWantedMaterialService::class)->activeFor($selectedNation);
                    $selectedNationAchievements = app(NationAchievementService::class)->displayedFor($selectedNation);
                    $selectedTimelineService = app(NationTimelineService::class);
                    $selectedNationTimeline = $selectedTimelineService->entries($selectedNation, 5);
                    foreach ($selectedNationTimeline as $entry) {
                        $selectedNationTimelineDescriptions[$entry->id] = $selectedTimelineService->description($entry);
                    }
                }
            }
            if ($membership) {
                $nationLevels[$membership->nation_id] = $developmentLevelService->levelFor((int) $membership->nation->development_exp);
                $nationCapacities[$membership->nation_id] = $communitySettings->maxMembersFor($membership->nation);
            }
        } else {
            foreach ($nations as $listedNation) {
                $nationCapacities[$listedNation->id] = $communitySettings->maxMembersFor($listedNation);
            }
            if ($selectedNation) {
                $nationCapacities[$selectedNation->id] = $communitySettings->maxMembersFor($selectedNation);
            }
            if ($membership) {
                $nationCapacities[$membership->nation_id] = $communitySettings->maxMembersFor($membership->nation);
                $maxMembers = $nationCapacities[$membership->nation_id];
            }
        }

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
            'nationChatPreviewLimit' => self::NATION_CHAT_PREVIEW_LIMIT,
            'developmentProgress' => $developmentProgress,
            'levelBenefitsEnabled' => $levelBenefitsEnabled,
            'developmentBenefits' => $developmentBenefits,
            'nextDevelopmentBenefit' => $nextDevelopmentBenefit,
            'developmentBenefitMilestones' => $developmentBenefitMilestones,
            'personalContribution' => $personalContribution,
            'activeGoals' => $activeGoals,
            'goalHistory' => $goalHistory,
            'wantedMaterials' => $wantedMaterials,
            'allAchievements' => $allAchievements,
            'displayedAchievements' => $displayedAchievements,
            'achievementCatalog' => app(NationAchievementService::class)->catalog(),
            'decorationCatalog' => app(NationDecorationCatalog::class),
            'decorationTypeLabels' => [
                'outer_frame' => '外枠',
                'name_plate' => '国家名プレート',
                'header_ornament' => 'ヘッダ装飾',
                'emblem_frame' => '紋章枠',
                'level_badge' => '徽章',
                'divider' => '飾り罫',
            ],
            'nationFacilityTypeLabels' => [
                'wall' => '城壁',
                'magic_cannon' => '魔導砲',
                'logistics' => '兵站所',
                'arsenal' => '要塞工廠',
                'headquarters' => '本陣',
            ],
            'donationAnalytics' => $donationAnalytics,
            'donationMaterialBreakdown' => $donationMaterialBreakdown,
            'donationTierSummary' => $donationTierSummary,
            'donationDailyTrend' => $donationDailyTrend,
            'donationWeeklyTrend' => $donationWeeklyTrend,
            'wantedMaterialProgress' => $wantedMaterialProgress,
            'timelineEntries' => $timelineEntries,
            'timelineDescriptions' => $timelineDescriptions,
            'warPreparationPresets' => $warPreparationPresets,
            'selectedNationGoals' => $selectedNationGoals,
            'selectedNationWantedMaterials' => $selectedNationWantedMaterials,
            'selectedNationAchievements' => $selectedNationAchievements,
            'selectedNationTimeline' => $selectedNationTimeline,
            'selectedNationTimelineDescriptions' => $selectedNationTimelineDescriptions,
            'canManageGoals' => $canManageGoals,
            'canManageWantedMaterials' => $canManageWantedMaterials,
            'canManageShowcase' => $canManageShowcase,
            'canManageDecorations' => $canManageDecorations,
            'canManageWarPresets' => $canManageWarPresets,
            'contributionRows' => $contributionRows,
            'donatableMaterials' => $donatableMaterials,
            'donationPreviews' => $donationPreviews,
            'donationSummary' => $donationSummary,
            'confirmationTarget' => $confirmationTarget,
            'confirmationApplication' => $confirmationApplication,
            'nationTypes' => NationType::cases(),
            'foundingNationTypeOption' => NationType::tryFrom($this->foundingNationType) ?? NationType::KINGDOM,
            'emblems' => $emblemCatalog->all(),
            'foundingEmblem' => $emblemCatalog->get($this->foundingEmblemKey),
            'headerBackgrounds' => $headerBackgroundCatalog->all(),
            'maxMembers' => $maxMembers,
            'activeNationCount' => $activeNationCount,
            'developmentEnabled' => $developmentEnabled,
            'nationLevels' => $nationLevels,
            'nationCapacities' => $nationCapacities,
            'nationMaterialOptions' => NationMaterialConversionRate::query()
                ->with('material')
                ->where('is_active', true)
                ->orderBy('material_id')
                ->get()
                ->filter(fn (NationMaterialConversionRate $rate): bool => $rate->material !== null)
                ->values(),
            'memberPreviewLimit' => self::MEMBER_PREVIEW_LIMIT,
            'memberSortOptions' => self::MEMBER_SORT_OPTIONS,
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

    private function memberOrError(): ?NationMembership
    {
        $membership = $this->currentMembership();
        if (! $membership) {
            $this->addError('nationAction', '国家へ所属していません。');

            return null;
        }

        return $membership;
    }

    private function levelBenefitsEnabledOrError(): bool
    {
        if (config('features.nation_development_enabled', false)
            && app(NationLevelBenefitSettingsService::class)->enabled()) {
            return true;
        }

        $this->addError('nationAction', '国家Lv特典は現在準備中です。');

        return false;
    }

    private function selectedActiveNation(): ?Nation
    {
        $nation = $this->selectedNationId
            ? Nation::active()->publiclyVisible()->find($this->selectedNationId)
            : null;
        if (! $nation) {
            $this->addError('nationAction', '国家が見つかりません。');
        }

        return $nation;
    }

    private function memberListNation(): ?Nation
    {
        if ($this->page === 'home') {
            return $this->currentMembership()?->nation;
        }

        if ($this->page === 'detail' && $this->selectedNationId) {
            return Nation::active()->publiclyVisible()->find($this->selectedNationId);
        }

        return null;
    }

    /**
     * @param  Collection<int, NationMembership>  $memberships
     * @return Collection<int, NationMembership>
     */
    private function sortMemberships(Collection $memberships): Collection
    {
        $sort = array_key_exists($this->memberSort, self::MEMBER_SORT_OPTIONS)
            ? $this->memberSort
            : self::MEMBER_SORT_DEFAULT;

        return $memberships->sort(function (NationMembership $left, NationMembership $right) use ($sort): int {
            $leftCharacter = $left->character;
            $rightCharacter = $right->character;

            if ($sort === 'ruler_joined') {
                $comparison = ((int) ! $left->isRuler()) <=> ((int) ! $right->isRuler());
                if ($comparison !== 0) {
                    return $comparison;
                }

                $comparison = ($left->joined_at?->getTimestamp() ?? PHP_INT_MAX)
                    <=> ($right->joined_at?->getTimestamp() ?? PHP_INT_MAX);
                if ($comparison !== 0) {
                    return $comparison;
                }
            } elseif ($sort === 'joined_desc') {
                $comparison = ($right->joined_at?->getTimestamp() ?? PHP_INT_MIN)
                    <=> ($left->joined_at?->getTimestamp() ?? PHP_INT_MIN);
                if ($comparison !== 0) {
                    return $comparison;
                }
            } else {
                $comparison = ((int) ($leftCharacter === null)) <=> ((int) ($rightCharacter === null));
                if ($comparison !== 0) {
                    return $comparison;
                }

                if ($sort === 'level_desc') {
                    $comparison = ((int) ($rightCharacter?->level ?? 0)) <=> ((int) ($leftCharacter?->level ?? 0));
                } elseif ($sort === 'level_asc') {
                    $comparison = ((int) ($leftCharacter?->level ?? PHP_INT_MAX)) <=> ((int) ($rightCharacter?->level ?? PHP_INT_MAX));
                } else {
                    $comparison = 0;
                }
                if ($comparison !== 0) {
                    return $comparison;
                }

                $comparison = strnatcasecmp((string) $leftCharacter?->name, (string) $rightCharacter?->name);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return (int) $left->id <=> (int) $right->id;
        })->values();
    }

    private function navigate(string $page, bool $keepSelection = false): void
    {
        $this->page = $page;
        $this->showFoundingEmblemModal = false;
        $this->showFoundingConfirmationModal = false;
        $this->showDonationMaterialModal = false;
        $this->showDonationConfirmationModal = false;
        $this->showActivityLogModal = false;
        $this->showHeaderBackgroundModal = false;
        $this->showMemberListModal = false;
        $this->showNationChatModal = false;
        $this->showNationMenuModal = false;
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

    /** @return array{donationQuantities:array<int|string,int>,donationRequestId:string} */
    private function validateDonationInput(): array
    {
        return $this->validate([
            'donationQuantities' => ['required', 'array', 'min:1'],
            'donationQuantities.*' => ['required', 'integer', 'min:1'],
            'donationRequestId' => ['required', 'uuid'],
        ], [
            'donationQuantities.required' => '納品する素材を1種類以上選んでください。',
            'donationQuantities.min' => '納品する素材を1種類以上選んでください。',
            'donationQuantities.*.required' => '納品数を入力してください。',
            'donationQuantities.*.integer' => '納品数は整数で指定してください。',
            'donationQuantities.*.min' => '納品数は1以上で指定してください。',
            'donationRequestId.required' => '納品情報を更新するため、画面を再読み込みしてください。',
            'donationRequestId.uuid' => '納品情報を更新するため、画面を再読み込みしてください。',
        ]);
    }

    private function rotateDonationRequestId(): void
    {
        $this->donationRequestId = (string) Str::uuid();
    }

    private function donatableMaterialForSelection(int $materialId): ?object
    {
        if ($this->page !== 'resources' || ! config('features.nation_development_enabled', false)) {
            return null;
        }

        $material = app(NationResourceService::class)->donatableMaterial($this->character(), $materialId);
        if (! $material || (int) $material->quantity < 1) {
            $this->addError('donationQuantities', '納品できる素材が見つかりません。');

            return null;
        }

        $this->resetErrorBag(['donationQuantities', 'donationQuantities.*']);

        return $material;
    }
}
