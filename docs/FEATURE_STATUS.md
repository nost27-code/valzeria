# FEATURE_STATUS.md

Legend: D=done, P=partial, N=not implemented, ?=unverified, X=removed

| Feature | St | Evidence | Notes |
|---|---:|---|---|
| Login | ? | <file/route> | 未確認 |
| Exploration | ? | <file/route> | 未確認 |
| Battle | ? | <file/route> | 未確認 |
| Market | ? | <file/route> | 未確認 |
| Daily supply depot | D | `DailySupplyService`, `/shop/items` | 回復アイテム各10個/日の補給枠と補給所ストックを実装。 |
| Home action prompts | D | `HomeActionService`, `HomeActionPanel`, `home-action-panel` | 次やることカードに未完了の最前線エリアへの探索誘導と補給所の回復アイテム受け取り誘導を表示。 |
| Rank battle notifications | D | `PvPBattleService`, `CharacterNotificationService` | ランク戦の入れ替えで順位が下がった冒険者へ通知を作成。 |
| Admin mail favicon badge | D | `components.layouts.admin`, `/admin/contact-messages/badge-count` | 管理画面を開いている間、5分間隔でメール取り込みと新規受信数確認を行い、faviconとタイトルに新規数を表示。 |
| Local sandbox startup | D | `.env.local.example`, `scripts/local-setup.ps1`, `scripts/local-dev.ps1`, `docs/LOCAL_DEVELOPMENT.md` | 本番外部サービスを避けるローカル起動手順とスクリプトを整備。 |
| Public logs | ? | <file/route> | 未確認 |
| Valmon | ? | <file/route> | 未確認 |
