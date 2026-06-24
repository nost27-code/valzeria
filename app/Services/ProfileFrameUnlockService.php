<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileFrameUnlockService
{
    private const MATERIALS_PER_FRAGMENT = 10;
    private const FRAGMENTS_PER_UNLOCK = 10;

    private const THEME_CITY_IDS = [
        'arclea' => 1,
        'marine' => 2,
        'elphia' => 3,
        'granberg' => 4,
        'frostria' => 5,
        'sandra' => 6,
        'luminous' => 7,
        'necrom' => 8,
        'celestia' => 9,
        'valzeria' => 10,
    ];

    private const THEME_MATERIAL_CODES = [
        'arclea' => 'MAT_REGION_ARKREA_RAW',
        'marine' => 'MAT_REGION_TIDAL_PIECE',
        'elphia' => 'MAT_REGION_WORLD_TREE_LEAF',
        'granberg' => 'MAT_REGION_BLACK_IRON_PART',
        'frostria' => 'MAT_REGION_ICE_CRYSTAL',
        'sandra' => 'MAT_REGION_ANCIENT_SAND',
        'luminous' => 'MAT_REGION_MAGIC_CRYSTAL',
        'necrom' => 'MAT_REGION_ABYSS_FRAGMENT',
        'celestia' => 'MAT_REGION_HEAVEN_FEATHER',
        'valzeria' => 'MAT_REGION_HEAVEN_FEATHER',
    ];

    public function unlockedCodes(Character $character): array
    {
        $codes = DB::table('character_profile_frames')
            ->where('character_id', $character->id)
            ->pluck('frame_theme')
            ->all();

        return array_values(array_unique(array_merge(['standard'], $codes)));
    }

    public function isUnlocked(Character $character, string $theme): bool
    {
        if ($theme === 'standard') {
            return true;
        }

        return DB::table('character_profile_frames')
            ->where('character_id', $character->id)
            ->where('frame_theme', $theme)
            ->exists();
    }

    public function progress(Character $character, array $themes): array
    {
        $unlocked = $this->unlockedCodes($character);
        $fragmentCounts = DB::table('character_profile_frame_fragments')
            ->where('character_id', $character->id)
            ->pluck('quantity', 'frame_theme');

        return collect($themes)
            ->filter(fn (array $theme) => ($theme['code'] ?? null) !== 'standard')
            ->map(function (array $theme) use ($character, $unlocked, $fragmentCounts) {
                $code = (string) $theme['code'];
                $materials = $this->eligibleMaterialsForTheme($code);
                $ownedMaterials = $this->ownedRegionalMaterialCount($character, $materials->pluck('id'));
                $fragments = (int) ($fragmentCounts[$code] ?? 0);

                return array_merge($theme, [
                    'unlocked' => in_array($code, $unlocked, true),
                    'regional_material_count' => $ownedMaterials,
                    'material_names' => $materials->pluck('name')->values()->all(),
                    'fragment_count' => $fragments,
                    'can_compress' => $ownedMaterials >= self::MATERIALS_PER_FRAGMENT && !in_array($code, $unlocked, true),
                    'can_unlock' => $fragments >= self::FRAGMENTS_PER_UNLOCK && !in_array($code, $unlocked, true),
                    'materials_per_fragment' => self::MATERIALS_PER_FRAGMENT,
                    'fragments_per_unlock' => self::FRAGMENTS_PER_UNLOCK,
                ]);
            })
            ->values()
            ->all();
    }

    public function compress(Character $character, string $theme): void
    {
        $this->assertExchangeableTheme($theme);

        DB::transaction(function () use ($character, $theme): void {
            $materials = $this->eligibleMaterialsForTheme($theme);
            if ($materials->isEmpty()) {
                throw ValidationException::withMessages([
                    'profile_frame_theme' => 'この地域の交換対象素材が見つかりません。',
                ]);
            }

            $remaining = self::MATERIALS_PER_FRAGMENT;
            $rows = CharacterMaterial::query()
                ->where('character_id', $character->id)
                ->whereIn('material_id', $materials->pluck('id')->all())
                ->where('quantity', '>', 0)
                ->orderBy('material_id')
                ->lockForUpdate()
                ->get();

            if ($rows->sum('quantity') < self::MATERIALS_PER_FRAGMENT) {
                throw ValidationException::withMessages([
                    'profile_frame_theme' => '地方限定素材が10個に足りません。',
                ]);
            }

            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $consume = min($remaining, (int) $row->quantity);
                $row->quantity = (int) $row->quantity - $consume;
                $remaining -= $consume;

                if ($row->quantity <= 0) {
                    $row->delete();
                    continue;
                }

                $row->save();
            }

            $this->incrementFragments($character, $theme, 1);
        });
    }

    public function unlock(Character $character, string $theme): void
    {
        $this->assertExchangeableTheme($theme);

        DB::transaction(function () use ($character, $theme): void {
            if ($this->isUnlocked($character, $theme)) {
                return;
            }

            $fragmentRow = DB::table('character_profile_frame_fragments')
                ->where('character_id', $character->id)
                ->where('frame_theme', $theme)
                ->lockForUpdate()
                ->first();

            $fragments = (int) ($fragmentRow->quantity ?? 0);
            if ($fragments < self::FRAGMENTS_PER_UNLOCK) {
                throw ValidationException::withMessages([
                    'profile_frame_theme' => '装飾片が10個に足りません。',
                ]);
            }

            DB::table('character_profile_frame_fragments')
                ->where('id', $fragmentRow->id)
                ->update([
                    'quantity' => $fragments - self::FRAGMENTS_PER_UNLOCK,
                    'updated_at' => now(),
                ]);

            DB::table('character_profile_frames')->updateOrInsert(
                [
                    'character_id' => $character->id,
                    'frame_theme' => $theme,
                ],
                [
                    'source' => 'regional_material_exchange',
                    'unlocked_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });
    }

    private function assertExchangeableTheme(string $theme): void
    {
        if (!array_key_exists($theme, self::THEME_CITY_IDS)) {
            throw ValidationException::withMessages([
                'profile_frame_theme' => '交換できないプロフィール枠です。',
            ]);
        }
    }

    private function eligibleMaterialsForTheme(string $theme): Collection
    {
        $materialCode = self::THEME_MATERIAL_CODES[$theme] ?? null;
        if ($materialCode === null) {
            return collect();
        }

        return Material::query()
            ->where('material_type', 'regional_drop')
            ->where('material_code', $materialCode)
            ->orderBy('id')
            ->get();
    }

    private function ownedRegionalMaterialCount(Character $character, Collection $materialIds): int
    {
        if ($materialIds->isEmpty()) {
            return 0;
        }

        return (int) CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->whereIn('material_id', $materialIds->all())
            ->sum('quantity');
    }

    private function incrementFragments(Character $character, string $theme, int $quantity): void
    {
        $row = DB::table('character_profile_frame_fragments')
            ->where('character_id', $character->id)
            ->where('frame_theme', $theme)
            ->lockForUpdate()
            ->first();

        if ($row) {
            DB::table('character_profile_frame_fragments')
                ->where('id', $row->id)
                ->update([
                    'quantity' => (int) $row->quantity + $quantity,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('character_profile_frame_fragments')->insert([
            'character_id' => $character->id,
            'frame_theme' => $theme,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
