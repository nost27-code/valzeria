<?php

namespace App\Services;

use App\Support\CharacterIconCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TownRankingService
{
    private const LIMIT = 30;

    public function boards(): array
    {
        return Cache::remember('town_ranking_boards_v4', now()->addMinutes(5), function (): array {
            $definitions = $this->definitions();

            return collect($definitions)
                ->map(function (array $definition, string $key): array {
                    $definition['key'] = $key;
                    $definition['rows'] = $this->rowsFor($key)->values()->all();

                    return $definition;
                })
                ->all();
        });
    }

    public function board(?string $key): array
    {
        $boards = $this->boards();

        return $boards[$key] ?? reset($boards);
    }

    public function definitions(): array
    {
        return [
            'wins' => [
                'title' => '勝利数番付',
                'short_title' => '勝利数',
                'unit' => '勝',
                'description' => 'これまでの通常戦や探索で積み上げた勝利数です。',
                'badge' => '武勇',
            ],
            'valmons' => [
                'title' => '所持ヴァルモン数番付',
                'short_title' => 'ヴァルモン',
                'unit' => '体',
                'description' => '牧場に迎えたヴァルモンの所持数です。',
                'badge' => '牧場',
            ],
            'monster_marks' => [
                'title' => 'モンスター印収集数番付',
                'short_title' => '印収集',
                'unit' => '個',
                'description' => '倉庫に記録されたモンスター印の総獲得数です。',
                'badge' => '印',
            ],
            'excellent_equipment' => [
                'title' => '逸品装備所持数番付',
                'short_title' => '逸品装備',
                'unit' => '個',
                'description' => '現在所持している【逸品】装備の数です。',
                'badge' => '逸品',
            ],
            'materials' => [
                'title' => '素材総所持数番付',
                'short_title' => '素材総数',
                'unit' => '個',
                'description' => '素材倉庫にある素材の合計所持数です。',
                'badge' => '素材',
            ],
            'riches' => [
                'title' => '総資産番付',
                'short_title' => '総資産',
                'unit' => 'G',
                'description' => '冒険者の手持ちGoldと銀行預金、宿屋の累計売上を合わせて比べる総資産です。',
                'badge' => 'Gold',
            ],
            'market_sales' => [
                'title' => '市場売上番付',
                'short_title' => '市場売上',
                'unit' => 'G',
                'description' => '冒険者市場で販売して受け取ったGoldの合計です。',
                'badge' => '市場',
            ],
            'procurement_deliveries' => [
                'title' => '調達納品数番付',
                'short_title' => '調達納品',
                'unit' => '個',
                'description' => 'NPC調達依頼へ納品した素材数の合計です。',
                'badge' => '納品',
            ],
            'job_masters' => [
                'title' => '仕事人番付',
                'short_title' => '仕事人',
                'unit' => '職',
                'description' => 'マスターした職業数です。',
                'badge' => '職人',
            ],
            'excellent_appraiser' => [
                'title' => '逸品鑑定士番付',
                'short_title' => '逸品鑑定士',
                'unit' => '点',
                'description' => '現在所持している逸品を3点、良品を1点として数えた鑑定点です。',
                'badge' => '鑑定',
            ],
        ];
    }

    private function rowsFor(string $key): Collection
    {
        return match ($key) {
            'wins' => $this->characterValueRows('COALESCE(characters.wins, 0)', '勝利'),
            'valmons' => $this->countRows('player_valmons', 'character_id', 'ヴァルモン'),
            'monster_marks' => $this->sumRows('character_monster_marks', 'character_id', 'quantity', '印'),
            'excellent_equipment' => $this->excellentEquipmentRows(),
            'materials' => $this->sumRows('character_materials', 'character_id', 'quantity', '素材'),
            'riches' => $this->richesRows(),
            'market_sales' => $this->marketSalesRows(),
            'procurement_deliveries' => $this->sumRows('npc_procurement_deliveries', 'character_id', 'quantity', '納品'),
            'job_masters' => $this->jobMasterRows(),
            'excellent_appraiser' => $this->excellentAppraiserRows(),
            default => collect(),
        };
    }

    private function characterValueRows(string $expression, string $detailLabel): Collection
    {
        if (!Schema::hasTable('characters')) {
            return collect();
        }

        return DB::table('characters')
            ->select([
                'characters.id',
                'characters.name',
                'characters.icon_path',
                'characters.level',
                'characters.profile_comment',
                DB::raw($expression . ' as score'),
            ])
            ->whereRaw($expression . ' > 0')
            ->orderByDesc('score')
            ->orderBy('characters.id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn ($row) => $this->formatRow($row, "{$detailLabel} " . number_format((int) $row->score)));
    }

    private function countRows(string $table, string $characterColumn, string $detailLabel): Collection
    {
        if (!Schema::hasTable($table) || !Schema::hasTable('characters')) {
            return collect();
        }

        $sub = DB::table($table)
            ->select($characterColumn, DB::raw('COUNT(*) as score'))
            ->groupBy($characterColumn);

        return $this->joinedAggregateRows($sub, $characterColumn, $detailLabel);
    }

    private function sumRows(string $table, string $characterColumn, string $valueColumn, string $detailLabel): Collection
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $valueColumn) || !Schema::hasTable('characters')) {
            return collect();
        }

        $sub = DB::table($table)
            ->select($characterColumn, DB::raw("SUM(COALESCE({$valueColumn}, 0)) as score"))
            ->groupBy($characterColumn);

        return $this->joinedAggregateRows($sub, $characterColumn, $detailLabel);
    }

    private function excellentEquipmentRows(): Collection
    {
        if (!Schema::hasTable('character_items') || !Schema::hasColumn('character_items', 'affix_quality')) {
            return collect();
        }

        $sub = DB::table('character_items')
            ->select('character_id', DB::raw('COUNT(*) as score'))
            ->where('affix_quality', 'excellent')
            ->groupBy('character_id');

        return $this->joinedAggregateRows($sub, 'character_id', '逸品装備');
    }

    private function richesRows(): Collection
    {
        $bank = Schema::hasColumn('characters', 'bank_gold') ? 'COALESCE(characters.bank_gold, 0)' : '0';
        $rows = $this->characterValueRows("COALESCE(characters.money, 0) + {$bank}", '総資産');

        if ($innRow = $this->innRevenueRow()) {
            $rows->push($innRow);
        }

        return $rows
            ->sortByDesc('score')
            ->values()
            ->take(self::LIMIT);
    }

    private function marketSalesRows(): Collection
    {
        if (!Schema::hasTable('market_transactions')) {
            return collect();
        }

        $sub = DB::table('market_transactions')
            ->select('seller_character_id', DB::raw('SUM(COALESCE(seller_received, total_price, 0)) as score'))
            ->whereNotNull('seller_character_id')
            ->groupBy('seller_character_id');

        return $this->joinedAggregateRows($sub, 'seller_character_id', '市場売上');
    }

    private function jobMasterRows(): Collection
    {
        if (!Schema::hasTable('character_jobs')) {
            return collect();
        }

        $sub = DB::table('character_jobs')
            ->select('character_id', DB::raw('SUM(CASE WHEN COALESCE(is_mastered, 0) = 1 OR COALESCE(job_level, 0) >= 10 THEN 1 ELSE 0 END) as score'))
            ->groupBy('character_id');

        return $this->joinedAggregateRows($sub, 'character_id', 'マスター職');
    }

    private function excellentAppraiserRows(): Collection
    {
        if (!Schema::hasTable('character_items') || !Schema::hasColumn('character_items', 'affix_quality')) {
            return collect();
        }

        $sub = DB::table('character_items')
            ->select('character_id', DB::raw("SUM(CASE WHEN affix_quality = 'excellent' THEN 3 WHEN affix_quality = 'good' THEN 1 ELSE 0 END) as score"))
            ->groupBy('character_id');

        return $this->joinedAggregateRows($sub, 'character_id', '鑑定点');
    }

    private function innRevenueRow(): ?array
    {
        if (!Schema::hasTable('gold_transactions')) {
            return null;
        }

        $score = (int) DB::table('gold_transactions')
            ->where('type', 'inn')
            ->where('amount', '<', 0)
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) as total')
            ->value('total');

        if ($score <= 0) {
            return null;
        }

        return [
            'character_id' => null,
            'name' => '宿屋のおばあちゃん＆おじいちゃん',
            'icon_path' => '/images/icon/icon_243.webp',
            'image_type' => 'asset',
            'level' => null,
            'profile_comment' => 'じいさんや、そろそろ宿を改装するかねぇ。',
            'score' => $score,
            'detail' => '宿屋売上 ' . number_format($score),
        ];
    }

    private function joinedAggregateRows($sub, string $characterColumn, string $detailLabel): Collection
    {
        return DB::table('characters')
            ->joinSub($sub, 'rank_values', function ($join) use ($characterColumn) {
                $join->on('rank_values.' . $characterColumn, '=', 'characters.id');
            })
            ->select([
                'characters.id',
                'characters.name',
                'characters.icon_path',
                'characters.level',
                'characters.profile_comment',
                DB::raw('COALESCE(rank_values.score, 0) as score'),
            ])
            ->where('rank_values.score', '>', 0)
            ->orderByDesc('score')
            ->orderBy('characters.id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn ($row) => $this->formatRow($row, "{$detailLabel} " . number_format((int) $row->score)));
    }

    private function formatRow(object $row, string $detail): array
    {
        return [
            'character_id' => (int) $row->id,
            'name' => (string) $row->name,
            'icon_path' => CharacterIconCatalog::normalize($row->icon_path ?? null),
            'image_type' => 'character',
            'level' => (int) ($row->level ?? 1),
            'profile_comment' => trim((string) ($row->profile_comment ?? '')) ?: 'よろしくお願いします',
            'score' => (int) $row->score,
            'detail' => $detail,
        ];
    }
}
