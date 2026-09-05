<?php

namespace App\Services\Nation\Raid;

/** ローカル試遊の確定済み戦闘結果を、通常戦闘と同じ時系列の表示契約へ整形する。 */
final readonly class NationRaidTrialBattleLogPresenter
{
    /** @var array<string, string> */
    private const ENEMY_EFFECT_MESSAGES = [
        'defense_down_10_two_actions' => '防御が10%低下した！（2行動）',
        'healing_down_25_two_actions' => 'HP回復量が25%低下した！（2行動）',
        'counter_damage_down_50' => '反撃ダメージが50%低下した！',
        'field_remove_and_extension_block' => '展開中の場が消滅し、延長を封じられた！',
        'current_sp_down_8' => '現在SPが最大SPの8%減少した！',
        'nonlethal_reflect_max_hp_8' => '次の直接攻撃に、最大HP8%の非致死反射が仕込まれた！',
        'defense_spirit_healing_down_25_two_actions' => '防御・精神・HP回復量が25%低下した！（2行動）',
        'hp_sp_healing_down_50_two_actions' => 'HP・SP回復量が50%低下した！（2行動）',
        'drain_healing_down_50_one_action' => '吸収回復量が50%低下した！（1行動）',
        'next_direct_damage_down_30' => '次の直接ダメージが30%低下する！',
        'clear_marks_and_next_multihit_down_25' => '狩猟印と崩し印が消滅し、次の多段ダメージが25%低下する！',
        'cleanse_and_guard_per_debuff' => '黒天竜の弱体効果が浄化され、解除数に応じた守りを得た！',
    ];

    public function __construct(private NationRaidRules $rules) {}

    /**
     * @param  list<string>  $playerBattleLogs
     * @return array{opening_logs:list<string>,turns:list<array<string,mixed>>,outcome_message:string}
     */
    public function present(
        NationRaidBattleResult $battle,
        array $playerBattleLogs,
        string $playerName,
        string $bossName,
    ): array {
        [$openingLogs, $playerLogsByTurn] = $this->splitPlayerLogs($playerBattleLogs);
        if ($openingLogs === []) {
            $openingLogs[] = '【戦闘開始】'.e($playerName).' は '.e($bossName).' と遭遇した！';
        }

        $actionNames = $this->enemyActionNames();
        $turns = [];
        foreach ($battle->turns as $index => $turn) {
            $enemyDamage = is_array($turn['enemy_damage'] ?? null) ? $turn['enemy_damage'] : [];
            $sources = is_array($turn['player_damage']['sources'] ?? null)
                ? $turn['player_damage']['sources']
                : [];
            $damage = $this->playerDamageBreakdown($sources);
            $enemyActionId = is_string($turn['enemy_action_id'] ?? null)
                ? $turn['enemy_action_id']
                : null;
            $enemyActionName = $enemyActionId !== null
                ? ($actionNames[$enemyActionId] ?? $enemyActionId)
                : '行動遅延';
            $hits = is_array($enemyDamage['hits'] ?? null) ? $enemyDamage['hits'] : [];
            $hitOutcomes = array_count_values(array_map(
                static fn (array $hit): string => (string) ($hit['outcome'] ?? 'miss'),
                array_filter($hits, 'is_array'),
            ));
            $counterplay = is_array($turn['counterplay'] ?? null) ? $turn['counterplay'] : null;
            $nextTurn = is_array($battle->turns[$index + 1] ?? null) ? $battle->turns[$index + 1] : null;
            $playerActionLogs = $this->playerActionLogs(
                is_array($playerLogsByTurn[(int) $turn['turn']] ?? null)
                    ? $playerLogsByTurn[(int) $turn['turn']]
                    : [],
                $playerName,
                $bossName,
            );

            $turns[] = [
                'turn' => (int) $turn['turn'],
                'player_logs' => $playerActionLogs,
                'player_action_damage' => $damage['action'],
                'counter_damage' => $damage['counter'],
                'eclipse_backlash_damage' => $damage['eclipse_backlash'],
                'counterplay_name' => $this->counterplayName($turn['selected_counterplay_identity'] ?? null),
                'counterplay_message' => $this->counterplayMessage($counterplay),
                'enemy_action_id' => $enemyActionId,
                'enemy_action_name' => $enemyActionName,
                'enemy_action_kind' => $this->enemyActionKind($turn, $enemyActionId),
                'enemy_damage' => (int) ($enemyDamage['finalDamage'] ?? 0),
                'enemy_hit_count' => (int) ($hitOutcomes['hit'] ?? 0),
                'enemy_miss_count' => (int) ($hitOutcomes['miss'] ?? 0),
                'enemy_evade_count' => (int) ($hitOutcomes['evade'] ?? 0),
                'enemy_total_hits' => count($hits),
                'enemy_critical' => in_array(true, array_map(
                    static fn (array $hit): bool => (bool) ($hit['critical'] ?? false),
                    array_filter($hits, 'is_array'),
                ), true),
                'damage_cap_hit' => (int) ($enemyDamage['beforeCap'] ?? 0) > (int) ($enemyDamage['afterCap'] ?? 0),
                'damage_before_cap' => (int) ($enemyDamage['beforeCap'] ?? 0),
                'damage_after_cap' => (int) ($enemyDamage['afterCap'] ?? 0),
                'defense_messages' => $this->defenseMessages($enemyDamage['playerDefense'] ?? null),
                'effect_messages' => $this->effectMessages($enemyDamage['appliedEffects'] ?? null),
                'player_self_damage' => (int) ($turn['player_self_damage'] ?? 0),
                'player_hp_after' => (int) ($turn['player_hp_after'] ?? 0),
                'player_sp_after' => (int) ($turn['player_sp_after'] ?? 0),
                'boss_sp_after' => (int) ($turn['boss_sp_after'] ?? 0),
                'note' => is_string($turn['note'] ?? null) ? $turn['note'] : null,
                'telegraph' => $this->telegraphForNextTurn($turn, $nextTurn, $actionNames),
            ];
        }

        return [
            'opening_logs' => $openingLogs,
            'turns' => $turns,
            'outcome_message' => $battle->outcome === 'survived'
                ? $playerName.'は、20ターンを戦い抜いた！'
                : $playerName.'は、倒れてしまった……。',
        ];
    }

    /** @return array<string, string> */
    private function enemyActionNames(): array
    {
        $names = ['lineage_observation' => '系譜観測'];
        foreach ($this->rules->basicActions() as $id => $action) {
            $names[$id] = $action['name'];
        }
        foreach ($this->rules->counterActions() as $action) {
            $names[$action['action_id']] = $action['name'];
        }

        return $names;
    }

    /**
     * @param  list<string>  $logs
     * @return array{list<string>,array<int,list<string>>}
     */
    private function splitPlayerLogs(array $logs): array
    {
        $opening = [];
        $byTurn = [];
        $currentTurn = null;

        foreach ($logs as $log) {
            $log = (string) $log;
            $plain = $this->plainText($log);
            if (preg_match('/---\s*ターン\s*(\d+)\s*---/u', $plain, $matches) === 1) {
                $currentTurn = (int) $matches[1];
                $byTurn[$currentTurn] ??= [];

                continue;
            }
            if ($this->isGenericBattleEnding($plain)) {
                continue;
            }
            if ($currentTurn === null) {
                $opening[] = $log;
            } else {
                $byTurn[$currentTurn][] = $log;
            }
        }

        return [$opening, $byTurn];
    }

    /**
     * @param  list<string>  $logs
     * @return list<string>
     */
    private function playerActionLogs(array $logs, string $playerName, string $bossName): array
    {
        $visible = [];
        $normalActionAdded = false;
        foreach ($logs as $log) {
            $plain = $this->plainText($log);
            if ($this->isRaidIncomingDefenseLog($plain, $playerName, $bossName)) {
                // ボスターンの防御・反撃結果はPhase 1の確定値から、敵技直後へ表示する。
                continue;
            }
            if (str_contains($plain, $bossName) && str_contains($plain, 'ダメージ')) {
                if (! $normalActionAdded && str_contains($plain, $playerName.' の攻撃！')) {
                    $critical = str_contains($plain, '痛恨の一撃');
                    $visible[] = e($playerName).' の攻撃！'.($critical
                        ? ' <span class="text-orange-500 font-bold">【痛恨の一撃！】</span>'
                        : '');
                    $normalActionAdded = true;
                } elseif (! $normalActionAdded && str_contains($plain, $playerName.' の魔法攻撃！')) {
                    $visible[] = e($playerName).' の魔法攻撃！';
                    $normalActionAdded = true;
                }

                // player engineの仮damageではなく、Phase 1で確定した有効damageを別途表示する。
                continue;
            }

            $visible[] = $log;
        }

        return $visible;
    }

    private function isRaidIncomingDefenseLog(string $plain, string $playerName, string $bossName): bool
    {
        return str_contains($plain, $playerName.' は剣冠の構えで攻撃を受け流した！')
            || (
                str_contains($plain, $playerName.' の王冠剣陣が反撃し、'.$bossName.' に')
                && str_contains($plain, 'ダメージ')
            );
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array{action:int,counter:int,eclipse_backlash:int}
     */
    private function playerDamageBreakdown(array $sources): array
    {
        $result = ['action' => 0, 'counter' => 0, 'eclipse_backlash' => 0];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $damage = max(0, (int) ($source['applied_damage'] ?? 0));
            $kind = (string) ($source['kind'] ?? '');
            if ($kind === NationRaidRules::DAMAGE_COUNTER) {
                $result['counter'] += $damage;
            } elseif ($kind === NationRaidRules::DAMAGE_ECLIPSE_BACKLASH) {
                $result['eclipse_backlash'] += $damage;
            } else {
                $result['action'] += $damage;
            }
        }

        return $result;
    }

    private function counterplayName(mixed $identity): ?string
    {
        if (! is_string($identity)) {
            return null;
        }

        return $this->rules->counterplayArt($identity)['name'] ?? null;
    }

    /** @param array<string, mixed>|null $counterplay */
    private function counterplayMessage(?array $counterplay): ?string
    {
        if ($counterplay === null) {
            return null;
        }
        $name = $this->counterplayName($counterplay['identity'] ?? null) ?? '対抗戦技';
        if (($counterplay['applied'] ?? false) !== true) {
            return match ($counterplay['notAppliedReason'] ?? null) {
                'miss_or_evade' => "《{$name}》は届かず、対抗効果は発生しなかった。",
                'no_destroyable_raid_preparation' => "《{$name}》で破壊できる予告準備がなかった。",
                default => "《{$name}》の対抗条件を満たせなかった。",
            };
        }

        return match ($counterplay['effect'] ?? null) {
            'counter_intercept' => "《{$name}》が予告攻撃をさらに20%軽減する！",
            'eclipse_backlash' => "《{$name}》が黒天竜へ反撃の刻印を残した！",
            'pierce_opening' => "《{$name}》が黒天竜の守りを半減し、与ダメージを15%高めた！",
            'field_suppression' => "《{$name}》が予告技の固有効果を封じた！",
            'hunt_cancel' => "《{$name}》が予告行動を1ターン遅らせた！",
            'aim_sp_pressure' => "《{$name}》が黒天竜のSPを".(int) ($counterplay['bossSpLoss'] ?? 0).'削った！',
            'ultimate_guard' => "《{$name}》が予告攻撃を35%軽減する！",
            'fortress_guard' => "《{$name}》が予告攻撃を50%軽減し、付随する妨害を防いだ！",
            'transmute_resource_slow' => "《{$name}》が黒天竜のSP回復を2回鈍らせた！",
            'break_preparation' => "《{$name}》が黒天竜の予告準備を破壊した！",
            'readiness_delay' => "《{$name}》が予告行動を1ターン遅らせた！",
            default => "《{$name}》が予告へ対抗した！",
        };
    }

    /** @param array<string, mixed> $turn */
    private function enemyActionKind(array $turn, ?string $enemyActionId): string
    {
        if ($enemyActionId === null) {
            return 'delayed';
        }
        if (($turn['pending_kind'] ?? null) === 'observation') {
            return 'observation';
        }
        if ($enemyActionId === 'ten_lineage_end') {
            return 'ultimate';
        }

        return 'attack';
    }

    /** @return list<string> */
    private function defenseMessages(mixed $defense): array
    {
        if (! is_array($defense)) {
            return [];
        }

        $messages = [];
        if (($defense['parry_succeeded'] ?? false) === true) {
            $messages[] = '剣冠の構えが攻撃を受け流した！';
        }
        if (($defense['guard_consumed'] ?? false) === true) {
            $rate = (int) round(max(0.0, (float) ($defense['guard_rate'] ?? 0.0)) * 100);
            $messages[] = "ガードが発動し、被害を{$rate}%軽減した！";
        }
        if (($defense['guts_triggered'] ?? false) === true) {
            $messages[] = '不屈の精神で致死ダメージを耐えた！（HP1）';
        }

        return $messages;
    }

    /** @return list<string> */
    private function effectMessages(mixed $effects): array
    {
        if (! is_array($effects)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $effect): string => self::ENEMY_EFFECT_MESSAGES[$effect] ?? '未知の妨害効果を受けた。',
            array_values(array_filter($effects, 'is_string')),
        ));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $next
     * @param  array<string, string>  $actionNames
     * @return array{kind:string,message:string}|null
     */
    private function telegraphForNextTurn(array $current, ?array $next, array $actionNames): ?array
    {
        if ($next === null || ! is_string($next['pending_enemy_action_id'] ?? null)) {
            return null;
        }
        if (($current['pending_enemy_action_id'] ?? null) === $next['pending_enemy_action_id']) {
            return null;
        }

        $kind = (string) ($next['pending_kind'] ?? 'counter');
        $actionId = is_string($next['enemy_action_id'] ?? null) ? $next['enemy_action_id'] : null;
        $actionName = $actionId !== null ? ($actionNames[$actionId] ?? $actionId) : '未知の行動';

        return match ($kind) {
            'observation' => [
                'kind' => 'observation',
                'message' => 'ヴァルグレイドは攻撃を止め、こちらの系譜を見定めようとしている……。',
            ],
            'ultimate' => [
                'kind' => 'ultimate',
                'message' => '⚠ 黒天竜が大技《十系終焉・ヴァルグレイド》の構えに入った！',
            ],
            default => [
                'kind' => 'counter',
                'message' => "⚠ ヴァルグレイドは《{$actionName}》の気配を見せた！",
            ],
        };
    }

    private function plainText(string $html): string
    {
        $text = str_ireplace(['<br>', '<br/>', '<br />'], ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function isGenericBattleEnding(string $plain): bool
    {
        return str_contains($plain, '倒れてしまった……。')
            || str_contains($plain, '双方が疲弊し、戦闘は終了した。')
            || str_contains($plain, 'を倒した！')
            || str_contains($plain, '【Gold獲得】');
    }
}
