<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        User::updateOrCreate(
            ['email' => 'yuta.nostalgia@gmail.com'],
            [
                'name' => 'yuta',
                'password' => Hash::make('nostalgia0905'),
                'role' => 'admin',
            ]
        );
    }

    public function down(): void
    {
        User::where('email', 'yuta.nostalgia@gmail.com')
            ->where('role', 'admin')
            ->delete();
    }
};
