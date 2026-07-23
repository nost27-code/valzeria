<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 旧ランク比例補正の移行用Seeder。武器能力8倍化方式では使用しない。
 */
class WeaponStatRescaleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('WeaponStatRescaleSeeder は廃止済みです。武器能力は移行 2026_07_23_100000 で8倍化されます。');
    }
}
