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
- 敵ドロップ武器のうち `items.affix_enabled=true` のものは、ドロップ時に能力銘/種族銘/良品/逸品を確率抽選する。銘補正は `character_items` の個体カラムに保存し、装備中のみステータスへ加算する。種族銘の与ダメージ補正はPvEの敵にのみ適用し、PvP/チャンプ戦には適用しない。
- 下部チャットの個別チャット/手紙ログは本人に関係するものだけ取得し、全体タブには表示せず個人(手紙)タブだけに表示する。
- 管理画面の「管理人チャット」から `type=admin` の公開ログを投稿でき、プレイヤー側の全体チャットには管理人メッセージとしてヴァルゼリアブルーで表示する。同画面には直近活動中の冒険者名も表示する。
- 管理ダッシュボードは `config/admin_update_summaries.php` の最新10件を「最近の更新情報」として表示し、今後の意味ある実装タスクは必要に応じて同ファイルへ運営向けサマリを追記する。
- ヘルプページと街の案内所は `HelpContentService` 経由で `config/help_content.php` の初期文言を表示し、管理画面の「ヘルプ文言管理」から `game_texts` の上書き文言を編集できる。
- 鍛冶屋では武器・防具・装飾品を最大+5まで強化できる。武器は強化石、防具は守護石、装飾品は装飾強化石を使い、素材交換所では各欠片3個を石1個へ精製できる。
- 神殿の転職ページでは、職業カードの詳細モーダルから特徴、伸びやすい能力、覚える奥義、マスター恩恵、必要条件を確認できる。転職可能な職業は詳細モーダルから既存の転職確認へ進める。
- 通常探索の連戦待機は表層10秒、深部15秒、深層/最深層/異界層20秒。深部/深層/最深層/異界層では、階層入場時に危険度を0%へ戻し、次階層へ進むために危険度を再蓄積する。敵ステータスは深度帯の目標Lvに対する種族・派生・役割ベースの敵ステータス生成式を下限として補正する。
- ランク戦で格上に勝つと相手の順位まで一気に上がり、間の冒険者は1つずつ下がる。順位が下がった対戦相手には通知アイコンへ「ランク戦順位が低下しました」を出す。勝利で順位を上げた冒険者の番付上昇/TOP10入りは下部の全体チャットへ公開ログとして流す。冒険者タブの「次やること」には、到達済みの街全体から未完了の最前線通常ダンジョンを優先した探索誘導、補給所で受け取れる回復アイテム、装備中装備の進化合成可否/素材不足、Lv30以上かつ現在職マスター時の転職案内、奥義セット不足の候補を表示する。奥義は2枠以上セット済みで奥義画面を確認した構成なら、Cost5未満でも完了扱いにする。
- ヴァルモンはLvに応じて通常探索後の素材発見、得意素材補正、通常探索戦闘中の追撃、未発見要素ヒント、1探索チェーン1回までの応急回復、Lv100称号「名相棒」を解放する。
- <current fact 1>
- <current fact 2>
- <current fact 3>
