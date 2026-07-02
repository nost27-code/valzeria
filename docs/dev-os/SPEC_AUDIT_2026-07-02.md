# docs全体 仕様矛盾監査レポート（2026-07-02）

Status: **レポートのみ。修正は未適用**（人間承認後に個別タスク化する）。
監査範囲: 探索/スタミナ、ヴァルモン、Gold、輝石、素材、装備、職業、NPCランク戦、所属、ランキング、課金、更新履歴。
方法: 中核5ファイル（valzeria_spec / DOMAIN_RULES / AI_CONTEXT / CLAUDE.md / ai_development_rules）+ spec_gap_report / FEATURE_STATUS / base.md / valzeria_guide 等を精読し、数値・名称の横断グレップとコード裏取り（読み取りのみ）を実施。

---

## 深刻度A: Codexが誤実装しうる矛盾（早急に対応推奨）

### A-1. spec_gap_report.md が「Gold廃止期」の古い対応案を推奨として残置
- 根拠: docs/spec_gap_report.md はGold表示・価格・売却を「廃止済み仕様の残骸」とし、削除/非表示を「対応案」として列挙している（valzeria_guideのゴールド文言削除、価格ソート削除、売却→分解への改名等）。
- 現実: Goldはその後**再実装されて正仕様**（ai_development_rules「Gold経済のルール」、DOMAIN_RULESの宿代/鍛冶Gold/素材交換Gold/倉庫Gold拡張、ShopService.php:55-60 はGold購入を実装済み）。
- リスク: Codexがこのレポートを読むと、**正常に稼働中のGold機能を「残骸」として削除**しかねない。監査で最も危険な文書。
- 提案: 冒頭に「このレポートは2026-06のGold廃止検討期のもので、Gold再実装により対応案の大半は失効。歴史的資料として扱い、対応案を実行しないこと」の失効ノートを追加。

### A-2. valzeria_spec.md の「現在の確定仕様」の一部が現実態・裁定と不一致
- ①転職: 「転職はLv100で可能」（3・23・80・182行目）→ **2026-07-02裁定で「Lv30以上+要求職のマスター」が正**。Lv100は未採用案。
- ②ステ表記: 「HP / MP / ATK / ...」（20行目、ステータス仕様表62行目）→ 裁定でプレイヤー向け・新規docsの正表記は**SP**（DBカラムはmp系のまま維持）。
- ③ショップ: 「現実装のショップはGランク武器、防具の無料支給を主とする」（126行目）→ ShopService は `price` と `characters.money` によるGold購入を実装済み。無料支給主体の記述は旧状態。
- ④「要確認事項」節（180行目〜）に裁定済み項目（転職条件）が未解決のまま残る。
- リスク: 同ファイル冒頭が「この文書を実装より優先する」と宣言しているため、Codexが**現行実装を旧仕様へ巻き戻す**方向に働く。
- 提案: valzeria_spec更新をCodexタスク化（①〜④の該当行のみ最小diffで修正。AGENTS.mdのSource of truth節と整合させる）。

### A-3. 所属（冒険者協会）システムが仕様書側に存在しない
- 根拠: app/Services/GuildService.php に寄付ランク・救助費軽減の計算が実装済み（ただし GuildService.php:71 で寄付機能は「現在利用できません」と停止中）。GuildAssociationController も存在。
- 矛盾: DOMAIN_RULES.md・FEATURE_STATUS.md に協会/救助費の記載が一切ない。一方 valzeria_spec の非採用リストは「銀行預金まで対象にする救助費請求」を非採用と宣言しており、現行の救助費仕様がこれに抵触するかどうか判定材料がない。
- 提案（要裁定）: (a) 救助費・協会ランクの現行正仕様を確定し DOMAIN_RULES に追記、(b) FEATURE_STATUS に行追加、(c) 寄付機能停止中の扱い（再開予定か廃止か）を裁定。

---

## 深刻度B: 誤解を生む不整合（次のdocs整理で対応）

### B-4. .claude/CLAUDE.md の残存誤記
- 都市名「マリナス」→ コード正は「**港町マリネス**」（AllDungeonsSeeder.php:22、装備マスタ各TSVも全てマリネス）。
- エリア構成「71〜74が特殊」→ 街道ID **75〜83** が欠落（DOMAIN_RULES/AI_CONTEXTは記載済み）。
- 戦闘「3秒クールタイム」→ last_battle_at ガード自体は現存するが、現行の主制御である連戦待機（10/15/20秒）・探索力制への言及がなく古い印象を与える。

### B-5. プレイヤー向け文言に旧表記「MP」残存
- config/valzeria_guide.php:20 「HPやMPが減ったら」等。SP裁定違反（プレイヤー可視）。
- 提案: 「STR/AGI/MP のプレイヤー可視残存を全grep→修正」タスク（Blade/config/game_texts横断）に含める。
- 補足: 同ファイルのゴールド関連文言は、Gold再実装後の現実装とは整合するため**修正不要に変わった**（spec_gap_report A-1の対応案を実行しないこと）。

### B-6. base.md（原典）の失効数値
- base.md:579 「初期実装のレベル上限は50」等、初期設計の数値が多数。CLAUDE.md が「ゲーム仕様書（全体設計の原典）」として筆頭参照に挙げているため、Codexが数値を拾う危険がある。
- 提案: base.md 冒頭に「初期設計の歴史的資料。数値・仕様は現行正仕様（DOMAIN_RULES）と異なる」ノートを追加し、CLAUDE.md の紹介文言も「設計思想の原典（数値は失効）」へ変更。

### B-7. FEATURE_STATUS.md の未記入行
- Login / Battle / Market / Public logs が Evidence `<file/route>` プレースホルダのまま。協会（A-3）・銀行・秘境・図鑑以外の主要施設も行が不足。
- 提案: Codexへ「コードを根拠にD/P/N同期」調査タスク（コード変更なし）。

---

## 深刻度C: 軽微・注意喚起

- C-8. ai_overview.md: 3秒クールタイム・チャンプ10分等は現状と概ね整合するが作成日不明。参照時は「実装当時のスナップショット」として扱う。
- C-9. CLAUDE.md の「ドロップ率目安 normal 5〜10%...」と DOMAIN_RULES の装備ドロップ率（武器1.5%/防具1%/装飾0.5%）は**対象が別**（素材系 vs 装備）。並記時に対象を明示しないと混同する。
- C-10. 旧マスタdocs（buki.md、armor_master.tsv、dungeon_drops_master.md 等）は価格・数値が現DBと乖離している可能性が高い。正本はDB/Seederであることを各所で明示済みだが、マスタ変更時は必ずSeeder/DB側を正として扱うこと（ai_development_rulesに既存ルールあり）。

---

## 整合確認済み（問題なし）

- 探索力: 最大500 / 60秒1回復 / 小瓶 輝石5・+50・1日3個 / 薬 輝石12・+150・1日2個 — DOMAIN_RULES・AI_CONTEXT一致
- 素材倉庫: 標準500 / +500拡張50輝石 / Gold拡張10万G・+50・**最大10回** — 本日の修正（100回→10回）で両ファイル一致
- モンスター印: マスタ設定値の半分 / 15個以上で1/3 — 一致
- 職業: ランク★10マスター / EXP倍率1・2・5・10倍 / Job EXP上限3 — DOMAIN_RULES・AI_CONTEXT・FEATURE_STATUS一致
- チャンプ戦: 10分クールダウン / 連勝疲労2%最大40% / 素材報酬1〜2個 — 一致
- 秘境: 出現率0.2/0.5/1.0% / 1/10減衰 / 採取確率 — 一致
- NPCランク戦: TOP10保護 / 51位以降配置 / 表示Lv上限50 — DOMAIN_RULES・AI_CONTEXT・FEATURE_STATUS一致
- 更新履歴: UPDATE_LOG と admin_update_summaries の直近項目に食い違いなし

## 推奨対応順

1. A-1 spec_gap_report 失効ノート（5分・矛盾ではなく事故防止）
2. A-2 valzeria_spec 最小diff修正（Codexタスク・指示書は作成可能）
3. A-3 協会/救助費の裁定（人間判断）→ DOMAIN_RULES追記
4. B-4〜B-6 の文言修正（1タスクにまとめてCodexへ）
5. B-7 FEATURE_STATUS同期（Codex調査タスク）
