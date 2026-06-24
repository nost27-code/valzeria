<?php

namespace Database\Seeders;

use App\Services\NpcProcurementRequestGenerationService;
use Illuminate\Database\Seeder;

class NpcProcurementRequestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(NpcProcurementRequestTemplateSeeder::class);

        app(NpcProcurementRequestGenerationService::class)->generateDailyRequests();
    }
}
