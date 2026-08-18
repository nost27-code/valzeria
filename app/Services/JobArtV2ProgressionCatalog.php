<?php

namespace App\Services;

use App\Models\Skill;

/**
 * 2026-08-10 human rulingを含むFIX_NOW対象の完全identity正本。
 * power/hit_countはmasterを正本とし、このcatalogへ複製しない。
 */
final class JobArtV2ProgressionCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const ARTS = [
        // C-design B1: existing-effect ownership and newly assigned lineage grammar.
        '10:1:聖盾撃' => ['key' => 'c_design_guard_basic', 'effect_texts' => ['次に受ける直接ダメージを10%軽減（1回）']],
        '14:5:暴走撃' => ['key' => 'c_design_eclipse_reckless', 'effect_texts' => ['反動で最大HPの8%分のダメージ']],
        '15:5:ガーディアンブロウ' => ['key' => 'c_design_guard_cycle', 'effect_texts' => ['次に受ける直接ダメージを16%軽減（1回）']],
        '18:1:マーキング' => ['key' => 'c_design_aim_mark', 'effect_texts' => ['対象をマーキングし、DEF -10% / SPR -5%']],
        '19:5:スピリットスティール' => ['key' => 'c_design_eclipse_drain', 'effect_texts' => ['与ダメージ35%分HP回復・最大SP10%回復・SPR -7%（2ターン）']],
        '27:1:勇気の灯' => ['key' => 'c_design_command_light', 'effect_texts' => ['次の指揮系連携の発動率 +10ポイント（最大3行動）']],
        '28:5:無拍子' => ['key' => 'c_design_counter_parry_chain', 'effect_texts' => ['予告中の奥義または大技を20%軽減し、実軽減後の次の反撃系連携/奥義を1.20倍']],
        '30:1:闇の契約' => ['key' => 'c_design_eclipse_contract', 'effect_texts' => ['使用成立時に冥蝕+4・物理型/魔法型に応じた4ターン強化']],
        '30:5:暗黒剣' => ['key' => 'c_design_eclipse_drain_cost', 'effect_texts' => ['与ダメージ35%分HP回復・最大HP5%の反動']],
        '31:5:ゴールドラッシュ' => ['key' => 'c_design_transmute_reward', 'effect_texts' => ['通常探索勝利時のGold獲得量 +2%']],
        '32:1:竜槍構え' => ['key' => 'c_design_pierce_stance', 'effect_texts' => ['物理型/魔法型に応じた4ターン強化']],
        '32:5:ドラゴンダイブ' => ['key' => 'c_design_pierce_reform', 'effect_texts' => ['DEF30%無視・予告中は最終ダメージ1.15倍/DEF50%無視']],
        '33:5:羅刹連撃' => ['key' => 'c_design_break_multi_mark', 'effect_texts' => ['HIT時、対象へ崩し印 +1段階（1行動につき1回）']],
        '34:5:夢幻殺' => ['key' => 'c_design_hunt_marked_strike', 'effect_texts' => ['DEF/SPR -15%（3ターン）']],
        '37:1:影追い' => ['key' => 'c_design_hunt_mark', 'effect_texts' => ['HIT時、対象へ狩猟印 +1段階']],
        '37:5:シャドウスナイプ' => ['key' => 'c_design_hunt_marked_accuracy', 'effect_texts' => ['対象に狩猟印がある場合、この攻撃の命中率 +8ポイント']],
        '44:1:守護の構え' => ['key' => 'c_design_guard_stance', 'effect_texts' => ['物理型/魔法型に応じた4ターン強化']],
        '44:5:聖盾裁き' => ['key' => 'c_design_guard_counter', 'effect_texts' => ['物理型/魔法型に応じた4ターン強化']],
        '45:1:魔矢統率' => ['key' => 'c_design_pierce_adaptive_prep', 'effect_texts' => ['次の貫通系連携を物理・魔法の有利な経路で実行（1回・最大3行動）']],
        '46:5:祝福の大旋律' => ['key' => 'c_design_field_blessing', 'effect_texts' => ['MAG +25% / SPR +15%（5ターン）', '不変律（次のターン制強化短縮・解除を1回無効）']],
        '47:5:霊薬の加護' => ['key' => 'c_design_transmute_lineage_only', 'effect_texts' => ['変成の主・副系譜専用。通常探索勝利時の報酬補助']],
        '48:1:先読みの布陣' => ['key' => 'c_design_command_forecast', 'effect_texts' => ['次の指揮系連携の発動率 +10ポイント（最大4行動）']],
        '48:5:王戦の号令' => ['key' => 'c_design_command_order', 'effect_texts' => ['次の指揮系連携/奥義の発動率 +15ポイント（最大3行動）']],
        '49:1:高等錬成' => ['key' => 'c_design_transmute_conversion_reward', 'effect_texts' => ['HP→SP変換・通常探索勝利時のGold/素材補助']],
        '49:5:大錬成爆装' => ['key' => 'c_design_transmute_cycle_reward', 'effect_texts' => ['通常探索勝利時のGold/素材補助']],
        '50:5:聖剣烈破' => ['key' => 'c_design_counter_focus_cycle', 'effect_texts' => ['counter_focusの共通倍率対象']],
        '51:1:黒炎纏い' => ['key' => 'c_design_eclipse_flame_opener', 'effect_texts' => ['HITした場合、冥蝕を+4する']],
        '51:5:黒炎斬' => ['key' => 'c_design_eclipse_flame_blade', 'effect_texts' => ['最大HP5%を非致死で消費できた場合、最終ダメージ ×1.15', '実際に支払った自傷量を記録']],
        '28:9:無双一閃' => ['key' => 'crown_musou_zanshin'],
        '36:9:神域審判' => ['key' => 'crown_holy_wall'],
        '48:9:覇王大戦略' => ['key' => 'crown_overlord_formation'],
        '49:9:錬金大崩壊' => ['key' => 'crown_alchemy_collapse'],
        '51:9:獄炎ナイトメア' => ['key' => 'crown_nightmare_finisher'],
        '53:1:星読の瞬き' => ['key' => 'c_design_field_star_deploy', 'effect_texts' => ['星光の場を展開（生成した場はこの攻撃自身に適用しない）']],
        '53:5:星詠みの光' => ['key' => 'c_design_field_star_extend', 'effect_texts' => ['現在の場を2ターン延長（最大8ターン）']],
        '53:9:星天グランドスペル' => ['key' => 'crown_field_fix'],
        '56:1:聖域の印' => ['key' => 'c_design_guard_sanctuary', 'effect_texts' => ['MAG +15% / SPR +15%（4ターン）']],
        '60:5:剣冠裁断' => ['key' => 'c_design_counter_received', 'effect_texts' => ['物理ダメージ']],
        '60:9:王冠聖剣陣' => ['key' => 'crown_royal_sword_formation'],
        '61:9:黒冠アビスブレイク' => ['key' => 'crown_black_reversal'],

        '79:5:白銀王盾' => ['key' => 'silver_guard_bridge', 'effect_texts' => ['同系譜: DEF/SPR +15%（2ラウンド・継承減衰あり）', '異系譜: 実軽減後のみ使用可能（ダメージのみ）']],
        '22:1:魔矢装填' => ['key' => 'magic_aim_prep', 'effect_texts' => ['次の照準系Rank5/9を物理・魔法の有利な経路で実行（1回・最大4行動）']],
        '26:5:錬成爆弾' => ['key' => 'balance_alchemy_bomb'],
        '33:1:練気' => ['key' => 'break_focus_prep', 'effect_texts' => ['防御準備中の敵に対して、次の崩し系譜の連携または奥義の最終ダメージを1.15倍にする（1回・最大3行動）']],
        '98:1:蒼竜の息吹' => ['key' => 'split_pierce_prep', 'effect_texts' => ['次の貫通系Rank5を2回まで竜気2で使用（最大5行動）']],

        '52:1:蒼天槍' => ['key' => 'pierce_super_stance', 'effect_texts' => ['蒼天構えを5ターン形成']],
        '52:5:蒼天竜槍' => ['key' => 'pierce_super_cycle', 'effect_texts' => ['構え中: 物理DEF35%貫通・構えを1ラウンド再形成']],
        '52:9:蒼穹ドラグーンダイブ' => ['key' => 'pierce_super_finisher', 'effect_texts' => ['蒼天構え成立時: 物理DEF50%貫通・最終ダメージ ×1.15']],
        '62:1:竜冠の槍印' => ['key' => 'pierce_crown_prep'],
        '62:5:竜冠穿槍' => ['key' => 'pierce_crown_cycle'],
        '62:9:竜冠天穿槍' => ['key' => 'pierce_crown_finisher', 'effect_texts' => ['Rank5使用済みなら実効威力470、未使用ならmaster威力']],

        '54:1:影糸仕込み' => ['key' => 'hunt_super_opener', 'effect_texts' => ['行動開始時に相手の標的印が0なら、与えるダメージを1.15倍にする', 'HITした場合、相手の標的印を+1段階する']],
        '54:5:影縫い乱舞' => ['key' => 'hunt_super_seal', 'effect_texts' => ['相手の標的印が1段階以上ある時だけ発動でき、実行時に1段階消費する', 'HITした場合、対象が直前に使った行動の種類を3ターン封じる予約をする']],
        '54:9:影牢・無明縛' => ['key' => 'hunt_super_finisher', 'effect_texts' => ['Rank5由来の封技成立済み: ×1.20 / 未成立: ×0.80']],
        '64:1:影冠追跡' => ['key' => 'hunt_crown_observe', 'effect_texts' => ['HIT時に標的印 +1段階', '対象の直近行動カテゴリを観測']],
        '64:5:影冠狙撃' => ['key' => 'hunt_crown_adaptive_seal', 'effect_texts' => ['相手の標的印が1段階以上ある時だけ発動でき、実行時に1段階消費する', 'HITした場合、読み外し時に次の行動カテゴリへ封技対象を1回だけ補正する']],
        '64:9:影冠終葬射' => ['key' => 'hunt_crown_delayed_seal', 'effect_texts' => ['相手の標的印が2段階以上ある時だけ発動でき、実行時に2段階消費する', 'HITした場合、観測した行動カテゴリの次回使用を封じる予約をする']],

        '55:1:機導起動' => ['key' => 'aim_super_load', 'effect_texts' => ['標準装填: 照準 +4']],
        '55:5:鋼機魔導砲' => ['key' => 'aim_super_accuracy', 'effect_texts' => ['この攻撃の命中率を+5ポイントする']],
        '55:9:機神オーバードライブ' => ['key' => 'crown_tracking_coordinates'],
        '65:1:鋼冠起動' => ['key' => 'aim_crown_load'],
        '65:5:鋼冠機砲' => ['key' => 'aim_crown_pressure'],
        '65:9:鋼冠グラビトンコア' => ['key' => 'aim_crown_finisher'],

        '57:1:金脈錬成' => ['key' => 'transmute_super_producer'],
        '57:5:黄金転化' => ['key' => 'transmute_super_cycle', 'effect_texts' => ['触媒4消費・標準変成の連携']],
        '57:9:黄金創世陣' => ['key' => 'transmute_super_finisher'],
        '67:1:金冠錬符' => ['key' => 'transmute_crown_suppress', 'effect_texts' => ['HIT時に対象へ金蝕1回（次の系譜リソース獲得行動で各獲得量-1、最低1）']],
        '67:5:金冠錬成' => ['key' => 'transmute_crown_compensation', 'effect_texts' => ['対象の次2行動でresource実獲得がなければ触媒 +2']],
        '67:9:金冠ミダスフィールド' => ['key' => 'transmute_crown_double_suppress', 'effect_texts' => ['HIT時に対象へ金蝕2回（次の系譜リソース獲得行動で各獲得量-1、最低1）']],

        '58:1:雷気充填' => ['key' => 'break_super_mark', 'effect_texts' => ['HIT時に対象へ崩し印 +1段階']],
        '58:5:雷拳乱舞' => ['key' => 'break_super_multi', 'effect_texts' => ['3Hit（master総威力を均等分割）', '崩し印は1行動につき最大1段階']],
        '58:9:雷霆覇王拳' => ['key' => 'break_super_finisher', 'effect_texts' => ['相手の崩し印が3段階以上ある時だけ発動できる', '行動開始時HP35%以下なら最終ダメージ ×1.20']],
        '68:1:雷冠練気' => ['key' => 'break_crown_mark'],
        '68:5:雷冠閃拳' => ['key' => 'break_crown_reconnect', 'effect_texts' => ['崩し印を浄化された後の残心があれば、HIT時に崩し印へ再接続']],
        '68:9:雷冠天鳴掌' => ['key' => 'break_crown_finisher', 'effect_texts' => ['残心があればHIT時に崩し印へ再接続']],

        '59:1:戦線把握' => ['key' => 'command_super_observe', 'effect_texts' => ['成功した戦技区分を記録し、次の指揮系譜戦技の発動率を+15ポイントする']],
        '59:5:勝機の戦陣' => ['key' => 'command_super_chain', 'effect_texts' => ['直前と異なる区分（始動／連携／奥義）の指揮系譜戦技を優先し、発動率を+20ポイントする']],
        '59:9:八陣無双策' => ['key' => 'crown_eight_formation'],
        '69:5:戦冠総攻令' => ['key' => 'command_crown_priority', 'effect_texts' => ['次の指揮系譜戦技の発動率を+20ポイントする']],
        '69:9:王戦アークフォーメーション' => ['key' => 'crown_royal_formation'],
    ];

    /** @return array<string, mixed>|null */
    public function forArt(Skill $skill): ?array
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        return self::ARTS[$this->identity($skill)] ?? null;
    }

    public function keyFor(Skill $skill): ?string
    {
        return $this->forArt($skill)['key'] ?? null;
    }

    public function isCDesignOnly(Skill $skill): bool
    {
        $key = $this->keyFor($skill);

        return is_string($key) && str_starts_with($key, 'c_design_');
    }

    /** @return list<string> */
    public function effectTexts(Skill $skill): array
    {
        return array_values(array_map('strval', $this->forArt($skill)['effect_texts'] ?? []));
    }

    /** @return list<string> */
    public function effectTextsForDisplay(
        Skill $skill,
        bool $mechanicsAllowed,
        bool $cDesignMechanicsAllowed = false,
    ): array
    {
        $key = $this->keyFor($skill);
        if ($key === null) {
            return [];
        }
        if ($this->isCDesignOnly($skill) && ! $cDesignMechanicsAllowed) {
            return [];
        }
        if ($key === 'silver_guard_bridge') {
            return $mechanicsAllowed
                ? ['DEF/SPR +15%（2ラウンド・継承減衰あり）']
                : ['異系譜では実軽減後のみ使用可能（ダメージのみ）'];
        }

        return $mechanicsAllowed ? $this->effectTexts($skill) : [];
    }

    public function replacementTemplateForDisplay(Skill $skill, bool $mechanicsAllowed): ?string
    {
        if ($this->forArt($skill) === null) {
            return null;
        }

        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;
        if ($jobId === 79 && $rank === 5) {
            return 'PHYSICAL_DAMAGE';
        }
        if ($jobId === 67) {
            return 'MAGICAL_DAMAGE';
        }
        if ($jobId === 68) {
            return 'PHYSICAL_DAMAGE';
        }
        if (! $mechanicsAllowed) {
            return null;
        }

        return match (true) {
            $jobId === 33 && $rank === 1 => 'V2_ROLE_EFFECT_ONLY',
            $jobId === 55 => 'MAGICAL_DAMAGE',
            $jobId === 58 && $rank === 5 => 'MULTI_HIT',
            default => null,
        };
    }

    public function hitCountForDisplay(Skill $skill, bool $mechanicsAllowed): int
    {
        if ($mechanicsAllowed
            && $this->keyFor($skill) === 'break_super_multi'
        ) {
            return 3;
        }

        return max(1, (int) $skill->hit_count);
    }

    private function identity(Skill $skill): string
    {
        return implode(':', [(int) $skill->job_id, (int) $skill->learn_rank, (string) $skill->name]);
    }
}
