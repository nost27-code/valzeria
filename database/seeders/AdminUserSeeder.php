<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@valzeria.local'],
            [
                'name' => '管理者',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        $this->command?->info('管理者アカウント(admin@valzeria.local / password)を作成・更新しました。');
    }
}
