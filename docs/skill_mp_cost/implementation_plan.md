# 必殺技のMP消費仕様追加と敗北時ペナルティの変更

## Goal Description
必殺技（スキル）を発動するための共通リソースとしてMPを導入し、魔法や物理系の技すべてでMPを消費するようにします。また、敗北時のペナルティとして街に戻る際のHP/MP回復量を調整し、MP枯渇時には通常攻撃へフォールバックするロジックを実装します。

## User Review Required
> [!IMPORTANT]
> - 敗北時の回復量がHP30%、MP10%となります。これにより、連続で敗北しやすくなる（ダンジョンから戻った後すぐに再挑戦しづらくなる）可能性があります。よろしいでしょうか？
> - 既存スキルの `mp_cost` は、紐づく職業のランク（Normal, Middle, Advanced, Legend）に応じて、自動的にマイグレーションで一括設定します。

## Proposed Changes

### Database Migration
#### [NEW] database/migrations/xxxx_xx_xx_add_mp_cost_to_skills_table.php
- `skills` テーブルに `mp_cost` (整数型、デフォルト0) カラムを追加します。
- マイグレーションの実行時に、既存データの `mp_cost` を更新します。
  - 職業ランク Normal: 3
  - 職業ランク Middle: 6
  - 職業ランク Advanced: 10
  - 職業ランク Legend: 15

### Models
#### [MODIFY] app/Models/Skill.php
- `$fillable` プロパティに `mp_cost` を追加し、保存・更新できるようにします。

### Services
#### [MODIFY] app/Services/BattleService.php
- **スキル発動・MP消費ロジック (`executeAction` 等)**
  - ターン開始時または攻撃処理前に必殺技発動率（`trigger_rate`）を判定。
  - 成功した場合、アクターの現在MPが `$skill->mp_cost` 以上であるか確認。
  - 足りていればMPを消費し、戦闘ログに「〇〇の必殺技、△△が発動！」と表示してスキルを実行。
  - 発動しなかった、またはMPが足りなかった場合は通常攻撃を実行（フォールバック）。
- **スキルによるMP回復 (`executeSkillAction`)**
  - `$skill->mp_recover_percent` が設定されている場合、スキル発動後にアクターの最大MPに対する割合分、MPを回復する処理を追加。
  - 回復後のMPが最大MPを超えないように制御。
- **戦闘終了後の処理 (`executeBattle`)**
  - 勝敗にかかわらず、戦闘終了後の `playerActor->mp` を `Character` の `current_mp` に保存する処理を有効化。
  - **敗北時のペナルティ**: 戦闘敗北時、キャラクターのHPを最大HPの30%、MPを最大MPの10%で保存するように修正（現在はHPがそのまま、または1で保存される状態）。

#### [MODIFY] app/Services/InnService.php
- 宿屋でのHP/MP全回復処理はすでに実装済みですが、処理内容を再確認し、必要があれば微調整します（現在すでに `current_mp = $maxMp` となっています）。

## Verification Plan

### Manual Verification
1. ローカルサーバーを起動し、ブラウザでアクセス。
2. 宿屋でHP・MPを全回復。
3. 迷宮で戦闘を開始し、MPが消費されて必殺技が発動することを確認。
4. MPが不足している状態で発動判定に成功した場合、必殺技が不発となり通常攻撃に切り替わることを戦闘ログで確認。
5. 戦闘で敗北した場合、HPが最大値の約30%、MPが約10%になった状態で街に戻ることを確認。
