<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\ExplorationMap;
use App\Models\MapExplorationBatch;
use App\Models\TownMapRegistration;
use App\Services\MapExplorationBatchService;
use App\Services\ExplorationMapDiscardService;
use App\Services\ExplorationMapDisplayService;
use App\Services\MapPublicationService;
use App\Services\MapExplorationItemService;
use App\Services\MapSurveyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ExplorationMapController extends Controller
{
    private function character() { return Auth::user()?->currentCharacter() ?? abort(404); }
    public function index()
    {
        $character = $this->character();
        $ownedMaps = ExplorationMap::with('registration.town')
            ->where('owner_character_id', $character->id)
            ->where('status', '!=', 'discarded')
            ->latest()
            ->get()
            ->filter(function (ExplorationMap $map): bool {
                $registration = $map->registration;

                return !$registration?->isPublished() || $registration->isOpen() || $registration->isRecentlyClosed();
            })
            ->values();
        $towns = City::whereBetween('id', [1, 10])->orderBy('id')->get();
        $surveyCosts = app(MapSurveyService::class)->costs();

        return view('exploration-maps.index', ['character' => $character, 'ownedMaps' => $ownedMaps, 'towns' => $towns, 'surveyCosts' => $surveyCosts]);
    }
    public function published()
    {
        $character = $this->character();
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

        $display = app(ExplorationMapDisplayService::class);
        $mapDetails = $published->mapWithKeys(fn (TownMapRegistration $registration) => [$registration->id => $display->details($registration->map)])->all();
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
            'activeRegistrationId' => (int) data_get(session('active_map_exploration'), 'registration_id', 0),
            'sort' => $sort,
            'sortOptions' => $sortOptions,
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
        return view('exploration-maps.show', ['registration' => $registration, 'character' => $this->character(), 'recommendedFee' => $publicationService->recommendedFee($registration), 'feeOptions' => $publicationService->feeOptions($registration), 'mapDetails' => app(ExplorationMapDisplayService::class)->details($registration->map)]);
    }
    public function startSurvey(Request $request, ExplorationMap $map)
    {
        $request->validate(['town_id' => ['required', 'integer', 'exists:cities,id']]);
        try { $registration = app(MapSurveyService::class)->start($this->character(), $map, City::findOrFail($request->integer('town_id'))); return redirect()->route('exploration-maps.show', $registration)->with('message', '地図院の調査が完了した。公開の準備をしよう。'); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
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
    public function publish(Request $request, TownMapRegistration $registration)
    {
        $request->validate(['entry_fee' => ['required', 'integer', 'min:0']]);
        try { app(MapPublicationService::class)->publish($this->character(), $registration, $request->integer('entry_fee')); return redirect()->route('exploration-maps.show', $registration)->with('message', '地図を公開した。冒険者たちへ知らせが流れた！'); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
    }
    public function explore(Request $request, TownMapRegistration $registration)
    {
        $request->validate(['count' => ['required', 'integer', 'min:1', 'max:10'], 'request_uuid' => ['nullable', 'uuid']]);
        try {
            $character = $this->character();
            $service = app(MapExplorationBatchService::class);
            $activeMap = session('active_map_exploration');
            $alreadyEntered = is_array($activeMap)
                && (int) ($activeMap['registration_id'] ?? 0) === (int) $registration->id;
            $batch = $service->reserve($character, $registration, $request->integer('count'), $request->input('request_uuid') ?: (string) Str::uuid(), !$alreadyEntered);
            $itemService = app(MapExplorationItemService::class);
            if ((!$alreadyEntered && $batch->wasRecentlyCreated)
                || ($alreadyEntered && !$itemService->hasEntry($character, (int) $registration->id))) {
                $itemService->begin($character, $registration);
            }
            $execution = $service->execute($character, $batch);
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
                ],
            ]);
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
