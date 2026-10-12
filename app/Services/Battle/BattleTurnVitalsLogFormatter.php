<?php

namespace App\Services\Battle;

final class BattleTurnVitalsLogFormatter
{
    /** Snapshot the battle actors for display only, before either actor acts. */
    public static function fromActors(BattleActor $player, BattleActor $opponent): string
    {
        return '<span class="battle-log-turn-vitals">'
            .self::combatant($player, 'あなた')
            .self::combatant($opponent, '対戦相手')
            .'</span>';
    }

    private static function combatant(BattleActor $actor, string $label): string
    {
        return '<span class="battle-log-vitals-combatant">'
            .'<span class="battle-log-vitals-name">'.$label.'（'.$actor->logName().'）</span>'
            .self::resource('HP', $actor->hp, $actor->maxHp, 'hp')
            .self::resource('SP', $actor->mp, $actor->maxMp, 'sp')
            .'</span>';
    }

    private static function resource(string $label, int $current, int $max, string $kind): string
    {
        $current = max(0, $current);
        $max = max(0, $max);
        $percent = $max > 0 ? min(100, ($current / $max) * 100) : 0;
        $width = number_format($percent, 2, '.', '');

        return '<span class="battle-log-vitals-resource">'
            .'<span class="battle-log-vitals-value">'.$label.' '.number_format($current).' / '.number_format($max).'</span>'
            .'<span class="battle-log-vitals-track">'
            .'<span class="battle-log-vitals-fill battle-log-vitals-'.$kind.'" style="width: '.$width.'%;"></span>'
            .'</span></span>';
    }
}
