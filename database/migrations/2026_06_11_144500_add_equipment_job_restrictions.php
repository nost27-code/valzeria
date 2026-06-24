<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'weapon_category')) {
                $table->string('weapon_category', 50)->nullable()->after('sub_type');
            }
            if (!Schema::hasColumn('items', 'weapon_hand_type')) {
                $table->string('weapon_hand_type', 20)->nullable()->after('weapon_category');
            }
            if (!Schema::hasColumn('items', 'weapon_role')) {
                $table->string('weapon_role', 20)->nullable()->after('weapon_hand_type');
            }
            if (!Schema::hasColumn('items', 'armor_category')) {
                $table->string('armor_category', 50)->nullable()->after('weapon_role');
            }
            if (!Schema::hasColumn('items', 'armor_weight')) {
                $table->string('armor_weight', 20)->nullable()->after('armor_category');
            }
            if (!Schema::hasColumn('items', 'armor_role')) {
                $table->string('armor_role', 20)->nullable()->after('armor_weight');
            }
        });

        Schema::create('weapon_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_key', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('armor_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_key', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('job_weapon_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('weapon_category', 50);
            $table->timestamps();
            $table->unique(['job_id', 'weapon_category']);
            $table->index('job_id');
            $table->index('weapon_category');
        });

        Schema::create('job_armor_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('armor_category', 50);
            $table->timestamps();
            $table->unique(['job_id', 'armor_category']);
            $table->index('job_id');
            $table->index('armor_category');
        });

        $now = now();
        foreach ($this->weaponCategories() as $key => [$name, $description, $sort]) {
            DB::table('weapon_categories')->updateOrInsert(
                ['category_key' => $key],
                ['name' => $name, 'description' => $description, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach ($this->armorCategories() as $key => [$name, $description, $sort]) {
            DB::table('armor_categories')->updateOrInsert(
                ['category_key' => $key],
                ['name' => $name, 'description' => $description, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $this->classifyExistingItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('job_armor_permissions');
        Schema::dropIfExists('job_weapon_permissions');
        Schema::dropIfExists('armor_categories');
        Schema::dropIfExists('weapon_categories');

        Schema::table('items', function (Blueprint $table) {
            foreach (['weapon_category', 'weapon_hand_type', 'weapon_role', 'armor_category', 'armor_weight', 'armor_role'] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function weaponCategories(): array
    {
        return [
            'sword' => ['剣', '標準的な剣。剣士・騎士系が得意とする。', 10],
            'axe' => ['斧', '高威力の重武器。戦士・狂戦士系が得意とする。', 20],
            'dagger' => ['短剣', '軽量で扱いやすい武器。盗賊・忍者系が得意とする。', 30],
            'bow' => ['弓', '遠距離武器。弓使い・狙撃手系が得意とする。', 40],
            'staff' => ['杖', '魔法や回復に適した武器。魔法使い・僧侶系が得意とする。', 50],
            'magic_device' => ['魔導具', '魔力を増幅する装置。魔法職・機工系が扱う。', 60],
            'gun' => ['銃', '機械式の遠距離武器。狙撃手・機工系が扱う。', 70],
            'spear' => ['槍', 'リーチに優れた武器。騎士・竜騎士系が得意とする。', 80],
            'fist' => ['拳甲', '拳を強化する武器。格闘家・武神系が得意とする。', 90],
            'katana' => ['刀', '技量を要する刃。侍・剣聖系が得意とする。', 100],
        ];
    }

    private function armorCategories(): array
    {
        return [
            'clothes' => ['服・旅装', '軽く扱いやすい防具。多くの職業が装備できる。', 10],
            'robe' => ['ローブ・法衣', '魔法や精神力に優れた防具。魔法職・僧侶職向け。', 20],
            'cloak' => ['外套・マント', '身軽さと防御を両立する防具。軽量職・魔法職向け。', 30],
            'light_armor' => ['革鎧・軽鎧', '動きやすさを残した鎧。前衛・軽量職向け。', 40],
            'heavy_armor' => ['鎧・重鎧', '防御力に優れた重装備。戦士・騎士系向け。', 50],
        ];
    }

    private function classifyExistingItems(): void
    {
        $weaponRules = [
            'magic_device' => ['魔導具', '魔導', 'オーブ', 'グリモア', '水晶', '魔器'],
            'katana' => ['刀', '太刀', '村正', '正宗'],
            'dagger' => ['短剣', 'ダガー', 'ナイフ'],
            'spear' => ['槍', 'ランス', 'スピア'],
            'staff' => ['杖', 'ロッド', 'ワンド'],
            'bow' => ['弓', 'ボウ'],
            'gun' => ['銃', '短銃', 'ライフル'],
            'axe' => ['斧', 'アックス'],
            'fist' => ['拳', '拳甲', 'ナックル', 'グローブ'],
            'sword' => ['剣', 'ブレード', 'サーベル'],
        ];

        $armorRules = [
            'heavy_armor' => ['重鎧', '鎧', '甲冑', 'プレート', 'メイル'],
            'light_armor' => ['革鎧', '軽鎧', 'レザー', 'ジャケット'],
            'robe' => ['ローブ', '法衣', '聖衣'],
            'cloak' => ['外套', 'マント', 'クローク', '羽織'],
            'clothes' => ['服', '衣', '旅装', 'チュニック'],
        ];

        $items = DB::table('items')
            ->select('id', 'name', 'type', 'weapon_category', 'armor_category')
            ->whereIn('type', ['weapon', 'armor'])
            ->get();

        foreach ($items as $item) {
            if ($item->type === 'weapon' && empty($item->weapon_category)) {
                $category = $this->matchCategory($item->name, $weaponRules);
                if ($category) {
                    DB::table('items')->where('id', $item->id)->update([
                        'weapon_category' => $category,
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($item->type === 'armor' && empty($item->armor_category)) {
                $category = $this->matchCategory($item->name, $armorRules);
                if ($category) {
                    DB::table('items')->where('id', $item->id)->update([
                        'armor_category' => $category,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function matchCategory(string $name, array $rules): ?string
    {
        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
};
