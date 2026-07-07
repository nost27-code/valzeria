<?php

namespace App\Console\Commands;

use App\Services\OuterWorldDungeonGenerationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class GenerateOuterWorldDungeon extends Command
{
    protected $signature = 'outer-world:generate-dungeon
        {name : 生成する外縁ダンジョン名}
        {--apply : 実際に areas/enemies/materials/material_drops/area_discovery_links を更新する}
        {--area-id= : 固定したい area ID}
        {--city=10 : 所属させる街ID}
        {--from-area= : このダンジョンの探索度で発見する元エリアID}
        {--threshold=5000 : 発見に必要な探索度}
        {--target-power= : 現在戦力から逆算する目標戦力}
        {--level= : 目標戦力の代わりに使う敵Lv}
        {--slug= : area slug}
        {--sort-order= : area sort_order}
        {--unlock-order=99 : area unlock_order}
        {--description= : ダンジョン説明文}
        {--material-name= : 外縁限定素材名}
        {--material-code= : 外縁限定素材コード}
        {--material-drop-rate= : 外縁限定素材のドロップ率。material-name指定時は必須}';

    protected $description = 'Preview or create an outer-world dungeon, generated enemies, discovery link, and optional material drop.';

    public function handle(OuterWorldDungeonGenerationService $service): int
    {
        try {
            $input = $this->inputPayload();
            $result = (bool) $this->option('apply')
                ? $service->apply($input)
                : ['plan' => $service->plan($input)];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $plan = $result['plan'];
        $this->line((bool) $this->option('apply') ? 'Applied outer-world dungeon.' : 'Dry run: no database changes.');
        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Area', ($plan['area']['id'] ? '#' . $plan['area']['id'] . ' ' : '') . $plan['area']['name']],
                ['Slug', $plan['area']['slug']],
                ['City', $plan['area']['city_id']],
                ['Lv', $plan['area']['recommended_level_min'] . '-' . $plan['area']['recommended_level_max']],
                ['Discovery', $plan['discovery_link'] ? 'area #' . $plan['discovery_link']['from_id'] . ' / ' . $plan['discovery_link']['required_development_point'] : '-'],
                ['Target power', $plan['target_power'] ?? '-'],
                ['Material', $plan['material'] ? $plan['material']['material_code'] . ' / ' . $plan['material']['name'] . ' / ' . $plan['material']['drop_rate'] . '%' : '-'],
            ]
        );

        $this->table(
            ['Name', 'Lv', 'Role', 'HP', 'ATK', 'DEF', 'MAG', 'SPR', 'SPD', 'LUK', 'Boss'],
            collect($plan['enemies'])->map(fn (array $enemy): array => [
                $enemy['name'],
                $enemy['level'],
                $enemy['role_key'],
                $enemy['stats']['hp'],
                $enemy['stats']['attack'],
                $enemy['stats']['defense'],
                $enemy['stats']['magic'],
                $enemy['stats']['spirit'],
                $enemy['stats']['speed'],
                $enemy['stats']['luck'],
                $enemy['is_boss'] ? 'yes' : 'no',
            ])->all()
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputPayload(): array
    {
        return [
            'name' => $this->argument('name'),
            'area_id' => $this->option('area-id'),
            'city_id' => $this->option('city'),
            'from_area_id' => $this->option('from-area'),
            'required_development_point' => $this->option('threshold'),
            'target_power' => $this->option('target-power'),
            'base_level' => $this->option('level'),
            'slug' => $this->option('slug'),
            'sort_order' => $this->option('sort-order'),
            'unlock_order' => $this->option('unlock-order'),
            'description' => $this->option('description'),
            'material_name' => $this->option('material-name'),
            'material_code' => $this->option('material-code'),
            'material_drop_rate' => $this->option('material-drop-rate'),
        ];
    }
}
