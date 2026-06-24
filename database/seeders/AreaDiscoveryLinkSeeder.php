<?php

namespace Database\Seeders;

use App\Models\AreaDiscoveryLink;
use Illuminate\Database\Seeder;

class AreaDiscoveryLinkSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->links() as $index => $link) {
            AreaDiscoveryLink::updateOrCreate(
                [
                    'from_type' => $link['from_type'],
                    'from_id' => $link['from_id'],
                    'to_type' => $link['to_type'],
                    'to_id' => $link['to_id'],
                ],
                [
                    'condition_type' => $link['condition_type'],
                    'required_development_point' => $link['required_development_point'] ?? null,
                    'requires_boss_defeated' => $link['requires_boss_defeated'] ?? false,
                    'rumor_text' => $link['rumor_text'] ?? null,
                    'implementation_phase' => $link['implementation_phase'] ?? 'Phase 1',
                    'sort_order' => $index + 1,
                ]
            );
        }

        $this->command?->info('発見リンクを登録・更新しました。');
    }

    private function links(): array
    {
        $links = [
            $this->link('city', 1, 'area', 1, 'initial'),
        ];

        $cityStarts = [1, 8, 15, 22, 29, 36, 43, 50, 57, 64];
        $routeAreas = [75, 76, 77, 78, 79, 80, 81, 82, 83];
        $routeRumors = [
            '王都の西へ続く街道が開けている',
            '深海神殿の浮上した石橋が、内陸の森道へ続いている',
            '月光庭園の外れに、鍛冶街方面へ下る山麓路がある',
            '兵器庫の北門から、雪国へ続く峠道が見える',
            '山脈の南斜面に、砂漠へ向かう交易路が埋もれている',
            '太陽神殿の星図が、魔導学院への学術街道を示している',
            '次元回廊の外れに、禁呪で封じられた境界路がある',
            '奈落の階段のさらに上方に、白い巡礼階段が続いている',
            '祭壇の空に黒雲が裂け、魔王城への征路が現れている',
        ];
        $cityRumors = [
            '潮風の匂いがする街道の先に港町がある',
            '潮風が弱まり、木々のさざめきが近づいている',
            '谷の向こうに、炉煙を上げる鍛冶街が見える',
            '峠の先で、白い町灯りが雪に滲んでいる',
            '砂混じりの風の向こうに、旅人の宿場が見える',
            '星砂の道の先に、魔導学院の塔影が見える',
            '境界路の奥から、死霊街の鐘が低く鳴っている',
            '巡礼階段の雲上に、天空神殿の白い柱が見える',
            '黒雲の彼方に、魔王城の尖塔が浮かび上がる',
        ];

        foreach ($cityStarts as $cityIndex => $start) {
            $cityId = $cityIndex + 1;
            if ($cityId > 1) {
                $links[] = $this->link('city', $cityId, 'area', $start, 'city_discovered', null, null, 'Phase 2');
            }

            $links = array_merge($links, $this->cityAreaLinks($start));

            if ($cityIndex < count($routeAreas)) {
                $lastArea = $start + 6;
                $routeArea = $routeAreas[$cityIndex];
                $links[] = $this->link('area', $lastArea, 'route_area', $routeArea, 'boss_defeated', null, $routeRumors[$cityIndex], 'Phase 2', true);
                $links[] = $this->link('route_area', $routeArea, 'city', $cityId + 1, 'development_point', 100, $cityRumors[$cityIndex], 'Phase 2');
            }
        }

        return $links;
    }

    private function cityAreaLinks(int $start): array
    {
        $links = [
            $this->link('area', $start, 'area', $start + 1, 'development_point', 30, '近くに別の道が見えはじめている'),
            $this->link('area', $start, 'area', $start + 2, 'development_point', 50, '少し離れた場所からただならぬ気配がする'),
            $this->link('area', $start, 'area', $start + 3, 'development_point', 100, '奥へ続く本筋の道が開けそうだ'),
        ];

        if ($start === 15) {
            $links[] = $this->link('area', 16, 'area', 20, 'boss_or_development', 100, '妖精たちの祈りが、森の奥の神殿へ流れている', 'Phase 1', true);
            $links[] = $this->link('area', 18, 'area', 19, 'development_point', 100, '中層の枝先から、さらに空へ伸びる足場が見える');
            $links[] = $this->link('area', 19, 'area', 21, 'development_point', 100, '上層の葉陰に、月明かりだけで咲く庭園が浮かんでいる');

            return $links;
        }

        $links[] = $this->link('area', $start + 1, 'area', $start + 5, 'boss_or_development', 100, '分岐の奥に、寄り道できそうな気配がある', 'Phase 1', true);
        $links[] = $this->link('area', $start + 2, 'area', $start + 4, 'boss_or_development', 100, '別道の奥に、隠れた戦場が続いている', 'Phase 1', true);
        $links[] = $this->link('area', $start + 3, 'area', $start + 6, 'development_point', 100, 'この地方の最奥へ続く道が見えてきた');

        return $links;
    }

    private function link(
        string $fromType,
        int $fromId,
        string $toType,
        int $toId,
        string $conditionType,
        ?int $requiredDevelopmentPoint = null,
        ?string $rumorText = null,
        string $phase = 'Phase 1',
        bool $requiresBossDefeated = false
    ): array {
        return [
            'from_type' => $fromType,
            'from_id' => $fromId,
            'to_type' => $toType,
            'to_id' => $toId,
            'condition_type' => $conditionType,
            'required_development_point' => $requiredDevelopmentPoint,
            'requires_boss_defeated' => $requiresBossDefeated,
            'rumor_text' => $rumorText,
            'implementation_phase' => $phase,
        ];
    }
}
