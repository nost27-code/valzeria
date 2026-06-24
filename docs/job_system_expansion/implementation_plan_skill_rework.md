# 必殺技のデータ駆動型システムへの改修計画

## 1. 概要
ご提示いただいた「全43職の専用必殺技データ」および「データ駆動型のテーブル設計」に基づき、これまでのクラスベースの必殺技（`ActionClass`）を廃止し、パラメータベースの汎用処理へシステムを大改修します。

## 2. データベースの改修 (`skills` テーブル)
ご提案のカラム構成に沿ってマイグレーションを作成します。既存の `action_class` などの不要なカラムは削除します。
* `job_id` : 紐づく職業のID（今後は職業1つにつき1つのスキルを持つ1対1の関係に変更します）
* `name` : 必殺技名
* `trigger_rate` : 発動率 (2% ~ 5%)
* `damage_type` : `physical` / `magical` / `hybrid` / `heal` / `support` / `gold`
* `power_multiplier` : ダメージ倍率（decimal型）
* `hit_count` : 攻撃回数
* 各種効果（`heal_percent`, `self_damage_percent`, `gold_bonus_percent`, `drop_bonus_percent`, `def_ignore_percent`, `damage_reduction_percent`, `enemy_def_down_percent`, `enemy_spr_down_percent`, `enemy_spd_down_percent`, `mp_recover_percent`）

## 3. シードデータの作成
`SkillSeeder.php` を完全に書き換え、提示いただいた43種類の必殺技の名称と各種パラメータを正確に投入します。（基本職、中級職、上級職、伝説職）

## 4. バトルシステム (`BattleService.php`) の改修
以下のルールに則って、スキルの判定と実行のロジックを改修します。

1. **発動判定**: ターン開始時または通常攻撃前に `trigger_rate` に基づいて判定。
2. **クリティカル無効**: 必殺技発動時は、通常のクリティカル判定を行わない。
3. **タイプ別の基本処理**:
   * `physical` : ATK依存
   * `magical` : MAG依存
   * `hybrid` : (ATK + MAG) / 2 依存
   * `heal` / `support` : ダメージなし（または低ダメージ）＋回復・バフ
   * `gold` : ATK依存＋ゴールド補正
4. **副効果の適用**:
   * 回復系（HP/MP）、反動ダメージ
   * 防御無視 (`def_ignore_percent`) を加味したダメージ計算
   * **デバフ処理**: ボス敵（`is_boss` フラグ等で判定）に対しては、デバフ効果値（%）を半減させる。また、同じデバフは重複させず上書きのみとする。
   * **戦闘後ボーナス**: ゴールド・ドロップ補正をステートに記録し、リザルト画面（戦闘報酬計算時）に適用。

> [!IMPORTANT]
> **ユーザー確認**
> 1. 上記の実装方針で進めて問題ないでしょうか？
> 2. `skills` テーブルに `job_id` を持たせる構造に変更するため、既存の `job_classes` テーブルにあった `skill_id` カラムは不要となります（マイグレーションで削除します）。これでよろしいでしょうか？

承認いただき次第、開発（テーブル再構築、Seeder作成、BattleService改修）に進みます！
