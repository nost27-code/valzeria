<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sub_areas')) {
            Schema::create('sub_areas', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('layer_type')->default('deep');
                $table->unsignedInteger('recommended_level_min')->default(1);
                $table->unsignedInteger('recommended_level_max')->default(1);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('world_first_character_id')->nullable();
                $table->timestamp('world_first_discovered_at')->nullable();
                $table->unsignedInteger('total_discoveries')->default(0);
                $table->unsignedInteger('total_clears')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->index(['layer_type', 'is_enabled']);
            });
        }

        if (!Schema::hasTable('sub_area_routes')) {
            Schema::create('sub_area_routes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_area_id');
                $table->unsignedBigInteger('source_area_id');
                $table->string('route_name');
                $table->unsignedInteger('min_exploration_point')->default(300);
                $table->unsignedInteger('min_danger_rate')->default(25);
                $table->unsignedInteger('min_character_level')->default(1);
                $table->unsignedBigInteger('required_boss_cleared_area_id')->nullable();
                $table->decimal('discovery_chance', 5, 2)->default(3.00);
                $table->text('entrance_description')->nullable();
                $table->string('enemy_pool_key')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['sub_area_id', 'source_area_id'], 'sub_area_routes_unique_source');
                $table->index(['source_area_id', 'is_enabled'], 'sub_area_routes_source_enabled_idx');
            });
        }

        if (!Schema::hasTable('character_sub_area_route_discoveries')) {
            Schema::create('character_sub_area_route_discoveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->unsignedBigInteger('sub_area_route_id');
                $table->timestamp('discovered_at')->nullable();
                $table->timestamp('first_entered_at')->nullable();
                $table->timestamp('first_cleared_at')->nullable();
                $table->unsignedInteger('discovery_exploration_point')->default(0);
                $table->unsignedInteger('discovery_danger_rate')->default(0);
                $table->timestamps();

                $table->unique(['character_id', 'sub_area_route_id'], 'character_sub_area_route_unique');
                $table->index(['sub_area_route_id', 'discovered_at'], 'character_sub_area_route_discovered_idx');
            });
        }

        $this->seedSubAreas();
    }

    public function down(): void
    {
        Schema::dropIfExists('character_sub_area_route_discoveries');
        Schema::dropIfExists('sub_area_routes');
        Schema::dropIfExists('sub_areas');
    }

    private function seedSubAreas(): void
    {
        $now = now();

        $subAreas = [
            [
                'name' => '古王国の水道橋',
                'layer_type' => 'deep',
                'recommended_level_min' => 45,
                'recommended_level_max' => 60,
                'description' => '王都と港町を地下でつなぐ、古王国時代の水道橋。',
                'routes' => [
                    [3, '古びた洞窟の崩れた水路', 300, 25, 35, 4.00, '苔むした壁の向こうに、古い水音が響いている。'],
                    [5, '忘れられた墓地の地下排水路', 330, 25, 35, 3.50, '墓石の下から、王都の古い地下水路へ続く階段を見つけた。'],
                    [8, '潮風の海岸の排水口', 300, 25, 35, 3.00, '波打ち際の岩陰に、人工的な水門が口を開けている。'],
                    [9, '海蝕洞窟の旧水門', 300, 25, 35, 4.00, '潮の奥で、王都の紋章が刻まれた水門を見つけた。'],
                ],
            ],
            [
                'name' => '潮鉄の旧坑道',
                'layer_type' => 'deep',
                'recommended_level_min' => 55,
                'recommended_level_max' => 75,
                'description' => '港町と鍛冶街をつなぐ、錆びた鉱石運搬路。',
                'routes' => [
                    [9, '海蝕洞窟の錆びた軌道', 360, 30, 45, 3.00, '錆びた鉱車の線路が、海の底へ沈むように伸びている。'],
                    [10, '難破船の残骸下の搬入口', 380, 30, 45, 2.50, '船底のさらに下に、鉱石を運んだ古い搬入口が見える。'],
                    [22, '鉄鉱山の海風坑', 340, 25, 45, 3.50, '坑道の奥から、潮の匂いが流れてきた。'],
                    [23, '廃坑の古い運搬路', 360, 30, 45, 3.50, '使われなくなった線路が、見知らぬ地下道へ続いている。'],
                ],
            ],
            [
                'name' => '世界樹の水脈',
                'layer_type' => 'deep',
                'recommended_level_min' => 80,
                'recommended_level_max' => 110,
                'description' => '王都、精霊の森、魔導学院を結ぶ中央大陸の霊脈。',
                'routes' => [
                    [6, '妖精の泉の底光り', 500, 35, 60, 2.00, '泉の底で、淡い光が脈打っている。'],
                    [17, '世界樹の根の水音', 450, 35, 60, 3.00, '巨大な根の隙間から、清らかな地下水脈が見える。'],
                    [43, '魔導図書館の霊脈図', 520, 40, 70, 2.00, '古い地図が、世界樹へ続く水脈を指し示している。'],
                    [46, '浮遊庭園の落水路', 520, 40, 70, 2.00, '空中庭園の水が、地上ではないどこかへ流れ落ちている。'],
                ],
            ],
            [
                'name' => '月下の夢界',
                'layer_type' => 'otherworld',
                'recommended_level_min' => 90,
                'recommended_level_max' => 110,
                'description' => '月光と妖精の気配が混ざる、夢のような共有秘境。',
                'routes' => [
                    [6, '妖精の泉の月影', 600, 45, 70, 1.50, '水面に映った月が、こちらを見返している。'],
                    [5, '忘れられた墓地の白昼夢', 650, 45, 70, 1.20, '墓地の霧の奥で、月夜の庭園を見た気がした。'],
                    [20, '精霊神殿の眠り石', 560, 45, 70, 2.00, '祭壇の石が、眠るように淡く光っている。'],
                    [21, '月光庭園の夢門', 520, 40, 70, 3.00, '月光の中に、薄い扉の輪郭が浮かぶ。'],
                ],
            ],
            [
                'name' => '霜眠る精霊林',
                'layer_type' => 'deep',
                'recommended_level_min' => 110,
                'recommended_level_max' => 130,
                'description' => '精霊の森と雪原の境界に眠る、凍った樹霊の森。',
                'routes' => [
                    [16, '妖精の森の霜道', 650, 45, 85, 2.00, '若葉の上に、季節外れの霜が降りている。'],
                    [19, '世界樹上層の白い枝道', 700, 50, 85, 1.80, '白く凍った枝が、雪の森へ架かっている。'],
                    [31, '氷結洞窟の樹氷穴', 650, 45, 85, 2.00, '氷の奥に、森の匂いを含んだ空洞がある。'],
                    [32, '白銀の森の眠り根', 620, 45, 85, 2.50, '雪の下で、大きな根が静かに眠っている。'],
                ],
            ],
            [
                'name' => '星樹の観測庭',
                'layer_type' => 'otherworld',
                'recommended_level_min' => 140,
                'recommended_level_max' => 170,
                'description' => '世界樹と星見の塔を結ぶ、星と精霊の観測庭。',
                'routes' => [
                    [21, '月光庭園の星渡り橋', 800, 55, 110, 1.50, '月光の橋の向こうに、星々を映す庭が見える。'],
                    [19, '世界樹上層の星芽', 820, 55, 110, 1.20, '枝先の芽が、小さな星のように瞬いている。'],
                    [47, '星見の塔の観測門', 760, 55, 110, 2.20, '観測機の向こうに、森の影をまとった庭が映った。'],
                    [48, '異界観測所の星樹座標', 850, 60, 110, 1.50, '観測記録に、存在しない庭の座標が残っている。'],
                ],
            ],
            [
                'name' => '沈砂の密輸港',
                'layer_type' => 'deep',
                'recommended_level_min' => 130,
                'recommended_level_max' => 160,
                'description' => '海賊と砂漠商人が使った、砂に沈む密輸港。',
                'routes' => [
                    [12, '海賊の隠れ家の密輸桟橋', 720, 50, 95, 2.00, '朽ちた桟橋の下に、砂混じりの海風が流れている。'],
                    [10, '難破船の砂積み倉庫', 760, 50, 95, 1.50, '積荷の砂袋に、見知らぬ港印が押されている。'],
                    [36, '砂海の沈み船道', 720, 50, 95, 2.00, '砂の波間に、船底のような黒い影が見える。'],
                    [41, '地下水路の密輸水門', 760, 55, 95, 1.80, '水門の奥から、潮と香辛料の匂いが漂う。'],
                ],
            ],
            [
                'name' => '死砂の地下道',
                'layer_type' => 'deep',
                'recommended_level_min' => 150,
                'recommended_level_max' => 180,
                'description' => '死霊街と砂漠の王墓をつなぐ、乾いた墓道。',
                'routes' => [
                    [50, '死者の荒野の砂穴', 850, 60, 120, 1.80, '乾いた風が、地の底へ吸い込まれている。'],
                    [56, '奈落への階段の横穴', 900, 65, 120, 1.20, '奈落へ続く階段の脇に、砂に埋もれた横穴がある。'],
                    [39, '王家の墓の裏葬路', 820, 60, 120, 2.00, '王墓の壁裏に、死者を運ぶための細い道が続いている。'],
                    [41, '地下水路の干上がった支流', 850, 60, 120, 1.80, '水の消えた支流に、古い葬列の跡が残っている。'],
                ],
            ],
        ];

        foreach ($subAreas as $subArea) {
            $routes = $subArea['routes'];
            unset($subArea['routes']);

            $subAreaId = DB::table('sub_areas')->updateOrInsert(
                ['name' => $subArea['name']],
                array_merge($subArea, ['is_enabled' => true, 'updated_at' => $now, 'created_at' => $now])
            );

            $subAreaRecord = DB::table('sub_areas')->where('name', $subArea['name'])->first();
            if (!$subAreaRecord) {
                continue;
            }

            foreach ($routes as [$sourceAreaId, $routeName, $minPoint, $minDanger, $minLevel, $chance, $description]) {
                DB::table('sub_area_routes')->updateOrInsert(
                    ['sub_area_id' => $subAreaRecord->id, 'source_area_id' => $sourceAreaId],
                    [
                        'route_name' => $routeName,
                        'min_exploration_point' => $minPoint,
                        'min_danger_rate' => $minDanger,
                        'min_character_level' => $minLevel,
                        'required_boss_cleared_area_id' => null,
                        'discovery_chance' => $chance,
                        'entrance_description' => $description,
                        'enemy_pool_key' => null,
                        'is_enabled' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
};
