<?php

namespace App\Support;

class FacilityConfig
{
    const TOWN_ENTRIES = [
        ['slug' => 'inn',               'label' => '宿屋',        'default_name' => '宿屋',        'default_desc' => 'HPとSPを全回復して次の冒険に備える',               'default_icon' => 'facilities/facility_inn_300.webp'],
        ['slug' => 'supply',            'label' => '補給所',      'default_name' => '補給所',      'default_desc' => '毎日の回復アイテム補給と残りストックを受け取る',     'default_icon' => 'facilities/facility_supply_300.webp'],
        ['slug' => 'equipment_shop',    'label' => '装備屋',      'default_name' => '装備屋',      'default_desc' => 'この街で作られた店売り装備をGoldで購入する',           'default_icon' => 'facilities/facility_equipment_shop.webp'],
        ['slug' => 'blacksmith',        'label' => '鍛冶屋',      'default_name' => '鍛冶屋',      'default_desc' => '強化石系素材で装備を+1〜+5へ強化する',                 'default_icon' => 'facilities/facility_blacksmith_300.webp'],
        ['slug' => 'synthesis',         'label' => '合成屋',      'default_name' => '合成屋',      'default_desc' => '装備と欠片・専用素材で武器・防具を進化させる',         'default_icon' => 'facilities/facility_synthesis_300.webp'],
        ['slug' => 'material_exchange', 'label' => '素材交換所',  'default_name' => '素材交換所',  'default_desc' => '素材精製・錬成・調合で必要素材を作る',                 'default_icon' => 'facilities/facility_material_exchange_300.webp'],
        ['slug' => 'valmon_farm',       'label' => 'ヴァルモン牧場', 'default_name' => 'ヴァルモン牧場', 'default_desc' => '相棒ヴァルモンの確認・相棒設定・餌育成を行う', 'default_icon' => 'facilities/facility_valmon_farm_300.webp'],
        ['slug' => 'temple',            'label' => '神殿',        'default_name' => '神殿',        'default_desc' => '職業変更と職業ランクを確認する',                       'default_icon' => 'facilities/facility_temple.webp'],
        ['slug' => 'guide',             'label' => '案内所',      'default_name' => '案内所',      'default_desc' => 'ヴァルゼリアの遊び方やヘルプを確認する',               'default_icon' => 'facilities/facility_guide_300.webp'],
        ['slug' => 'bank',              'label' => '銀行',        'default_name' => '銀行',        'default_desc' => 'Goldを預けて探索中の喪失から守る',                     'default_icon' => 'facilities/facility_bank.webp'],
        ['slug' => 'tavern',            'label' => '酒場',        'default_name' => '酒場',        'default_desc' => '冒険者たちの噂話や名簿を確認する',                     'default_icon' => 'facilities/facility_tavern_300.webp'],
        ['slug' => 'ranking_board',     'label' => '番付掲示板',  'default_name' => '番付掲示板',  'default_desc' => '冒険者たちの各種番付を確認する',                       'default_icon' => 'icon/icon_223.webp'],
        ['slug' => 'guild_assoc',       'label' => '冒険者協会',  'default_name' => '冒険者協会',  'default_desc' => '救助支援システムを調整中',                             'default_icon' => 'facilities/association_symbol.webp'],
        ['slug' => 'kiseki_shop',       'label' => '輝石ショップ', 'default_name' => '輝石ショップ', 'default_desc' => '有償輝石を購入してアイテムや強化に役立てる',          'default_icon' => 'facilities/kiseki_shop.webp'],
        ['slug' => 'supply_merchant',   'label' => '補給商会',    'default_name' => '補給商会',    'default_desc' => '輝石やGoldで冒険支援アイテムを購入できる',               'default_icon' => 'facilities/hokyu_symbol.webp'],
    ];

    // simpleFacilities のうち town から引き継がない独自アイテムのみ
    const SIMPLE_ONLY_ENTRIES = [
        ['slug' => 'explore',           'label' => '探索する',    'default_name' => '探索する',    'default_desc' => '解放済みダンジョンへ向かう',            'default_icon' => 'icon/icon_004.webp'],
        ['slug' => 'city_move',         'label' => '街を移動',    'default_name' => '街を移動',    'default_desc' => '世界地図から街を選ぶ',                  'default_icon' => 'icon/icon_003.webp'],
        ['slug' => 'storage',           'label' => '倉庫',        'default_name' => '倉庫',        'default_desc' => '素材や探索用アイテムを確認する',        'default_icon' => 'icon/icon_025.webp'],
        ['slug' => 'equipment_change',  'label' => '装備変更',    'default_name' => '装備変更',    'default_desc' => '装備・保護・倉庫・売却を行う',          'default_icon' => 'icon/icon_006.webp'],
        ['slug' => 'seal_book',         'label' => '印図鑑',      'default_name' => '印図鑑',      'default_desc' => '集めた印の永続効果を確認する',          'default_icon' => 'icon/icon_240.webp'],
        ['slug' => 'item_book',         'label' => 'アイテム図鑑', 'default_name' => 'アイテム図鑑', 'default_desc' => '素材の入手方法・作り方・用途を確認する', 'default_icon' => 'icon/icon_241.webp'],
        ['slug' => 'ranking_board',     'label' => '番付掲示板',  'default_name' => '番付掲示板',  'default_desc' => '冒険者たちの各種番付を確認する',        'default_icon' => 'icon/icon_223.webp'],
        ['slug' => 'adventurer_market', 'label' => '冒険者市場',  'default_name' => '冒険者市場',  'default_desc' => '素材を冒険者同士で売買する',            'default_icon' => 'icon/icon_032.webp'],
        ['slug' => 'colosseum',         'label' => '闘技場',      'default_name' => '闘技場',      'default_desc' => '他の冒険者と戦う',                      'default_icon' => 'icon/icon_005.webp'],
        ['slug' => 'private_chat',      'label' => '個人チャット', 'default_name' => '個人チャット', 'default_desc' => '冒険者同士でメッセージを送る',         'default_icon' => 'icon/icon_015.webp'],
        ['slug' => 'settings',          'label' => '設定',        'default_name' => '設定',        'default_desc' => '表示やキャラクター情報を変更する',      'default_icon' => 'icon/icon_022.webp'],
    ];

    const HOME_ENTRIES = [
        ['slug' => 'bonus_points',  'label' => '能力割振り',  'default_name' => '能力割振り',  'default_desc' => '未使用BPを使って能力を伸ばす',               'default_icon' => 'menu/menu_bonus_points.webp'],
        ['slug' => 'job_arts',      'label' => '奥義',        'default_name' => '奥義',        'default_desc' => '通常戦用・ボス戦用の奥義をセットする',         'default_icon' => 'icon/icon_041.webp'],
        ['slug' => 'item_book',     'label' => 'アイテム図鑑', 'default_name' => 'アイテム図鑑', 'default_desc' => '素材の入手方法・作り方・用途を確認する',     'default_icon' => 'icon/icon_241.webp'],
        ['slug' => 'seal_book',     'label' => '印図鑑',      'default_name' => '印図鑑',      'default_desc' => '集めた印の永続効果を確認する',               'default_icon' => 'icon/icon_240.webp'],
        ['slug' => 'titles',        'label' => '称号',        'default_name' => '称号',        'default_desc' => '獲得した称号を確認する',                     'default_icon' => 'icon/icon_242.webp'],
        ['slug' => 'valmon',        'label' => 'ヴァルモン',  'default_name' => 'ヴァルモン',  'default_desc' => '相棒ヴァルモンの確認・育成を行う',           'default_icon' => 'menu/menu_valmon.webp'],
        ['slug' => 'equipment_change', 'label' => '装備',     'default_name' => '装備',        'default_desc' => '装備変更・保護・売却を行う',                 'default_icon' => 'icon/icon_006.webp'],
        ['slug' => 'storage',       'label' => '倉庫',        'default_name' => '倉庫',        'default_desc' => '素材や探索用アイテムを確認する',             'default_icon' => 'menu/menu_storage.webp'],
        ['slug' => 'private_chat',  'label' => '個人チャット', 'default_name' => '個人チャット', 'default_desc' => '冒険者同士でメッセージをやり取りする',     'default_icon' => 'menu/menu_messages.webp'],
        ['slug' => 'help',          'label' => 'ヘルプ',      'default_name' => 'ヘルプ',      'default_desc' => '遊び方や施設の説明を確認する',               'default_icon' => 'menu/menu_help.webp'],
        ['slug' => 'settings',      'label' => '設定',        'default_name' => '設定',        'default_desc' => '名前やアイコンなどを変更する',               'default_icon' => 'menu/menu_settings.webp'],
    ];

    public static function nameToSlug(string $section): array
    {
        $entries = match ($section) {
            'town'   => self::TOWN_ENTRIES,
            'simple' => self::SIMPLE_ONLY_ENTRIES,
            'home'   => self::HOME_ENTRIES,
            default  => [],
        };

        $map = [];
        foreach ($entries as $entry) {
            $map[$entry['label']] = $entry['slug'];
        }
        return $map;
    }

    public static function keysForSection(string $section): array
    {
        $entries = match ($section) {
            'town'   => self::TOWN_ENTRIES,
            'simple' => self::SIMPLE_ONLY_ENTRIES,
            'home'   => self::HOME_ENTRIES,
            default  => [],
        };

        $keys = [];
        foreach ($entries as $entry) {
            $prefix = "fac.{$section}.{$entry['slug']}";
            $keys[] = "{$prefix}.name";
            $keys[] = "{$prefix}.desc";
            $keys[] = "{$prefix}.icon";
        }
        return $keys;
    }
}
