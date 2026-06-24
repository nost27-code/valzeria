<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        Schema::table('materials', function (Blueprint $table) {
            if (! Schema::hasColumn('materials', 'usage_summary')) {
                $table->string('usage_summary')->nullable()->after('main_use');
            }
            if (! Schema::hasColumn('materials', 'acquisition_summary')) {
                $table->string('acquisition_summary')->nullable()->after('usage_summary');
            }
            if (! Schema::hasColumn('materials', 'usage_tags')) {
                $table->json('usage_tags')->nullable()->after('acquisition_summary');
            }
            if (! Schema::hasColumn('materials', 'acquisition_tags')) {
                $table->json('acquisition_tags')->nullable()->after('usage_tags');
            }
            if (! Schema::hasColumn('materials', 'market_hint')) {
                $table->string('market_hint')->nullable()->after('acquisition_tags');
            }
            if (! Schema::hasColumn('materials', 'display_order')) {
                $table->integer('display_order')->default(0)->after('market_hint');
            }
        });

        $this->fillDisplayMetadata();
    }

    public function down(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        Schema::table('materials', function (Blueprint $table) {
            foreach ([
                'usage_summary',
                'acquisition_summary',
                'usage_tags',
                'acquisition_tags',
                'market_hint',
                'display_order',
            ] as $column) {
                if (Schema::hasColumn('materials', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function fillDisplayMetadata(): void
    {
        $now = now();

        DB::table('materials')
            ->where('material_type', 'common_drop')
            ->update([
                'usage_summary' => DB::raw("COALESCE(usage_summary, '装備進化・強化や序盤からの素材需要に使う通常素材です。')"),
                'acquisition_summary' => DB::raw("COALESCE(acquisition_summary, '通常探索で複数の敵から入手できます。')"),
                'usage_tags' => DB::raw("COALESCE(usage_tags, " . DB::getPdo()->quote($this->json(['通常素材', '装備進化'])) . ')'),
                'acquisition_tags' => DB::raw("COALESCE(acquisition_tags, " . DB::getPdo()->quote($this->json(['通常探索', '敵ドロップ'])) . ')'),
                'market_hint' => DB::raw("COALESCE(market_hint, '装備進化で継続的に使われるため、余剰分は市場で売れやすい素材です。')"),
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('material_type', 'regional_drop')
            ->update([
                'usage_summary' => DB::raw("COALESCE(usage_summary, '地域装備の進化や街ごとの素材需要に使う地域素材です。')"),
                'acquisition_summary' => DB::raw("COALESCE(acquisition_summary, '対応する街や周辺ダンジョンの敵から入手できます。')"),
                'usage_tags' => DB::raw("COALESCE(usage_tags, " . DB::getPdo()->quote($this->json(['地域素材', '装備進化'])) . ')'),
                'acquisition_tags' => DB::raw("COALESCE(acquisition_tags, " . DB::getPdo()->quote($this->json(['地域ダンジョン', '敵ドロップ'])) . ')'),
                'market_hint' => DB::raw("COALESCE(market_hint, '地域素材は供給元が限られるため、必要になるまでは保管もおすすめです。')"),
                'updated_at' => $now,
            ]);

        foreach ($this->definitions() as $index => $definition) {
            DB::table('materials')
                ->where('material_code', $definition['code'])
                ->update([
                    'usage_summary' => $definition['usage_summary'],
                    'acquisition_summary' => $definition['acquisition_summary'],
                    'usage_tags' => $this->json($definition['usage_tags']),
                    'acquisition_tags' => $this->json($definition['acquisition_tags']),
                    'market_hint' => $definition['market_hint'],
                    'display_order' => $index + 1,
                    'updated_at' => $now,
                ]);
        }
    }

    private function definitions(): array
    {
        return [
            [
                'code' => 'MAT_COMMON_SLIME_MUCUS',
                'usage_summary' => '防具や基礎装備の進化に使う、序盤から出番の多い素材です。',
                'acquisition_summary' => '草原・水辺・各地のスライム系モンスターから入手できます。',
                'usage_tags' => ['防具進化', '基礎素材', '通常素材'],
                'acquisition_tags' => ['スライム系', '通常探索'],
                'market_hint' => '低単価ですが必要数が増えやすく、序盤の市場で動きやすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_GOBLIN_FANG',
                'usage_summary' => '短剣・斧・拳具など、物理系装備の初期進化に使います。',
                'acquisition_summary' => '小鬼の森などの小鬼系モンスターから入手できます。',
                'usage_tags' => ['武器進化', '物理装備', '序盤素材'],
                'acquisition_tags' => ['小鬼系', '小鬼の森'],
                'market_hint' => '序盤装備で要求されやすいため、余りは市場出品の候補になります。',
            ],
            [
                'code' => 'MAT_COMMON_BEAST_FANG',
                'usage_summary' => '打撃武器や格闘系装備の進化に使う獣系素材です。',
                'acquisition_summary' => '牙や爪を持つ獣系モンスターから入手できます。',
                'usage_tags' => ['武器進化', '格闘装備', '獣系'],
                'acquisition_tags' => ['獣系', '通常探索'],
                'market_hint' => '物理系装備でまとまった数を使うため、一定の需要があります。',
            ],
            [
                'code' => 'MAT_COMMON_BEAST_FUR',
                'usage_summary' => '軽装備・旅装・防具系の進化に使う素材です。',
                'acquisition_summary' => 'ウルフや獣系モンスター、森・草原系ダンジョンで入手できます。',
                'usage_tags' => ['防具進化', '軽装備', '獣系'],
                'acquisition_tags' => ['ウルフ系', '森系', '草原'],
                'market_hint' => '防具系レシピで使われるため、武具更新期に需要が出やすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_WING_MEMBRANE',
                'usage_summary' => '弓・軽装・速度寄り装備の進化に使う薄い翼素材です。',
                'acquisition_summary' => 'コウモリや飛行系モンスターから入手できます。',
                'usage_tags' => ['軽装備', '弓', '速度系'],
                'acquisition_tags' => ['飛行系', 'コウモリ系'],
                'market_hint' => '必要な装備系統がはっきりしているため、使わない分は売却判断しやすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_OLD_BONE',
                'usage_summary' => '鎧・魔導書など、序盤から中盤の進化補助に使います。',
                'acquisition_summary' => '洞窟や墓地のスケルトン系モンスターから入手できます。',
                'usage_tags' => ['防具進化', '魔導書', '序盤素材'],
                'acquisition_tags' => ['スケルトン系', '洞窟', '墓地'],
                'market_hint' => '序盤で集めやすく、初期装備をまとめて進化する冒険者に需要があります。',
            ],
            [
                'code' => 'MAT_COMMON_ROTTEN_CLOTH',
                'usage_summary' => 'ローブ・魔装・忍び装備など、布系装備の進化に使います。',
                'acquisition_summary' => '墓地や闇系エリアのアンデッド系モンスターから入手できます。',
                'usage_tags' => ['布装備', '魔装', '忍び装備'],
                'acquisition_tags' => ['アンデッド系', '墓地', '闇系'],
                'market_hint' => '魔法職や軽装系の育成時に必要になりやすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_FAIRY_DUST',
                'usage_summary' => '魔法装備・祈祷系装備・幸運系装飾の進化に使います。',
                'acquisition_summary' => '妖精の泉や森・風系エリアの妖精系モンスターから入手できます。',
                'usage_tags' => ['魔法装備', '祈祷', '幸運装飾'],
                'acquisition_tags' => ['妖精系', '森系', '風系'],
                'market_hint' => '魔法系レシピで継続的に使われるため、売れやすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_MAGIC_ORE',
                'usage_summary' => '剣・槍・斧など、金属系武器の進化に使う鉱石素材です。',
                'acquisition_summary' => '洞窟・鉱山・鉄鋼都市周辺の敵から入手できます。',
                'usage_tags' => ['武器進化', '金属装備', '鍛冶'],
                'acquisition_tags' => ['洞窟', '鉱山', '鉄鋼都市'],
                'market_hint' => '武器更新で需要が高く、序盤から中盤まで市場で動きやすい素材です。',
            ],
            [
                'code' => 'MAT_COMMON_MONSTER_SHELL',
                'usage_summary' => '鎧・盾・耐久系装備の進化に使う硬質素材です。',
                'acquisition_summary' => '甲殻や外殻を持つ耐久型モンスターから入手できます。',
                'usage_tags' => ['防具進化', '重装備', '耐久系'],
                'acquisition_tags' => ['甲殻系', '耐久型'],
                'market_hint' => '防御系装備を伸ばす冒険者に向けて一定の需要があります。',
            ],
            [
                'code' => 'MAT_REGION_ARKREA_RAW',
                'usage_summary' => 'アークレア周辺装備の進化や序盤レシピの補助に使う地域素材です。',
                'acquisition_summary' => '王都アークレア周辺のダンジョンやボス報酬で入手できます。',
                'usage_tags' => ['地域素材', '序盤装備', '装備進化'],
                'acquisition_tags' => ['王都アークレア', '序盤地域'],
                'market_hint' => '序盤から複数レシピで使うため、不足時は市場購入の価値があります。',
            ],
            [
                'code' => 'MAT_REGION_TIDAL_PIECE',
                'usage_summary' => '港町・海系装備や中盤序盤の進化補助に使う地域素材です。',
                'acquisition_summary' => '港町マリネス周辺の海・船・水辺系ダンジョンで入手できます。',
                'usage_tags' => ['地域素材', '海系装備', '装備進化'],
                'acquisition_tags' => ['港町マリネス', '海系'],
                'market_hint' => '入手地域が限られるため、必要数だけ確保して余剰分を出品すると扱いやすい素材です。',
            ],
            [
                'code' => 'MAT_REGION_ICE_CRYSTAL',
                'usage_summary' => '氷属性装備や高ランク防具の進化に使う地域素材です。',
                'acquisition_summary' => '雪都フロストリア周辺の氷系ダンジョンで入手できます。',
                'usage_tags' => ['氷属性', '地域素材', '防具進化'],
                'acquisition_tags' => ['雪都フロストリア', '氷系'],
                'market_hint' => '氷系地域を周回できる冒険者が限られる時期は、価格が上がりやすい素材です。',
            ],
        ];
    }

    private function json(array $values): string
    {
        return json_encode(array_values($values), JSON_UNESCAPED_UNICODE);
    }
};
