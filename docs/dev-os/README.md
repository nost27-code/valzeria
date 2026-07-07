# ヴァルゼリア開発OS

Purpose: Codex・ChatGPT・Claude等のAIエージェントで開発を回すときの標準手順・テンプレート集。
このディレクトリは「毎回ゼロから説明しない」ための土台であり、ゲーム仕様そのものは書かない（仕様は docs/DOMAIN_RULES.md / docs/AI_CONTEXT.md が正）。

## 解決する課題

- Codexへの指示書の粒度・観点のブレ
- 保存漏れ（表示だけ反映）・既存仕様破壊・DB変更漏れ・QA不足
- 更新履歴（管理者向け/ユーザー向け/SNS向け）の毎回手作り
- AGENTS.md / AI_CONTEXT / Skills の役割重複とトークン浪費

## 構成

| ファイル | 役割 | 使うタイミング |
|---|---|---|
| [CODEX_TASK_TEMPLATE.md](CODEX_TASK_TEMPLATE.md) | Codex実装指示書テンプレート（Full版/Light版） | 実装依頼を書くとき |
| [QA_CHECKLIST.md](QA_CHECKLIST.md) | 実装後QA 3段階チェックリスト | Codexの実装完了後 |
| [IMPACT_MAP.md](IMPACT_MAP.md) | 仕様変更時の影響範囲チェック（機能別マトリクス） | 仕様を変える前 |
| [RELEASE_TEMPLATES.md](RELEASE_TEMPLATES.md) | 管理者サマリ/ユーザー告知/SNS告知テンプレート | デプロイ前後 |
| [IMAGE_TEMPLATES.md](IMAGE_TEMPLATES.md) | 画像生成依頼テンプレート6種 | 素材制作時 |
| [AGENTS_MD_PROPOSAL.md](AGENTS_MD_PROPOSAL.md) | AGENTS.md改訂案（人間確認後に適用） | 一度だけ |
| [AI_CONTEXT_PROPOSAL.md](AI_CONTEXT_PROPOSAL.md) | AI_CONTEXT.md修正案と矛盾リスト（人間確認後に適用） | 一度だけ |
| [SKILLS_PLAN.md](SKILLS_PLAN.md) | Skills候補一覧と切り出し方針 | Skills整備時 |

## 3層の役割分担（重複禁止）

```
AGENTS.md（ルール層・常時読込・~150行以内）
  └ 「何をしてはいけないか」「作業フロー」「報告形式」だけ。仕様は書かない。

docs/AI_CONTEXT.md + DOMAIN_RULES.md + CODEMAP.md（知識層・必要時読込）
  └ 「今どうなっているか」「ゲームルールの正」。手順は書かない。

docs/dev-os/ + Skills（手順層・作業種別ごとに読込）
  └ 「この作業はこの手順で」。ルールも仕様も書かず、上2層を参照する。
```

判断基準：**「破ったら事故る」→AGENTS.md、「知らないと間違う」→知識層、「毎回同じ段取り」→手順層**。

## 標準運用フロー（1実装サイクル）

```
1. 仕様検討    IMPACT_MAP.md で影響範囲を先に洗う（人間）
2. 指示書作成  CODEX_TASK_TEMPLATE.md を埋める（AIに下書きさせてよい。承認は人間）
3. Codex実装   指示書を渡す。AGENTS.mdが自動で読まれる前提
4. QA         QA_CHECKLIST.md の該当段階を実施（AI+人間。DB/課金は必ず人間も見る）
5. docs同期    AI_CONTEXT / DOMAIN_RULES / FEATURE_STATUS の該当行だけ更新
6. 履歴作成    admin_update_summaries.php 追記 + UPDATE_LOG.md + RELEASE_TEMPLATES.md で告知文
7. デプロイ    php local_deploy.php（管理画面のみなら local_deploy_admin.php）
8. 本番確認    QA_CHECKLIST.md「デプロイ後スモーク」を実施（人間）
```

人間が必ず判断するポイント：**仕様の最終決定 / migrationの破壊的変更 / 課金・輝石・ランキングに触る変更の承認 / デプロイ実行 / 本番データ操作**。それ以外はAIに委任してよい。

## 開発運用マップ（作業×分担）

| 作業 | 入力 | 出力 | AIに任せる | 人間が判断 | 事故りやすい点 |
|---|---|---|---|---|---|
| 1. 仕様設計 | アイデア、DOMAIN_RULES、IMPACT_MAP | 仕様メモ | 矛盾検出・影響列挙 | 仕様の採否・数値 | 既存経済との整合を見ずに数値を決める |
| 2. 実装指示書作成 | 仕様メモ、CODEX_TASK_TEMPLATE | 指示書 | テンプレ埋め下書き | 実装対象外の線引き | 「対象外」を書かず勝手に周辺を直される |
| 3. Codex実装 | 指示書、AGENTS.md | diff | 実装全般 | — | 表示だけ直してDB保存を忘れる |
| 4. GitHub管理 | diff | commit/PR | commitメッセージ | push/mergeの可否 | 一時スクリプト(scratch_*)の混入 |
| 5. デプロイ前確認 | diff、QA_CHECKLIST | チェック結果 | 静的確認・php -l | デプロイGO判断 | migrationリネームで二重実行 |
| 6. QA・テスト | 実装物 | QA報告 | チェックリスト消化 | 課金/DB系の最終確認 | スマホ幅・エラー系・敗北時の未確認 |
| 7. 更新履歴作成 | diff、UPDATE_LOG | サマリ3種 | 全文下書き | 公開トーンの承認 | 内部事情（弱体化理由等）の書きすぎ |
| 8. 管理者機能反映 | 新機能仕様 | admin画面/ログ | 実装 | 権限・削除操作の設計 | 新通貨・新ログが管理画面から見えない |
| 9. ユーザー告知 | サマリ | お知らせ/note | 下書き | 公開判断 | β免責の欠落、過剰な期待煽り |
| 10. 画像生成 | IMAGE_TEMPLATES | webp素材 | プロンプト作成 | テイスト最終判断 | 既存テイスト不一致、透過忘れ、サイズ違い |
| 11. バランス調整 | DOMAIN_RULES、実データ | 数値変更 | 影響シミュレーション | 数値の最終決定 | 装備だけ/敵だけの片側調整、合成品の見落とし |
| 12. 将来拡張設計 | FEATURE_STATUS | 設計メモ | 選択肢整理 | 優先順位 | 核ループより新機能を優先してしまう |

## この開発OSの呼び出し方（次回以降）

- **Codexに実装依頼するとき**: 「docs/dev-os/CODEX_TASK_TEMPLATE.md の形式で指示書を作って」→ 埋まった指示書をCodexへ。AGENTS.mdは自動で効く。
- **仕様変更を検討するとき**: 「docs/dev-os/IMPACT_MAP.md を使って◯◯変更の影響範囲を出して」
- **実装が終わったとき**: 「docs/dev-os/QA_CHECKLIST.md でQAして」
- **リリースするとき**: 「docs/dev-os/RELEASE_TEMPLATES.md で今回の告知3種を作って」
- **画像が欲しいとき**: 「docs/dev-os/IMAGE_TEMPLATES.md の◯◯用で依頼文を作って」

## 次に整備すべきタスク（優先順）

1. **AGENTS_MD_PROPOSAL.md を確認して AGENTS.md に適用**（検証コマンド未記入・正仕様の二重定義を解消）— 30分
2. **AI_CONTEXT_PROPOSAL.md を確認して AI_CONTEXT.md を修正**（野球テンプレ残骸・Stack未記入・肥大化した Recent state の移設）— 1時間
3. ~~docs/ai_development_rules.md と AGENTS.md の統合~~ — **完了（2026-07-07）**。docs/dev-os/tasks/TASK_H_AI_DEV_RULES_CONSOLIDATION.md 参照。旧ファイルはリダイレクトスタブ化済み
4. FEATURE_STATUS.md の実態同期（全行 `?` のままなら価値ゼロ）— Codexに調査タスクとして依頼可
5. Skills切り出し（SKILLS_PLAN.md 参照）— 運用が安定してから
