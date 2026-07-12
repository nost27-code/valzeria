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
        ]);
    }
}
