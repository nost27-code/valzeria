<?php

namespace App\Services\Field;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Services\CharacterStatusService;
use App\Services\ExplorationService;
use App\Services\ExplorationStaminaService;
use App\Services\ExplorationStateService;
use App\Services\MapExplorationItemService;
use App\Services\StorageCapacityService;
use App\Services\SubAreaExplorationStateService;
use App\Services\ValmonService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * フィールドの魔物との戦闘。
 *
 * 触れた魔物との戦闘は既存の探索1回（ExplorationService::explore）を使い、
 * 探索力・報酬・敗北処理を通常探索と共有する。宝箱・採取は扱わない。
 */
class FieldEncounterService
{
    private const EXPLORE_REQUEST_DELAY_SECONDS = 2;

    public function __construct(private readonly ValzeriaFieldService $field) {}

    /**
     * そのマスに出る魔物の探索地（いちばん近い入口のエリア）。都市や入口のそばは null。
     */
    public function territoryAt(string $plane, int $tx, int $ty): ?int
    {
        $cfg = config('valzeria_field.encounters');
        $margin = (int) $cfg['safe_margin_tiles'];
        foreach ($this->field->cityDefinitions() as $city) {
            if ($city['plane'] !== $plane) {
                continue;
            }
            if ($tx >= $city['tx'] - $margin && $tx < $city['tx'] + $city['w'] + $margin
                && $ty >= $city['ty'] - $margin && $ty < $city['ty'] + $city['h'] + $margin) {
                return null;
            }
        }

        $best = null;
        $bestDistance = INF;
        foreach (config('valzeria_field.entrances') as $entrance) {
            $entrancePlane = $entrance['plane'] ?? ValzeriaFieldService::PLANE_LAND;
            if ($entrancePlane !== $plane) {
                continue;
            }
            [$ex, $ey] = $this->field->toTile($entrancePlane, $entrance['at']);
            $distance = hypot($tx - $ex, $ty - $ey);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (int) $entrance['area_id'];
            }
        }
        if ($best === null
            || $bestDistance > (int) $cfg['territory_tiles']
            || $bestDistance < (int) $cfg['entrance_safe_tiles']) {
            return null;
        }

        return $best;
    }

    /**
     * @param  list<int>  $areaIds
     * @return array<int, list<array{name: string, image: ?string}>>
     */
    public function monsterLooks(array $areaIds): array
    {
        $images = config('enemy_images', []);

        return Enemy::query()
            ->whereIn('area_id', $areaIds)
            ->where('is_boss', false)
            ->orderBy('id')
            ->get(['id', 'area_id', 'name'])
            ->groupBy('area_id')
            ->map(fn ($enemies) => $enemies->take(4)->map(fn (Enemy $enemy): array => [
                'name' => (string) $enemy->name,
                'image' => isset($images[(string) $enemy->name]) ? asset($images[(string) $enemy->name]) : null,
            ])->values()->all())
            ->all();
    }

    /** @return array{hp: int, max_hp: int, stamina: array<string, mixed>} */
    public function vitals(Character $character): array
    {
        $stats = app(CharacterStatusService::class)->getFinalStats($character);

        return [
            'hp' => max(0, (int) $character->current_hp),
            'max_hp' => max(1, (int) ($stats['max_hp'] ?? $character->hp_base ?? 1)),
            'stamina' => app(ExplorationStaminaService::class)->summary($character),
        ];
    }

    /** @return array<string, mixed> */
    public function fight(Character $character, Area $area, string $plane, int $x, int $y): array
    {
        $tile = $this->field->tilePx();
        if ($this->territoryAt($plane, intdiv($x, $tile), intdiv($y, $tile)) !== (int) $area->id) {
            return $this->failure($character, 'その魔物の気配は、もう遠くへ去ってしまった。');
        }

        if (app(StorageCapacityService::class)->isFull($character)) {
            return $this->failure($character, '倉庫がいっぱいで戦利品を持てない。街で整理しよう。');
        }

        if (! Cache::add("explore_request_delay:{$character->id}", true, now()->addSeconds(self::EXPLORE_REQUEST_DELAY_SECONDS))) {
            return $this->failure($character, '息を整えている……。少し待ってから戦おう。');
        }

        $states = app(ExplorationStateService::class);
        $current = $states->currentFor($character);
        if (! $current || (int) $current->area_id !== (int) $area->id || ! $states->hasActiveExploration($character)) {
            app(MapExplorationItemService::class)->end($character);
            session()->forget('active_map_exploration');
            $states->reset($character, (int) $area->id);
        }

        // 通常探索と同じ入口なので、現在設定の探索力コスト（既定1）と報酬処理をそのまま使う。
        $result = app(ExplorationService::class)->explore($character, (int) $area->id);
        if (isset($result['error'])) {
            return $this->failure($character, strip_tags((string) $result['error']));
        }
        session(['field_exploration_area_id' => (int) $area->id]);

        $payload = $this->present($result);
        if (($result['result'] ?? null) === 'defeat') {
            $this->closeExploration($character);
            [$spawnPlane, $spawnX, $spawnY] = $this->field->spawnPoint((int) ($character->current_city_id ?: 1));
            $this->field->savePosition($character, $spawnPlane, $spawnX, $spawnY, 0);
            $payload['respawn'] = ['plane' => $spawnPlane, 'x' => $spawnX, 'y' => $spawnY];
        }
        $character->refresh();
        $payload['vitals'] = $this->vitals($character);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function failure(Character $character, string $message): array
    {
        return ['ok' => false, 'message' => $message, 'vitals' => $this->vitals($character)];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function present(array $result): array
    {
        $enemy = $result['enemy'] ?? null;
        $enemyImages = config('enemy_images', []);
        $imagePath = $result['enemy_image_path'] ?? ($enemyImages[(string) ($enemy->name ?? '')] ?? null);

        $drops = [];
        foreach ($result['material_drop'] ?? [] as $material) {
            $drops[] = ($material['name'] ?? '素材').' x'.max(1, (int) ($material['quantity'] ?? 1));
        }
        foreach ($result['equipment_drops'] ?? [] as $equipment) {
            $name = $equipment['item_name'] ?? $equipment['name'] ?? null;
            if ($name) {
                $drops[] = (string) $name;
            }
        }
        if (! empty($result['kiseki_drop'])) {
            $drops[] = '輝石 x'.(int) ($result['kiseki_drop']['amount'] ?? $result['kiseki_drop']['quantity'] ?? 1);
        }
        if (! empty($result['monster_mark_drop']['name'])) {
            $drops[] = (string) $result['monster_mark_drop']['name'];
        }
        if (! empty($result['map_drop']['name'])) {
            $drops[] = (string) $result['map_drop']['name'];
        }

        $notes = [];
        if (! empty($result['gold_loss']['amount'])) {
            $notes[] = '所持金を '.number_format((int) $result['gold_loss']['amount']).'G 失った……。';
        }
        if (! empty($result['material_penalty']['total_lost'])) {
            $notes[] = '探索で得た素材の一部を落としてしまった……。';
        }
        if (! empty($result['valmon_egg_found']['name'])) {
            $notes[] = "{$result['valmon_egg_found']['name']}の卵を見つけた！";
        }
        foreach ($result['unlocked_areas'] ?? [] as $unlocked) {
            $name = is_array($unlocked) ? ($unlocked['name'] ?? null) : ($unlocked->name ?? null);
            if ($name) {
                $notes[] = "新たな探索地「{$name}」への道が開けた！";
            }
        }

        return [
            'ok' => true,
            'outcome' => (string) ($result['result'] ?? 'draw'),
            'special_event' => $result['special_event'] ?? null,
            'enemy' => [
                'name' => (string) ($enemy->name ?? '魔物'),
                'image' => $imagePath ? asset($imagePath) : null,
            ],
            'turns' => $this->splitTurns((string) ($result['log'] ?? '')),
            'exp' => (int) ($result['exp_gained'] ?? 0),
            'gold' => (int) ($result['gold_gained'] ?? 0),
            'job_exp' => (int) ($result['job_exp_gained'] ?? 0),
            'level_ups' => array_values(array_map(fn (array $detail): int => (int) ($detail['level'] ?? 0), $result['level_up_details'] ?? [])),
            'drops' => $drops,
            'notes' => $notes,
        ];
    }

    /** @return list<array{title: string, html: string}> */
    public function splitTurns(string $log): array
    {
        $clean = strip_tags($log, '<br><span><b><strong>');
        $clean = preg_replace_callback('/<(span|b|strong)\b([^>]*)>/i', function (array $match): string {
            preg_match('/class\s*=\s*"([^"]*)"/i', $match[2], $class);

            return '<'.strtolower($match[1]).(isset($class[1]) ? ' class="'.e($class[1]).'"' : '').'>';
        }, $clean) ?? '';

        $parts = preg_split('/(?:<br\s*\/?>\s*)*---\s*ターン\s*(\d+)\s*---/u', $clean, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$clean];
        $turns = [];
        $opening = trim((string) array_shift($parts));
        if ($opening !== '') {
            $turns[] = ['title' => '戦闘開始', 'html' => $this->trimBreaks($opening)];
        }
        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            $turns[] = ['title' => "ターン {$parts[$index]}", 'html' => $this->trimBreaks((string) $parts[$index + 1])];
        }

        return $turns;
    }

    private function trimBreaks(string $html): string
    {
        return preg_replace('/^(?:\s*<br\s*\/?>)+|(?:<br\s*\/?>\s*)+$/i', '', trim($html)) ?? $html;
    }

    /** @return list<string> */
    public function returnIfInTown(Character $character, string $plane, int $x, int $y): array
    {
        if (! session()->has('field_exploration_area_id')) {
            return [];
        }
        $tile = $this->field->tilePx();
        $tx = intdiv($x, $tile);
        $ty = intdiv($y, $tile);
        $inside = $this->field->cityDefinitions()->contains(fn (array $city): bool => $city['plane'] === $plane
            && $tx >= $city['tx'] && $tx < $city['tx'] + $city['w']
            && $ty >= $city['ty'] && $ty < $city['ty'] + $city['h']);
        if (! $inside) {
            return [];
        }

        $eggs = $this->closeExploration($character);
        $messages = ['街に戻り、探索を終えた。'];
        foreach ($eggs as $egg) {
            $messages[] = ! empty($egg['stored'])
                ? "{$egg['name']}の卵は、すでに仲間にいるため所持品へ入れた。"
                : "卵がかえった！ {$egg['name']}が仲間になった！";
        }

        return $messages;
    }

    /** @return array<int, array<string, mixed>> */
    private function closeExploration(Character $character): array
    {
        session()->forget('field_exploration_area_id');

        return DB::transaction(function () use ($character): array {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $eggs = app(ValmonService::class)->hatchActiveEggs($locked);
            app(ExplorationStateService::class)->reset($locked);
            app(SubAreaExplorationStateService::class)->reset($locked);
            app(MapExplorationItemService::class)->end($locked);

            return $eggs;
        });
    }

    /** @return list<string> 宝箱・採取は未公開のため常に空 */
    public function claimedSpotKeys(Character $character): array
    {
        return [];
    }
}
