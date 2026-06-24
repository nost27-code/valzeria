<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = [
        'ACC0003' => '素材交換所で魔物の魔核10個と魔鉱片5個から錬成できます。',
        'ACC0011' => '素材交換所で獣牙8個とアークレアの粗素材2個から錬成できます。',
        'ACC0014' => '素材交換所で魔物の外殻8個とアークレアの粗素材2個から錬成できます。',
        'ACC0017' => '素材交換所で魔鉱片8個と魔導結晶2個から錬成できます。',
        'ACC0020' => '素材交換所で妖精粉8個と世界樹の葉片2個から錬成できます。',
        'ACC0023' => '素材交換所で薄い翼膜8個と獣の毛皮4個から錬成できます。',
        'ACC0026' => '素材交換所で妖精粉8個と黒結晶4個から錬成できます。',
        'ACC0029' => '素材交換所で魔物の外殻8個と世界樹の葉片2個から錬成できます。',
        'ACC0032' => '素材交換所で魔鉱片8個と魔導結晶2個から錬成できます。',
        'ACC0035' => '素材交換所で古びた徽章8個と魔物の欠片8個から錬成できます。',
        'ACC0038' => '素材交換所で古びた徽章8個とアークレアの粗素材2個から錬成できます。',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $hasUsageTags = Schema::hasColumn('materials', 'usage_tags');
        $hasObtainMethod = Schema::hasColumn('materials', 'obtain_method');
        $hasMainUse = Schema::hasColumn('materials', 'main_use');

        foreach (self::TARGETS as $code => $obtainMethod) {
            $material = DB::table('materials')->where('material_code', $code)->first(['id', 'usage_tags']);
            if (!$material) {
                continue;
            }

            $payload = [];
            if ($hasObtainMethod) {
                $payload['obtain_method'] = $obtainMethod;
            }
            if ($hasMainUse) {
                $payload['main_use'] = '装飾品進化・素材交換所';
            }
            if ($hasUsageTags) {
                $tags = json_decode((string) ($material->usage_tags ?? '[]'), true);
                if (!is_array($tags)) {
                    $tags = [];
                }
                $tags[] = '合成';
                $tags[] = '交換所';
                $payload['usage_tags'] = json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_UNICODE);
            }

            if ($payload !== []) {
                DB::table('materials')->where('id', $material->id)->update($payload);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $payload = [];
        if (Schema::hasColumn('materials', 'obtain_method')) {
            $payload['obtain_method'] = '装飾品分解・敵ドロップ';
        }
        if (Schema::hasColumn('materials', 'main_use')) {
            $payload['main_use'] = '装飾品進化';
        }

        foreach (array_keys(self::TARGETS) as $code) {
            if ($payload !== []) {
                DB::table('materials')->where('material_code', $code)->update($payload);
            }

            if (!Schema::hasColumn('materials', 'usage_tags')) {
                continue;
            }

            DB::table('materials')
                ->where('material_code', $code)
                ->get(['id', 'usage_tags'])
                ->each(function (object $material): void {
                    $tags = json_decode((string) ($material->usage_tags ?? '[]'), true);
                    if (!is_array($tags)) {
                        $tags = [];
                    }

                    $tags = array_values(array_filter($tags, fn (string $tag): bool => $tag !== '交換所'));
                    DB::table('materials')->where('id', $material->id)->update([
                        'usage_tags' => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
                    ]);
                });
        }
    }
};
