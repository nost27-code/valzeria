# FEATURE_STATUS.md

Legend: D=done, P=partial, N=not implemented, ?=unverified, X=removed

| Feature | St | Evidence | Notes |
|---|---:|---|---|
| Login | ? | <file/route> | 未確認 |
| Exploration | ? | <file/route> | 未確認 |
| Battle | ? | <file/route> | 未確認 |
| Market | ? | <file/route> | 未確認 |
| NPC procurement market loop | D | `NpcProcurementRequestService`, `NpcProcurementRequestGenerationService`, `NpcMarketListingService`, `npc_material_stocks`, `market:generate-npc-listings` | 調達依頼を酒場NPC本人の `npc_id` に紐づけ、納品素材をNPC在庫へ加算し、NPC在庫から市場へ定期出品する。NPC出品は買う一覧の在庫・最安値に通常出品として混ざる。市場出品NPCは `npc_rank=hero/legend` を除外する。 |
| Equipment enhancement | D | `EquipmentEnhancementService`, `SmithController`, `/blacksmith` | 武器・防具・装飾品を最大+5まで強化。+1/+2は欠片中心、+3は石+欠片、+4/+5は高純度石・都市素材・高位素材を使い、+5では精錬核も要求する。 |
| Drop equipment affixes | D | `EquipmentAffixService`, `DropService`, `BattleService`, `CharacterItem` | 敵ドロップ武器・防具に能力銘、武器用種族特効、防具用種族耐性を確率付与。銘補正は個体保存し、装備中のみ反映。 |
| Material exchange | D | `MaterialExchangeService`, `/material-exchange`, `DropService` | 敵固有素材を共通素材へ変換し、共通素材+100Gから強化石/守護石/装飾強化石の欠片を合成できる。各欠片20個+500Gを対応する石1個へ精製でき、高純度石と精錬核も素材交換所で作れる。高純度強化石・高純度守護石・高純度装飾強化石は敵ドロップしない。 |
| Daily supply depot | D | `DailySupplyService`, `/shop/items` | 回復アイテム各10個/日の補給枠と補給所ストックを実装。 |
| Tavern NPC portraits | D | `NpcMaster`, `resources/views/tavern/*.blade.php`, `public/images/npc/npc_*.webp` | 酒場・会話・名簿・名簿詳細で `npc_id` 対応のキャラ画像を表示。未遭遇NPCは名簿で伏せる。 |
| Job change / temple | D | `JobChange`, `job-change.blade.php`, `/jobs` | 職業カードから詳細モーダルを開き、特徴・職業管理の成長倍率に基づく伸びやすい能力・奥義・マスター恩恵・必要条件を確認できる。職業ランクは全職10でマスターし、必要職業EXPは基本職1倍/中級職2倍/上級職5倍/伝説職10倍。1回の報酬処理で付与される職業EXPは最大3。転職可能職業は詳細モーダルから転職確認へ進める。 |
| Home action prompts | D | `HomeActionService`, `HomeActionPanel`, `home-action-panel` | 次やることカードに未完了の最前線エリアへの探索誘導、補給所の回復アイテム受け取り誘導、装備中装備の進化合成誘導、現在職マスター時の転職案内、奥義セット不足の誘導を表示。 |
| Rank battle notifications | D | `PvPBattleService`, `CharacterNotificationService`, `PublicLogService` | ランク戦で格上に勝つと相手順位まで上がり、対戦相手へ順位低下通知を作成。番付上昇/TOP10入りは全体チャットへ公開ログを流す。 |
| Arena NPC rankers | D | `ArenaNpcRankingService`, `ArenaNpcBattleService`, `ArenaNpcAutoBattleService`, `RunArenaNpcAutoBattles`, `ColosseumRanking`, `arena_npc_rankings` | 闘技場ランク戦に酒場NPC由来のNPCランカーを混ぜ、通常のランク戦ボタンからNPCにも挑戦できる。当面は中級/一般NPCだけをプレイヤー順位帯より下の下位帯（最低51位以降）へ配置し、上級職NPCはランク外で待機する。ランキングでは通称名、表示Lv、非公開ステータス、見た目用の現在装備をNPC詳細モーダルに表示し、1日数回の自動ランク戦も行う。NPCは勝利ごとに表示Lvが1上がる（上限50）。legend枠と戦闘に向かないNPCは不出場。NPCステータスは非公開表示。 |
| Admin mail favicon badge | D | `components.layouts.admin`, `/admin/contact-messages/badge-count` | 管理画面を開いている間、5分間隔でメール取り込みと新規受信数確認を行い、faviconとタイトルに新規数を表示。 |
| Admin operator analytics | D | `OperatorAnalyticsManager`, `/admin/operator-analytics` | 新規登録、既存ログから推定した活動者、戦闘、チャット、fulfilled売上の日別推移、7/14/30日伸び率、CSV出力を表示。厳密な日別ログイン履歴は未作成。 |
| Admin update summaries | D | `AdminDashboard`, `config/admin_update_summaries.php` | 管理ダッシュボードに最近の更新情報を最新50件表示。各項目の表示/非表示と見出しあり/なしのコピー用テキストを提供。AI実装タスク後の運営向けサマリ追記先を定義。 |
| Admin chat message | D | `AdminChatManager`, `ChatLog`, `/admin/chat` | 管理画面から全体チャットへ管理人メッセージを投稿でき、プレイヤー側ではヴァルゼリアブルーで表示する。 |
| Admin NPC market analytics | D | `NpcMarketAnalyticsManager`, `/admin/npc-market-analytics`, `npc_material_stocks`, `market_listings`, `market_transactions` | 管理画面でNPCごとの調達納品量、現在在庫、出品中数量、販売済み数量、販売額、素材別明細、直近販売履歴を確認できる。 |
| Admin help text management | D | `HelpTextManager`, `HelpContentService`, `/admin/help-texts`, `/help`, `/guide` | ヘルプページと案内所の共通説明文を管理画面から編集し、`game_texts` の上書きとして保存できる。 |
| Local sandbox startup | D | `.env.local.example`, `scripts/local-setup.ps1`, `scripts/local-dev.ps1`, `docs/LOCAL_DEVELOPMENT.md` | 本番外部サービスを避けるローカル起動手順とスクリプトを整備。 |
| Public logs | ? | <file/route> | 未確認 |
| Valmon | P | `ValmonService`, `BattleService`, `ExplorationService`, `/valmons`, `CityHeader` | Starter/partner/feed/egg/ranch and Lv効果の素材発見・得意素材補正・追撃・未発見ヒント・応急回復・Lv100称号は実装済み。冒険者カードでは3行7列のヴァルモンケース表示を行い、仲間済みは画像、未仲間は `?` で表示する。全体機能の網羅性は未確認。 |
| Adventurer card customization | D | `CharacterProfileService`, `ProfileController`, `profile.edit`, `CityHeader`, `character_adventurer_card_assets` | 冒険者カードの背景・四角枠・キャラ枠・ヴァルモンケースを、入手済みアセットからプロフィール編集で選択できる。初期状態ではカード装飾の各01番とヴァルモンケース色違いを所持する。 |
