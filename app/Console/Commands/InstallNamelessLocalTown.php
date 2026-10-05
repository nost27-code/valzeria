<?php

namespace App\Console\Commands;

use App\Services\NamelessTownService;
use Illuminate\Console\Command;

class InstallNamelessLocalTown extends Command
{
    protected $signature = 'nameless:install-local-town {--prepare-off : 機能OFFのまま確認済みschemaに街を登録}';

    protected $aliases = ['nameless:install-town'];

    protected $description = '有効化設定と必要なDB移行を確認して無もなき工房街を登録する';

    public function handle(NamelessTownService $town): int
    {
        $city = $town->installTown((bool) $this->option('prepare-off'));
        $this->info($city->name.'を登録しました。city_id='.$city->id);

        return self::SUCCESS;
    }
}
