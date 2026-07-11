# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: current behavior = code / intended spec = DOMAIN_RULES.md + human rulings (see AGENTS.md "Source of truth"). On conflict, report 要裁定 — do not pick a side.
Last updated: 2026-07-02
Branch: main

## Read order

For implementation planning:
1. AGENTS.md
2. docs/AI_CONTEXT.md
3. docs/CODEMAP.md
4. docs/FEATURE_STATUS.md
5. docs/DATA_MODEL.md if DB/types are involved
6. docs/DOMAIN_RULES.md if game rules/economy/progression are involved
7. docs/dev-os/ for task templates, QA checklists, and impact maps

## Status legend

D = implemented
P = partially implemented
N = not implemented
? = unverified
X = deprecated/removed

## Stack

- App: Laravel 11 (PHP) + Livewire v3 + Blade + Alpine.js
- Styling: Tailwind CSS
- DB: MySQL (production on Xserver)
- Auth: Google OAuth, 1 account = 1 character
- Payment: Stripe (輝石 purchase; paid/free tracked separately)
- Tests: PHPUnit via `php artisan test`
- Deploy: 現行は `php local_deploy.php` が署名付きリリースZIPを送信し、`server_deploy_api.php` が `valzeria_current` を原子的に切り替える。GitHub Actions + SSH の置換用workflowとリリーススクリプトは追加済みだが、Xserver SSH / GitHub Environment設定後の初回実行は未確認。初期設定と移行手順は `docs/GITHUB_ACTIONS_DEPLOY.md` を正とする。

## Product summary

ヴァルゼリアの冒険者 is a browser fantasy RPG recreating the feel of classic CGI-game FFA.
Core loop: login → explore/battle → EXP·Gold → level up → equip → job change → unlock next city → climb rankings.
Level cap: Lv255. Player-facing stat labels: HP / SP / ATK / DEF / MAG / SPR / SPD / LUK (old internal names like mp/str/agi remain in DB columns only).
Primary currencies: Gold (in-game), 輝石 (paid/support currency).
Main player entities: character (1 per user), jobs (rank ★1-10), equipment (with 銘 affixes), materials, valmon (companion), monster marks, arena rankings, tavern NPCs.
World: 10 cities (アークレア→…→ヴァルゼリア城), 40+ dungeons (area 1-70 normal, 71-74 special, 75-83 街道).

## Current feature map

See docs/FEATURE_STATUS.md (single source for feature status; do not duplicate the table here).

## Architecture notes

- Routing: routes/web.php; screens are Livewire components + Blade views
- Server pattern: thin Controllers, logic in app/Services/* (BattleService, ExplorationService, etc.)
- State management: Livewire component state + DB; no SPA framework
- DB access: Eloquent models (snake_case columns); DTO/BattleActor use camelCase
- Auth/session: Google OAuth login, character via Auth::user()->characters()->first()
- Logging: battle_logs, player_lifecycle_events, gold_transactions, kiseki_transactions, admin_item_grant_logs, public_logs (bottom chat), admin analytics screens
- Battle: server-side auto turn-based; PRG pattern (redirect after POST); 3s cooldown via last_battle_at

## Important invariants

- Do not change economy balance without explicit request.
- Do not change DB schema without migration/type update.
- Do not expose admin-only data to normal users.
- Public logs must not leak private/internal data.
- Feature status must reflect code, not intention.

## Known gaps / 未確認

- docs/FEATURE_STATUS.md is not yet synced against actual code (many rows unverified)
- 街の復興機能は未実装（復興予定素材9種のみ図鑑掲載済み）
- 厳密なログイン履歴は `player_lifecycle_events` で計測開始後の登録者から取得する。計測開始前の既存ユーザーについては過去行動を補完しない。
- 転職条件は2026-07-02裁定済み: 正仕様は「Lv30以上+要求職のマスター」（現実装どおり）。valzeria_specの「Lv100」は未採用案であり、コードへ反映しない

## Recent implementation state

詳細は docs/AI_CONTEXT_ARCHIVE.md（2026-07-02移設・全50項目）。恒久ルールは docs/DOMAIN_RULES.md が正。
該当機能に触るタスクではアーカイブの該当項目を検索して読むこと。ここには「隠し/停止中フラグ」と「最近の要点」だけを残す。

Hidden / disabled（承認なしに有効化しない）:
- 高位職ID60〜99は未公開（ID44〜49の新上級職は公開済み。超級職ID50〜59は条件達成者にのみ神殿表示。ID39〜43は職業IDとして欠番）
- 冒険者支援パス30日は `SUPPORT_PASS_ENABLED=false` で非公開（管理画面からON可）
- フェルディア地方は `FERDIA_REGION_ENABLED=false` 既定で非公開（管理画面の追加コンテンツ設定からON可）
- 探索補助品は `EXPLORATION_SUPPORT_ENABLED=false` 既定で非公開（管理画面の追加コンテンツ設定からON可）。OFF中は薬屋・もちもの導線・直URLを隠し、有効中の効果や所持品は削除せず凍結する
- 素材交換所では、薬草の若葉5個とアークレアの粗素材2個から薬草1個、または世界樹の葉片1個と妖精粉3個から薬草2個を調合できる。毎日10個の無料補給は維持する
- 冒険者協会の寄付・ランク別救助費軽減は停止中（DOMAIN_RULES参照）
- S→SS装飾品進化と古代片入手は未実装表示

Recent key points:
- 職業階層は8層（normal〜myth、EXP倍率1/2/5/8/10/15/22/30、転職引き継ぎ1/2・2/5・1/3）
- 星樹の塔は1〜100階マスタ、塔戦闘、戦闘後に通常探索に近い結果画面から次階へ進む導線、次階前の行動選択、50階以降5階ごとの挑戦中累積「星樹の構え」、一時中断/再開、軽量EXP/Job EXP報酬、行商人、行商人購入アイテムの塔内使用/次戦闘自動護符、ランキング、50/60/70/80/90/100階の公開ログ、10階刻み到達称号、50/70/90階初回到達の選択式武器宝箱、50階初回到達の冒険者カード背景自動付与（背景獲得の公開ログなし）、100階初回到達の冒険者カード装飾枠、エルフィアのダンジョン一覧からの導線、管理画面でのON/OFFと開催期間設定まで実装済み。ただし `STAR_TREE_TOWER_ENABLED=false` 既定で、管理設定がONかつ開催期間内の時だけダンジョン一覧に表示。汎用Gold/ランダムドロップ報酬は未実装
- フェルディア地方は `config/ferdia_world_map.php` と `FerdiaMapService` で本線13探索地・公開物語分岐4地点・アビス前段1地点＋3街のMAP状態を管理し、`extra_content.enabled.ferdia_unlocked` がONかつ期間内の時だけ街移動画面にタブ表示する。探索地は既存 `areas` / `character_area_progresses.development_point` を使い、フェルディアだけ勝利時開拓度が1〜2上がる。見晴らしの丘道・グランフォード外郭路・水門街道は開拓度150後の関門ボス撃破で次の街を解放し、北境の霊峰エルヴァンには最終ボスを置く。星詠みの廃塔・瀑布神殿アクエリス・風化列柱都市オルド・白潮灯台は本線の到達条件で必ず公開され、4地点すべての開拓度を最大にすると地下の謎の穴が恒久解放される。街はルヴァン、グランフォード、アーヴェンの3つを既存Cityとして登録し、街滞在時は通常の街施設を表示する。MAP上の探索地ボタンから探索タブへ移った場合だけ、街タブは「フェルディア簡易拠点」として主要施設を表示する。薬屋ではフェルディア薬素材から30戦有効・同時1種の探索補助品を調合でき、`ExplorationSupportService` が戦闘開始前の自動継続、戦闘後の残数確定、効果発動を管理する。探索補助品は独立した追加コンテンツ `exploration_support` がONかつ期間内の時だけ利用でき、OFF中はデータを残したまま効果を凍結する。
- 通常PvEの敵技は `enemy_actions` マスタで管理する。敵ターンごとに特徴行動を最大1回だけ抽選し、フェルディア敵には火傷・毒・出血・DEF低下・鈍足・回復阻害・現在HP割合・連続・溜め攻撃を設定済み。PvP・チャンプ戦・星樹の塔は対象外で、予兆技の自動防御作戦は将来拡張用のデータだけを保持する。
- 探索力上限は勝利数で250→350(1000勝)→450(2000勝)→500(3000勝)。探索力制はOFF初期・運営切替
- 輝石: 補給商会の小瓶は輝石10・薬は輝石25・各1日10個。補給商会商品は管理画面でキャンペーン価格と開始/終了日時を設定でき、期間中だけ割引価格になる。無償輝石は戦闘勝利0.1%・1日3個。管理画面から有償輝石付与可（監査ログ付き）
- 番付の総資産は手持ち+銀行。宿屋は直近7日想定利益で参加。番付案内バナーは2026-07-14まで
- ランク戦・チャンプ戦は挑戦者がボス戦セット奥義+職業固有必殺技を使用可（PvPは防衛側も）
