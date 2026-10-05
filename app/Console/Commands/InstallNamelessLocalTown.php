<?php

namespace App\Console\Commands;

use App\Services\NamelessTownService;
use Illuminate\Console\Command;

class InstallNamelessLocalTown extends Command
{
    protected $signature = 'nameless:install-local-town';

    protected $description = 'ローカル試作の無もなき工房街だけを登録する';

    public function handle(NamelessTownService $town): int
    {
        $city = $town->installLocalTown();
        $this->info($city->name.'を登録しました。city_id='.$city->id);

        return self::SUCCESS;
    }
}
