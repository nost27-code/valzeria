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
        '79:5:白銀王盾' => ['key' => 'silver_guard_bridge', 'effect_texts' => ['同系譜: DEF/SPR +15%（2ラウンド・継承減衰あり）', '異系譜: 実軽減後のみ使用可能（ダメージのみ）']],
        '22:1:魔矢装填' => ['key' => 'magic_aim_prep', 'effect_texts' => ['次の照準系Rank5/9を物理・魔法の有利な経路で実行（1回・最大3行動）']],
        '33:1:練気' => ['key' => 'break_focus_prep', 'effect_texts' => ['崩し +4', '防御準備中の敵なら次の崩し系Rank5/9最終ダメージ ×1.15（1回・最大3行動）']],
        '98:1:蒼竜の息吹' => ['key' => 'split_pierce_prep', 'effect_texts' => ['次の貫通系Rank5を2回まで竜気2で使用（最大5行動）']],

        '52:1:蒼天槍' => ['key' => 'pierce_super_stance', 'effect_texts' => ['蒼天構えを3ラウンド形成']],
        '52:5:蒼天竜槍' => ['key' => 'pierce_super_cycle', 'effect_texts' => ['構え中: 物理DEF35%貫通・構えを1ラウンド再形成']],
        '52:9:蒼穹ドラグーンダイブ' => ['key' => 'pierce_super_finisher', 'effect_texts' => ['蒼天構え成立時: 物理DEF50%貫通・最終ダメージ ×1.15']],
        '62:1:竜冠の槍印' => ['key' => 'pierce_crown_prep'],
        '62:5:竜冠穿槍' => ['key' => 'pierce_crown_cycle'],
        '62:9:竜冠天穿槍' => ['key' => 'pierce_crown_finisher', 'effect_texts' => ['Rank5使用済みなら実効威力470、未使用ならmaster威力']],

        '54:1:影糸仕込み' => ['key' => 'hunt_super_opener', 'effect_texts' => ['行動開始時に対象の狩猟印0なら最終ダメージ ×1.15', 'HIT時に対象へ狩猟印 +1段階']],
        '54:5:影縫い乱舞' => ['key' => 'hunt_super_seal', 'effect_texts' => ['狩猟印1段階消費', '対象が直前に使った行動カテゴリを3ラウンド封技予約']],
        '54:9:影牢・無明縛' => ['key' => 'hunt_super_finisher', 'effect_texts' => ['Rank5由来の封技成立済み: ×1.20 / 未成立: ×0.80']],
        '64:1:影冠追跡' => ['key' => 'hunt_crown_observe', 'effect_texts' => ['HIT時に狩猟印 +1段階', '対象の直近行動カテゴリを観測']],
        '64:5:影冠狙撃' => ['key' => 'hunt_crown_adaptive_seal', 'effect_texts' => ['狩猟印1段階消費', '読み外し時、次の行動カテゴリへ封技対象を1回だけ補正']],
        '64:9:影冠終葬射' => ['key' => 'hunt_crown_delayed_seal', 'effect_texts' => ['狩猟印2段階消費', '観測カテゴリの次回使用を封技予約']],

        '55:1:機導起動' => ['key' => 'aim_super_load', 'effect_texts' => ['標準装填: 照準 +4']],
        '55:5:鋼機魔導砲' => ['key' => 'aim_super_accuracy', 'effect_texts' => ['物理攻撃・命中率 +5ポイント', '照準8以上で使用し4消費']],
        '55:9:機神オーバードライブ' => ['key' => 'aim_super_finisher', 'effect_texts' => ['基礎MISSを省略（能動回避は有効）', 'HIT時に対象最大SP5%を1戦1回減少']],
        '65:1:鋼冠起動' => ['key' => 'aim_crown_load'],
        '65:5:鋼冠機砲' => ['key' => 'aim_crown_pressure'],
        '65:9:鋼冠グラビトンコア' => ['key' => 'aim_crown_finisher'],

        '57:1:金脈錬成' => ['key' => 'transmute_super_producer', 'effect_texts' => ['標準変成: 触媒 +4']],
        '57:5:黄金転化' => ['key' => 'transmute_super_cycle', 'effect_texts' => ['触媒4消費・標準変成の展開']],
        '57:9:黄金創世陣' => ['key' => 'transmute_super_finisher', 'effect_texts' => ['触媒12消費・標準変成の奥義']],
        '67:1:金冠錬符' => ['key' => 'transmute_crown_suppress', 'effect_texts' => ['対象の次回resource実獲得を半減']],
        '67:5:金冠錬成' => ['key' => 'transmute_crown_compensation', 'effect_texts' => ['対象の次2行動でresource実獲得がなければ触媒 +2']],
        '67:9:金冠ミダスフィールド' => ['key' => 'transmute_crown_double_suppress', 'effect_texts' => ['対象の次2回のresource実獲得を半減（1戦1回）']],

        '58:1:雷気充填' => ['key' => 'break_super_mark', 'effect_texts' => ['HIT時に対象へ崩し印 +1段階']],
        '58:5:雷拳乱舞' => ['key' => 'break_super_multi', 'effect_texts' => ['3Hit（master総威力を均等分割）', '崩し印は1行動につき最大1段階']],
        '58:9:雷霆覇王拳' => ['key' => 'break_super_finisher', 'effect_texts' => ['行動開始時HP35%以下なら最終ダメージ ×1.20']],
        '68:1:雷冠練気' => ['key' => 'break_crown_mark', 'effect_texts' => ['HIT時に冠位由来の崩し印 +1段階']],
        '68:5:雷冠閃拳' => ['key' => 'break_crown_reconnect', 'effect_texts' => ['崩し印を浄化された後の残心があれば、HIT時に崩し印へ再接続']],
        '68:9:雷冠天鳴掌' => ['key' => 'break_crown_finisher', 'effect_texts' => ['残心があればHIT時に崩し印へ再接続']],

        '59:1:戦線把握' => ['key' => 'command_super_observe', 'effect_texts' => ['指揮点 +4', '成功した行動カテゴリを記録し、次の現在職戦技の発動率 +15pt']],
        '59:5:勝機の戦陣' => ['key' => 'command_super_chain', 'effect_texts' => ['直前と異なるカテゴリの現在職戦技を優先し、発動率 +20pt']],
        '59:9:八陣無双策' => ['key' => 'command_super_guarantee', 'effect_texts' => ['次の現在職戦技を発動確定（1戦2回まで）']],
        '69:1:戦冠指揮' => ['key' => 'command_crown_delay', 'effect_texts' => ['次ラウンド、元判定が後攻の時だけ先後を1回再抽選', '内部CT 3ラウンド']],
        '69:5:戦冠総攻令' => ['key' => 'command_crown_priority', 'effect_texts' => ['次の現在職戦技の発動率 +20pt']],
        '69:9:王戦アークフォーメーション' => ['key' => 'command_crown_force', 'effect_texts' => ['次ラウンドに先行確定し、現在職戦技を先に評価（1戦1回）']],
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

    /** @return list<string> */
    public function effectTexts(Skill $skill): array
    {
        return array_values(array_map('strval', $this->forArt($skill)['effect_texts'] ?? []));
    }

    /** @return list<string> */
    public function effectTextsForDisplay(Skill $skill, bool $mechanicsAllowed): array
    {
        $key = $this->keyFor($skill);
        if ($key === null) {
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
            $jobId === 55 => 'PHYSICAL_DAMAGE',
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
