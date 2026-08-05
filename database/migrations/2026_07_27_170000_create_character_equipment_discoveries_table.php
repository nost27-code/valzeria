<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_equipment_discoveries')) {
            Schema::create('character_equipment_discoveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
                $table->timestamp('discovered_at');
                $table->timestamps();
                $table->unique(['character_id', 'item_id'], 'character_equipment_discoveries_unique');
                $table->index(['character_id', 'discovered_at'], 'character_equipment_discoveries_recent_idx');
            });
        }

        $this->backfillCurrentEquipment();
        $this->backfillEvolutionHistory();
    }

    public function down(): void
    {
        Schema::dropIfExists('character_equipment_discoveries');
    }

    private function backfillCurrentEquipment(): void
    {
        if (!Schema::hasTable('character_items') || !Schema::hasTable('items')) {
            return;
        }

        DB::table('character_items')
            ->join('items', 'items.id', '=', 'character_items.item_id')
            ->whereIn('items.type', ['weapon', 'armor'])
            ->select(['character_items.id', 'character_items.character_id', 'character_items.item_id'])
            ->orderBy('character_items.id')
            ->chunkById(500, function ($rows): void {
                $this->insertRows($rows->map(fn (object $row): array => [
                    'character_id' => (int) $row->character_id,
                    'item_id' => (int) $row->item_id,
                ])->all());
            }, 'character_items.id', 'id');
    }

    private function backfillEvolutionHistory(): void
    {
        if (!Schema::hasTable('equipment_evolution_logs')) {
            return;
        }

        DB::table('equipment_evolution_logs')
            ->select(['id', 'character_id', 'before_equipment_id', 'after_equipment_id'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $payload = [];
                foreach ($rows as $row) {
                    $payload[] = [
                        'character_id' => (int) $row->character_id,
                        'item_id' => (int) $row->before_equipment_id,
                    ];
                    $payload[] = [
                        'character_id' => (int) $row->character_id,
                        'item_id' => (int) $row->after_equipment_id,
                    ];
                }
                $this->insertRows($payload);
            });
    }

    private function insertRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        DB::table('character_equipment_discoveries')->insertOrIgnore(array_map(
            fn (array $row): array => $row + [
                'discovered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows
        ));
    }
};
