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
            JobSeeder::class,
            SkillSeeder::class,
            ItemSeeder::class,
            JobArtSeeder::class,
            Phase1Seeder::class,
            EnemyDropsSeeder::class,
            AllDungeonsSeeder::class,
            RouteAreaSeeder::class,
            AreaDiscoveryLinkSeeder::class,
            EnemySeeder::class,
            DropEquipmentAdditionsSeeder::class,
            ValmonSeeder::class,
            NpcProcurementRequestSeeder::class,
        ]);
    }
}
