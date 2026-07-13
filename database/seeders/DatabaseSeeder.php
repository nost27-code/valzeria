<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CitySeeder::class,
            AllDungeonsSeeder::class,
            RouteAreaSeeder::class,
            FerdiaRegionSeeder::class,
            ExplorationSupportMasterSeeder::class,
            JobSystemSeeder::class,
            SkillSeeder::class,
            ItemSeeder::class,
            JobArtSeeder::class,
            Phase1Seeder::class,
            EnemySeeder::class,
            EnemyDropsSeeder::class,
            DropEquipmentAdditionsSeeder::class,
            AreaDiscoveryLinkSeeder::class,
            ValmonSeeder::class,
            NpcProcurementRequestSeeder::class,
            TitleSeeder::class,
            StarTreeTowerFloorSeeder::class,
            StarTreeTowerTitleSeeder::class,
            // WeaponStatRescaleSeeder::class は一時的にチェーンから除外している。
            // 理由: 銘V逸品の再計算誤り修正・進化素材の入手経路未整備の確認が完了するまで、
            // itemsテーブルの武器固定値(str_bonus/mag_bonus)を新仕様(×1.8/×2.5)へ
            // 書き換えたくないため（このSeeder自体は環境変数トグルを持たず、
            // 実行すれば即座にDBへ反映されるため config だけでは止められない）。
            // 有効化する際はこの行のコメントを外すか、
            // `php artisan db:seed --class="Database\Seeders\WeaponStatRescaleSeeder"` を手動実行する。
            // WeaponStatRescaleSeeder::class,
        ]);
    }
}
