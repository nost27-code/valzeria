<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $this->updateObtainMethod('%の導石', 'エリア高位探索・強敵で入手する分岐進化素材。');
        $this->updateObtainMethod('%の古代片', '再戦不可ボス素材の代替。最深部候補・強敵で入手する古代素材。');
        $this->updateObtainMethod('%の秘境晶', '秘境探索で入手する高位素材。');
        $this->updateObtainMethod('%の極印', 'EPIC進化用の特別素材。高難度報酬で入手。');
    }

    public function down(): void
    {
        // Do not re-add champ battle as a source for branch evolution materials.
    }

    private function updateObtainMethod(string $nameLike, string $obtainMethod): void
    {
        if (!Schema::hasColumn('materials', 'obtain_method')) {
            return;
        }

        DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('name', 'like', $nameLike)
            ->update([
                'obtain_method' => $obtainMethod,
                'updated_at' => now(),
            ]);
    }
};
