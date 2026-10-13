<?php

namespace App\Services;

use App\Models\Skill;

/** Prototype対象10枚のexact identityと表示文言を一元化する。 */
final class JobArtV2UltimateCounterplayCatalog
{
    public const COUNTER_INTERCEPT = 'counter_intercept';
    public const ECLIPSE_BACKLASH = 'eclipse_backlash';
    public const PIERCE_OPENING = 'pierce_opening';
    public const FIELD_SUPPRESSION = 'field_suppression';
    public const HUNT_CANCEL = 'hunt_cancel';
    public const AIM_SP_PRESSURE = 'aim_sp_pressure';
    public const ULTIMATE_GUARD = 'ultimate_guard';
    public const TRANSMUTE_RESOURCE_SLOW = 'transmute_resource_slow';
    public const BREAK_PREPARATION = 'break_preparation';
    public const READINESS_DELAY = 'readiness_delay';
    public const FORTRESS_GUARD = 'fortress_guard';

    /** @var array<string, array{effect:string,effect_text:string}> */
    private const ARTS = [
        '28:5:無拍子' => [
            'effect' => self::COUNTER_INTERCEPT,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時は、次に受けるその予告された攻撃のダメージを20%減らす。実際に1以上軽減した場合、剣勢を+4する。',
        ],
        '30:5:暗黒剣' => [
            'effect' => self::ECLIPSE_BACKLASH,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時に、この攻撃が命中すると、冥蝕反噬を付与する。対象が予告中の行動を実行すると、解決後に最大HPの5%を失う。ただし、この効果でHPは1未満にならない。',
        ],
        '32:5:ドラゴンダイブ' => [
            'effect' => self::PIERCE_OPENING,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時は、最終ダメージを1.15倍にし、相手の防御を50%無視する。予告された技の準備は中断しない。',
        ],
        '53:5:星詠みの光' => [
            'effect' => self::FIELD_SUPPRESSION,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時は、対象の次の行動終了まで封式の場を展開する。その間に予告中の行動を発動した場合、技そのものは発動するが、その技の追加効果は発動しない。',
        ],
        '54:5:影縫い乱舞' => [
            'effect' => self::HUNT_CANCEL,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時に、この攻撃が命中すると、対人戦では相手の奥義の準備を中断する。敵モンスターの場合は、その予告された技の使用を1行動分遅らせる。この時は、相手が直前に使った行動を封じる効果は発動しない。',
        ],
        '4:5:狙い撃ち' => [
            'effect' => self::AIM_SP_PRESSURE,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時に、この攻撃が命中すると、対象がSPを持つなら、現在SPを最大SPの3%減らす。予告された技の準備は中断しない。',
        ],
        '15:9:不落要塞' => [
            'effect' => self::FORTRESS_GUARD,
            'effect_text' => '相手が奥義、または敵モンスターが強い攻撃を予告している時に使うと、次に受けるその攻撃のダメージを50%減らす。ダメージ軽減の効かない攻撃は対象外。通常の軽減とは重ならない。その攻撃による能力低下・SP減少・戦技に使う力（聖護など）への妨害を防ぐ。複数回当たる攻撃も、まとめて1つの行動として扱う。',
        ],
        '15:5:ガーディアンブロウ' => [
            'effect' => self::ULTIMATE_GUARD,
            'effect_text' => '相手が奥義、または敵モンスターが強い攻撃を予告している時に使うと、次に受けるその攻撃のダメージを35%減らす。ダメージ軽減の効かない攻撃は対象外。通常の軽減とは重ならない。複数回当たる攻撃も、まとめて1つの行動として扱う。',
        ],
        '49:5:大錬成爆装' => [
            'effect' => self::TRANSMUTE_RESOURCE_SLOW,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時に、この攻撃が命中すると、相手が戦技用の力（聖護・触媒など）を持つ場合、その予告された技を使った後の2回、力の獲得量をそれぞれ-1する（最低0）。',
        ],
        '33:5:羅刹連撃' => [
            'effect' => self::BREAK_PREPARATION,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時に、この攻撃が命中し、相手に崩し印がある場合、予告中の行動を強化する準備効果を1つ解除する。解除対象がある場合だけ崩し印を1段階消費する。予告された技の準備は中断しない。',
        ],
        '48:5:王戦の号令' => [
            'effect' => self::READINESS_DELAY,
            'effect_text' => '相手が奥義、または敵モンスターが強い技を予告している時は、予告中の行動を1行動分遅らせる。同じ予告に対して遅らせられるのは1回だけ。',
        ],
    ];

    /** @return array{effect:string,effect_text:string}|null */
    public function forArt(Skill $skill): ?array
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        return self::ARTS[$this->key($skill)] ?? null;
    }

    public function effectFor(Skill $skill): ?string
    {
        return $this->forArt($skill)['effect'] ?? null;
    }

    public function isCounterplayArt(Skill $skill): bool
    {
        return $this->forArt($skill) !== null;
    }

    public function effectText(Skill $skill): ?string
    {
        return $this->forArt($skill)['effect_text'] ?? null;
    }

    private function key(Skill $skill): string
    {
        return implode(':', [(int) $skill->job_id, (int) $skill->learn_rank, (string) $skill->name]);
    }
}
