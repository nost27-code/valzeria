<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Job EXP values before the Valzeria continent master was capped at 3.
     *
     * @var array<int, array<int, int>>
     */
    private const ORIGINAL_REWARDS_BY_VALUE = [
        4 => [126, 143, 149, 155, 160, 161, 166, 167, 171, 172, 173, 177, 178, 179, 183, 184, 188, 189, 190, 194, 195, 196, 200, 201, 205, 206, 207, 211, 212, 213, 216, 217, 218, 219, 222, 223, 224, 228, 229, 230, 234, 235, 236, 240, 241, 246, 247, 253, 258],
        5 => [168, 185, 191, 197, 202, 203, 208, 209, 214, 215, 220, 225, 226, 231, 232, 237, 238, 242, 243, 248, 249, 254, 255, 259, 260, 261, 264, 265, 266, 267, 270, 271, 272, 276, 277, 278, 282, 283, 284, 288, 289, 290, 295, 300, 301, 306, 307, 312, 313],
        6 => [210, 221, 227, 233, 239, 244, 245, 250, 251, 252, 256, 257, 262, 268, 273, 274, 279, 280, 285, 286, 291, 296, 297, 302, 303, 308, 309, 314, 315, 318, 319, 320, 324, 325, 326, 330, 331, 332, 337, 338, 342, 343, 344, 348, 349, 354, 355, 360, 361, 367],
        7 => [263, 269, 275, 281, 287, 292, 293, 294, 298, 299, 304, 310, 316, 321, 322, 327, 328, 333, 339, 345, 350, 351, 356, 357, 362, 363, 366, 368, 372, 373, 374, 379, 380, 384, 385, 386, 390, 391, 392, 396, 397, 398, 402, 403, 408, 409, 414, 415],
        8 => [305, 311, 317, 323, 329, 334, 335, 336, 340, 341, 346, 352, 358, 364, 369, 370, 375, 376, 377, 381, 387, 393, 399, 404, 405, 410, 411, 416],
        9 => [347, 353, 359, 365, 371, 378, 382, 388, 394, 400, 406, 412, 417, 418],
        10 => [383, 389, 395, 401, 407, 413, 419],
        15 => [420],
        18 => [421, 422],
        20 => [423, 424],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('enemies') || !Schema::hasColumn('enemies', 'job_exp_reward')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (array_chunk($this->targetEnemyIds(), 100) as $enemyIds) {
                DB::table('enemies')
                    ->whereIn('id', $enemyIds)
                    ->where('job_exp_reward', '>', 3)
                    ->update(['job_exp_reward' => 3, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('enemies') || !Schema::hasColumn('enemies', 'job_exp_reward')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::ORIGINAL_REWARDS_BY_VALUE as $reward => $enemyIds) {
                DB::table('enemies')
                    ->whereIn('id', $enemyIds)
                    ->update(['job_exp_reward' => $reward, 'updated_at' => now()]);
            }
        });
    }

    /** @return array<int, int> */
    private function targetEnemyIds(): array
    {
        return array_merge(...array_values(self::ORIGINAL_REWARDS_BY_VALUE));
    }
};
