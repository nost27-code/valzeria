<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('monster_marks')) {
            Schema::create('monster_marks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enemy_id')->unique()->constrained('enemies')->cascadeOnDelete();
                $table->string('mark_name');
                $table->string('bonus_stat', 16);
                $table->unsignedInteger('bonus_per_level')->default(10);
                $table->unsignedInteger('required_per_level')->default(10);
                $table->unsignedTinyInteger('max_level')->default(10);
                $table->decimal('drop_rate', 5, 2)->default(8.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('character_monster_marks')) {
            Schema::create('character_monster_marks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
                $table->foreignId('monster_mark_id')->constrained('monster_marks')->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedTinyInteger('unlocked_level')->default(0);
                $table->timestamps();
                $table->unique(['character_id', 'monster_mark_id']);
            });
        }

        if (Schema::hasTable('character_items') && Schema::hasTable('items')) {
            DB::table('character_items')
                ->join('items', 'items.id', '=', 'character_items.item_id')
                ->where('character_items.is_equipped', true)
                ->where('items.type', 'accessory')
                ->where(function ($query) {
                    $query->whereIn('items.sub_type', ['印', '刻印', '王印', '神印'])
                        ->orWhere('items.name', 'like', '%の印')
                        ->orWhere('items.name', 'like', '%の刻印')
                        ->orWhere('items.name', 'like', '%の王印')
                        ->orWhere('items.name', 'like', '%の神印');
                })
                ->update([
                    'character_items.is_equipped' => false,
                    'character_items.equipped_slot' => null,
                    'character_items.updated_at' => now(),
                ]);

            DB::table('items')
                ->where('type', 'accessory')
                ->where(function ($query) {
                    $query->whereIn('sub_type', ['印', '刻印', '王印', '神印'])
                        ->orWhere('name', 'like', '%の印')
                        ->orWhere('name', 'like', '%の刻印')
                        ->orWhere('name', 'like', '%の王印')
                        ->orWhere('name', 'like', '%の神印');
                })
                ->delete();
        }

        $this->seedMonsterMarks();
    }

    public function down(): void
    {
        Schema::dropIfExists('character_monster_marks');
        Schema::dropIfExists('monster_marks');
    }

    private function seedMonsterMarks(): void
    {
        if (!Schema::hasTable('enemies')) {
            return;
        }

        $now = now();
        $enemies = DB::table('enemies')
            ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')
            ->select('enemies.*', 'areas.city_id as stage_id')
            ->where('is_boss', false)
            ->orderBy('area_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($enemies as $enemy) {
            $stat = $this->bonusStat($enemy);
            DB::table('monster_marks')->updateOrInsert(
                ['enemy_id' => $enemy->id],
                [
                    'mark_name' => $enemy->name . 'の印',
                    'bonus_stat' => $stat,
                    'bonus_per_level' => $this->bonusPerLevel($enemy),
                    'required_per_level' => 10,
                    'max_level' => 4,
                    'drop_rate' => $this->dropRate($enemy),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function bonusStat(object $enemy): string
    {
        $text = (string) ($enemy->type_name ?? '') . ' ' . (string) ($enemy->role ?? '') . ' ' . (string) ($enemy->name ?? '');

        if (str_contains($text, '耐久') || str_contains($text, '重装') || str_contains($text, '防御')) {
            return 'def';
        }
        if (str_contains($text, '高速') || str_contains($text, '俊敏') || str_contains($text, '飛行')) {
            return 'agi';
        }
        if (str_contains($text, '魔法') || str_contains($text, '魔導') || str_contains($text, '術')) {
            return 'mag';
        }
        if (str_contains($text, '聖') || str_contains($text, '祈') || str_contains($text, '精神')) {
            return 'spr';
        }
        if (str_contains($text, '幸運') || str_contains($text, '宝') || str_contains($text, '兎')) {
            return 'luk';
        }

        $stats = [
            'hp' => (int) (($enemy->max_hp ?? 0) / 8),
            'str' => (int) ($enemy->str ?? 0),
            'def' => (int) ($enemy->def ?? 0),
            'agi' => (int) ($enemy->agi ?? 0),
            'mag' => (int) ($enemy->mag ?? 0),
            'spr' => (int) ($enemy->spr ?? 0),
            'luk' => (int) ($enemy->luk ?? 0),
        ];

        arsort($stats);

        return array_key_first($stats) ?: 'str';
    }

    private function dropRate(object $enemy): float
    {
        $role = (string) ($enemy->role ?? '');

        if (str_contains($role, 'レア')) {
            return 20.0;
        }

        return 8.0;
    }

    private function bonusPerLevel(object $enemy): int
    {
        $stage = (int) ($enemy->stage_id ?? 1);

        return match (true) {
            $stage >= 7 => 3,
            $stage >= 4 => 2,
            default => 1,
        };
    }
};
