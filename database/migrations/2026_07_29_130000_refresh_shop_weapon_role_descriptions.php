<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_SUB_TYPES = [
        '斧',
        '棍棒',
        '銃',
        '機工銃',
    ];

    public function up(): void
    {
        $this->updateDescriptions(false);
    }

    public function down(): void
    {
        $this->updateDescriptions(true);
    }

    private function updateDescriptions(bool $restorePrevious): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $path = database_path('data/shop_equipment_master.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $subType = (string) ($row['sub_type'] ?? '');
            if (
                empty($row['external_item_id'])
                || !in_array($subType, self::TARGET_SUB_TYPES, true)
                || !array_key_exists('description', $row)
            ) {
                continue;
            }

            $description = (string) $row['description'];
            if ($restorePrevious) {
                $description = $this->previousDescription($row, $description);
            }

            DB::table('items')
                ->where('external_item_id', (string) $row['external_item_id'])
                ->update([
                    'description' => $description,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function previousDescription(array $row, string $description): string
    {
        $name = (string) ($row['name'] ?? '');
        $subType = (string) ($row['sub_type'] ?? '');
        $separatorPosition = mb_strpos($description, '。');
        $suffix = $separatorPosition === false
            ? ''
            : mb_substr($description, $separatorPosition + 1);
        $opening = in_array($subType, ['斧', '棍棒'], true)
            ? "{$name}は、重い一撃で装甲や殻を断つための武器。"
            : "{$name}は、射撃時の反動を抑えやすい銃器。";

        return $opening . $suffix;
    }
};
