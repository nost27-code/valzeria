<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('character_items')
            ->join('items', 'items.id', '=', 'character_items.item_id')
            ->where('character_items.is_equipped', true)
            ->where('items.type', 'accessory')
            ->select([
                'character_items.id',
                'character_items.character_id',
                'character_items.equipped_slot',
                'items.name',
                'items.sub_type',
            ])
            ->orderBy('character_items.character_id')
            ->orderBy('character_items.id')
            ->get()
            ->groupBy('character_id');

        foreach ($rows as $characterItems) {
            $marks = [];
            $accessories = [];

            foreach ($characterItems as $row) {
                if ($this->isMark($row->name, $row->sub_type)) {
                    $marks[] = $row;
                } else {
                    $accessories[] = $row;
                }
            }

            foreach ($marks as $index => $row) {
                if ($index >= 3) {
                    $this->unequip($row->id);
                    continue;
                }

                DB::table('character_items')
                    ->where('id', $row->id)
                    ->update([
                        'equipped_slot' => 'mark' . ($index + 1),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($accessories as $index => $row) {
                if ($index >= 1) {
                    $this->unequip($row->id);
                    continue;
                }

                DB::table('character_items')
                    ->where('id', $row->id)
                    ->update([
                        'equipped_slot' => 'accessory',
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['mark1' => 'accessory1', 'mark2' => 'accessory2', 'mark3' => 'accessory3'] as $from => $to) {
            DB::table('character_items')
                ->where('equipped_slot', $from)
                ->update([
                    'equipped_slot' => $to,
                    'updated_at' => now(),
                ]);
        }
    }

    private function isMark(?string $name, ?string $subType): bool
    {
        if ($subType && in_array($subType, ['印', '刻印', '王印', '神印'], true)) {
            return true;
        }

        $name ??= '';

        return str_ends_with($name, 'の印')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function unequip(int $characterItemId): void
    {
        DB::table('character_items')
            ->where('id', $characterItemId)
            ->update([
                'is_equipped' => false,
                'equipped_slot' => null,
                'updated_at' => now(),
            ]);
    }
};
