<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MapExplorationBatch;
use App\Models\TownMapRegistration;
use App\Services\MapExplorationBatchService;
use App\Services\ExplorationMapDiscardService;
use App\Services\ExplorationMapDisplayService;
use App\Services\MapPublicationService;
use App\Services\MapExplorationItemService;
use App\Services\MapSurveyService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ExplorationMapController extends Controller
{
    private function character() { return Auth::user()?->currentCharacter() ?? abort(404); }
    public function index(Request $request)
    {
        $character = $this->character();
        $ownedMaps = ExplorationMap::with('registration.town')
            ->where('owner_character_id', $character->id)
            ->where('status', '!=', 'discarded')
            ->latest()
            ->get()
            ->filter(function (ExplorationMap $map): bool {
                $registration = $map->registration;

                return $registration === null || $registration->isOpen() || $registration->isRecentlyClosed() || in_array($map->status, ['uninvestigated', 'surveying', 'surveyed'], true);
            })
            ->values();
        $ownedMapCount = $ownedMaps->count();
        $statusFilterOptions = [
            'all' => 'すべての状態',
            'uninvestigated' => '未調査',
            'surveying' => '調査中',
            'surveyed' => '調査完了・公開待ち',
            'published' => '公開中',
            'closed' => '終了・取り下げ済み',
        ];
        $gradeFilterOptions = [
            'all' => 'すべての等級',
            'normal' => '通常',
            'rare' => '希少',
            'hero' => '英雄',
            'legend' => '伝説',
        ];
        $sortOptions = [
            'recent' => '入手が新しい順',
            'oldest' => '入手が古い順',
            'grade_desc' => '等級が高い順',
            'grade_asc' => '等級が低い順',
            'status_asc' => '状態順（未調査から）',
        ];
        $statusFilter = $request->string('status')->toString();
        $statusFilter = array_key_exists($statusFilter, $statusFilterOptions) ? $statusFilter : 'all';
        $gradeFilter = $request->string('grade')->toString();
        $gradeFilter = array_key_exists($gradeFilter, $gradeFilterOptions) ? $gradeFilter : 'all';
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, $sortOptions) ? $sort : 'recent';

        if ($statusFilter !== 'all') {
            $ownedMaps = $ownedMaps->filter(function (ExplorationMap $map) use ($statusFilter): bool {
                return $this->ownedMapStatus($map) === $statusFilter;
            })->values();
        }
        if ($gradeFilter !== 'all') {
            $ownedMaps = $ownedMaps
                ->where('map_grade', $gradeFilter)
                ->values();
        }
        $ownedMaps = $this->sortOwnedMaps($ownedMaps, $sort);
        $towns = City::whereBetween('id', [1, 10])->orderBy('id')->get();
        $surveyCosts = app(MapSurveyService::class)->costs();
        $publicationService = app(MapPublicationService::class);
        $surveyedMaps = $ownedMaps->where('status', 'surveyed');
        $enemyIds = $surveyedMaps
            ->flatMap(fn (ExplorationMap $map) => collect($map->normal_monster_variants_json ?? [])->pluck('base_monster_id'))
            ->filter()
            ->unique()
            ->values();
        $mapEnemies = Enemy::whereIn('id', $enemyIds)->get()->keyBy('id');
        $display = app(ExplorationMapDisplayService::class);
        $mapDetails = $surveyedMaps
            ->mapWithKeys(fn (ExplorationMap $map) => [$map->id => $display->details($map, $mapEnemies)])
            ->all();

        return view('exploration-maps.index', [
            'character' => $character,
            'ownedMaps' => $ownedMaps,
            'ownedMapCount' => $ownedMapCount,
            'ownedMapStatusFilter' => $statusFilter,
            'ownedMapGradeFilter' => $gradeFilter,
            'ownedMapSort' => $sort,
            'ownedMapStatusFilterOptions' => $statusFilterOptions,
            'ownedMapGradeFilterOptions' => $gradeFilterOptions,
            'ownedMapSortOptions' => $sortOptions,
            'ownedMapFiltersActive' => $statusFilter !== 'all' || $gradeFilter !== 'all' || $sort !== 'recent',
            'towns' => $towns,
            'surveyCosts' => $surveyCosts,
            'activePublicationCount' => $publicationService->activePublicationCount($character),
            'activePublicationLimit' => $publicationService->activePublicationLimit(),
            'mapDetails' => $mapDetails,
            'bankSummary' => app(\App\Services\BankService::class)->summary($character),
        ]);
    }

    private function sortOwnedMaps(Collection $ownedMaps, string $sort): Collection
    {
        $gradeOrder = ['normal' => 0, 'rare' => 1, 'hero' => 2, 'legend' => 3];
        $statusOrder = ['uninvestigated' => 0, 'surveying' => 1, 'surveyed' => 2, 'published' => 3, 'closed' => 4];

        return $ownedMaps->sort(function (ExplorationMap $left, ExplorationMap $right) use ($sort, $gradeOrder, $statusOrder): int {
            $leftCreatedAt = $left->created_at?->getTimestamp() ?? 0;
            $rightCreatedAt = $right->created_at?->getTimestamp() ?? 0;
            $comparison = match ($sort) {
                'oldest' => $leftCreatedAt <=> $rightCreatedAt,
                'grade_desc' => ($gradeOrder[$right->map_grade] ?? -1) <=> ($gradeOrder[$left->map_grade] ?? -1),
                'grade_asc' => ($gradeOrder[$left->map_grade] ?? PHP_INT_MAX) <=> ($gradeOrder[$right->map_grade] ?? PHP_INT_MAX),
                'status_asc' => ($statusOrder[$this->ownedMapStatus($left)] ?? PHP_INT_MAX) <=> ($statusOrder[$this->ownedMapStatus($right)] ?? PHP_INT_MAX),
                default => $rightCreatedAt <=> $leftCreatedAt,
            };

            if ($comparison !== 0) {
                return $comparison;
            }

            return $sort === 'oldest'
                ? (int) $left->id <=> (int) $right->id
                : (int) $right->id <=> (int) $left->id;
        })->values();
    }

    private function ownedMapStatus(ExplorationMap $map): string
    {
        $registration = $map->registration;
        if ($registration?->isOpen()) {
            return 'published';
        }
        if ($registration !== null
            && ($registration->isPublished() || $registration->isWithdrawn())
            && !$registration->isOpen()) {
            return 'closed';
        }

        return (string) $map->status;
    }
    public function published()
    {
        $character = $this->character();
        $activeRegistration = app(MapExplorationItemService::class)->restoreActiveSession($character);
        $sort = request()->string('sort')->toString();
        $sortOptions = [
            'recently_entered' => '最近探索した順',
            'latest_published' => '最新公開順',
            'power_asc' => '目安戦力が低い順',
            'power_desc' => '目安戦力が高い順',
            'fee_asc' => '入場料が安い順',
        ];
        $sort = array_key_exists($sort, $sortOptions) ? $sort : 'recently_entered';
        $lastExploration = MapExplorationBatch::query()
            ->select('created_at')
            ->whereColumn('registration_id', 'town_map_registrations.id')
            ->where('character_id', $character->id)
            ->latest('created_at')
            ->limit(1);

        $published = TownMapRegistration::with(['map.owner', 'town'])
            ->select('town_map_registrations.*')
            ->selectSub($lastExploration, 'last_entered_at')
            ->where('status', 'published')
            ->where(function ($query) {
                $recentlyClosedAfter = now()->subHours((int) config('exploration_maps.closed_map_display_hours', 6));

                $query->where(function ($query) {
                    $query->where('remaining_explorations', '>', 0)->where('expires_at', '>', now());
                })->orWhere(function ($query) use ($recentlyClosedAfter) {
                    $query->where('remaining_explorations', '<=', 0)->where('updated_at', '>', $recentlyClosedAfter);
                })->orWhere(function ($query) use ($recentlyClosedAfter) {
                    $query->where('expires_at', '<=', now())->where('expires_at', '>', $recentlyClosedAfter);
                });
            })
            ->orderByRaw('CASE WHEN remaining_explorations > 0 AND expires_at > ? THEN 0 ELSE 1 END', [now()])
            ->orderByDesc('last_entered_at')
            ->latest('published_at')
            ->get();

        if ($activeRegistration && !$published->contains('id', $activeRegistration->id)) {
            $published->push($activeRegistration->loadMissing(['map.owner', 'town']));
        }

        $enemyIds = $published
            ->flatMap(fn (TownMapRegistration $registration) => collect($registration->map->normal_monster_variants_json ?? [])->pluck('base_monster_id'))
            ->filter()
            ->unique()
            ->values();
        $mapEnemies = Enemy::whereIn('id', $enemyIds)->get()->keyBy('id');
        $display = app(ExplorationMapDisplayService::class);
        $mapDetails = $published->mapWithKeys(fn (TownMapRegistration $registration) => [$registration->id => $display->details($registration->map, $mapEnemies)])->all();
        $published = (match ($sort) {
            'latest_published' => $published->sortByDesc('published_at'),
            'power_asc' => $published->sortBy(fn (TownMapRegistration $registration) => $mapDetails[$registration->id]['enemy_power_min'] ?: PHP_INT_MAX),
            'power_desc' => $published->sortByDesc(fn (TownMapRegistration $registration) => $mapDetails[$registration->id]['enemy_power_max']),
            'fee_asc' => $published->sortBy(fn (TownMapRegistration $registration) => (int) $registration->entry_fee_per_exploration),
            default => $published,
        })->values();

        return view('exploration-maps.published', [
            'character' => $character,
            'published' => $published,
            'mapDetails' => $mapDetails,
            'activeRegistrationId' => (int) ($activeRegistration?->id ?? 0),
            'sort' => $sort,
            'sortOptions' => $sortOptions,
            'bankSummary' => app(\App\Services\BankService::class)->summary($character),
        ]);
    }
    public function leave()
    {
        app(MapExplorationItemService::class)->end($this->character());
        session()->forget('active_map_exploration');

        return redirect()->route('home')->with('message', '地図探索を切り上げて街へ戻った。');
    }
    public function show(TownMapRegistration $registration)
    {
        $registration->load(['map.owner', 'town']);
        abort_if($registration->map->status === 'discarded', 404);
        abort_unless($registration->isOpen() || $registration->map->owner_character_id === $this->character()->id, 404);
        $publicationService = app(MapPublicationService::class);
        $character = $this->character();
        $isActiveMapEntry = app(MapExplorationItemService::class)->hasEntry($character, (int) $registration->id);
        return view('exploration-maps.show', [
            'registration' => $registration,
            'character' => $character,
            'recommendedFee' => $publicationService->recommendedFee($registration),
            'feeOptions' => $publicationService->feeOptions($registration),
            'mapDetails' => app(ExplorationMapDisplayService::class)->details($registration->map),
            'activePublicationCount' => $publicationService->activePublicationCount($character),
            'activePublicationLimit' => $publicationService->activePublicationLimit(),
            'bankSummary' => app(\App\Services\BankService::class)->summary($character),
            'isActiveMapEntry' => $isActiveMapEntry,
        ]);
    }
    public function startSurvey(Request $request, ExplorationMap $map)
    {
        $request->validate(['town_id' => ['required', 'integer', 'exists:cities,id'], 'use_bank' => ['nullable', 'boolean']]);
        try { $registration = app(MapSurveyService::class)->start($this->character(), $map, City::findOrFail($request->integer('town_id')), $request->boolean('use_bank')); return redirect()->route('exploration-maps.show', $registration)->with('message', '地図院の調査が完了した。公開の準備をしよう。'); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function bulkSurvey(Request $request)
    {
        $validated = $request->validate([
            'map_ids' => ['required', 'array', 'min:1'],
            'map_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'town_id' => ['required', 'integer', 'exists:cities,id'],
            'use_bank' => ['nullable', 'boolean'],
        ]);

        try {
            $registrations = app(MapSurveyService::class)->startMany(
                $this->character(),
                $validated['map_ids'],
                City::findOrFail((int) $validated['town_id']),
                $request->boolean('use_bank'),
            );

            return redirect()->route('exploration-maps.index')->with('message', "選択した{$registrations->count()}件の探索地図を一括調査した。");
        }
        catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
    public function completeSurvey(TownMapRegistration $registration)
    {
        try { app(MapSurveyService::class)->complete($this->character(), $registration); return redirect()->route('exploration-maps.show', $registration)->with('message', '遠征調査が完了し、地図の全容が判明した。'); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function discard(ExplorationMap $map)
    {
        try {
            app(ExplorationMapDiscardService::class)->discard($this->character(), $map);

            return redirect()->route('exploration-maps.index')->with('message', '探索地図を破棄した。');
        }
        catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
    public function bulkDiscard(Request $request)
    {
        $validated = $request->validate([
            'map_ids' => ['required', 'array', 'min:1'],
            'map_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        try {
            $count = app(ExplorationMapDiscardService::class)->discardMany($this->character(), $validated['map_ids']);

            return redirect()->route('exploration-maps.index')->with('message', "選択した{$count}件の探索地図を破棄した。");
        }
        catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
    public function publish(Request $request, TownMapRegistration $registration)
    {
        $request->validate(['entry_fee' => ['required', 'integer', 'min:0']]);
        try { app(MapPublicationService::class)->publish($this->character(), $registration, $request->integer('entry_fee')); return redirect()->route('exploration-maps.show', $registration)->with('message', '地図を公開した。冒険者たちへ知らせが流れた！'); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function withdraw(TownMapRegistration $registration)
    {
        try {
            app(MapPublicationService::class)->withdraw($this->character(), $registration);

            return redirect()->route('exploration-maps.show', $registration)->with('message', '公開を取り下げた。新しい入場は受け付けない。');
        }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function explore(Request $request, TownMapRegistration $registration)
    {
        $request->validate(['count' => ['required', 'integer', 'min:1', 'max:10'], 'request_uuid' => ['nullable', 'uuid'], 'use_bank' => ['nullable', 'boolean']]);
        try {
            $character = $this->character();
            $service = app(MapExplorationBatchService::class);
            $itemService = app(MapExplorationItemService::class);
            $activeRegistration = $itemService->restoreActiveSession($character);
            if ($activeRegistration && (int) $activeRegistration->id !== (int) $registration->id) {
                throw new \RuntimeException('別の地図を探索中です。現在の地図探索を切り上げてから入場してください。');
            }
            $alreadyEntered = (int) ($activeRegistration?->id ?? 0) === (int) $registration->id;
            $execution = DB::transaction(function () use ($character, $registration, $request, $service, $itemService, $alreadyEntered): array {
                $batch = $service->reserve($character, $registration, $request->integer('count'), $request->input('request_uuid') ?: (string) Str::uuid(), !$alreadyEntered, $request->boolean('use_bank'));
                if ((!$alreadyEntered && $batch->wasRecentlyCreated)
                    || ($alreadyEntered && !$itemService->hasEntry($character, (int) $registration->id))) {
                    $itemService->begin($character, $registration);
                }

                return $service->execute($character, $batch);
            });
            $batch = $execution['batch'];
            $jobHistory = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
            session(['active_map_exploration' => [
                'registration_id' => (int) $batch->registration_id,
                'area_id' => (int) $batch->map->source_area_id,
            ]]);

            return redirect()->route('battle.result')->with('battleData', [
                'result' => $execution['battle_result'],
                'areaId' => (int) $batch->map->source_area_id,
                'isBoss' => false,
                'jobLevel' => $jobHistory ? $jobHistory->job_level : 1,
                'mapExploration' => [
                    'registration_id' => (int) $batch->registration_id,
                    'map_name' => (string) $batch->map->name,
                    'can_continue' => $batch->registration->isOpen() && $batch->registration->remaining_explorations > 0,
                    'remaining_explorations' => (int) $batch->registration->remaining_explorations,
                    'entry_fee' => (int) $batch->fee_per_exploration,
                    'loot_summary' => app(\App\Services\MapExplorationDefeatService::class)->currentLootSummary($character, (int) $batch->registration_id),
                ],
            ]);
        }
        catch (DecryptException $e) {
            report($e);

            return back()->with('error', 'この地図の探索情報を読み込めませんでした。探索回数・料金・探索力は消費されていません。別の地図を選んでください。');
        }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function result(string $uuid)
    {
        $batch = MapExplorationBatch::with(['map', 'registration.town', 'results'])->where('uuid', $uuid)->firstOrFail();
        abort_unless($batch->character_id === $this->character()->id, 403);
        if (!$batch->result_viewed_at) $batch->update(['result_viewed_at' => now()]);
        return view('exploration-maps.result', ['batch' => $batch]);
    }
}
