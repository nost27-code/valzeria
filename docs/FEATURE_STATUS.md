# FEATURE_STATUS.md

Legend: D=done, P=partial, N=not implemented, ?=unverified, X=removed

| Feature | St | Evidence | Notes |
|---|---:|---|---|
| Login | ? | <file/route> | 未確認 |
| Exploration | ? | <file/route> | 未確認 |
| Battle | ? | <file/route> | 未確認 |
| Market | ? | <file/route> | 未確認 |
| Equipment enhancement | D | `EquipmentEnhancementService`, `SmithController`, `/blacksmith` | 武器・防具・装飾品を最大+5まで強化。武器は強化石、防具は守護石、装飾品は装飾強化石を使用。 |
| Drop equipment affixes | D | `EquipmentAffixService`, `DropService`, `BattleService`, `CharacterItem` | 敵ドロップ武器・防具に能力銘、武器用種族特効、防具用種族耐性を確率付与。銘補正は個体保存し、装備中のみ反映。 |
| Material exchange | D | `MaterialExchangeService`, `/material-exchange` | 強化石/守護石/装飾強化石の欠片3個を、それぞれの石1個へ精製できる。 |
| Daily supply depot | D | `DailySupplyService`, `/shop/items` | 回復アイテム各10個/日の補給枠と補給所ストックを実装。 |
| Tavern NPC portraits | D | `NpcMaster`, `resources/views/tavern/*.blade.php`, `public/images/npc/npc_*.webp` | 酒場・会話・名簿・名簿詳細で `npc_id` 対応のキャラ画像を表示。未遭遇NPCは名簿で伏せる。 |
| Job change / temple | D | `JobChange`, `job-change.blade.php`, `/jobs` | 職業カードから詳細モーダルを開き、特徴・職業管理の成長倍率に基づく伸びやすい能力・奥義・マスター恩恵・必要条件を確認できる。職業ランクは全職10でマスターし、必要職業EXPは基本職1倍/中級職2倍/上級職5倍/伝説職10倍。転職可能職業は詳細モーダルから転職確認へ進める。 |
| Home action prompts | D | `HomeActionService`, `HomeActionPanel`, `home-action-panel` | 次やることカードに未完了の最前線エリアへの探索誘導、補給所の回復アイテム受け取り誘導、装備中装備の進化合成誘導、現在職マスター時の転職案内、奥義セット不足の誘導を表示。 |
| Rank battle notifications | D | `PvPBattleService`, `CharacterNotificationService`, `PublicLogService` | ランク戦で格上に勝つと相手順位まで上がり、対戦相手へ順位低下通知を作成。番付上昇/TOP10入りは全体チャットへ公開ログを流す。 |
| Admin mail favicon badge | D | `components.layouts.admin`, `/admin/contact-messages/badge-count` | 管理画面を開いている間、5分間隔でメール取り込みと新規受信数確認を行い、faviconとタイトルに新規数を表示。 |
| Admin update summaries | D | `AdminDashboard`, `config/admin_update_summaries.php` | 管理ダッシュボードに最近の更新情報を最新10件表示。各項目の表示/非表示と見出しあり/なしのコピー用テキストを提供。AI実装タスク後の運営向けサマリ追記先を定義。 |
| Admin chat message | D | `AdminChatManager`, `ChatLog`, `/admin/chat` | 管理画面から全体チャットへ管理人メッセージを投稿でき、プレイヤー側ではヴァルゼリアブルーで表示する。 |
| Admin help text management | D | `HelpTextManager`, `HelpContentService`, `/admin/help-texts`, `/help`, `/guide` | ヘルプページと案内所の共通説明文を管理画面から編集し、`game_texts` の上書きとして保存できる。 |
| Local sandbox startup | D | `.env.local.example`, `scripts/local-setup.ps1`, `scripts/local-dev.ps1`, `docs/LOCAL_DEVELOPMENT.md` | 本番外部サービスを避けるローカル起動手順とスクリプトを整備。 |
| Public logs | ? | <file/route> | 未確認 |
| Valmon | P | `ValmonService`, `BattleService`, `ExplorationService`, `/valmons` | Starter/partner/feed/egg/ranch and Lv効果の素材発見・得意素材補正・追撃・未発見ヒント・応急回復・Lv100称号は実装済み。全体機能の網羅性は未確認。 |
