# 確定成長方式（小数点累積）の実装計画

レベルアップ時のステータス上昇をランダム（乱数）から、「ベース期待値×倍率」による完全な確定成長方式へと変更します。
これにより、計算（努力）した分だけ裏切られることなく確実に成長する実力・計算ゲーとしての面白さを提供します。端数（小数点以下の成長量）をストックし、1.0を超えたタイミングで+1のボーナスとして基礎値に加算します。

## Proposed Changes

### データベース変更 (Migration)
#### [NEW] `database/migrations/xxxx_xx_xx_xxxxxx_add_fraction_columns_to_characters_table.php`
`characters` テーブルに、ステータスごとの端数（小数点以下）をストックするためのカラムを追加します。
- `hp_fraction` (float, default: 0.0)
- `mp_fraction` (float, default: 0.0)
- `attack_fraction` (float, default: 0.0)
- `defense_fraction` (float, default: 0.0)
- `magic_fraction` (float, default: 0.0)
- `speed_fraction` (float, default: 0.0)
- `luck_fraction` (float, default: 0.0)

### モデル
#### [MODIFY] `app/Models/Character.php`
- 追加した端数カラムを `$fillable` に追加します。
- `casts` にて `float` にキャストするように設定します。

### サービス層
#### [MODIFY] `app/Services/LevelService.php`
- レベルアップ時の成長ロジックを `rand()` から固定期待値へと変更します。
  - HPベース期待値: `7.5`
  - MPベース期待値: `4.5`
  - 他ステータスベース期待値: `3.5`
- 成長量を以下のように計算し、端数ストックと合算した上で整数部分を基礎値に加算し、残りをストックに戻します。
  ```php
  $hpUpRaw = 7.5 * $hpRate * $reincarnationBonus;
  $totalHpFraction = $character->hp_fraction + $hpUpRaw;
  $hpUp = (int)floor($totalHpFraction);
  $character->hp_fraction = $totalHpFraction - $hpUp;
  $character->hp_base += $hpUp;
  // 他のステータスも同様
  ```

#### [MODIFY] `app/Services/CharacterJobChangeService.php`
- `changeJob()` メソッド内で転職（ステータス50%引き継ぎ）を実行する際、蓄積されていた端数ストック（`hp_fraction` 等）を全て `0.0` にリセットする処理を追加します。
- （転職して新しい体になるため、前職で蓄積した端数はリセットされるのが自然であり、仕様上もスッキリします）

## Verification Plan

### Manual Verification
1. 開発環境のデータベースにマイグレーションを適用。
2. キャラクターをレベルアップさせ、乱数によるブレがなく、常に期待値通りのステータスが加算される（端数がストックされていく）ことを確認。
3. 何度かレベルアップを繰り返し、端数が1.0を超えたタイミングで基礎値が+1多く上昇することを確認。
4. 転職を実行し、端数カラムが正常に `0.0` にリセットされ、かつ基礎ステータスが50%引き継がれることを確認。
