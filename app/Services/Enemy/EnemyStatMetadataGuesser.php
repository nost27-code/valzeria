<?php

namespace App\Services\Enemy;

class EnemyStatMetadataGuesser
{
    /**
     * @param  array<string, mixed>  $enemy
     * @return array{family_key:string,variant_key:string,role_key:string,manual_adjustment_note:?string}
     */
    public function guess(array $enemy): array
    {
        $name = (string) ($enemy['name'] ?? '');
        $role = (string) ($enemy['role'] ?? '');
        $type = (string) ($enemy['type_name'] ?? '');
        $element = (string) ($enemy['element'] ?? '');
        $isBoss = (bool) ($enemy['is_boss'] ?? false);
        $text = $name . ' ' . $role . ' ' . $type . ' ' . $element;

        return [
            'family_key' => $this->familyKey($text),
            'variant_key' => $this->variantKey($text, $element),
            'role_key' => $this->roleKey($role, $type, $name, $isBoss),
            'manual_adjustment_note' => 'auto_migration_note: stat generation keys inferred from existing enemy master. Please review.',
        ];
    }

    private function roleKey(string $role, string $type, string $name, bool $isBoss): string
    {
        $text = $role . ' ' . $type . ' ' . $name;

        if (str_contains($text, '異界')) {
            return 'otherworld_boss';
        }
        if (str_contains($text, '都市ボス')) {
            return 'city_boss';
        }
        if (str_contains($text, '最深部候補') || str_contains($text, 'ボス候補')) {
            return 'deep_candidate';
        }
        if ($isBoss || str_contains($text, 'ボス')) {
            return 'boss';
        }
        if (str_contains($text, '黄金')) {
            return 'golden';
        }
        if (str_contains($text, 'レア')) {
            return 'rare';
        }
        if (str_contains($text, 'やや強い') || str_contains($text, '強敵')) {
            return 'strong';
        }
        if (str_contains($text, '弱')) {
            return 'normal_weak';
        }

        return 'normal';
    }

    private function familyKey(string $text): string
    {
        if ($this->containsAny($text, ['スライム', '粘液'])) return 'slime';
        if ($this->containsAny($text, ['ゴブリン', '小鬼'])) return 'goblin';
        if ($this->containsAny($text, ['竜', 'ドラゴン', 'ワイバーン'])) return 'dragon';
        if ($this->containsAny($text, ['機械', '兵器', '蒸気', 'ギア', 'オメガ', 'コア'])) return 'machine';
        if ($this->containsAny($text, ['悪魔', '魔神', '魔王', 'デーモン', 'バフォメット'])) return 'demon';
        if ($this->containsAny($text, ['妖精', '精霊', 'ピクシー', 'フェアリー', '樹霊', 'ニンフィア'])) return 'spirit';
        if ($this->containsAny($text, ['魔導', '魔術', '魔法', '魔女', '魔導士', 'シャーマン', 'メイジ', '司書', '占星', '禁書', '術師', '司祭', '神官'])) return 'mage';
        if ($this->containsAny($text, ['スケルトン', 'ゾンビ', 'リッチ', '亡霊', '幽霊', '死者', '冥', '墓守'])) return 'undead';
        if ($this->containsAny($text, ['ゴーレム', '巨人', 'トロル', '巨体'])) return 'giant';
        if ($this->containsAny($text, ['ワーム', 'サソリ', 'マンティス', '甲虫', '虫'])) return 'insect';
        if ($this->containsAny($text, ['コウモリ', '鳥', '翼', 'グリフォン', '天馬', 'ペガサス'])) return 'flying';
        if ($this->containsAny($text, ['海', '水', '珊瑚', '人魚', '蟹', 'ガニ', '甲殻', '深海'])) return 'aquatic';
        if ($this->containsAny($text, ['ウルフ', '狼', '兎', 'うさぎ', 'ネズミ', '熊', '鹿', '獣', 'ボア', '馬'])) return 'beast';
        if ($this->containsAny($text, ['盗賊', '兵', '騎士', '剣士', '教官', '衛士', '海賊', '隊長', '将軍', 'ドワーフ'])) return 'soldier';

        return 'standard';
    }

    private function variantKey(string $text, string $element): string
    {
        $element = trim($element);
        if ($element !== '') {
            if ($this->containsAny($element, ['火', '炎'])) return 'fire';
            if ($this->containsAny($element, ['氷', '雪'])) return 'ice';
            if ($this->containsAny($element, ['雷'])) return 'thunder';
            if ($this->containsAny($element, ['毒'])) return 'poison';
            if ($this->containsAny($element, ['聖', '光'])) return 'holy';
            if ($this->containsAny($element, ['闇'])) return 'dark';
            if ($this->containsAny($element, ['地', '砂'])) return 'earth';
            if ($this->containsAny($element, ['水', '海'])) return 'water';
            if ($this->containsAny($element, ['自然', '森'])) return 'forest';
            if ($this->containsAny($element, ['魔', '星', '次元'])) return 'arcane';
            if ($this->containsAny($element, ['古代'])) return 'ancient';
            if ($this->containsAny($element, ['深淵'])) return 'abyss';
            if ($this->containsAny($element, ['金属', '鉄', '鋼'])) return 'metal';
            if ($this->containsAny($element, ['亡霊', '霊'])) return 'ghost';
        }

        $source = $text;

        if ($this->containsAny($source, ['火', '炎', '灼', '溶鉱'])) return 'fire';
        if ($this->containsAny($source, ['氷', '雪', '凍', '吹雪'])) return 'ice';
        if ($this->containsAny($source, ['雷', '電', 'ヴォルト'])) return 'thunder';
        if ($this->containsAny($source, ['毒', '瘴気'])) return 'poison';
        if ($this->containsAny($source, ['聖', '神', '光', '天界'])) return 'holy';
        if ($this->containsAny($source, ['闇', '魔界', '死霊', '奈落'])) return 'dark';
        if ($this->containsAny($source, ['地', '砂', '岩', '王墓'])) return 'earth';
        if ($this->containsAny($source, ['水', '海', '潮', '深海', '珊瑚'])) return 'water';
        if ($this->containsAny($source, ['自然', '森', '草', '樹', '若葉'])) return 'forest';
        if ($this->containsAny($source, ['魔導', '魔法', '星', '次元', '禁書'])) return 'arcane';
        if ($this->containsAny($source, ['古代', '遺跡'])) return 'ancient';
        if ($this->containsAny($source, ['深淵', '奈落'])) return 'abyss';
        if ($this->containsAny($source, ['金属', '鉄', '鋼', '機械'])) return 'metal';
        if ($this->containsAny($source, ['亡霊', '幽霊', '霊'])) return 'ghost';

        return 'none';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
