<?php

namespace App\Support;

class JobSpecialSkillValidator
{
    public static function validateRows(array $rows): array
    {
        $problems = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $problems[] = 'job_special_skills.php: 配列ではない行があります。';
                continue;
            }

            $jobKey = (string) ($row['job_key'] ?? '');
            $name = (string) ($row['special_name'] ?? '');
            $description = (string) ($row['description'] ?? '');

            if (str_contains($description, 'LUKに応じて') && (float) ($row['luk_power_rate'] ?? 0) <= 0) {
                $problems[] = self::message($jobKey, $name, 'LUK依存の説明がありますが luk_power_rate が設定されていません');
            }
            if (str_contains($description, '確率で追加') && (int) ($row['extra_hit_chance_percent'] ?? 0) <= 0) {
                $problems[] = self::message($jobKey, $name, '追加攻撃の説明がありますが extra_hit_chance_percent が設定されていません');
            }
            if ((str_contains($description, '最高火力') || str_contains($description, '高い方'))
                && (string) ($row['hybrid_scaling'] ?? 'average') !== 'max'
            ) {
                $problems[] = self::message($jobKey, $name, '最高火力依存の説明がありますが hybrid_scaling=max ではありません');
            }
            if (str_contains($description, 'レア判定UP') && (int) ($row['rare_bonus_percent'] ?? 0) <= 0) {
                $problems[] = self::message($jobKey, $name, 'レア判定UPの説明がありますが rare_bonus_percent が設定されていません');
            }

            if (preg_match('/([2-9])回/u', $description, $matches)
                && (int) ($row['hit_count'] ?? 0) !== (int) $matches[1]
            ) {
                $problems[] = self::message($jobKey, $name, sprintf('説明は%d回攻撃ですが hit_count=%d です', (int) $matches[1], (int) ($row['hit_count'] ?? 0)));
            }

            if (preg_match('/最大HPの?(\d+)%回復|HP(\d+)%回復/u', $description, $matches)) {
                $expected = (int) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? 0));
                if ((int) ($row['heal_percent'] ?? 0) !== $expected) {
                    $problems[] = self::message($jobKey, $name, sprintf('HP回復説明は%d%%ですが heal_percent が一致しません', $expected));
                }
            }
            if (preg_match('/最大SPの?(\d+)%回復|SP(\d+)%回復/u', $description, $matches)) {
                $expected = (int) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? 0));
                if ((int) ($row['mp_recover_percent'] ?? 0) !== $expected) {
                    $problems[] = self::message($jobKey, $name, sprintf('SP回復説明は%d%%ですが mp_recover_percent が一致しません', $expected));
                }
            }

            foreach (['ATK' => 'enemy_atk_down_percent', 'MAG' => 'enemy_mag_down_percent', 'DEF' => 'enemy_def_down_percent', 'SPR' => 'enemy_spr_down_percent', 'SPD' => 'enemy_spd_down_percent'] as $label => $field) {
                if (preg_match('/' . $label . 'を(\d+)%低下/u', $description, $matches)
                    && (int) ($row[$field] ?? 0) !== (int) $matches[1]
                ) {
                    $problems[] = self::message($jobKey, $name, sprintf('%s低下説明は%d%%ですが %s が一致しません', $label, (int) $matches[1], $field));
                }
            }

            if (preg_match('/(\d+)%無視/u', $description, $matches)
                && (int) ($row['def_ignore_percent'] ?? 0) !== (int) $matches[1]
            ) {
                $problems[] = self::message($jobKey, $name, sprintf('防御無視説明は%d%%ですが def_ignore_percent が一致しません', (int) $matches[1]));
            }

            if (preg_match('/(\d+(?:\.\d+)?)倍/u', $description, $matches)) {
                $expected = (float) $matches[1];
                if (abs((float) ($row['power_multiplier'] ?? 0) - $expected) > 0.01) {
                    $problems[] = self::message($jobKey, $name, sprintf('倍率説明は%.2f倍ですが power_multiplier が一致しません', $expected));
                }
            }
        }

        return $problems;
    }

    private static function message(string $jobKey, string $name, string $message): string
    {
        return sprintf('%s %s: %s', $jobKey ?: '(job_keyなし)', $name ?: '(名称なし)', $message);
    }
}
