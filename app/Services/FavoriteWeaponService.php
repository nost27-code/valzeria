<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FavoriteWeaponService
{
    private const AFFIX_ROMAN = [1 => 'Ⅰ', 2 => 'Ⅱ', 3 => 'Ⅲ', 4 => 'Ⅳ', 5 => 'Ⅴ'];

    private const AFFIX_LEVEL_COLORS = [
        1 => '#64748b',
        2 => '#2563eb',
        3 => '#7c3aed',
        4 => '#d97706',
        5 => '#e11d48',
    ];

    private const QUALITY_DISPLAY = [
        'good' => [
            'label' => '良品',
            'color' => '#315f9d',
            'background' => '#e8f1ff',
            'border_color' => '#8eadd5',
            'display_background' => 'radial-gradient(circle at 50% 32%, #ffffff 0%, #eef6ff 55%, #c8d9ec 100%)',
        ],
        'excellent' => [
            'label' => '逸品',
            'color' => '#80560b',
            'background' => '#fff0bc',
            'border_color' => '#c99a35',
            'display_background' => 'radial-gradient(circle at 50% 32%, #fffdf2 0%, #fff3ca 54%, #e2bd67 100%)',
        ],
    ];

    private const SPECIAL_DISPLAY_BACKGROUND = 'radial-gradient(circle at 22% 18%, rgba(255,248,180,0.95) 0 1px, transparent 2px), radial-gradient(circle at 79% 27%, rgba(229,255,201,0.92) 0 1px, transparent 2px), radial-gradient(circle at 68% 76%, rgba(255,244,176,0.78) 0 1px, transparent 2px), radial-gradient(circle at 50% 38%, #ffffe8 0%, #d9efad 24%, #6cac74 62%, #174b38 100%)';

    /** @var array<string, int> 添付済みの武器画像カタログ（武器名 => weapon_XXX.webp） */
    private const IMAGE_NUMBER_BY_NAME = [
        '木の剣' => 1, '鉄の剣' => 2, '鋼の剣' => 3, '騎士の剣' => 4, '白銀の剣' => 5, '王家の剣' => 6, '英雄の剣' => 7, '聖剣アークレア' => 8, '星冠の誓剣' => 9, '天命の聖剣' => 10, '星冠聖剣セレスティル' => 11, '黒炎の魔剣' => 12, '冥哭剣ネクロム' => 13, '深淵喰らいの魔剣' => 14, '深淵魔剣ネクロディア' => 15, '風切りの英雄剣' => 16, '翠嵐剣エルフィア' => 17, '天翔の迅剣' => 18, '天翔迅剣エルフィード' => 19,
        '錆びた短剣' => 20, '鉄の短剣' => 21, '盗賊のナイフ' => 22, '影切りの短剣' => 23, '風牙のダガー' => 24, '夜叉の短剣' => 25, '月影の刃' => 26, '白銀の祝刃' => 27, '星祈りの双刃' => 28, '天命の双聖刃' => 29, '聖影双刃アークシェル' => 30, '影炎の短剣' => 31, '夜哭きの冥刃ネクロム' => 32, '深淵裂きの双刃' => 33, '冥影刃ネクロヴェイン' => 34, '風切りの双刃' => 35, '木霊の翠刃エルフィア' => 36, '天翔の瞬刃' => 37, '風切双刃エルフィネル' => 38,
        '木の槍' => 39, '鉄の槍' => 40, '騎兵の槍' => 41, '鋼牙の槍' => 42, '竜牙の槍' => 43, '蒼穹の槍' => 44, '聖騎士の槍' => 45, '騎聖槍アークレア' => 46, '星標の巡礼槍' => 47, '竜王の誓槍' => 48, '天翼聖槍セレスヴァーン' => 49, '黒炎の牙槍' => 50, '冥哭槍ネクロム' => 51, '深淵穿ちの魔槍' => 52, '冥竜魔槍ネクログレイヴ' => 53, '風穿ちの英雄槍' => 54, '翠角槍エルフィア' => 55, '天翔の迅槍' => 56, '風穿迅槍エルファリオン' => 57,
        '石斧' => 58, '鉄斧' => 59, '戦斧' => 60, '重戦斧' => 61, '断岩の斧' => 62, '暴君の斧' => 63, '竜断ちの斧' => 64, '聖斧グランベルグ' => 65, '王城の断罪斧' => 66, '竜王の砕天斧' => 67, '聖断戦斧アークベルグ' => 68, '黒炎の断斧' => 69, '冥獣斧ネクロム' => 70, '深淵割りの戦斧' => 71, '黒炎裂斧ネクロバルグ' => 72, '嵐割りの戦斧' => 73, '森断ちの嵐斧' => 74, '天翔の旋斧' => 75, '嵐割迅斧エルヴァルト' => 76,
        '棍棒' => 77, '強化棍棒' => 78, '鉄の棍棒' => 79, '戦棍' => 80, '破砕の戦棍' => 81, '巨人の棍棒' => 82, '星砕きの棍棒' => 83, '聖棍アークレア' => 84, '星鐘の聖棍' => 85, '竜王の破城棍' => 86, '王守聖棍アークガルド' => 87, '黒鉄の魔棍' => 88, '冥鐘棍ネクロム' => 89, '深淵砕きの魔棍' => 90, '冥砕魔棍ネクロドゥーム' => 91, '疾風の棍' => 92, '翠蔓の風棍エルフィア' => 93, '天翔の迅棍' => 94, '風鳴棍エルヴェント' => 95,
        '木の弓' => 96, '狩人の弓' => 97, '鋼弦の弓' => 98, '森守の弓' => 99, '疾風の弓' => 100, '月詠みの弓' => 101, '天空弓セレス' => 102, '聖弓セレスティア' => 103, '星導きの天弓' => 104, '天命の白弓' => 105, '天翼聖弓セレスアロー' => 106, '黒月の魔弓' => 107, '冥月弓ネクロム' => 108, '深淵射ちの魔弓' => 109, '黒月魔弓ネクロルナ' => 110, '風精の長弓' => 111, '緑葉の狩弓エルフィア' => 112, '天翔の迅弓' => 113, '風精王弓エルフィオン' => 114,
        '木の杖' => 115, '魔導の杖' => 116, '精霊の杖' => 117, '祈りの杖' => 118, '星見の杖' => 119, '大魔導の杖' => 120, 'ルミナスロッド' => 121, '祈星杖アークレア' => 122, '星詠みの祝杖' => 123, '天啓の神杖' => 124, '星祈聖杖セレスティナ' => 125, '黒炎の呪杖' => 126, '冥府杖ネクロム' => 127, '深淵の禁杖' => 128, '深淵魔導杖ネクロセイド' => 129, '星風の短杖' => 130, '森羅の風杖エルフィア' => 131, '天翔の迅杖' => 132, '森羅風杖エルヴェール' => 133,
        '古びた魔導書' => 134, '初級魔導書' => 135, '精霊術の書' => 136, '星詠みの書' => 137, '禁呪の書' => 138, '大賢者の書' => 139, 'ルミナスの秘典' => 140, '聖典アークレア' => 141, '星律の聖典' => 142, '天命の啓典' => 143, '聖律典アークノヴァ' => 144, '黒炎の禁書' => 145, '冥府の禁書ネクロム' => 146, '深淵写本' => 147, '冥律魔典ネクロノクス' => 148, '星風の魔導書' => 149, '翠風の魔導環エルフィア' => 150, '天翔の迅典' => 151, '風詠魔典エルシルフ' => 152,
        '布の拳帯' => 153, '革のグローブ' => 154, '鉄拳グローブ' => 155, '闘士の拳具' => 156, '破岩の拳甲' => 157, '修羅の拳' => 158, '武神の拳' => 159, '聖拳アークレア' => 160, '星拳の聖甲' => 161, '天命の拳甲' => 162, '聖武拳甲アークガント' => 163, '黒炎の魔拳' => 164, '冥牙拳ネクロム' => 165, '深淵の鬼拳' => 166, '冥獄魔拳ネクロファング' => 167, '疾風の闘拳' => 168, '風牙の拳甲エルフィア' => 169, '天翔の迅拳' => 170, '神速風牙拳エルラッシュ' => 171,
        '粗製の銃' => 172, '鉄筒銃' => 173, '機工銃' => 174, '連装機工銃' => 175, '雷鳴銃' => 176, '古代機構銃' => 177, '錬成炉の魔銃' => 178, '聖銃アークレア' => 179, '星穿ちの聖銃' => 180, '天命の機聖銃' => 181, '聖機銃セレスバレット' => 182, '黒炎の魔銃' => 183, '冥銃ネクロム' => 184, '深淵の魔銃' => 185, '冥機銃ネクロヴォルト' => 186, '疾風の機銃' => 187, '風迅銃エルフィア' => 188, '天翔の迅機銃' => 189, '風迅機銃エルストーム' => 190,
        '城門衛士の剣' => 501, '見習い衛士の短剣' => 502, '王都兵の槍' => 503, '兵団支給の戦斧' => 504, '訓練兵の棍棒' => 505, '巡邏兵の弓' => 506, '修練場の拳具' => 507, '衛士見習いの拳甲' => 508, '学徒の杖' => 509, '初等魔導書' => 510, '見習い魔導具' => 511, '衛兵制式小銃' => 512, '試作機工銃' => 513,
        '潮騒の剣' => 514, '波止場の短剣' => 515, '水夫の銛槍' => 516, '甲板割りの戦斧' => 517, '船乗りの棍棒' => 518, '海風の弓' => 519, '水夫の拳具' => 520, '錨打ちの拳甲' => 521, '潮読みの杖' => 522, '航海士の魔導書' => 523, '潮紋の魔導具' => 524, '甲板守りの銃' => 525, '艦載機工銃' => 526,
        '木漏れ日の剣' => 527, '若枝の短剣' => 528, '蔓巻きの槍' => 529, '樹皮割りの戦斧' => 530, '樫守りの棍棒' => 531, '若葉の弓' => 532, '蔦編みの拳具' => 533, '森守りの拳甲' => 534, '精霊樹の杖' => 535, '森語りの魔導書' => 536, '精霊紋の魔導具' => 537, '木霊の銃' => 538, '蔦絡みの機工銃' => 539,
        '炉鉄の剣' => 540, '鍛冶場の短剣' => 541, '鉄杭の槍' => 542, '炉心の戦斧' => 543, '鋳鉄の棍棒' => 544, '工房仕立ての拳具' => 545, '鋼打ちの拳甲' => 546, '炉火の杖' => 547, '錬鉄の魔導書' => 548, '炉心魔導具' => 549, '工房仕込みの銃' => 550, '蒸機銃' => 551,
        '霜銀の剣' => 552, '氷刃の短剣' => 553, '氷柱の槍' => 554, '霜割りの戦斧' => 555, '凍土の棍棒' => 556, '雪原の弓' => 557, '霜革の拳具' => 558, '氷殻の拳甲' => 559, '雪灯りの杖' => 560, '氷紋の魔導書' => 561, '霜晶の魔導具' => 562, '雪原仕込みの銃' => 563, '氷霧の機工銃' => 564,
        '砂塵の剣' => 565, '砂影の短剣' => 566, '陽炎の槍' => 567, '熱砂の戦斧' => 568, '砂岩の棍棒' => 569, '砂丘の弓' => 570, '旅布の拳具' => 571, '砂殻の拳甲' => 572, '陽砂の杖' => 573, '蜃気楼の魔導書' => 574, '砂紋の魔導具' => 575, '砂嵐の銃' => 576, '砂熱の機工銃' => 577,
        '墓地騎士の錆剣' => 578, '小鬼射手の粗弓' => 579, '洞窟トロルの石棍棒' => 580, '亡霊船員の曲刀' => 581, '爆弾使いの火薬斧' => 582, '人魚の泡杖' => 583, '葉隠れ狩人の長弓' => 584, '根絡みの棘槍' => 585, '胞子術士の魔導具' => 586, '鉄殻虫の拳甲' => 587, '炎精の炉火杖' => 588, '蒸気兵の工房銃' => 589, '氷竜幼体の牙槍' => 590, '雪妖精の雪灯杖' => 591,
        '星葉の剣' => 701, '星葉の槍' => 702, '星葉の戦斧' => 703, '星葉の短剣' => 704, '星葉の弓' => 705, '星葉の拳甲' => 706, '星葉の機銃' => 707, '星葉の杖' => 708, '星葉の魔導書' => 709,
        '月枝の剣' => 710, '月枝の槍' => 711, '月枝の戦斧' => 712, '月枝の短剣' => 713, '月枝の弓' => 714, '月枝の拳甲' => 715, '月枝の機銃' => 716, '月露の杖' => 717, '月枝の魔導書' => 718,
        '星樹の剣' => 719, '星樹の聖槍' => 720, '星樹の大斧' => 721, '星樹の影刃' => 722, '星樹の神弓' => 723, '星樹の拳甲' => 724, '星樹の星銃' => 725, '星樹の聖杖' => 726, '星樹の星典' => 727,
    ];

    private const CATEGORY_STARTS = [
        'sword' => 1, 'dagger' => 20, 'spear' => 39, 'axe' => 58, 'club' => 77,
        'bow' => 96, 'staff' => 115, 'magic_device' => 134, 'fist' => 153, 'gun' => 172,
    ];

    private const STANDARD_RANK_OFFSETS = [
        'G' => 0, 'F' => 1, 'E' => 2, 'D' => 3, 'C' => 4, 'B' => 5,
        'A' => 6, 'S' => 7, 'SS' => 8, 'SSS' => 9, 'EPIC' => 10,
    ];

    private const BRANCH_RANK_OFFSETS = ['S' => 0, 'SS' => 1, 'SSS' => 2, 'EPIC' => 3];

    public function enabled(): bool
    {
        return (bool) config('favorite_weapons.enabled', false);
    }

    public function maxCount(): int
    {
        return max(1, (int) config('favorite_weapons.max_count', 3));
    }

    public function selectedIds(Character $character): array
    {
        $ownedIds = $this->ownedWeaponIds($character);

        return $this->storedSelectedIds($character)
            ->filter(fn (int $id) => in_array($id, $ownedIds, true))
            ->values()->all();
    }

    public function availableWeapons(Character $character): Collection
    {
        return $this->availableWeaponsQuery($character)->get();
    }

    public function paginateAvailableWeapons(Character $character, int $perPage = 12): LengthAwarePaginator
    {
        return $this->availableWeaponsQuery($character)->paginate($perPage);
    }

    private function availableWeaponsQuery(Character $character)
    {
        return $character->characterItems()
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->orderByDesc('is_equipped')->orderByDesc('enhance_level')->orderByDesc('id');
    }

    protected function ownedWeaponIds(Character $character): array
    {
        return $character->characterItems()
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->pluck('character_items.id')->map(fn ($id) => (int) $id)->all();
    }

    public function saveSelection(Character $character, array $weaponIds): void
    {
        $requestedIds = collect($weaponIds)->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)->unique()->take($this->maxCount())->values();
        $ownedIds = $this->ownedWeaponIds($character);
        $knownStaleIds = $this->storedSelectedIds($character)->diff($ownedIds);
        $unknownUnownedIds = $requestedIds->diff($ownedIds)->diff($knownStaleIds);

        if ($unknownUnownedIds->isNotEmpty()) {
            throw new \InvalidArgumentException('所持していない武器はお気に入りに設定できません。');
        }

        $character->profile_favorite_weapon_ids = $requestedIds
            ->filter(fn (int $id) => in_array($id, $ownedIds, true))
            ->values()->all();
    }

    public function displayWeapons(Character $character): array
    {
        $weaponsById = $this->availableWeapons($character)->keyBy('id');

        return $this->storedSelectedIds($character)->map(fn (int $id) => $weaponsById->get($id))
            ->filter()->map(fn (CharacterItem $weapon) => $this->toDisplayArray($weapon))->values()->all();
    }

    private function storedSelectedIds(Character $character): Collection
    {
        return collect($character->profile_favorite_weapon_ids ?? [])
            ->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)
            ->unique()->take($this->maxCount())->values();
    }

    public function toDisplayArray(CharacterItem $weapon): array
    {
        $item = $weapon->item;
        $rank = strtoupper((string) ($item?->weapon_rank ?? $item?->rarity ?? ''));
        $enhanceLevel = max(0, (int) ($weapon->enhance_level ?? 0));
        $quality = self::QUALITY_DISPLAY[(string) $weapon->affix_quality] ?? null;
        $isSpecial = $rank === 'SPECIAL';

        return [
            'id' => (int) $weapon->id,
            'name' => (string) ($item?->name ?? '不明な武器'),
            'rank' => $rank !== '' && $rank !== 'NORMAL' ? ($isSpecial ? 'SP' : $rank) : null,
            'rank_color' => $this->rankColor($rank),
            'is_special' => $isSpecial,
            'display_background' => $isSpecial ? self::SPECIAL_DISPLAY_BACKGROUND : ($quality['display_background'] ?? null),
            'enhance_level' => $enhanceLevel,
            'enhance_style' => $this->enhanceStyle($enhanceLevel),
            'quality' => $quality,
            'engraving' => $this->affixDisplay($weapon->affixPrefix?->name, $weapon->effectiveAffixPrefixLevel()),
            'killer' => $this->affixDisplay($weapon->affixSuffix?->name, $weapon->effectiveAffixSuffixLevel()),
            'image' => asset($this->imagePathFor($item)),
            'is_equipped' => (bool) $weapon->is_equipped,
        ];
    }

    /** @return array{color: string, background: string, border_color: string, font_size: string, shadow: string} */
    private function enhanceStyle(int $level): array
    {
        if ($level >= 30) {
            return ['color' => '#9f1239', 'background' => '#fff1bd', 'border_color' => '#e29b21', 'font_size' => '0.95rem', 'shadow' => '0 1px 5px rgba(234,88,12,0.35)'];
        }

        if ($level >= 20) {
            return ['color' => '#c2410c', 'background' => '#fff0dc', 'border_color' => '#fb923c', 'font_size' => '0.9rem', 'shadow' => '0 1px 4px rgba(249,115,22,0.24)'];
        }

        if ($level >= 10) {
            return ['color' => '#a16207', 'background' => '#fff8dc', 'border_color' => '#e9bd50', 'font_size' => '0.8rem', 'shadow' => '0 1px 3px rgba(202,138,4,0.18)'];
        }

        if ($level > 0) {
            return ['color' => '#b45309', 'background' => '#fffdf5', 'border_color' => '#f4c766', 'font_size' => '0.75rem', 'shadow' => '0 1px 2px rgba(180,83,9,0.12)'];
        }

        return ['color' => '#94a3b8', 'background' => '#f8fafc', 'border_color' => '#d7dee8', 'font_size' => '0.7rem', 'shadow' => 'none'];
    }

    /**
     * @return array{label: string, level: int, color: string}|null
     */
    private function affixDisplay(?string $name, int $level): ?array
    {
        if (!$name) {
            return null;
        }

        $roman = self::AFFIX_ROMAN[$level] ?? '';

        $baseName = mb_substr($name, -1) === 'の'
            ? mb_substr($name, 0, -1)
            : $name;
        $label = $baseName.$roman;

        return [
            'label' => $label,
            'level' => $level,
            'color' => self::AFFIX_LEVEL_COLORS[$level] ?? self::AFFIX_LEVEL_COLORS[1],
        ];
    }

    private function imagePathFor(?Item $item): string
    {
        $imageNumber = self::IMAGE_NUMBER_BY_NAME[(string) ($item?->name ?? '')] ?? null;
        if ($imageNumber !== null) {
            return sprintf('images/weapon/weapon_%03d.webp', $imageNumber);
        }

        if (!$item || !isset(self::CATEGORY_STARTS[(string) $item->weapon_category])) {
            return 'images/icon/icon_006.webp';
        }

        $rank = strtoupper((string) ($item->weapon_rank ?? $item->rarity ?? ''));
        $externalId = strtoupper((string) $item->external_item_id);
        $offset = self::STANDARD_RANK_OFFSETS[$rank] ?? null;

        if (str_contains($externalId, '_DARK_') && isset(self::BRANCH_RANK_OFFSETS[$rank])) {
            $offset = 11 + self::BRANCH_RANK_OFFSETS[$rank];
        } elseif ((str_contains($externalId, '_WIND_') || str_contains($externalId, '_FOREST_')) && isset(self::BRANCH_RANK_OFFSETS[$rank])) {
            $offset = 15 + self::BRANCH_RANK_OFFSETS[$rank];
        }

        return $offset === null
            ? 'images/icon/icon_006.webp'
            : sprintf('images/weapon/weapon_%03d.webp', self::CATEGORY_STARTS[$item->weapon_category] + $offset);
    }

    private function rankColor(string $rank): string
    {
        return [
            'EPIC' => '#e11d48', 'SSS' => '#f97316', 'SS' => '#c084fc', 'S' => '#d4af37',
            'SPECIAL' => '#13795b',
            'A' => '#ef4444', 'B' => '#3b82f6', 'C' => '#22c55e', 'D' => '#94a3b8',
            'E' => '#64748b', 'F' => '#b0bec5', 'G' => '#d1d5db',
        ][$rank] ?? '#94a3b8';
    }
}
