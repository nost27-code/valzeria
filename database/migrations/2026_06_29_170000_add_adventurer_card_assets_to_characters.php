<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_BACKGROUND = 'images/profile/adventurer_card_bg01.webp';
    private const DEFAULT_CARD_FRAME = 'images/profile/adventurer_card_frame01.webp';
    private const DEFAULT_AVATAR_FRAME = 'images/profile/adventurer_avatar_frame01.webp';

    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (!Schema::hasColumn('characters', 'profile_card_background')) {
                    $table->string('profile_card_background', 120)->default(self::DEFAULT_BACKGROUND)->after('profile_frame_theme');
                }
                if (!Schema::hasColumn('characters', 'profile_card_frame')) {
                    $table->string('profile_card_frame', 120)->default(self::DEFAULT_CARD_FRAME)->after('profile_card_background');
                }
                if (!Schema::hasColumn('characters', 'profile_avatar_frame')) {
                    $table->string('profile_avatar_frame', 120)->default(self::DEFAULT_AVATAR_FRAME)->after('profile_card_frame');
                }
            });
        }

        if (!Schema::hasTable('character_adventurer_card_assets')) {
            Schema::create('character_adventurer_card_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('asset_type', 40);
                $table->string('asset_path', 120);
                $table->string('source', 40)->default('default');
                $table->timestamp('obtained_at')->nullable();
                $table->timestamps();

                $table->unique(['character_id', 'asset_type', 'asset_path'], 'character_card_assets_unique');
            });
        }

        if (Schema::hasTable('characters') && Schema::hasTable('character_adventurer_card_assets')) {
            $now = now();
            DB::table('characters')
                ->select('id')
                ->orderBy('id')
                ->chunk(500, function ($characters) use ($now) {
                    $rows = [];

                    foreach ($characters as $character) {
                        foreach ([
                            ['background', self::DEFAULT_BACKGROUND],
                            ['card_frame', self::DEFAULT_CARD_FRAME],
                            ['avatar_frame', self::DEFAULT_AVATAR_FRAME],
                        ] as [$type, $path]) {
                            $rows[] = [
                                'character_id' => $character->id,
                                'asset_type' => $type,
                                'asset_path' => $path,
                                'source' => 'default',
                                'obtained_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    DB::table('character_adventurer_card_assets')->upsert(
                        $rows,
                        ['character_id', 'asset_type', 'asset_path'],
                        ['updated_at']
                    );
                });

            DB::table('characters')
                ->where(function ($query) {
                    $query->whereNull('profile_card_background')
                        ->orWhere('profile_card_background', '');
                })
                ->update(['profile_card_background' => self::DEFAULT_BACKGROUND]);

            DB::table('characters')
                ->where(function ($query) {
                    $query->whereNull('profile_card_frame')
                        ->orWhere('profile_card_frame', '');
                })
                ->update(['profile_card_frame' => self::DEFAULT_CARD_FRAME]);

            DB::table('characters')
                ->where(function ($query) {
                    $query->whereNull('profile_avatar_frame')
                        ->orWhere('profile_avatar_frame', '');
                })
                ->update(['profile_avatar_frame' => self::DEFAULT_AVATAR_FRAME]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_adventurer_card_assets');

        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                foreach (['profile_avatar_frame', 'profile_card_frame', 'profile_card_background'] as $column) {
                    if (Schema::hasColumn('characters', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
