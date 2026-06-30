# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: repository code + docs below.
Last updated: YYYY-MM-DD
Branch: <branch>
Commit: <commit>

## Read order

For implementation planning:
1. AGENTS.md
2. docs/AI_CONTEXT.md
3. docs/CODEMAP.md
4. docs/FEATURE_STATUS.md
5. docs/DATA_MODEL.md if DB/types are involved
6. docs/DOMAIN_RULES.md if game rules/economy/progression are involved

## Status legend

D = implemented
P = partially implemented
N = not implemented
? = unverified
X = deprecated/removed

## Stack

- App: <Next.js / React / etc>
- Language: <TypeScript / etc>
- DB: <Supabase / PostgreSQL / etc>
- Auth: <fill>
- Styling: <fill>
- Tests: <fill>
- Deploy: <fill>

## Product summary

ヴァルゼリア is a browser-based pro-baseball GM / RPG-style web game.
Core loop: <one-line summary>
Primary currencies/resources: <Gold / 輝石 / etc>
Main player entities: <user/team/player/valmon/etc>

## Current feature map

| Area | St | Main code | Notes |
|---|---:|---|---|
| Auth | ? | see CODEMAP | 未確認 |
| Home/dashboard | ? | see CODEMAP | 未確認 |
| Exploration | ? | see CODEMAP | 未確認 |
| Battle | ? | see CODEMAP | 未確認 |
| Jobs/class change | ? | see CODEMAP | 未確認 |
| Equipment | ? | see CODEMAP | 未確認 |
| Market | ? | see CODEMAP | 未確認 |
| Public logs | ? | see CODEMAP | 未確認 |
| Valmon | ? | see CODEMAP | 未確認 |
| Admin | ? | see CODEMAP | 未確認 |
| Billing/輝石 | ? | see CODEMAP | 未確認 |

## Architecture notes

- Routing: <short>
- Server/API pattern: <short>
- State management: <short>
- DB access pattern: <short>
- Auth/session pattern: <short>
- Logging pattern: <short>

## Important invariants

- Do not change economy balance without explicit request.
- Do not change DB schema without migration/type update.
- Do not expose admin-only data to normal users.
- Public logs must not leak private/internal data.
- Feature status must reflect code, not intention.

## Known gaps / 未確認

- <gap 1>
- <gap 2>
- <gap 3>

## Recent implementation state

Keep this section short. Current state only, not changelog.

- 補給所は薬草・回復薬・魔力水を各10個/日の無料補給枠で扱い、所持上限で受け取れない分や当日未受取分を補給所に保管中として表示し、持ち越し分/本日ストック/今日の未受取の内訳も表示する。
- 補給商会では探索力回復商品を販売する。探索力の小瓶は輝石10で+50、1日3個まで。探索力の薬は輝石25で+150、1日2個まで。購入時は `character_consumable_items` の所持数に入り、倉庫の所持品欄から使用した時に探索力を回復する。使用による回復分は探索力上限を超えて蓄積できる。自然回復は上限未満の時だけ進む。
- 敵ドロップ武器・防具のうち `items.affix_enabled=true` のものは、ドロップ時に能力銘/種族銘/良品/逸品を確率抽選する。銘補正は `character_items` の個体カラムに保存し、装備中のみステータスへ加算する。武器の種族銘はPvEの敵への与ダメージ増加、防具の防護銘はPvEの敵からの被ダメージ軽減として適用し、PvP/チャンプ戦には適用しない。
- 通常探索の汎用装備ドロップ率は標準で武器1.5%、防具1%、装飾0.5%を目安に抑制している。敵固有装備ドロップもマスタ/既存DBともに初期値の約半分に調整している。
- 下部チャットの個別チャット/手紙ログは本人に関係するものだけ取得し、全体タブには表示せず個人(手紙)タブだけに表示する。ホーム画面だけでなく、戦闘/チャンプ戦/ランク戦/決闘系の `facility` レイアウト画面下部にも同じ `ChatLog` を表示する。戦闘結果テンプレートはタイトルが「入口発見」「探索を継続」に変わっても戦闘結果レイアウトとして扱い、チャット欄と「戦利品を持って帰る」帰還ボタンを維持する。
- 管理画面の「管理人チャット」から `type=admin` の公開ログを投稿でき、プレイヤー側の全体チャットには管理人メッセージとしてヴァルゼリアブルーで表示する。同画面には直近活動中の冒険者名も表示する。
- 管理画面の `/admin/public-logs` では、下部チャットに表示される `public_logs` を種別・日付・本文/名前で検索し、単体または複数選択で削除できる。管理人メッセージ（`type=admin`）は通常の選択削除から保護し、種別を「管理人」に絞って保護解除した場合だけ削除できる。
- 管理画面の `/admin/operator-analytics` では、新規登録、既存ログから推定した活動者、通常戦闘/チャンプ戦/ランク戦、チャット投稿、fulfilled売上の日別推移、7/14/30日伸び率、CSV出力を確認できる。厳密な日別ログイン履歴は未作成のため、活動者は既存ログと `characters.last_seen_at` に基づく推定値として表示する。
- Stripe決済完了時は購入者に購入完了メール、運営に売上通知メールを送る。運営通知の宛先は `ADMIN_EMAIL` を優先し、未設定時は `CONTACT_MAIL_ADDRESS` に送る。実送信には本番環境の `MAIL_MAILER=smtp` 等のメール設定が必要。
- 管理ダッシュボードは `config/admin_update_summaries.php` の最新50件を「最近の更新情報」として表示し、今後の意味ある実装タスクは必要に応じて同ファイルへ運営向けサマリを追記する。
- 装備ドロップの公開ログはSSS/EPIC、または銘抽選で「逸品」になった装備を対象にする。通常のAランク装備獲得だけでは下部の全体チャットへ流さない。
- 管理画面の `/admin/npc-market-analytics` では、NPCごとの調達納品量、現在在庫、出品中数量、販売済み数量、販売額、素材別明細、直近販売履歴を確認できる。
- ヘルプページと街の案内所は `HelpContentService` 経由で `config/help_content.php` の初期文言を表示し、管理画面の「ヘルプ文言管理」から `game_texts` の上書き文言を編集できる。
- 素材倉庫の標準上限は500個。素材倉庫+500拡張は50輝石、装備倉庫+100拡張は50輝石で、追加分は標準上限に加算される。過去に倉庫+50拡張を購入済みの冒険者には、素材倉庫は購入1回あたり差分+450、装備倉庫は購入1回あたり差分+50を補填する。
- 鍛冶屋では武器・防具・装飾品を最大+5まで強化できる。+1/+2は欠片中心、+3は石+欠片、+4/+5は高純度石・都市素材・高位素材を使い、+5には精錬核も要求する。都市素材は現在街や装備ごとに変動せず、+4は最低限魔導都市ルミナス到達後の固定レシピとして王都アークレアから魔導都市ルミナスまで、+5は次の死霊街ネクロムまでの各地域素材を1個ずつ要求する。素材交換所では敵固有素材を小鬼の牙・獣の毛皮・魔鉱片などの共通素材へ圧縮でき、共通素材+100Gから強化石の欠片/守護石の欠片/装飾強化石の欠片を合成できる。欠片20個+500Gを対応する石1個へ精製でき、高純度石は石5個+魔鉱片10個+2,000G、精錬核は魔物の魔核20個+都市高位素材5個+10,000Gで素材交換所から作る。高純度強化石・高純度守護石・高純度装飾強化石は敵ドロップさせず、敵ドロップの強化系補助枠では欠片を許可する。
- プレイヤー向けの探索目安は推奨Lvではなく `CharacterPowerService` の目安戦力で表示する。上部ステータスバー、左サイドバー、チャンプカードには現在戦力を表示し、通常ダンジョンの目安は実際に出る通常敵（雑魚/やや強い）の戦力範囲から算出する。内部の推奨Lvは敵生成・深度補正・通常敵データがない表示のフォールバック用に残す。戦力計算ではSP/MPを補助評価として控えめに扱い、AGIは最大35%まで緩やかに火力補正する。
- 酒場NPCは `npc_id` に対応する `public/images/npc/npc_001.webp`〜`npc_070.webp` をキャラ画像として表示する。未遭遇NPCは名簿で画像を伏せる。
- NPC調達依頼は酒場NPC本人の `npc_id` に紐づく。納品された素材は `npc_material_stocks` にNPC在庫として保存され、`market:generate-npc-listings` が在庫からNPC市場出品を作る。NPC出品は買う一覧の在庫・最安値に通常出品として混ざり、内部では `market_listings.seller_type=npc` / `seller_npc_id` でプレイヤー出品と区別する。市場出品NPCは `npc_rank=hero/legend` を除外し、購入時はプレイヤー売上通知や売上Goldを発生させない。
- 神殿の転職ページでは、職業カードの詳細モーダルから特徴、職業管理の成長倍率に基づく伸びやすい能力、覚える奥義、マスター恩恵、必要条件を確認できる。未使用BPが残っている場合は転職不可で、転職前にすべて能力へ割り振る必要がある。転職可能な職業は詳細モーダルから既存の転職確認へ進める。奥義は `skills.skill_type=job_art`、職業固有の従来必殺技は `job_classes.skill_id` 経由の別系統として戦闘中に発動する。
- 職業ランクは全職共通で10がマスター上限。必要職業EXPは基本職1倍、中級職2倍、上級職5倍、伝説職10倍で補正する。1回の報酬処理で付与される職業EXPは、探索深度・亜域・チャンプ戦などの補正後も最大3に抑える。
- 通常探索の連戦待機は表層10秒、深部15秒、深層/最深層/異界層20秒。深部/深層/最深層/異界層では、階層入場時に危険度を0%へ戻し、次階層へ進むために危険度を再蓄積する。敵ステータスは深度帯の目標Lvに対する種族・派生・役割ベースの敵ステータス生成式を下限として補正する。
- 秘境入口の基本出現率は探索度500以上で0.2%、700以上で0.5%、1000以上で1.0%。同エリアで秘境を見つけるたびに、次回以降の秘境入口出現率は10分の1に減衰する。
- 通常探索は運営設定で連戦待機方式と探索力制を切り替えられる。探索力制はOFFが初期値で、ON時は探索力最大500、60秒で1回復、通常探索/ボス挑戦で1消費する。既存/新規プレイヤーの初期探索力は勝利数から算出した各自の最大探索力に揃える。探索力制でも通常探索の敵EXP/Gold/職業EXP、通常ドロップ抽選、探索度、危険度、開拓度は旧探索1回分。探索力制ON時は通常探索の10回探索ボタンを使え、最大10回を順番に実行し、敗北・HP30%以下・特殊イベント・深度入口到達・エラーで途中停止して合計報酬と獲得した印を表示する。黄金ゴブリンは報酬付きの通常敵扱いで、10回探索を止めない。
- 通常探索、ボス挑戦、サブエリア探索は、同一キャラクターからの短時間連続実行をサーバー側で抑止し、重複送信時は探索処理中として案内する。探索力制でもダンジョン主の遭遇案内だけでは探索力を消費せず、実際に挑んだ時点で消費する。宿屋利用後の探索待機はなく、HP/SP満タン時は宿屋を利用できない。所持金と銀行預金の合計が宿代に足りない救済宿泊時は、専用画面で宿屋のおばちゃんの案内を表示する。救済宿泊は連続2回までで、3回目以降は素材売却や補給所利用を案内して宿泊を拒否し、通常料金で泊まると連続回数をリセットする。次の層への入口で引き返した場合は、専用の継続画面を挟まず現在エリアの次の探索戦闘へ進む。
- 砂都サンドラ以降の敵EXPは後半ほど伸びを緩やかにするため、サンドラ94%、ルミナス88%、ネクロム82%、セレスティア76%、魔王城以降70%を目安に抑制している。
- PvEで敵がプレイヤーを攻撃する場合は最低命中率82%、魔王城ヴァルゼリア以降は最低命中率88%にして、回避だけで高難度を突破しにくくする。魔王城ヴァルゼリアの通常敵・ボスは、敵ステータス自動生成後の最終ステータスを1.1倍にする。
- チャンプ戦では勝敗に関わらず素材報酬を1〜2個付与する。報酬対象素材は装備中装備の進化素材候補を優先し、候補がない場合はフォールバック素材を使う。挑戦者とチャンプは現在職の職業固有必殺技をSP消費で発動でき、低レベル側はLv差2ごとに発動率+1%、最大+25%の補正を受ける。挑戦者本人のHP/SPは戦闘後に消費保存しない。挑戦者がチャンプよりLv10以上低い場合、1戦につき最大1回、レベル差に応じた確率でチャンプ最大HP基準の追加ダメージが発生する。チャンプは連勝数1ごとに戦闘時のATK/DEF/MAG/SPR/SPD/LUKが2%低下し、最大40%まで低下する。
- ランク戦で格上に勝つと相手の順位まで一気に上がり、間の冒険者は1つずつ下がる。順位が下がった対戦相手には通知アイコンへ「ランク戦順位が低下しました」を出す。勝利で順位を上げた冒険者の番付上昇/TOP10入りは下部の全体チャットへ公開ログとして流す。冒険者タブの「次やること」には、到達済みの街全体から未完了の最前線通常ダンジョンを優先した探索誘導、補給所で受け取れる回復アイテム、装備中装備の進化合成可否/素材不足、未使用BPの能力割振り、Lv30以上かつ現在職マスター時の転職案内、奥義セット不足の候補を表示する。未使用BPが残る場合は転職案内より能力割振りを優先する。奥義は2枠以上セット済みで奥義画面を確認した構成なら、Cost5未満でも完了扱いにする。
- 公式note RSS通知は `note:rss-sync` が `https://note.com/valzeria/rss` の最新1件だけを定期確認し、未通知なら全キャラクターの通知ベルに「noteを更新しました」を作成する。通知済みRSS記事は `external_feed_items` に保存して重複通知を防ぐ。
- 闘技場ランク戦には酒場NPC由来のNPCランカーも混ざる。NPCは `arena_npc_rankings` で順位を持ち、通常のランク戦ボタンからランダム対戦相手として選ばれる。TOP10はプレイヤー枠として保護し、当面は中級/一般NPCだけをプレイヤー順位帯より下の下位帯（最低51位以降）へ配置する。上級職NPCは `is_active=false` のランク外扱いで、表示Lv上限はハヤト級でも50程度に抑える。ランキングではNPC名を通称部分だけで表示し、LvはNPCランク帯に応じた表示Lvを使い、名前クリックで非公開ステータスと見た目用の現在装備を表示するNPC詳細モーダルを開く。legend枠と戦闘に向かない商人・酒場娘・地図屋・治療/聖女系などは不出場。NPC相手のランク戦では相手ステータスを伏せ、勝利時は同じ順位帯のプレイヤー/NPCをまとめて順位シフトする。NPCランカーは `arena:npc-auto-battles` で1日数回、11位以下を対象に自動ランク戦も行い、NPCが勝つたびに表示Lvが1上がる（上限50）。
- ヴァルモンはLvに応じて通常探索後の素材発見、得意素材補正、通常探索戦闘中の追撃、未発見要素ヒント、1探索チェーン1回までの応急回復、Lv100称号「名相棒」を解放する。
- 冒険者カードはプロフィール編集から、入手済みの背景・四角枠・キャラ枠・ヴァルモンケースを個別に選択できる。初期所持は `images/profile/adventurer_card_bg01.webp`、`images/profile/adventurer_card_frame01.webp`、`images/profile/adventurer_avatar_frame01.webp`。ヴァルモンケースは `images/profile/valmon_case01.webp` から色違いを選択する。冒険の記録には既存の戦闘ログ、称号、所持装備、素材、闘技場、ヴァルモン所持データから集計できる項目を表示する。
- <current fact 1>
- <current fact 2>
- <current fact 3>
