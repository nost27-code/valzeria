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

    /** @var array<string, array{effect:string,effect_text:string}> */
    private const ARTS = [
        '28:5:無拍子' => [
            'effect' => self::COUNTER_INTERCEPT,
            'effect_text' => '相手が奥義予告中、または敵が大技予告中の場合、次に受ける予告中の攻撃を20%軽減する。実際に1以上軽減した場合、剣勢を+4する。',
        ],
        '30:5:暗黒剣' => [
            'effect' => self::ECLIPSE_BACKLASH,
            'effect_text' => '奥義予告中の相手、または大技予告中の敵にHITした場合、冥蝕反噬を付与する。対象が予告中の行動を実行すると、解決後に最大HPの5%を非致死で失う。',
        ],
        '32:5:ドラゴンダイブ' => [
            'effect' => self::PIERCE_OPENING,
            'effect_text' => '奥義予告中の相手、または大技予告中の敵には、最終ダメージを1.15倍にし、相手の防御を50%無視する。予告そのものは解除しない。',
        ],
        '53:5:星詠みの光' => [
            'effect' => self::FIELD_SUPPRESSION,
            'effect_text' => '相手が奥義予告中、または敵が大技予告中の場合、対象の次の行動終了まで封式の場を展開する。その間に予告中の行動を発動した場合、基礎効果だけを発動し、固有の追加効果は発動しない。',
        ],
        '54:5:影縫い乱舞' => [
            'effect' => self::HUNT_CANCEL,
            'effect_text' => '奥義予告中または大技予告中にHITした場合、PvPでは奥義準備を中断し、敵の大技予告は発動を1行動分遅らせる。この場合、通常の封技予約は行わない。',
        ],
        '4:5:狙い撃ち' => [
            'effect' => self::AIM_SP_PRESSURE,
            'effect_text' => '奥義予告中の相手、または大技予告中の敵にHITした場合、対象がSPを持つなら、現在SPを最大SPの3%減らす。予告そのものは解除しない。',
        ],
        '15:5:ガーディアンブロウ' => [
            'effect' => self::ULTIMATE_GUARD,
            'effect_text' => '相手が奥義予告中、または敵が軽減可能な大技予告中なら、通常の軽減を、次に受ける予告中の攻撃のダメージを35%軽減する専用防御へ変える。多段攻撃も1つの行動として軽減する。',
        ],
        '49:5:大錬成爆装' => [
            'effect' => self::TRANSMUTE_RESOURCE_SLOW,
            'effect_text' => '奥義予告中の相手、または大技予告中の敵にHITした場合、対象が系譜資源を持つなら、予告中の行動の実行後、次に系譜資源を獲得する2回の獲得量をそれぞれ-1する（最低0）。',
        ],
        '33:5:羅刹連撃' => [
            'effect' => self::BREAK_PREPARATION,
            'effect_text' => '奥義予告中の相手、または大技予告中の敵にHITし、崩し印がある場合、予告中の行動を強化する準備効果を1つ解除する。解除対象がある場合だけ崩し印を1段階消費する。予告そのものは解除しない。',
        ],
        '48:5:王戦の号令' => [
            'effect' => self::READINESS_DELAY,
            'effect_text' => '相手が奥義予告中、または敵が大技予告中の場合、予告中の行動を1行動分遅らせる。同じ予告に対して遅らせられるのは1回だけ。',
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
