<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CharacterProfileService
{
    private const DEFAULT_RANCH_BACKGROUND = 'images/valmon/ranch_bg.webp';
    private const DEFAULT_FRAME_THEME = 'standard';
    private const DEFAULT_FRAME_IMAGE = 'images/base/waku_000.webp';
    private const DEFAULT_CARD_BACKGROUND = 'images/profile/adventurer_card_bg01.webp';
    private const DEFAULT_CARD_FRAME = 'images/profile/adventurer_card_frame01.webp';
    private const DEFAULT_AVATAR_FRAME = 'images/profile/adventurer_avatar_frame01.webp';
    private const DEFAULT_VALMON_CASE = 'images/profile/valmon_case01.webp';
    private const FRAME_IMAGES_BY_THEME = [
        'standard' => 'images/base/waku_000.webp',
        'arclea' => 'images/base/waku_001.webp',
        'marine' => 'images/base/waku_001.webp',
        'elphia' => 'images/base/waku_002.webp',
        'granberg' => 'images/base/waku_002.webp',
        'frostria' => 'images/base/waku_003.webp',
        'sandra' => 'images/base/waku_003.webp',
        'luminous' => 'images/base/waku_004.webp',
        'necrom' => 'images/base/waku_004.webp',
        'celestia' => 'images/base/waku_005.webp',
        'valzeria' => 'images/base/waku_005.webp',
    ];

    public function frameThemes(): array
    {
        $themes = [
            [
                'code' => 'standard',
                'label' => '標準',
                'unlock_city_id' => null,
                'description' => '現在の金枠を基調にした通常プロフィール枠です。',
                'preview_class' => 'from-white via-amber-50/30 to-white border-[#d4af37]',
            ],
            [
                'code' => 'arclea',
                'label' => 'アークレア',
                'unlock_city_id' => 1,
                'description' => '王都アークレアを思わせる白金と紋章のプロフィール枠です。',
                'preview_class' => 'from-amber-50 via-white to-yellow-50 border-amber-400',
            ],
            [
                'code' => 'marine',
                'label' => 'マリン',
                'unlock_city_id' => 2,
                'description' => '港町マリネスを思わせる青と泡のプロフィール枠です。',
                'preview_class' => 'from-sky-50 via-cyan-50 to-white border-sky-400',
            ],
            [
                'code' => 'elphia',
                'label' => 'エルフィア',
                'unlock_city_id' => 3,
                'description' => '精霊の森エルフィアを思わせる若葉と木漏れ日のプロフィール枠です。',
                'preview_class' => 'from-emerald-50 via-lime-50 to-white border-emerald-400',
            ],
            [
                'code' => 'granberg',
                'label' => 'グランベルグ',
                'unlock_city_id' => 4,
                'description' => '鍛冶街グランベルグを思わせる黒鉄と火花のプロフィール枠です。',
                'preview_class' => 'from-slate-100 via-stone-50 to-orange-50 border-slate-500',
            ],
            [
                'code' => 'frostria',
                'label' => 'フロストリア',
                'unlock_city_id' => 5,
                'description' => '雪原の町フロストリアを思わせる氷晶のプロフィール枠です。',
                'preview_class' => 'from-slate-50 via-blue-50 to-white border-blue-300',
            ],
            [
                'code' => 'sandra',
                'label' => 'サンドラ',
                'unlock_city_id' => 6,
                'description' => '砂漠の宿場サンドラを思わせる砂金と陽炎のプロフィール枠です。',
                'preview_class' => 'from-orange-50 via-yellow-50 to-white border-orange-300',
            ],
            [
                'code' => 'luminous',
                'label' => 'ルミナス',
                'unlock_city_id' => 7,
                'description' => '魔導学院ルミナスを思わせる紫紺と魔力光のプロフィール枠です。',
                'preview_class' => 'from-violet-50 via-indigo-50 to-white border-violet-400',
            ],
            [
                'code' => 'necrom',
                'label' => 'ネクロム',
                'unlock_city_id' => 8,
                'description' => '死霊街ネクロムを思わせる闇色と幽光のプロフィール枠です。',
                'preview_class' => 'from-slate-200 via-purple-50 to-white border-purple-700',
            ],
            [
                'code' => 'celestia',
                'label' => 'セレスティア',
                'unlock_city_id' => 9,
                'description' => '天空神殿セレスティアを思わせる空色と聖光のプロフィール枠です。',
                'preview_class' => 'from-sky-50 via-white to-indigo-50 border-indigo-300',
            ],
            [
                'code' => 'valzeria',
                'label' => 'ヴァルゼリア',
                'unlock_city_id' => 10,
                'description' => '魔王城ヴァルゼリアを思わせる深紅と黒金のプロフィール枠です。',
                'preview_class' => 'from-rose-50 via-slate-100 to-white border-rose-700',
            ],
        ];

        return collect($themes)
            ->map(fn (array $theme): array => array_merge($theme, [
                'frame_image' => $this->frameImageForTheme((string) $theme['code']),
            ]))
            ->all();
    }

    public function availableFrameThemes(Character $character): array
    {
        return collect($this->frameThemes())
            ->filter(fn (array $theme) => (string) $theme['code'] === self::DEFAULT_FRAME_THEME)
            ->values()
            ->all();
    }

    public function selectedFrameTheme(?string $theme): string
    {
        return self::DEFAULT_FRAME_THEME;
    }

    public function selectedFrameThemeFor(Character $character, ?string $theme): string
    {
        return self::DEFAULT_FRAME_THEME;
    }

    public function frameThemeLabel(?string $theme): string
    {
        $selected = $this->selectedFrameTheme($theme);

        return collect($this->frameThemes())
            ->firstWhere('code', $selected)['label'] ?? '標準';
    }

    public function frameImageForTheme(?string $theme): string
    {
        $selected = $this->selectedFrameTheme($theme);

        return self::FRAME_IMAGES_BY_THEME[$selected] ?? self::DEFAULT_FRAME_IMAGE;
    }

    public function ranchBackgrounds(): array
    {
        $dir = public_path('images/valmon');
        $files = glob($dir . DIRECTORY_SEPARATOR . 'ranch_bg*.webp') ?: [];

        $backgrounds = collect($files)
            ->map(fn (string $path) => 'images/valmon/' . basename($path))
            ->sortBy(fn (string $path) => $path === 'images/valmon/ranch_bg.webp' ? '00' : $path)
            ->values()
            ->map(fn (string $path, int $index) => [
                'path' => $path,
                'label' => $index === 0 ? '草原の牧場' : '牧場背景 ' . $index,
            ])
            ->all();

        return $backgrounds ?: [[
            'path' => self::DEFAULT_RANCH_BACKGROUND,
            'label' => '草原の牧場',
        ]];
    }

    public function ownedRanchBackgrounds(Character $character): array
    {
        $this->ensureDefaultRanchBackground($character);

        $ownedPaths = DB::table('character_profile_backgrounds')
            ->where('character_id', $character->id)
            ->pluck('background_path')
            ->all();

        $owned = collect($this->ranchBackgrounds())
            ->filter(fn (array $background) => in_array($background['path'], $ownedPaths, true))
            ->values()
            ->all();

        return $owned ?: [[
            'path' => self::DEFAULT_RANCH_BACKGROUND,
            'label' => '草原の牧場',
        ]];
    }

    public function ensureDefaultRanchBackground(Character $character): void
    {
        DB::table('character_profile_backgrounds')->updateOrInsert(
            [
                'character_id' => $character->id,
                'background_path' => self::DEFAULT_RANCH_BACKGROUND,
            ],
            [
                'source' => 'default',
                'obtained_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function isOwnedRanchBackground(Character $character, string $path): bool
    {
        $this->ensureDefaultRanchBackground($character);

        return DB::table('character_profile_backgrounds')
            ->where('character_id', $character->id)
            ->where('background_path', $path)
            ->exists();
    }

    public function selectedRanchBackground(?Character $character, ?string $path): string
    {
        $path = $path ?: self::DEFAULT_RANCH_BACKGROUND;

        if (!$character) {
            return self::DEFAULT_RANCH_BACKGROUND;
        }

        return $this->isOwnedRanchBackground($character, $path)
            ? $path
            : self::DEFAULT_RANCH_BACKGROUND;
    }

    public function adventurerCardBackgrounds(): array
    {
        return $this->profileAssetCatalog('adventurer_card_bg*.webp', '背景', self::DEFAULT_CARD_BACKGROUND);
    }

    public function adventurerCardFrames(): array
    {
        return $this->profileAssetCatalog('adventurer_card_frame*.webp', '枠', self::DEFAULT_CARD_FRAME);
    }

    public function adventurerAvatarFrames(): array
    {
        return $this->profileAssetCatalog('adventurer_avatar_frame*.webp', '丸枠', self::DEFAULT_AVATAR_FRAME);
    }

    public function valmonCases(): array
    {
        return $this->profileAssetCatalog('valmon_case*.webp', 'ケース', self::DEFAULT_VALMON_CASE);
    }

    public function ownedAdventurerCardBackgrounds(Character $character): array
    {
        return $this->ownedAdventurerCardAssets($character, 'background', $this->adventurerCardBackgrounds(), self::DEFAULT_CARD_BACKGROUND);
    }

    public function ownedAdventurerCardFrames(Character $character): array
    {
        return $this->ownedAdventurerCardAssets($character, 'card_frame', $this->adventurerCardFrames(), self::DEFAULT_CARD_FRAME);
    }

    public function ownedAdventurerAvatarFrames(Character $character): array
    {
        return $this->ownedAdventurerCardAssets($character, 'avatar_frame', $this->adventurerAvatarFrames(), self::DEFAULT_AVATAR_FRAME);
    }

    public function ownedValmonCases(Character $character): array
    {
        return $this->ownedAdventurerCardAssets($character, 'valmon_case', $this->valmonCases(), self::DEFAULT_VALMON_CASE);
    }

    public function selectedAdventurerCardBackground(Character $character, ?string $path): string
    {
        return $this->selectedOwnedAdventurerCardAsset($character, 'background', $path, self::DEFAULT_CARD_BACKGROUND);
    }

    public function selectedAdventurerCardFrame(Character $character, ?string $path): string
    {
        return $this->selectedOwnedAdventurerCardAsset($character, 'card_frame', $path, self::DEFAULT_CARD_FRAME);
    }

    public function selectedAdventurerAvatarFrame(Character $character, ?string $path): string
    {
        return $this->selectedOwnedAdventurerCardAsset($character, 'avatar_frame', $path, self::DEFAULT_AVATAR_FRAME);
    }

    public function selectedValmonCase(Character $character, ?string $path): string
    {
        return $this->selectedOwnedAdventurerCardAsset($character, 'valmon_case', $path, self::DEFAULT_VALMON_CASE);
    }

    private function profileAssetCatalog(string $pattern, string $labelPrefix, string $defaultPath): array
    {
        $dir = public_path('images/profile');
        $files = glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [];

        $assets = collect($files)
            ->map(fn (string $path) => 'images/profile/' . basename($path))
            ->sort()
            ->values()
            ->map(function (string $path) use ($labelPrefix) {
                preg_match('/(\d+)$/', pathinfo($path, PATHINFO_FILENAME), $matches);
                $assetNumber = $matches[1] ?? '01';

                return [
                    'path' => $path,
                    'label' => $assetNumber === '91' ? '冒険者' : $labelPrefix . $assetNumber,
                ];
            })
            ->all();

        return $assets ?: [[
            'path' => $defaultPath,
            'label' => $labelPrefix . '01',
        ]];
    }

    private function ownedAdventurerCardAssets(Character $character, string $type, array $catalog, string $defaultPath): array
    {
        if (!Schema::hasTable('character_adventurer_card_assets')) {
            return collect($catalog)
                ->filter(fn (array $asset) => $asset['path'] === $defaultPath)
                ->values()
                ->all();
        }

        $this->ensureDefaultAdventurerCardAsset($character, $type, $defaultPath);

        $ownedPaths = DB::table('character_adventurer_card_assets')
            ->where('character_id', $character->id)
            ->where('asset_type', $type)
            ->pluck('asset_path')
            ->all();

        $owned = collect($catalog)
            ->filter(fn (array $asset) => in_array($asset['path'], $ownedPaths, true))
            ->values()
            ->all();

        return $owned ?: collect($catalog)
            ->filter(fn (array $asset) => $asset['path'] === $defaultPath)
            ->values()
            ->all();
    }

    private function selectedOwnedAdventurerCardAsset(Character $character, string $type, ?string $path, string $defaultPath): string
    {
        $path = $path ?: $defaultPath;

        if (!Schema::hasTable('character_adventurer_card_assets')) {
            return $defaultPath;
        }

        $this->ensureDefaultAdventurerCardAsset($character, $type, $defaultPath);

        return DB::table('character_adventurer_card_assets')
            ->where('character_id', $character->id)
            ->where('asset_type', $type)
            ->where('asset_path', $path)
            ->exists()
                ? $path
                : $defaultPath;
    }

    private function ensureDefaultAdventurerCardAsset(Character $character, string $type, string $path): void
    {
        if (!Schema::hasTable('character_adventurer_card_assets')) {
            return;
        }

        DB::table('character_adventurer_card_assets')->updateOrInsert(
            [
                'character_id' => $character->id,
                'asset_type' => $type,
                'asset_path' => $path,
            ],
            [
                'source' => 'default',
                'obtained_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

}
