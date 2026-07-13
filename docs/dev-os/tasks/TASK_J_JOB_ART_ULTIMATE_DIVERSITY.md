# 実装指示書: 上位職ランク9必殺技の個性化（J44〜J99）

## 目的

後発追加職（J44〜J99）のランク9必殺技が「名前だけ違う同倍率の単発ダメージ」6パターンのコピペになっているのを、職業の系譜・イメージに沿った固有効果に分化させる。継承奥義として「どの職の奥義を継ぐか」がビルド選択になる状態を作る。

## 背景

- 技マスターは `database/data/job_arts.json`（1技1レコード、`JobArtSeeder` が `updateOrCreate` で投入）
- J1〜J38（初期実装組）は既に個性化済み。J44〜J99の56職は追加時にティア雛形をコピーしたため、同ティア内で完全同一の効果になっている
- 効果パラメータは行ごとの追加フィールド（`hit_count` / `def_ignore_percent` / `drain_hp_rate` / `heal_percent` / `damage_reduction_percent` / `enemy_*_down_percent` / `gold_bonus_percent` / `drop_bonus_percent` / `luk_power_rate` / `mp_recover_percent` / `self_damage_percent`）で表現する。**いずれも既存データで使用実績あり**（例: J98ランク5=hit_count+def_ignore、J96ランク5=drain、J99=enemy_spd_down、J36=heal+damage_reduction、J97ランク5=mp_recover、J31ランク5=luk_power_rate）
- 参照: `app/Support/JobArtEffectCatalog.php`（テンプレート定義）、`app/Support/JobArtMasterValidator.php`（memo⇔フィールド整合検査）

## 現状の問題

同ティアの物理職17職・魔法職13職・聖剣型12職・報酬型8職が、それぞれ倍率まで完全一致の同一技を持つ。継承奥義の選択肢として意味がなく、転職の楽しみも削がれている。

## 実装対象

- `database/data/job_arts.json` の **learn_rank=9 の技のみ**、下記「変更一覧」の通りに変更する
  - `effect_template` / `power_hint` / 追加効果フィールドを表の値に更新
  - `memo` を新しい効果と数値が一致するよう書き換える（Validatorがmemo内の「N回攻撃」「N%軽減」「N%回復」「反動N%」等とフィールド値の一致を検査する）
  - 効果が変わった技は `activation_description` を新効果と矛盾しないよう最小限修正する（口調・世界観は現行を踏襲。`activation_phrase` と技名は**変更しない**）
- J61（黒冠魔騎士）の既存バグ修正: template が `PHYSICAL_DAMAGE` なのに memo が「大魔法ダメージ。MAG参照」。ランク9・ランク5とも `MAGICAL_DAMAGE` に修正する（ランク5はこのバグ修正のみ許可、効果変更はしない）
- `php artisan db:seed --class=JobArtSeeder` で反映

### 着手前の必須確認（仮置き禁止）

変更を書き始める前に、戦闘エンジン側（`app/Services/BattleService.php` および `app/Services/Admin/SkillEffectPreviewService.php` のスキル効果解決部）を読み、**表で使う各フィールドが対象テンプレートで実際に消費されることを確認する**。消費されない組み合わせがあれば、その行は変更せず報告して指示を仰ぐこと。推測で「効いているはず」として進めない。

## 実装対象外（重要・必ず書く）

- J1〜J38 の全技、J44〜J99 の learn_rank 1・5 の技（ランク5の個性化は別タスク。例外は上記J61のtemplateバグ修正のみ）
- 変更なし指定の4職: J50 聖剣将 / J70 暁の勇者 / J79 白銀の守護王 / J95 ヴァルゼリアの救世主
- 技名・`activation_phrase`・`element`・`activation_rate`・`sp_cost_fixed`・`sp_cost_rate`・`cooldown_turns`・`max_uses_per_battle`・`inherit_*` 各値の変更
- `JobArtEffectCatalog` への新テンプレート追加、`BattleService` 等エンジン側のロジック変更
- 依頼範囲外のリファクタリング・文言変更

## 変更範囲

- 想定ファイル: `database/data/job_arts.json` のみ（＋Seeder再実行）
- 想定しない範囲: `app/Services/**`、`app/Support/**`、Blade、routes

## バランス設計ルール（威力予算制）

ティア基準倍率: Advanced 275 / Super 320 / Crown 355 / Hero 395 / Legend 455 / Myth 510

| 型 | power_hint |
|---|---|
| 付帯効果なし（純火力） | 基準 +5〜6%（「最高倍率」枠） |
| 付帯効果1つ（多段含む。多段は合計威力表記） | 基準 −10% |
| 付帯効果2つ | 基準 −15% |
| 報酬型（Gold/Drop配分変更のみ） | 基準維持 |
| 自傷リスク型（self_damage 5%） | 基準 +5%（リスクの対価） |

- 多段技の会心は各Hit判定（連斬J1のmemo準拠）のため、多段化は−10%側に含める
- Gold/Drop補正の**合計は1職6%まで**（継承で全職に行き渡るため、総量は増やさない。例外: J67金冠錬師のみ合計7でCrown昇格分+1を許容）

## 変更一覧（learn_rank=9）

フィールド名は正式名で書くこと: `enemy_atk_down_percent` / `enemy_mag_down_percent` / `enemy_def_down_percent` / `enemy_spr_down_percent` / `enemy_spd_down_percent` / `def_ignore_percent` / `damage_reduction_percent` / `drain_hp_rate` / `heal_percent` / `mp_recover_percent` / `self_damage_percent` / `gold_bonus_percent` / `drop_bonus_percent` / `luk_power_rate` / `hit_count`

### Advanced（基準275）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 44 | 盾聖 | 天壁イージス | DAMAGE_GUARD_BARRIER | damage_reduction_percent: 20 | 250 | 聖属性ダメージ＋次の被ダメージを20%軽減。1戦1回 |
| 45 | 魔弓将 | 天弓メテオレイン | MAGICAL_DAMAGE | hit_count: 3 | 250 | 魔法3回攻撃（合計威力250）。会心判定は各Hit |
| 46 | 詩聖 | 聖譚フィナーレ | MAGICAL_DAMAGE | enemy_atk_down_percent: 10, enemy_mag_down_percent: 10 | 235 | 聖属性ダメージ＋敵ATK/MAGを10%低下（戦闘中） |
| 47 | 薬聖 | 神薬アムリタ | REWARD_MIXED（維持） | heal_percent: 12 | 275（維持） | 勝利時Gold/Drop判定に小効果＋最大HP12%回復。1戦1回 |
| 48 | 戦略王 | 覇王大戦略 | DAMAGE_DEBUFF | enemy_def_down_percent: 15 | 250 | 物理ダメージ＋敵DEFを15%低下（戦闘中）。1戦1回 |
| 49 | 大錬金術師 | 錬金大崩壊 | 維持 | gold_bonus_percent: 0, drop_bonus_percent: 6 | 275（維持） | ドロップ特化（素材錬成） |

### Super（基準320）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 50 | 聖剣将 | 光翼クロスブレイク | — | **変更なし**（攻め聖アーキタイプの正当な持ち主として残す） | — | — |
| 51 | 黒炎騎士 | 獄炎ナイトメア | PHYSICAL_DAMAGE | drain_hp_rate: 0.3 | 290 | 物理ダメージ＋与ダメの一部を吸収（暗黒騎士の系譜） |
| 52 | 蒼天竜騎士 | 蒼穹ドラグーンダイブ | PHYSICAL_DAMAGE | hit_count: 2, def_ignore_percent: 15 | 290 | 2回攻撃（合計290）＋敵DEF15%無視（竜騎士の系譜） |
| 53 | 星詠み賢者 | 星天グランドスペル | MAGICAL_DAMAGE | enemy_spr_down_percent: 12 | 290 | 魔法ダメージ＋敵SPRを12%低下（戦闘中） |
| 54 | 影縫い | 影牢・無明縛 | PHYSICAL_DAMAGE | enemy_spd_down_percent: 20 | 290 | 技巧ダメージ（SPD/LUKで会心寄り維持）＋敵SPDを20%低下（戦闘中） |
| 55 | 鋼機導師 | 機神オーバードライブ | MAGICAL_DAMAGE | def_ignore_percent: 20 | 290 | 魔法ダメージ。敵防御を20%無視（機工王の系譜） |
| 56 | 聖域守護者 | 聖壁アルカディア | MAGICAL_DAMAGE | damage_reduction_percent: 25 | 290 | 聖属性ダメージ＋次の被ダメージを25%軽減。1戦1回 |
| 57 | 黄金錬師 | 黄金創世陣 | 維持 | gold_bonus_percent: 6, drop_bonus_percent: 0 | 320（維持） | Gold特化 |
| 58 | 雷拳覇 | 雷霆覇王拳 | PHYSICAL_DAMAGE | hit_count: 4 | 290 | 4回攻撃（合計290）。会心判定は各Hit |
| 59 | 戦陣軍師 | 八陣無双策 | DAMAGE_DEBUFF | enemy_atk_down_percent: 10, enemy_def_down_percent: 10 | 270 | 物理ダメージ＋敵ATK/DEFを10%低下（戦闘中） |

### Crown（基準355）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 60 | 剣冠騎士 | 王冠聖剣陣 | 維持 | なし | **375** | 付帯なし純火力（同ティア最高倍率） |
| 61 | 黒冠魔騎士 | 黒冠アビスブレイク | **MAGICAL_DAMAGE（バグ修正）** | drain_hp_rate: 0.3 | 320 | 魔法ダメージ＋与ダメの一部を吸収 |
| 62 | 竜冠槍将 | 竜冠天穿槍 | PHYSICAL_DAMAGE | hit_count: 2, def_ignore_percent: 20 | 320 | 2回攻撃（合計320）＋敵DEF20%無視 |
| 63 | 星冠導師 | 星冠アストラルレイ | MAGICAL_DAMAGE | enemy_spr_down_percent: 15 | 320 | 魔法ダメージ＋敵SPRを15%低下（戦闘中） |
| 64 | 影冠狩人 | 影冠終葬射 | PHYSICAL_DAMAGE | hit_count: 2 | 320 | 技巧2連射（合計320）。SPD/LUKで会心寄り維持、会心判定は各Hit |
| 65 | 鋼冠機導師 | 鋼冠グラビトンコア | MAGICAL_DAMAGE | def_ignore_percent: 25, enemy_spd_down_percent: 10 | 300 | 魔法ダメージ。敵防御25%無視＋敵SPDを10%低下（戦闘中） |
| 66 | 聖冠守護者 | 聖冠アイギスロード | MAGICAL_DAMAGE | damage_reduction_percent: 25 | 320 | 聖属性ダメージ＋次の被ダメージを25%軽減。1戦1回 |
| 67 | 金冠錬師 | 金冠ミダスフィールド | 維持 | gold_bonus_percent: 5, drop_bonus_percent: 2 | 355（維持） | Gold寄り配分 |
| 68 | 雷冠拳聖 | 雷冠天鳴掌 | DAMAGE_DEBUFF | hit_count: 3, enemy_spd_down_percent: 10 | 300 | 3回攻撃（合計300）＋雷の痺れで敵SPDを10%低下（戦闘中） |
| 69 | 戦冠司令 | 王戦アークフォーメーション | DAMAGE_DEBUFF | enemy_atk_down_percent: 8, enemy_mag_down_percent: 8, enemy_spd_down_percent: 8 | 300 | 物理ダメージ＋敵ATK/MAG/SPDを8%低下（十面埋伏の上位系譜） |

### Hero（基準395）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 70 | 暁の勇者 | 夜明けのギガブレイブ | — | **変更なし**（HYBRID既に固有） | — | — |
| 71 | 黒月の執行者 | 月蝕エクスキューション | PHYSICAL_DAMAGE | def_ignore_percent: 25 | 355 | 物理ダメージ。敵防御を25%無視する処刑の一撃 |
| 72 | 星天導師 | 星天裁きの大光輪 | MAGICAL_DAMAGE | enemy_spr_down_percent: 18 | 355 | 魔法ダメージ＋敵SPRを18%低下（戦闘中） |
| 73 | 蒼竜武王 | 蒼竜王牙・天 | PHYSICAL_DAMAGE | hit_count: 3 | 355 | 3回攻撃（合計355）。会心判定は各Hit |
| 74 | 天機宰相 | 天機万策 | 維持 | gold/drop 各3維持, mp_recover_percent: 10 | 375 | 報酬小効果＋SP10%回復（万策＝SP管理） |
| 75 | 聖域の審判者 | 聖裁サンクチュアリ | MAGICAL_DAMAGE | enemy_spr_down_percent: 15 | 355 | 聖属性ダメージ＋敵SPRを15%低下（審判は心の守りを砕く） |
| 76 | 幻葬の魔王 | 魔王葬送曲 | MAGICAL_DAMAGE | drain_hp_rate: 0.3 | 355 | 魔法ダメージ＋与ダメの一部を吸収 |
| 77 | 時詠みの旅人 | 時渡りの終鐘 | MAGICAL_DAMAGE | enemy_spd_down_percent: 20 | 355 | 魔法ダメージ＋敵SPDを20%低下（時間操作の系譜） |
| 78 | 荒天の覇者 | 天災ブレイカー | PHYSICAL_DAMAGE | self_damage_percent: 5 | **415** | 反動で最大HP5%ダメージと引き換えの高倍率物理 |
| 79 | 白銀の守護王 | 白銀王城陣 | — | **変更なし**（攻め聖アーキタイプの上位枠） | — | — |

### Legend（基準455）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 80 | 天翔剣皇 | 天翔皇刃・蒼空断 | 維持 | なし | **480** | 付帯なし純火力（同ティア最高倍率） |
| 81 | 黒焔魔皇 | 黒焔終獄陣 | MAGICAL_DAMAGE | self_damage_percent: 5 | **480** | 反動で最大HP5%ダメージと引き換えの高倍率魔法（禁術） |
| 82 | 世界樹の賢者 | 世界樹ユグドラシル | MAGICAL_DAMAGE | heal_percent: 12 | 410 | 聖属性ダメージ＋最大HP12%回復（世界樹＝生命） |
| 83 | 影葬の王 | 影葬千夜王 | PHYSICAL_DAMAGE | drain_hp_rate: 0.25 | 410 | 技巧ダメージ（SPD/LUKで会心寄り維持）＋与ダメの一部を吸収 |
| 84 | 星海航者 | 星海グランドヴォヤージュ | 維持 | gold_bonus_percent: 2, drop_bonus_percent: 5 | 455（維持） | ドロップ寄り配分（探索者） |
| 95 | ヴァルゼリアの救世主 | 救世覇道斬 | — | **変更なし**（HYBRID max既に固有） | — | — |
| 96 | 深淵歩き | 深淵崩壊 | PHYSICAL_DAMAGE | drain_hp_rate: 0.3 | 410 | 物理ダメージ＋与ダメの一部を吸収（ランク5の吸収系譜を奥義に通す） |
| 97 | 古代錬成王 | 神代錬成 | 維持 | gold/drop 各3維持, heal_percent: 10 | 410 | 報酬小効果＋最大HP10%回復（錬成による再構成） |
| 98 | 蒼竜王 | 蒼天竜王撃 | PHYSICAL_DAMAGE | hit_count: 2, def_ignore_percent: 20 | 410 | 2回攻撃（合計410）＋敵DEF20%無視（ランク5と一貫） |
| 99 | 時空王 | クロノブレイク | 維持 | enemy_spd_down_percent: 14 → **20** | 455 → **410** | 魔法ダメージ＋敵SPDを20%低下（威力予算ルールに合わせ倍率調整） |

### Myth（基準510）

| job | 職 | 技名 | template（変更後） | 追加フィールド | power_hint | memo骨子 |
|---|---|---|---|---|---|---|
| 85 | 星律神官 | 星律大聖堂 | MAGICAL_DAMAGE | heal_percent: 10, damage_reduction_percent: 15 | 435 | 聖属性ダメージ＋最大HP10%回復＋次の被ダメ15%軽減（神官戦士の系譜） |
| 86 | 深淵覇王 | 深淵覇滅陣 | PHYSICAL_DAMAGE | drain_hp_rate: 0.35 | 460 | 物理ダメージ＋与ダメの一部を吸収 |
| 87 | 時環の支配者 | 時環グランドリセット | MAGICAL_DAMAGE | enemy_spd_down_percent: 25 | 460 | 魔法ダメージ＋敵SPDを25%低下（時間停止級） |
| 88 | 天竜神 | 天竜神滅牙 | PHYSICAL_DAMAGE | hit_count: 2, def_ignore_percent: 25 | 435 | 2回攻撃（合計435）＋敵DEF25%無視 |
| 89 | 魔王神 | 魔界創世・終焉 | 維持 | なし | **535** | 付帯なし純火力（魔法側の最高倍率枠） |
| 90 | 雷霆武神 | 雷霆武神降臨 | PHYSICAL_DAMAGE | hit_count: 5 | 460 | 5回攻撃（合計460）。武神J33の多段系譜の最上位。会心判定は各Hit |
| 91 | 虚空導神 | 虚空神域 | MAGICAL_DAMAGE | def_ignore_percent: 30 | 460 | 魔法ダメージ。敵防御を30%無視（虚空はあらゆる守りを透過） |
| 92 | 世界樹の神子 | 神樹万象祝福 | MAGICAL_DAMAGE | heal_percent: 15, mp_recover_percent: 10 | 435 | 聖属性ダメージ＋最大HP15%回復＋SP10%回復 |
| 93 | 終焉の聖者 | ラストサンクチュアリ | MAGICAL_DAMAGE | enemy_spr_down_percent: 20 | 460 | 聖属性ダメージ＋敵SPRを20%低下（戦闘中） |
| 94 | 天命の観測者 | 天命星図・終局観測 | 維持 | gold/drop 各3維持, luk_power_rate: 0.5 | 510（維持） | LUK依存ダメージの唯一の運型奥義（黄金商人J31ランク5に前例） |

## 既存仕様への影響

- 継承奥義: `inherit_*` 値は不変。効果内容だけ変わるため、既にこれらを継承しているキャラは次回戦闘から新効果になる（データ駆動なので自動反映）
- 塔・アリーナ・チャンプ戦: `pve_enabled` / `boss_enabled` / `champ_enabled` は不変
- Gold経済: Gold/Drop補正の総量は現状維持（配分変更のみ）。J67のみ+1（6→7）
- ネガティブ影響: J99時空王は威力455→410の実質弱体（ルール統一のため）。他の−10%組も同様に素の倍率は下がるが付帯効果で期待値補償

## DB変更

- [x] なし（マスターデータのみ。`skills` テーブルへは既存Seederの `updateOrCreate` で反映され、スキーマ変更は不要）

## 画面/UI変更

- なし（技詳細表示は既存のmemo/カタログlabel経由で自動反映）

## バックエンド変更

- なし（エンジン改修禁止。データ変更のみ）

## 管理者機能への影響

- [x] なし（SkillEffectLab は既存機能のまま新データを表示する。確認には使う）

## ログ・更新履歴への影響

- 公開ログ: 流さない
- `config/admin_update_summaries.php`: 追記する（下記サマリ案）
- docs同期: `docs/UPDATE_LOG.md` に追記。`docs/DOMAIN_RULES.md` に「必殺技の威力予算ルール」（バランス設計ルールの表）を追記

## エラーハンドリング

- 該当なし（データ変更のみ）。JSON構文エラーに注意し、変更後に `php -l` 相当として `json_decode` が通ることをSeeder実行で確認

## セキュリティ注意点

- なし

## パフォーマンス注意点

- なし（レコード数不変）

## テスト観点

1. `php artisan valzeria:validate-job-arts` がエラー0で通る（memo⇔フィールド整合）
2. `php artisan test --filter=JobArt` が通る
3. `php artisan db:seed --class=JobArtSeeder` 後、`skills` テーブルで代表3件（J60 / J68 / J94）の値がJSONと一致する

## 手動確認手順

1. Seeder実行後、管理画面の SkillEffectLab で以下を確認:
   - J52 蒼穹ドラグーンダイブ → 2Hit表示・DEF無視が効いている
   - J51 獄炎ナイトメア → 吸収が発生する
   - J56 聖壁アルカディア → 被ダメ軽減が付与される
2. DBで `skills` テーブルの該当行を直接確認（power_hint・追加フィールドのカラム値がJSONと一致）
3. 継承奥義を装備した既存キャラで1戦して戦闘ログの技演出文が新効果と矛盾しないこと

## ロールバック方針

- job_arts.json を git revert して `php artisan db:seed --class=JobArtSeeder` を再実行すれば完全に戻る

## 完了条件

- [ ] 変更一覧の全行（変更なし指定4職を除くJ44〜J99のランク9技＋J61ランク5のtemplateバグ修正）がJSONに反映されている
- [ ] `php artisan valzeria:validate-job-arts` エラー0
- [ ] 「着手前の必須確認」で全フィールドのエンジン消費を確認済み（消費されないものがあれば変更せず報告）
- [ ] Seeder実行後にDB値がJSONと一致（代表3件の報告）
- [ ] docs/UPDATE_LOG.md・DOMAIN_RULES.md 追記、admin_update_summaries 追記

## 更新情報サマリ案

- category: balance
- title（15〜35字）: 上位職の必殺技に固有効果を追加
- detail（プレイヤー向け日本語）: 上位職（Advanced〜Myth帯）の必殺技を調整しました。同じ性能だった技それぞれに、貫通・多段・吸収・弱体・回復などの固有効果が付き、素の倍率は効果に応じて再調整されています。継承奥義としての選び分けもお楽しみください。
