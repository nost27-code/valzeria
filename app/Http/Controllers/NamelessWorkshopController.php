<?php

namespace App\Http\Controllers;

use App\Models\CharacterMaterial;
use App\Models\NpcMaster;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Services\CharacterStatusService;
use App\Services\ExplorationStaminaService;
use App\Services\NamelessEquipmentService;
use App\Services\NamelessEquipmentCollectionService;
use App\Services\NamelessEquipmentListService;
use App\Services\NamelessRelicCatalog;
use App\Services\NamelessRelicGrowthService;
use App\Services\NamelessRuinService;
use App\Services\NamelessWorkshopService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class NamelessWorkshopController extends Controller
{
    public function index(Request $request, NamelessWorkshopService $workshop, NamelessRuinService $ruins, NamelessRelicCatalog $catalog)
    {
        abort_unless($workshop->ready(), 404);
        $character = $request->user()->currentCharacter();
        $towns = app(\App\Services\NamelessTownService::class);
        $namelessTown = $towns->availableTown();
        abort_unless($namelessTown, 404);
        if ((int) $character->current_city_id !== (int) $namelessTown->id) {
            return view('nameless-workshop.arrival', compact('namelessTown', 'character'));
        }
        $selectedZone = (string) $request->query('zone', '');
        abort_if($selectedZone !== '' && ! array_key_exists($selectedZone, $ruins->zones()), 404);
        if ($request->query('tab') === 'ruins') {
            return $this->returnToTown($request, 'dungeon');
        }
        $equipment = PlayerNamelessEquipment::query()->where('character_id', $character->id)->with('relics')->orderByDesc('is_equipped')->orderBy('id')->get()->keyBy('id');
        $ordinaryRelics = app(\App\Services\NamelessRelicEquipmentService::class);
        $ordinaryEquipment = $ordinaryRelics->ordinaryForDisplay($character);
        $selectedOrdinaryId = $request->query('character_item');
        $selectedBody = $request->query('equipment');
        if (($choice = $request->query('relic_equipment')) !== null) {
            abort_unless(is_string($choice) && preg_match('/^(nameless|ordinary):([1-9][0-9]*)$/', $choice, $parts), 404);
            $selectedBody = $parts[1] === 'nameless' ? $parts[2] : null;
            $selectedOrdinaryId = $parts[1] === 'ordinary' ? $parts[2] : null;
        }
        if ($selectedOrdinaryId !== null) {
            abort_unless($selectedBody === null && is_scalar($selectedOrdinaryId) && ctype_digit((string) $selectedOrdinaryId)
                && $ordinaryEquipment->has((int) $selectedOrdinaryId), 404);
        }
        $selectedOrdinaryEquipment = $selectedOrdinaryId !== null ? $ordinaryEquipment->get((int) $selectedOrdinaryId) : null;
        if ($selectedBody !== null) {
            abort_unless(is_scalar($selectedBody) && ctype_digit((string) $selectedBody) && $equipment->has((int) $selectedBody), 404);
        }
        $tab = $selectedOrdinaryEquipment || in_array($request->query('tab'), ['sets', 'relics'], true) ? 'sets' : 'workshop';
        $list = app(NamelessEquipmentListService::class);
        $equipmentFilters = $list->normalize($request->only(array_keys(NamelessEquipmentListService::DEFAULTS)));
        $equipmentFilterQuery = $list->query($equipmentFilters);
        $storageSummary = app(\App\Services\StorageCapacityService::class)->summary($character);
        $receiptConversation = $request->query('conversation') === 'received';
        $receivedEquipment = $receiptConversation && $selectedBody !== null ? $equipment->get((int) $selectedBody) : null;
        if ($receiptConversation) {
            abort_unless($receivedEquipment?->kind === 'weapon' && $receivedEquipment->acquisition_source === 'starter', 404);
        }
        if ($receiptConversation || ($tab === 'workshop' && $selectedBody === null && $workshop->needsIntroduction($character)
            && $storageSummary['equipment_free'] > 0)) {
            return response()->view('nameless-workshop.introduction', [
                'namelessTown' => $namelessTown, 'storageSummary' => $storageSummary,
                'smith' => NpcMaster::query()->findOrFail(14), // 既存の鍛冶屋NPC「鉄槌のガンツ」。
                'weaponTypes' => NamelessEquipmentService::statOptionsFor('weapon'),
                'receivedEquipment' => $receivedEquipment,
                'workshopUrl' => route('nameless-workshop.index', $equipmentFilterQuery + array_filter(['tab' => 'workshop', 'equipment' => $receivedEquipment?->id])),
            ])->header('Cache-Control', 'no-store');
        }
        $filteredEquipment = $list->filter($equipment, $equipmentFilters);
        $selectedEquipment = $selectedBody !== null ? $equipment->get((int) $selectedBody) : $filteredEquipment->first();
        if ($selectedOrdinaryEquipment) {
            $selectedEquipment = null;
        } elseif (! $selectedEquipment && $selectedBody === null && $tab === 'sets') {
            $selectedOrdinaryEquipment = $ordinaryEquipment->first();
        }
        $selectionOutsideFilter = $selectedEquipment && ! $filteredEquipment->has($selectedEquipment->id);
        $selectableEquipment = clone $filteredEquipment;
        if ($selectionOutsideFilter) {
            $selectableEquipment->prepend($selectedEquipment, $selectedEquipment->id);
        }
        $relics = PlayerRelic::query()->where('character_id', $character->id)->with($ordinaryRelics->ordinarySchemaReady() ? ['equipment', 'characterItem.item'] : ['equipment'])->orderByDesc('rank')->orderBy('effect_key')->orderBy('id')->get();
        $growth = app(NamelessRelicGrowthService::class);
        $relicGrowth = $growth->ready() ? $relics->mapWithKeys(fn ($relic) => [$relic->id => $growth->summary($relic)]) : collect();
        $growId = $request->query('grow_relic');
        $selectedRelicGrowth = null;
        if ($growId !== null) {
            abort_unless(is_scalar($growId) && ctype_digit((string) $growId) && $relicGrowth->has((int) $growId), 404);
            $selectedRelicGrowth = $relics->firstWhere('id', (int) $growId);
            $tab = 'sets';
        }
        $materials = CharacterMaterial::query()->where('character_id', $character->id)->where('quantity', '>', 0)->with('material')->get()
            ->filter(fn ($row) => $row->material && $workshop->materialFeedExp($row->material) > 0)->sortBy('material.name');
        $selectionInput = old('equipment_id') !== null ? $request->session()->getOldInput() : $request->session()->get('nameless_forge_selection', []);
        $restoreSelection = (int) ($selectionInput['equipment_id'] ?? 0) === (int) $selectedEquipment?->id;
        $bestIds = $workshop->bestRelicIds($character);
        $forge = $equipment->map(fn ($body) => $workshop->forgeSummary($character, $body));
        $selectedCost = $selectedEquipment ? $forge->get($selectedEquipment->id) : null;
        $feedableRelics = $relics->filter(fn ($relic) => ! $relic->is_locked && ! $relic->isAttached() && ! $relic->growth_progress);

        return view('nameless-workshop.index', [
            'character' => $character, 'equipment' => $equipment, 'relics' => $relics, 'materials' => $materials,
            'relicGrowth' => $relicGrowth, 'selectedRelicGrowth' => $selectedRelicGrowth,
            'growthCandidates' => $selectedRelicGrowth ? $growth->candidates($selectedRelicGrowth, $relics) : collect(),
            'selectedEquipment' => $selectedEquipment, 'ordinaryEquipment' => $ordinaryEquipment,
            'relicFeedEquipment' => $selectedEquipment ?? $equipment->first(),
            'selectedOrdinaryEquipment' => $selectedOrdinaryEquipment, 'ordinaryRelics' => $ordinaryRelics,
            'filteredEquipment' => $filteredEquipment,
            'selectableEquipment' => $selectableEquipment, 'selectionOutsideFilter' => $selectionOutsideFilter,
            'equipmentFilters' => $equipmentFilters, 'equipmentFilterOptions' => $list->options(),
            'equipmentFilterQuery' => $equipmentFilterQuery,
            'starterKinds' => $equipment->where('acquisition_source', 'starter')->pluck('kind')->all(),
            'collection' => app(NamelessEquipmentCollectionService::class)->summary($character),
            'inventoryBlockReason' => $workshop->inventoryBlockReason($character, $storageSummary),
            'storageSummary' => $storageSummary,
            'inventoryCapacityChecked' => true,
            'effects' => $catalog->all(), 'catalog' => $catalog, 'workshop' => $workshop,
            'effectGroups' => $catalog->grouped(),
            'types' => ['weapon' => NamelessEquipmentService::statOptionsFor('weapon'), 'armor' => NamelessEquipmentService::statOptionsFor('armor'), 'accessory' => NamelessEquipmentService::statOptionsFor('accessory')],
            'forge' => $forge,
            'forgeMaterials' => $selectedEquipment ? $materials->map(fn ($row) => [
                'id' => $row->id, 'name' => $row->material->name, 'icon' => $row->material->iconImagePath(),
                'owned' => (int) $row->quantity, 'unit' => $workshop->materialFeedExp($row->material),
            ]) : collect(),
            'forgeRelics' => $feedableRelics->map(fn ($relic) => [
                'id' => $relic->id, 'name' => $relic->displayName(), 'summary' => $relic->effectSummary(), 'effect_key' => $relic->effect_key,
                'description' => $catalog->definition($relic->effect_key)['description'], 'unit' => $catalog->feedExp($relic->rank),
            ])->values(),
            'forgeSelection' => [
                'quantities' => $materials->mapWithKeys(fn ($row) => [$row->id => $restoreSelection ? min((int) $row->quantity, max(0, (int) ($selectionInput['materials'][$row->id] ?? 0))) : 0])->all(),
                'units' => $materials->mapWithKeys(fn ($row) => [$row->id => $workshop->materialFeedExp($row->material)])->all(),
                'relicUnits' => $feedableRelics->mapWithKeys(fn ($relic) => [$relic->id => $catalog->feedExp($relic->rank)])->all(),
                'relicIds' => $restoreSelection ? array_map('strval', array_values(array_intersect((array) ($selectionInput['relics'] ?? []), $feedableRelics->pluck('id')->all()))) : [],
                'protectBest' => $restoreSelection ? (bool) ($selectionInput['protect_best'] ?? true) : true,
                'useBank' => $restoreSelection ? (bool) ($selectionInput['use_bank'] ?? false) : false,
                'required' => $selectedEquipment ? max(0, $selectedCost['exp'] - $selectedEquipment->growth_exp) : 0,
                'carryBase' => $selectedEquipment ? max(0, $selectedEquipment->growth_exp - $selectedCost['exp']) : 0,
                'bestIds' => array_map('strval', $bestIds),
            ],
            'relicDescriptions' => $relics->mapWithKeys(fn ($relic) => [$relic->id => [
                'summary' => $relic->effectSummary(), 'description' => $catalog->definition($relic->effect_key)['description'],
                'name' => $relic->displayName(), 'image_url' => asset($relic->imagePath()),
            ]]),
            'bestIds' => $bestIds, 'stats' => app(CharacterStatusService::class)->getFinalStats($character),
            'tab' => $tab,
            'filterEffect' => (string) $request->query('effect', ''),
        ]);
    }

    public function act(Request $request, string $action, NamelessWorkshopService $workshop, NamelessRuinService $ruins)
    {
        abort_unless($workshop->ready(), 404);
        $rules = match ($action) {
            'claim' => ['kind' => ['required', Rule::in(['weapon'])], 'type' => ['required', 'string', 'max:20']],
            'equip' => ['equipment_id' => ['required', 'integer', 'min:1'], 'equipped' => ['required', 'boolean']],
            'protect-equipment' => ['equipment_id' => ['required', 'integer', 'min:1'], 'protected' => ['required', 'boolean']],
            'discard-equipment' => ['equipment_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:0'], 'confirmed' => ['accepted']],
            'configure' => ['equipment_id' => ['required', 'integer', 'min:1'], 'type' => ['required', 'string', 'max:20'], 'name' => ['nullable', 'string', 'max:32']],
            'rename' => ['equipment_id' => ['required', 'integer', 'min:1'], 'name' => ['nullable', 'string', 'max:32']],
            'attach-ordinary' => ['character_item_id' => ['required', 'integer', 'min:1'], 'slot' => ['required', 'integer', 'min:1', 'max:3'], 'relic_id' => ['required', 'integer', 'min:1']],
            'equip-ordinary' => ['character_item_id' => ['required', 'integer', 'min:1'], 'equipped' => ['required', 'boolean']],
            'attach' => ['equipment_id' => ['required', 'integer', 'min:1'], 'slot' => ['required', 'integer', 'min:1', 'max:3'], 'relic_id' => ['required', 'integer', 'min:1']],
            'detach' => ['relic_id' => ['required', 'integer', 'min:1'], 'equipment_id' => ['sometimes', 'integer', 'min:1']],
            'protect' => ['relic_id' => ['required', 'integer', 'min:1'], 'protected' => ['sometimes', 'boolean']],
            'discard' => ['relic_id' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'],
                'expected_rank' => ['required_with:expected_growth_progress', 'integer', 'min:1', 'max:9'],
                'expected_growth_progress' => ['required_with:expected_rank', 'integer', 'min:0', 'max:3']],
            'forge' => ['equipment_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:0'], 'use_bank' => ['sometimes', 'boolean'], 'material_row_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
            'preview-forge', 'forge-combined' => $this->forgeRules($action === 'forge-combined'),
            'preview-feed', 'feed' => $this->feedRules($action === 'feed'),
            'preview-relic-growth', 'grow-relic' => $this->relicGrowthRules($action === 'grow-relic'),
            'fight' => ['zone' => ['required', Rule::in(array_keys($ruins->zones()))], 'depth' => ['required', 'integer', 'min:1', 'max:100'], 'boss' => ['required', 'boolean'], 'batch_count' => ['sometimes', 'integer', 'min:1', 'max:50']],
            default => abort(404),
        };
        $data = $request->validate($rules + ['ordinary_context' => ['sometimes', 'integer', 'min:1'], 'character_item_id' => ['sometimes', 'integer', 'min:1'], 'request_uuid' => ['required', 'uuid'], 'workshop_tab' => ['sometimes', Rule::in(['workshop', 'sets', 'relics'])]]);
        $character = $request->user()->currentCharacter();
        $uuid = $data['request_uuid'];
        $list = app(NamelessEquipmentListService::class);
        $equipmentFilterQuery = $list->query($list->normalize($request->only(array_keys(NamelessEquipmentListService::DEFAULTS))));
        if ($action === 'fight') {
            session(['current_location' => 'dungeon']);
        }
        $back = $action === 'fight' ? route('home', ['skip_resume' => 1])
            : route('nameless-workshop.index', $equipmentFilterQuery + array_filter([
                'tab' => $data['workshop_tab'] ?? (in_array($action, ['protect', 'discard']) ? 'relics' : 'workshop'),
                'character_item' => $data['character_item_id'] ?? $data['ordinary_context'] ?? null,
                'equipment' => isset($data['equipment_id']) && $action !== 'discard-equipment' ? $data['equipment_id'] : null,
                'grow_relic' => in_array($action, ['preview-relic-growth', 'grow-relic'], true) ? $data['relic_id'] : null,
                'effect' => $data['effect'] ?? null,
            ]));
        if (in_array($action, ['preview-relic-growth', 'grow-relic'], true)) {
            $back .= '#relic-growth';
        }
        try {
            app(\App\Services\NamelessTownService::class)->assertVisiting($character);
            if ($action === 'preview-relic-growth') {
                $preview = app(NamelessRelicGrowthService::class)->preview($character, $data['relic_id'], $data['source_relics']);

                return response()->view('nameless-workshop.relic-growth-confirm', ['preview' => $preview, 'input' => $data, 'equipmentFilterQuery' => $equipmentFilterQuery, 'backUrl' => $back])->header('Cache-Control', 'no-store');
            }
            if ($action === 'preview-forge') {
                $preview = $workshop->previewForge($character, $data['equipment_id'], $data['revision'], $data['materials'] ?? [], $data['relics'] ?? [], $request->boolean('protect_best'), $request->boolean('use_bank'));
                $request->session()->put('nameless_forge_selection', \Illuminate\Support\Arr::only($data, ['materials', 'relics', 'protect_best', 'equipment_id', 'use_bank']));

                return response()->view('nameless-workshop.forge-confirm', ['preview' => $preview, 'input' => $data, 'equipmentFilterQuery' => $equipmentFilterQuery])->header('Cache-Control', 'no-store');
            }
            if ($action === 'preview-feed') {
                $preview = $workshop->previewFeed($character, $data['equipment_id'], $data['materials'] ?? [], $data['relics'] ?? [], $data['keep'], $request->boolean('protect_best'));

                return view('nameless-workshop.confirm', ['preview' => $preview, 'input' => $data, 'equipmentFilterQuery' => $equipmentFilterQuery]);
            }
            $result = match ($action) {
                'claim' => $workshop->claim($character, $data['kind'], $data['type'], $uuid),
                'equip' => $workshop->changeEquipment($character, $data['equipment_id'], $request->boolean('equipped'), $uuid),
                'protect-equipment' => $workshop->protectEquipment($character, $data['equipment_id'], $request->boolean('protected'), $uuid),
                'discard-equipment' => $workshop->discardEquipment($character, $data['equipment_id'], $data['revision'], $uuid),
                'configure' => $workshop->configure($character, $data['equipment_id'], $data['type'], $data['name'] ?? null, $uuid),
                'rename' => $workshop->rename($character, $data['equipment_id'], $data['name'] ?? null, $uuid),
                'attach-ordinary' => $workshop->attachOrdinary($character, $data['character_item_id'], $data['slot'], $data['relic_id'], $uuid),
                'equip-ordinary' => $workshop->changeOrdinaryEquipment($character, $data['character_item_id'], $request->boolean('equipped'), $uuid),
                'attach' => $workshop->attach($character, $data['equipment_id'], $data['slot'], $data['relic_id'], $uuid),
                'detach' => $workshop->detach($character, $data['relic_id'], $uuid),
                'protect' => $workshop->protect($character, $data['relic_id'], $request->boolean('protected'), $uuid),
                'discard' => $workshop->discard($character, $data['relic_id'], $uuid, isset($data['expected_rank'])
                    ? ['rank' => (int) $data['expected_rank'], 'growth_progress' => (int) $data['expected_growth_progress']] : null),
                'forge' => $workshop->forge($character, $data['equipment_id'], $data['revision'], $request->boolean('use_bank'), $uuid, isset($data['material_row_id']) ? (int) $data['material_row_id'] : null),
                'forge-combined' => $workshop->forgeCombined($character, $data['equipment_id'], $data['revision'], $data['materials'] ?? [], $data['relics'] ?? [], $request->boolean('protect_best'), $request->boolean('use_bank'), $data['confirmation_hash'], $uuid),
                'feed' => $workshop->feed($character, $data['equipment_id'], $data['materials'] ?? [], $data['relics'] ?? [], $data['keep'], $request->boolean('protect_best'), $data['confirmation_hash'], $uuid),
                'grow-relic' => app(NamelessRelicGrowthService::class)->grow($character, $data['relic_id'], $data['source_relics'], $data['confirmation_hash'], $uuid),
                'fight' => $ruins->fight($character, $data['zone'], $data['depth'], $request->boolean('boss'), $uuid, (int) ($data['batch_count'] ?? 1)),
            };
        } catch (QueryException $e) {
            report($e);

            return redirect($back)->with('error', '処理を完了できませんでした。画面を開き直し、所持状態を確認してから再試行してください。');
        } catch (RuntimeException $e) {
            return redirect($back)->with('error', $e->getMessage())->withInput();
        }
        $response = redirect($back)->with('status', $result['message']);
        if ($action === 'forge-combined' && (int) $request->session()->get('nameless_forge_selection.equipment_id') === (int) $data['equipment_id']) {
            $request->session()->forget('nameless_forge_selection');
        }

        if ($action === 'fight') {
            \App\Livewire\MainScreen::clearHomeCache((int) $character->id);
            return redirect()->route('nameless-workshop.result', ['uuid' => $uuid]);
        }
        \App\Livewire\MainScreen::clearHomeCache((int) $character->id);
        if ($action === 'claim') {
            return redirect()->route('nameless-workshop.index', $equipmentFilterQuery + ['equipment' => $result['equipment_id'], 'conversation' => 'received'])->with('status', $result['message']);
        }
        return $response;
    }

    public function result(Request $request, string $uuid, NamelessWorkshopService $workshop, NamelessRuinService $ruins)
    {
        abort_unless($workshop->ready(), 404);
        $character = $request->user()->currentCharacter();
        $operation = \App\Models\NamelessWorkshopOperation::query()
            ->where('character_id', $character->id)->where('request_uuid', $uuid)->where('action', 'ruin')->firstOrFail();
        $namelessRuins = $operation->result;
        abort_unless(isset($namelessRuins['enemy']), 404);
        $result = $namelessRuins;
        $result['enemy'] = (object) $result['enemy'];
        $result['drop'] = null; // Relics are displayed separately from ordinary equipment.
        $finalStats = app(CharacterStatusService::class)->getFinalStats($character);
        $history = $character->jobHistories()->where('job_class_id', $character->job_class_id)->first();
        \App\Livewire\MainScreen::clearHomeCache((int) $character->id);
        return response()->view('battle.result', [
            'character' => $character, 'result' => $result, 'namelessRuins' => $namelessRuins,
            'nextBossChallenge' => $ruins->nextBossChallenge($character, $namelessRuins),
            'finalStats' => $finalStats, 'jobLevel' => $history?->job_level ?? 1, 'areaId' => 0,
            'equippedItems' => app(\App\Services\BattleEquipmentSummaryService::class)->forEnemy($character, (string) ($result['enemy']->species_key ?? '')),
            'isBoss' => (bool) ($result['enemy']->is_boss ?? $namelessRuins['boss']), 'areaName' => $namelessRuins['zone_name'].' 深度'.$namelessRuins['depth'],
            'usesStamina' => false, 'battleWaitSeconds' => 0,
            'stamina' => app(ExplorationStaminaService::class)->summary($character),
        ])->header('Cache-Control', 'no-store');
    }

    public function returnToTown(Request $request, string $tab)
    {
        abort_unless(app(NamelessWorkshopService::class)->ready(), 404);
        abort_unless(in_array($tab, ['town', 'dungeon'], true), 404);
        $town = app(\App\Services\NamelessTownService::class)->availableTown();
        abort_unless($town && (int) $request->user()->currentCharacter()->current_city_id === (int) $town->id, 404);
        session(['current_location' => $tab]);
        return redirect()->route('home', ['skip_resume' => 1]);
    }

    private function forgeRules(bool $confirm): array
    {
        $rules = ['equipment_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:0'], 'materials' => ['sometimes', 'array', 'max:300'], 'materials.*' => ['integer', 'min:0', 'max:1000000'], 'relics' => ['sometimes', 'array', 'max:300'], 'relics.*' => ['integer', 'min:1', 'distinct'], 'protect_best' => ['required', 'boolean'], 'use_bank' => ['sometimes', 'boolean']];
        if ($confirm) {
            $rules += ['confirmation_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']];
        }

        return $rules;
    }

    private function relicGrowthRules(bool $confirm): array
    {
        $rules = ['relic_id' => ['required', 'integer', 'min:1'], 'source_relics' => ['required', 'array', 'min:1', 'max:4'],
            'source_relics.*' => ['required', 'integer', 'min:1', 'distinct'], 'equipment_id' => ['sometimes', 'integer', 'min:1'],
            'effect' => ['sometimes', 'nullable', 'string', 'max:64']];
        if ($confirm) {
            $rules += ['confirmation_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']];
        }

        return $rules;
    }

    private function feedRules(bool $confirm): array
    {
        $rules = ['equipment_id' => ['required', 'integer', 'min:1'], 'materials' => ['sometimes', 'array', 'max:300'], 'materials.*' => ['integer', 'min:0', 'max:1000000'], 'relics' => ['sometimes', 'array', 'max:300'], 'relics.*' => ['integer', 'min:1', 'distinct'], 'keep' => ['required', 'integer', 'min:0', 'max:1000000'], 'protect_best' => ['required', 'boolean']];
        if ($confirm) {
            $rules += ['confirmation_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']];
        }

        return $rules;
    }
}
