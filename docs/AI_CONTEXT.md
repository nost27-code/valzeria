# AI_CONTEXT.md

Purpose: compressed current-state snapshot for ChatGPT and Codex.
Source of truth: current behavior = code / intended spec = DOMAIN_RULES.md + human rulings (see AGENTS.md "Source of truth"). On conflict, report 要裁定 — do not pick a side.
Last updated: 2026-08-26
Branch: codex/nation-header-backgrounds

## Main navigation / nation community

- home下部Navigationは街・探索・冒険者・商店街・国家・闘技場の6項目。`nation`は商店街と闘技場の間に常時表示し、`public/images/icon/icon_305.webp`を使う。`NATION_COMMUNITY_ENABLED=true`ではタブ初回選択時に`NationScreen`をmountし、建国、国家一覧/詳細、募集、加入申請/承認/却下/取消、脱退/追放、役職、統治者譲渡、紹介/募集文/紋章、待機付き論理解散、操作履歴を利用できる。未所属TOPは`icon_306.webp`、国家紋章は`nation-crest_001.webp`〜`080.webp`から選ぶ。所属国家TOPと「国家を探す」から開く国家詳細の横長ヘッダは共通レイアウトで、国家名・紋章・統治者・国民数・国家Lv・募集状態・定員進捗を各国家の背景画像上にまとめる。rulerは自国TOPでのみ`nation-header-bg_001.webp`〜`020.webp`をmodalから選んで国家単位で保存できる。2026-08-26にMariaDB 10.5.13の背景key migrationと既存国家readbackを通過し、本番公開した。未所属TOPの「国家ピックアップ」はactive国家をID順の循環対象とし、active国家集合が変わらない間は同日固定の3国を日替わりで紹介するため、安定した1周期では各国家の表示回数と表示位置が均等になる。建国・解散でactive国家集合が変わった場合は、同日中でも新しい集合から再選定する。「国家を探す」専用画面はTOP案内枠を重ねず、募集ON、威信、ID順の全国家一覧とTOPへ戻る導線だけを表示する。本番は2026-08-25にMariaDB 10.5.13のmigration/readback gateを通過し、community flagをONで公開した
- 1Characterは1国家、1国家の現在の初期上限は20人。定員は`nation.max_members`を正本とし、一覧・詳細・所属dashboardの表示と申請時・承認時の判定で共通利用する。内部統治者roleは`ruler`、表示名は基礎国家名+国号、統治者名は国号から導出する。加入申請時は統治者へ申請一覧へのURL付き通知、承認時は申請者へ未読通知を作る。既存のURLなし加入申請通知も通知種別から申請一覧へ遷移する。承認ボタンは確認modalを開き、確定時だけ既存のtransaction/row lock付きServiceで加入させる。加入・脱退・追放・再建国の待機時間は`nation.*` GameSettingを正本とする。解散待機中は一般国民の自主脱退を拒否し、解散完了時にcooldownなしで無所属へ戻す
- 国家チャットは所属dashboardと常設`ChatLog`の国家タブで同じ`nation_chat_messages`を使う。現在所属する同一国家の国民だけが100文字以内で送信し、最新50件を閲覧できる。ほかの国民の新着がある間は下部国家ナビへ赤い未読dotを表示し、国家タブまたは常設チャットの国家タブを開くとmembership単位の最終既読message IDを更新する。加入前の履歴と自分の送信は未読にしない。全体/個人チャットの`public_logs`へは流さず、脱退・追放・解散完了後は閲覧・送信できない。ruler向け国家操作履歴はdashboardに最新5件だけを表示し、過去分はmodalで直近100件まで確認する。冒険者カードは「所属国家」に正式国家名を表示し、無所属者は「無所属」と表示する。2026-08-26にMariaDB 10.5.13の未読state migrationと既存membership backfillを本番適用し、不一致0で公開した
- 国家発展は`NATION_DEVELOPMENT_ENABLED`でcommunity/warから分離する。ON時は現在国民が40都市素材を確認modal付きで納品でき、低位素材は1個=国家資材1pt/国家発展EXP1、高位素材は1個=3pt/2EXPを同一transactionで加算する。国家Lvは累計EXPから整数閾値走査で導出し、次Lv必要EXPは`500×現在Lv`、表示上限Lv50（累計612,500）だが生EXPは上限後も保持する。国家外にはLvだけ、国民にはEXP進捗・現在国での個人貢献・退会者分を匿名集約した貢献一覧を表示する。`nations.development_exp`は台帳合計の読取cacheで、`nation:audit-development`が照合し、明示`--repair`時だけ復元する。発展実績の記録後は通常の`migrate:rollback`を実行せず、障害時はflag OFFと履歴を保持するforward migrationで復旧する。同一release batchの入口migrationも実績を検知して部分rollbackを拒否する。2026-08-26にMariaDB 10.5.13のstaging migration・schema/index・40換算率・shared lock readbackを通過し、本番はdevelopment flag ON、war flag OFFで公開した。MariaDBでの並行納品競合は未確認
- `要塞強化`・`宣戦布告`・`戦争方針設定`は引き続き準備中modalだけを開く。国家戦は専用tablesと`app/Services/Nation/`へ分離されているが、`NATION_WAR_ENABLED=false`、`nation_war.declaration_enabled=false`、`nation.facility_upgrades_enabled=false`、`nation_war.reference_damage=0`（未校正）を維持する。毎分lifecycleはOFF中no-op。`WEV0030`（瘴気の骨片）の敵dropは国家戦flagから独立し、魔界都市ネクロムのarea 50〜56にいる指定14体の通常敵で18%を有効とする

## Six Heroes / 六極殿（本番公開）

- 六英雄戦は6つの独立Room、Room別月次Ranking、各Room1日5回の公式戦、相性確認、月次英雄・空位確定、翌月順位引継ぎ、殿堂・冠・連覇、管理診断まで実装済み
- `SIX_HERO_UI_ENABLED`のコード既定値はOFF、本番設定はON。2026年8月中はhomeの「闘技場」タブ内で六英雄戦と通常闘技場を切り替えられ、旧対戦Route・Ranking・NPC自動順位戦も維持する。2026-09-01 00:00以降はON時の通常闘技場導線・対戦Route・NPC自動順位戦を停止し、六英雄戦だけを表示する。緊急時にflagをOFFへ戻すと従来闘技場へ復帰する
- 2026年8月は公式戦・順位変動を行うプレシーズンだが、英雄・空位の永久snapshotは作らない。8月最終順位は9月へ引き継ぎ、英雄・空位・殿堂・冠・連覇の記録は2026年9月Seasonから開始する
- 戦闘計算は副作用なしの`PvPBattleService::resolveBattle()`へ統一し、通常闘技場は`NullPvPRoomRule`、六英雄戦だけfreshな6種RoomRuleを注入する。通常闘技場の順位・ログ副作用は既存facadeに残す
- 六英雄戦の通常攻撃は表示威力100%、ランク戦基準ダメージへ0.5倍を掛けた値を基準とし、戦技の表示威力をその基準へ線形適用する。`PVP_SPEED_BREAKTHROUGH_ENABLED`はコード既定OFF・本番ON。六英雄戦ContextとのANDでだけ有効になり、行動開始時の実効敏捷比が1.30を超えた分へ係数1.25を掛けて名目突破率を最大30%とする。既存防御無視と乗算合成した総無視率は最大50%とし、既存無視適用済みのDEF/SPRを0.72/0.28合成した後の混合防御へ、総率へ到達するための追加分だけを1行動1回snapshotして適用する。多段Hitは同じsnapshot、追加行動は別snapshotを使う。通常闘技場・訓練所・チャンプ戦・NPC闘技場・PvEは対象外
- 永続化は`SixHeroSeason`、Room別`SixHeroRanking`、Room別日次使用回数、公式BattleLog、確定済み`SixHeroChampion` snapshot。管理画面とCLIは同じ`SixHeroOperationsService`を読み取り正本とし、安全な既存処理の再試行だけを許可する
- 管理者の既存`/admin/battle-simulator`には六英雄戦専用パネルを併設する。任意の別Character 2人、6Room、1方向1〜100試行を選択し、A→B・B→Aを同数実行する。`SixHeroBattleContextFactory::makePractice()`から毎試行freshなRoomRuleと公式戦同一の現行damage方針を取得して、`PvPBattleService::resolveBattle()`だけを実行する。両方向勝率、ターン、行動/追加行動、名目/既存/総/実追加無視率、通常/戦技damageの平均・中央値、最終HP、各試行、サンプル1戦の全ログを表示する。サンプルログはプレイヤーの六英雄戦結果と同じBlade部品を使い、フォント・文字サイズ・色・強調・セリフを保持する。Season・Room登録は不要で、Ranking、DailyUsage、SixHero/Arena BattleLog、Character HP/SP・戦績を永続更新しない
- 六英雄公式戦の全体チャット通知は、1位交代の「六英雄速報」と2位・3位への「六英雄」順位上昇だけに限定する。4位以下、敗北、順位不変、相性確認は通知しない
- 冒険者カードでは装飾付きカード本体をコメント欄で閉じ、その下・お気に入り武器の直前に独立した「六英雄戦績」枠を置く。現在月の6Room別順位を2行3列で表示し、挑戦・防衛の勝敗と確定済み実績は表示しない。順位カードは1位・2〜3位・4〜6位・7〜10位・11〜20位・21位以下の6段階で、金色からクリーム、水色、白へ淡く変化する
- 六極殿の「現在の六英雄」見出し右側に「遊び方」を置き、独立modalで競技の共通ルールと6Roomそれぞれの特殊な戦闘計算を表示する
- Room色は封魔=紫・封刃=赤・灼命=橙・神速=緑・逆刻=青・奇跡=黄。六部屋図・Room選択・現在首位王冠で共通利用し、街ヘッダの「現在の冒険者」では各Roomのrank 1へ対応色王冠を表示する。複数Room首位は王冠をenum定義順に並べる

## Job-art / exploration live metrics

- 管理者専用`/admin/gameplay-analytics`は、導入後の通常プレイヤー実績だけを`gameplay_metrics`へ記録して集計する。管理者・`tester_%@valzeria.local`は記録・表示から除外し、過去行動は補完しない
- 戦技は通常探索・追加ダンジョン・ボス・共有サブエリア・探索の地図・塔・英雄試練・プレイヤー闘技場・チャンプ戦・NPCランク戦の各実戦について、挑戦側冒険者の発動戦数、戦技別発動数、HIT/MISS/EVADE、支援型の判定なし、勝敗を記録する。訓練所と管理者ベンチマークは実績に含めない
- 探索はHTTP探索要求単位で、通常・追加ダンジョン・共有サブエリア・探索の地図の要求回数、完了回数、停止理由、勝敗内訳、EXP/Gold/職業EXP、装備/素材/印/地図、探索力と危険度の前後を記録する。報酬・ドロップ・危険度・戦闘判定自体は変更しない

## Dungeon-lord job-art set / training-ground practice battle

- ダンジョン主は`is_boss=false`の通常PvE・報酬ルールを維持しつつ、プレイヤーが使う戦技セットだけボス戦用を選ぶ
- 街施設「冒険者訓練所」は、通常戦用／ボス戦用セットで倒れない訓練人形と50ターン戦うほか、公開キャラクターを名前検索で選ぶか、闘技場ランキングのプルダウンから1回のPOSTで直接開始し、`TrainingGroundPvpBattleService`から副作用なしの`PvPBattleService::resolveBattle()`を使う対人模擬戦を行える。名前検索だけは選択した相手の確認カードを表示し、ランキング経由では重複カードを表示しない。各セットの戦技画面へ直接移動でき、PvPセットflag OFF時の対人戦導線はruntimeと同じボス戦用fallbackを案内する。戦闘ログは上から下へ発生順に表示する。開始時だけ訓練内のHP/SPを全快にし、実HP/SP、装備、順位、戦績、戦闘履歴、報酬、探索支援品、待機時間、実績計測は更新しない。多重実行はキャラクター単位の短時間ガードで止める

## Job-art v2 guide / rank-battle judgment (production)

- 戦技v2の基礎発動率は、現在職・継承とも始動50%・連携55%・奥義60%。通常探索・ボス・塔・プレイヤー闘技場・チャンプ戦・NPCランク戦の共通`JobArtV2BattleRules`を正本とし、場・戦技固有の既存補正はその後に適用する。flag OFFのlegacy発動率とマスタ値は変更しない
- 使用条件を満たして優先された奥義が発動抽選で不発だった場合は、技名・実効発動率と、条件成立中は次の行動でもその奥義が優先されることを戦闘ログへ表示する。不発後の再抽選・優先順・発動率は変更しない。プレイヤーPvP・チャンプ戦・NPCランク戦の戦技ダメージ色は実際の計算種別に合わせ、魔力ダメージを紫、物理・複合ダメージを赤で表示する
- 戦技の表示威力は、各戦闘経路の通常攻撃100%相当を基準に線形適用する。通常探索・ボス・塔は各PvE式、プレイヤーPvP・NPCランク戦はランク戦式、チャンプ戦は通常攻撃と同じDuel式を使う。ランク戦式は100%相当値へ通常攻撃の最低保証4%を適用してから表示倍率を掛け、通常・会心・戦技の最大HP割合ダメージ上限を設けない。戦技専用の1.85倍圧縮・総ダメージ35/40%上限・10%下限も使わない。将来の対戦バランス調整は表示倍率ではなく100%相当の通常攻撃式で行う
- プレイヤーPvP・NPCランク戦・チャンプ戦の会心ダメージは、各専用式で通常の防御・精神計算を終えた値の1.50倍とし、防御・精神を半減しない。運は会心率だけに使い、PvP・NPCは `3% + 運差×0.03`（2〜12%）、チャンプは `5% + 運差×0.05`（3〜20%）。確定会心ダメージ倍率は運で変えない
- 戦技セット上部から初心者向け解説モーダルを開ける。系譜と0〜12の戦闘内リソース、5枠の循環候補順と1行動1候補1抽選、10系譜の直接・共通獲得、PvP奥義予告、最大100ターン、最大5枠・Cost9・奥義1枚等の制限をまとめる。行動順のプレイヤー向け表記は「敏捷」とし、英字能力名を使わない
- プレイヤーPvPとNPCランク戦は、100ターン終了時に双方が生存していれば残り体力÷最大体力の割合を比較する。挑戦者の割合が高い時だけ判定勝利とし、同率または防衛側が高い場合は防衛成功。チャンプ戦はこの割合判定を使わず、100ターン以内の実際の撃破だけで交代する
- PvPセットが有効な時は`REWARD`区分の戦技も設定・発動できる。PvP・チャンプ戦・NPC闘技場では攻撃・回復・強化・弱体などの戦闘効果を維持し、実行用戦技と`BattleState`のGold・drop・rare・material報酬補正だけを必ず0にする。通常探索・ボスの報酬効果と、各対戦にもともとある固定報酬は変更しない

## Job-art v2 first replacement wave

- 既存戦技差し替え第1弾は、1:1見切りの呼吸（物理90%＋受け流し25%/1R）、2:5二段穿ち（合計145%/2Hit・DEF25%無視）、5:9大崩拳（225%、行動開始時HP30%以下×1.60）、9:9蝕みの終端（255%、HP40%以下×1.50）、12:9総力戦（複合255%後に攻撃/魔力+30%/3R・非加算更新）、15:1不屈の誓い（次の直接ダメージ40%軽減・1回）、29:1静寂の帳（魔力95%後に静寂の場5R・元攻撃へ非適用）を正とする
- `2026_08_17_150000_replace_first_wave_job_arts.php`は7件の自然キーだけを更新し、既存`skills.id`と戦技枠参照を維持する。見切りの呼吸と静寂の帳の専用アイコンも同じリリースへ含める。戦技v2では旧`cooldown_turns`・`max_uses_per_battle`・`limit_group`を再使用・同時セット制限に使わない
- 2026-08-18に `maintenance_required` で本番公開し、差し替えmigration・戦技/ダンジョン検証・公開URLと専用アイコンのHTTP確認まで完了。本番の認証済み実戦確認は未実施

## Job-art v2 replacement wave 2-A (production)

- 既存IDを維持した差し替え2-Aは、17:9狩猟の完成（狩猟印12・物理255%単発、対象の標的印2段階を発動確定時に消費できた場合だけ最終×1.50）、33:9崩落（崩し12・物理315%単発、解除可能な強化1つ解除、防御/精神-25%・5R）、6:5天測の陣（星印4・魔力145%単発後にobservationを5R展開）、19:9魂喰らい（冥蝕12・魔力255%単発、実ダメージ35%吸収、HIT時に最大SP10%圧）を正とする。19:5スピリットスティールのHP吸収30%は変更しない
- `2026_08_20_120000_replace_job_arts_wave2_2a.php`は4件の自然キーだけを更新し、既存`skills.id`と戦技枠参照を維持する。狩猟の完成の標的印消費はHIT/MISS/EVADEより前の発動確定時、崩落の強化解除はHIT非依存、天測の陣が新しく展開した場は展開元攻撃へ遡及しない。専用アイコンは天測の陣=`job_art_006_05.webp`、狩猟の完成=`job_art_017_09.webp`、魂喰らい=`job_art_019_09.webp`、崩落=`job_art_033_09.webp`を使い、更新時刻queryで同名画像の旧cacheを避ける
- 2026-08-21に`maintenance_required`で本番公開。新しい発動台詞・発動描写は今回の人間公開承認に基づき、別途差し替えるまで既存の汎用文を維持する

## Job-art v2 replacement wave 2-B (production)

- 既存IDを維持した差し替え2-Bは、2:9穿貫（物理225%単発・防御50%無視）、3:1影狩りの構え（物理90%単発・敏捷-15%/3R）、3:5急所狙い（物理145%単発・既存会心率+15ポイント）、4:1精密射撃（物理90%単発・命中率+15ポイント・既存会心率+10ポイント）、5:1崩し打ち（物理90%単発・防御-15%/3R）、5:5連環崩打（合計物理145%/3Hit・防御/精神-15%/3R）を正とする
- `2026_08_23_120000_replace_job_arts_wave2_2b.php`は6件の自然キーだけをtransaction内で更新し、既存`skills.id`と戦技枠・preset参照を維持する。既存の防御無視・弱体・Hit分割・命中補正・会心補正metadataだけを使い、新しいruntime primitive、追加RNG、`JobArtV2SelectionService`の変更は入れない
- 基礎発動率50%/55%/60%は別リリースの現行仕様を維持し、2-Bでは変更しない。PvP/champ/NPC arenaには戦技会心抽選を新設せず、会心補正metadataは既存抽選のある通常PvE・boss・towerだけで消費する。2026-08-23に本番公開

## Job-art v2 replacement wave 2-C phase 1 (production)

- 既存IDを維持した2-C Phase 1は、1:5受け返し（物理145%単発、直前の自分の行動後に受け流し成功なら最終damage×1.35）と17:1影伏せ（物理100%単発後、次の封狩Rank5/9を×1.20・1回・最大4回の自分の行動機会）を正とする
- `2026_08_25_180000_replace_job_arts_wave2_2c_phase1.php`は自然キー1:5・17:1だけをtransaction内で更新し、既存`skills.id`と戦技枠・preset参照を維持する。既存の`parry_success_since_previous_own_action`と`prepared_effect`だけを使い、新runtime、追加RNG、`JobArtV2SelectionService`、基礎発動率50%/55%/60%は変更しない。2026-08-26に本番公開

## Job-art v2 pre-release canonical state (historical)

- 戦技v2コードは現行マスタ94職・Rank1/5/9の282戦技を対象とする。v2有効時に現在職による対応外判定や旧マスタ説明への職別fallbackは設けない。プレイヤー自身に主系譜・副系譜・出張の所属は持たせず、習得済み戦技は現在職に関係なく編成でき、カードに記載された効果と威力を100%適用する
- 1セットは5枠・Cost上限9。始動/連携/奥義はCost1/2/3で、奥義は1セット1枚まで。SPは習得職階級×Rankの固定表（基本4/6/8、中級6/9/13、上級10/16/22、超級16/25/35、冠位23/36/50、英雄30/48/66、伝説40/64/88、神話52/84/115）を使い、現在職・系譜・継承率による軽減を行わない
- 系譜は戦技カードの資源・状態タグとしてのみ扱う。セット内にその資源を明示的に増減する戦技がある場合、またはその系譜の奥義をセットした場合に、その系譜資源を有効化する。有効でない系譜の共通獲得イベントは発生させない。同一戦技・同一行動・同一資源では、戦技本文の直接増減と系譜共通獲得を重複させない
- v2有効時の金冠錬師は、金冠錬符がHIT時触媒+4・金蝕1回、金冠ミダスフィールドが触媒8pt・power315・HIT時金蝕2回。金蝕は次の系譜資源獲得行動で各獲得量-1（最低1）、最大2・非加算更新で、複数資源でも1行動につき1回だけ消費する。同一行動・同一資源の場補正も1回だけとし、指揮の通常攻撃HIT+4と非戦技手番+1に天測がある場合は合計+6
- 選択は前回判定位置の次から5枠を巡る循環cursor方式。現在使用できない戦技は飛ばし、最初に見つかった候補へ発動抽選を1回だけ行う。不発時に同一行動中の別戦技を再抽選しない。奥義準備と対奥義/予告大技の応答は専用flag配下にある
- v2奥義は資源が必要量へ達した周期の初回候補だけ優先し、発動抽選不発後は次の自分の行動を通常の循環候補順へ戻す。同じ資源の奥義を装備した始動は資源上限時も候補に残る。プレイヤーランク戦・チャンプ戦・NPC闘技場の奥義予告は同系譜Rank5連携を前提とせず、資源条件と相手の1行動分の応答機会で成立する
- `config/battle.php`の戦技v2関連15 flagはすべてコード既定OFF。本番では文言専用の`BATTLE_JOB_ART_FLAVOR_REWRITE`だけをONにし、残る14 engine/UI flagはOFFを維持する。全282戦技のmaster同期や既存slot/preset更新は行わず、従来3枠・Cost5・legacy選択/SP/戦闘効果を維持する。ただし金冠ミダスフィールドのpowerだけは共通戦闘経路と既存DBを315へ同期済みで、金蝕・HIT時触媒・同一行動補正はengine flag ONまで休止する。v2 engineの本番有効化は別タスクでDB backup、残りのmaster同期、全6戦闘経路smokeを経て行う
- `BATTLE_JOB_ART_FLAVOR_REWRITE`は戦闘v2の他flagから独立した文言切替。`database/data/job_art_flavor_rewrites.json`に94職282戦技の台詞・発動描写を完全一致 `(job_id, learn_rank, name)` で保持し、2026-08-15から本番ON。通常/ボス/塔/PvP/チャンプ/NPC闘技場の奥義ログと神殿・管理確認画面だけへ適用し、威力・効果・発動条件・RNGは変更しない。OFF時、未一致時、読込失敗時は`skills.activation_phrase` / `activation_description`を維持し、DB同期は行わない

## Job-art v2 progression / FIX_NOW pass (historical design pass)

- 公開戦技237件の監査から、人間裁定済み22件だけを完全一致 `(job_id, learn_rank, name)` で補正する。`JobArtV2ProgressionCatalog`が対象identityと表示文言、`JobArtV2ProgressionService`が通常PvE・boss・tower・PvP・champ・NPC arenaの実効効果、`JobArtV2ProgressionState`がbattle-memory-onlyの準備・印・封技・残心・指揮・軽減ラッチを一元管理する。master、DB、Cost、SP、発動率は変更しない
- 上級・超級・冠位の同系譜内で、照準準備、貫通構え、狩猟印、崩し印、resource抑制、行動カテゴリ観測などを段階的に接続する。現在職または同系譜継承だけが系譜固有mechanicsを使い、異系譜継承は明示portable効果以外を持ち込まず、foreign resourceを生成しない。flag不足・未登録技・unsupported current jobはlegacyへfail closedする
- 白銀王盾はcurrent/same guard lineageではdamageにDEF/SPR +15%（2ラウンド・継承減衰あり）を付与する。cross-lineageではbuff/resourceを持ち込まず、前回の自分の行動以降にdirect damageを実際に1以上軽減した時だけ次の自分の行動機会まで使用できる。実行時はHIT/MISS/EVADEを問わずラッチを消費し、発動抽選不発では保持する。3-arm因果監査ではcross-lineage donor改善0/9、対象22件のdead-art/universal-donor/adjacent-tier/power-only blockerはいずれも0

## Job-art v2 role diversity pass (historical design pass)

- 公開済みmasterとv2 resource規則を変えず、同期後監査TOP20の13 engine gapと7 role-design gapだけを対象にした。正本は`JobArtV2RoleEffectCatalog`の完全一致 `(job_id, learn_rank, name)` metadataで、`JobArtV2RoleEffectService`がbattle-memory-onlyのTimedEffect/PreparedEffect、支援、報酬、場、適応damage routeを6戦闘経路へ接続する。対象外戦技・flag不足・unsupported current jobは既存効果とRNG経路を維持する
- 正本自己強化は第1弾公開時34件、差し替え2-A公開後は32件とする。raw能力を変更せず、解除可能な期限付き効果だけを使う。`JobArtV2CanonicalSelfBuffRuntimeAuditTest`が全282件から対象を抽出し、通常PvE・boss・tower・PvP・champ・NPC arena、現在職/継承、物理/魔法で値・持続・失効・非重複を検査する。旅支度は攻撃/防御/魔力/精神を同時に+10%し、4ターン維持する。第1弾で自己強化型から外れた渾身撃・爆裂闘気、第2弾2-Aで外れた瞬影乱舞・ルーン強奪の計4件はこの集合から除外し、総力戦の攻撃/魔力強化は役割効果側で監査する
- 期限付き能力強化の戦闘ログは、実際に選ばれた能力・上昇率・継続時間を日本語の能力名で表示する。正本自己強化に加え、役割効果の総力戦・商聖の助言と進行補正の白銀王盾も同じ表示規則を使う
- portable指定された役割効果は同系譜/異系譜継承でも使用できるが、現在職のresource barは1本のままでforeign resourceを生成しない。source `power` / `hit_count` の値、Cost、35・38・50%発動、normalized SPは変更しない。Job Artの`power`は1行動全体の総量で、`hit_count > 1`は`JobArtHitPower`が整数余りを前方Hitへ配り、全Hit入力の合計を総powerと一致させる。通常PvE/bossとそれを継承するtower、PvP/champ/NPC arenaで同じ分割を使う
- 反撃は納刀=短期tempo（ATK+5%/2R）、闘争本能=長期強化（ATK+25%・DEF+20%/5R）、剣気集中=決着準備（反撃Rank5/9を各×1.20、2回、最大6回の自分の行動機会）へ分離した。血潮の咆哮は非致死maxHP3%を払いATK+30%・MAG+25%/5R。秘薬調合はHP/SP中回復と有害状態の優先1件浄化、王者の秘薬は残存割合が低いHP/SP側の今回回復量を×1.50（同率HP、浄化なし）とする。照準は高命中・既存会心補正・乱数なしの物理/魔法期待値選択、場術は星光/旋律生成と場延長、貫通はstrict/flexible準備、変成/守護は長期buff・能力選択・回復/加護・Gold/Drop/鑑定/浄化/収奪へ分離した
- `MULTI_HIT` / `DAMAGE_BUFF` / `DAMAGE_DEBUFF` / `DAMAGE_GUARD_BARRIER`は通常攻撃と同じdamage種別を使う。プレイヤーPvP・champ・NPC arenaは`JobArtEffectCatalog::resolveDamageType()`を共通正本として`usesMagForNormalAttack()`へ統一し、v2が実行用Skillへ明示したdamage routeはlegacyの通常攻撃連動より優先する。`DAMAGE_BUFF`の後続強化にも同じ解決結果を渡す。管理プレビューと職業案内も同じ優先順位を使う

## PR27 job-art v2 release candidate (historical boundary)

- 既定OFFのcurrent-job v2対応は40職。PR26までの39職に63星冠導師を追加し、全94職のうち54職はcurrent-job v2をfail closedする。上級・超級28職のeffect inventoryは、凍結済み個別効果を持つ4職がfull、残る24職がresource-v2 + master-effect fallbackのままである
- 9種類のslot条件は`character_job_art_slots.condition_key`を正本として通常/ボス/PvPごとに保存する。`job_art_preset_slots.condition_key`にも同じ値を保存し、preset適用後も維持する。未知値は読込時だけ`always`へfail closedし、DB値を自動更新しない。旧cache専用storeは廃止した
- current job 63の信頼済みRank1は`star_light -> melody -> sanctuary -> silence -> observation`の固定順で次の場を展開し、実際に既存場を上書きした時だけ基礎+4に追加+2を得る。Rank5は上書きされた自分の旧場を1ラウンドだけechoとして保持する。echoは既存の場補正とHUD snapshotを再利用し、追加の主場/overlay枠を作らない
- current job 63 Rank9は行動開始時snapshotに主場がある場合だけ、本人が実際に発生させた`field_overwritten`回数0/1〜2/3〜4/5以上に応じて基礎powerを1.00/1.05/1.10/1.15倍する。生成・更新・延長・消滅・副場は数えず最大+15%。同系譜継承Rank9は星印12ptを共有できるがこのcurrent-job固有分岐は持ち込まない。最終blocker解消後のrelease candidate判定は`READY`
- `BATTLE_JOB_ART_PVP_SET`、`PRESETS`、`LOADOUT_V2`、`DYNAMIC_SINGLE`、`NORMALIZED_SP`、`HIT_RESOLUTION`、`DAMAGE_APPLICATION`、`RESOURCES`、`FIELDS`、`PENETRATION`、`PENETRATION_STANCE`はすべて既定OFFを維持する。公開手順・rollback・監視可能性は`docs/JOB_ART_V2_RELEASE_CHECKLIST.md`を正本とする

## PR26 tactical mixed and inherited job-art v2 loadouts (historical boundary)

- PR26時点の既定OFF current-job v2対応は39職。既存12職を維持し、上級18職と超級10職を追加した。当時はcurrent job 63と英雄・伝説・神話の未実装職がlineageだけを解決し、current-job v2をfail closedしていた
- `JobArtLineageCatalog`は人間裁定と凍結資料のjob IDを正本に全94職を10系譜へmappingする。上級/超級28職のうち、凍結済み個別効果が揃う4職はfull v2 effect、残る24職は共通resource/activation/SPとmaster effect fallbackで動作する
- 戦闘中のresource barは現在職の主系譜1本だけ。同主系譜の継承Rank1/5/9は同じ0〜12pt resourceを生成・消費でき、現在職Rank9が使用不能な場合だけ同主系譜継承Rank9を優先候補にする。異系譜継承はforeign resource・finisher優先・current-job限定特殊効果を持ち込まない
- v2 loadout内のactive戦技は現在職/継承ともRank1/5/9=35/38/50%とnormalized SPを使う。現在職だけ0.8補正、継承は補正なし。必要flag不成立時はmaster activation/SPとlegacy RNG経路を維持する
- PR26時点では各slotの9種類のdeterministic条件を共有cacheへ保持していた。PR27で通常slotとpreset slotのDB列へ移行し、前方条件不成立時だけ後方へ進む選択規則と1回だけの発動抽選は変更していない
- DEF/SPR低下、場の正式なowner補正、変成後SP、guard/parryなどglobal battle stateは継承戦技にも作用する一方、61魔法化、62貫通、65命中/SP圧力、68崩し付与、60構え、66加護/浄化、67変成などcurrent-job限定効果はportable化しない

## PR24 job-art v2 horizontal expansion (historical boundary)

- PR24時点では、既定OFFの既存v2依存flagと一元化されたcatalogを維持したままcurrent job 60剣冠騎士（反撃）と66聖冠守護者（守護）をproduction対応へ追加した。当時の対応jobは24/53/60/61/62/64/65/66/67/68/69/85で、current job 63はfail closedだった
- 60は剣勢0〜12pt。信頼済みRank1発動+4、通常攻撃HIT+1、direct physicalの基礎HIT受領+1、2ラウンド構え中の20%受け流し成功+1。受領と受け流しは同一source actionでも別eventとして両方成立し、多段でも受け流し抽選と各resource eventはaction単位1回。Rank5/9は4/12消費し、Rank9実効powerは実戦比較により455
- 66は聖護0〜12pt。信頼済みRank1発動+4、通常攻撃HIT+1、one-charge加護で実際に1以上軽減+1、Rank5で明示6状態を1件以上浄化+1。Rank1/5は次のdirect damageを20%、Rank9は25%軽減し、強い値を維持する。v2現在職R1/5/9だけlegacy汎用buffを抑止して既存magical damageを維持し、Rank9 powerはmaster 355を維持する
- `DirectAttackResolution`、`ParryResult`、`DamageTrace`、`CleanseResult`を6戦闘経路の構造化正本として共通HP適用へ接続した。DoT・自傷・変換cost・反動・未分類追撃はdirect防御対象外。HUD・戦技セット・おすすめ戦型・プリセットは共通metadata/resultを再利用し、継承・flag不足・対象外職はlegacyへfail closedする

## PR23 job-art v2 horizontal expansion

- 既定OFFの既存v2依存flagと共通基盤を維持したまま、production対応current jobへ67金冠錬師（変成）と68雷冠拳聖（崩し）を追加した。現在の対応jobは24/53/61/62/64/65/67/68/69/85。60反撃と66守護はPR24までprototype catalogへ登録せずfail closedする
- 67は触媒0〜12pt。信頼済みRank1の正式SP消費後に最大HP5%を非致死で支払い、最大SP5%を実回復できた時だけ変換成功として触媒+4、通常攻撃HITで+1。変換HP消費はdamage/self-damageへ通知しない。Rank5/9は4/12消費し、既存の魔法ダメージと報酬効果を維持する
- 68は崩し0〜12pt。信頼済みRank1の奥義単位HITで+4、通常攻撃HITで+1。Rank5/9は4/12消費し、その攻撃の解決後から対象DEF/SPRを10%・2ラウンド／15%・3ラウンド低下させる。非stackで強い値を優先し、同値だけrefresh、弱い値は既存状態を更新しない。ボスは既存規則どおり効果率半減。v2 R5/9だけlegacy自己buffを抑止する
- `ConversionResult`と`JobArtV2BreakDebuffResult`を表示用の構造化正本とし、HUD・おすすめ戦型・プリセットは既存共通経路へ接続する。当時の67/68 Rank9 powerは追加効果込みの120,096戦比較によりマスタ355を維持する判断だったが、67だけは2026-08-15の再裁定で315へ変更し、68は355を維持する

## PR22 job-art v2 horizontal expansion

- 既定OFFの既存v2依存flagと一元化された`JobArtV2PrototypeCatalog`を維持したまま、production対応current jobへ65鋼冠機導師（照準）と69戦冠司令（指揮）を追加した。照準は上限12で、信頼済みRank1発動+4、通常攻撃HIT+1/MISS+2、Rank5/9は4/12消費・その行動だけ命中+5/+8pt・HIT時に対象の現在SPへ最大SPの3%/5%圧力（同一相手へ一戦15%上限）。指揮点は上限12で、Rank1発動自体では増えず、通常攻撃HIT+4と最終行動が通常攻撃または現在職技だった非奥義手番+1を別eventとして得るため、通常攻撃HITは合計+5。Rank5/9は4/12消費する
- `NormalAttackResolution`は既存の通常攻撃HIT/MISS結果を再抽選せず構造化し、`BattleActionResult`は1 actor actionにつき最終行動をJOB_ART/CURRENT_JOB_SKILL/NORMAL_ATTACK/NO_ACTIONのいずれかへ一度だけ確定する。job_art不発後のfallbackは実際の現在職技/通常攻撃として扱い、job_art自体のMISS/EVADEはJOB_ARTのまま。65 Rank9実効powerは570、69 Rank9は455。master・`job_arts.json`・継承・対象外職・依存flag不成立はlegacyへfail closedし、PR22時点のproduction対応jobは24/53/61/62/64/65/69/85の8職
- 67変成と68崩しはPR23、60反撃と66守護はPR24でproduction接続済み。場術は53/85のvertical sliceを正本とし、current job 63は未展開

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
- DB: MariaDB 10.5.13 (production on Xserver; Six Heroes release baseline verified on the same version)
- Auth: Google OAuth, 1 account = 1 character。ゲストプレイ中は共通ヘッダの案内から同じユーザーIDへGoogle連携でき、進行データを引き継げる。冒険者タブの「設定」→「情報確認」では、Google連携とメールアドレス・パスワード登録を別々に判定し、両方利用可能な状態やゲストプレイも確認できる。トップページでは、β版の冒険データを正式版へ引き継ぐ方針を明記する
- Payment: Stripe (輝石 purchase; paid/free tracked separately)。ゲストは購入不可で、Google連携またはメールアドレス・パスワード登録済みアカウントだけが購入できる
- Tests: PHPUnit via `php artisan test`
- Deploy: `php local_deploy.php` / `server_deploy_api.php` は移行期間のフォールバックとして残す。GitHubホステッドRunnerからXserverへの直接SSHは接続拒否を確認済み。標準経路はGitHub側でビルドし、このPCのリポジトリ専用WindowsセルフホストRunnerがSSH転送と原子的切替だけを行う構成で、ステージング・本番とも原子的リリースと公開確認に成功済み。手順は `docs/GITHUB_ACTIONS_DEPLOY.md` を正とする。

## Product summary

ヴァルゼリアの冒険者 is a browser fantasy RPG recreating the feel of classic CGI-game FFA.
Core loop: login → explore/battle → EXP·Gold → level up → equip → job change → unlock next city → climb rankings.
Level cap: Lv255. Player-facing stat labels: HP / SP / 攻撃 / 防御 / 魔力 / 精神 / 敏捷 / 運 (old internal names like mp/str/agi and ATK/DEF/MAG/SPR/SPD/LUK remain internal only).
Primary currencies: Gold (in-game), 輝石 (paid/support currency).
Gold支払い対応施設では手持ちを先に使い、不足分だけ確認付きで銀行預金から支払う。対象は装備屋、素材交換所、装備強化・進化合成・銘/特攻/耐性加工、素材/装備市場、素材倉庫Gold拡張、薬屋、地図院遠征調査、探索地図/追加ダンジョン入場、星灯の行商人。
Main player entities: character (1 per user), jobs (rank ★1-10), equipment (with 銘 affixes), materials, valmon (companion), monster marks, arena rankings, tavern NPCs.
World: 10 cities (アークレア→…→ヴァルゼリア城), 40+ dungeons (area 1-70 normal, 71-74 special, 75-83 街道).

## Current feature map

See docs/FEATURE_STATUS.md (single source for feature status; do not duplicate the table here).

## Architecture notes

- 戦技セットの通常・ボス・PvP各セットには、runtimeと同じ有効資源判定による「有効な系譜」badgeを表示し、枠交換後は非同期で更新する。公式プリセット30件への導線は`battle.job_art_v2.official_preset_highlight_until`まで強調する。資源獲得の計算時点は変えず、被物理・受け流し・実軽減はダメージ表示後、HP代償は代償表示後、浄化成功は浄化表示後に資源ログを出す。チャンプ戦は遅延ログだけでなく通常の戦技資源ログも行動単位で外部ログへ取り込み、通常・ボス・塔・PvP・チャンプ・NPCランク戦の全経路で原因となる出来事の後へそろえる。

- ホームの「次やること」にある装備進化案内は、装備中アイテムに一致する進化レシピだけを候補化する。合成屋の全候補一覧は従来どおり全レシピを対象とし、ホーム初期表示では全レシピ走査を行わない。素材の実効ドロップ率計算に使う敵別ドロップ一覧は同一リクエスト内で一括取得し、候補ごとの重複DB問い合わせを行わない。
- ホーム初期表示では `HomeActionPanel`・`LeftSidebar`・`ChampCard`・`ChatLog` を初期HTMLへ直接描画し、別Livewireリクエストの処理順を待たずに表示する。`ChatLog` はバックグラウンドタブでも60秒間隔の更新を維持し、表示対象ログのID・更新日時・本文が変わらない間は一覧の再取得・再描画を省略する。週間番付は30分ごとに先回り更新する取得時刻付きキャッシュを初期HTMLへ直接描画し、右上の更新ボタンでは全体10秒制限付きで最新集計へ更新できる。闘技場番付だけを表示後に取得する。期限超過時も最大6時間は直前表示を返しながらレスポンス後に更新する。各カードの配置順は維持する。共通ヘッダーと左サイドバーは、その表示リクエストで先に取得した現在職・職業履歴・装備中アイテムを能力計算にも再利用し、補給状況は対象3品を一括集計する。チャットの全体タブは保存済み表示条件と個人宛除外をSQLへ適用してから最新50件だけを取得する。`SchemaStateService` は同一リクエスト内のテーブル・カラム存在確認だけをメモ化し、探索・戦闘可否などのプレイヤー状態はキャッシュしない。
- Routing: routes/web.php; screens are Livewire components + Blade views
- 冒険者カードは `AdventurerCardModal` の軽量Livewireインスタンスが表示イベントを受け、共通 `CityHeader` の能力値・通知・街情報を再描画しない。カードの表示・閉じる・職業階層取得は renderless action とし、既にある巨大なカードHTMLを応答ごとに再送・再描画せず、状態データだけを同期する。クリック直後はクライアント側で読み込み表示を開き、職業バッジは圧縮した配列で受け取り、選択された階層だけ小さなLivewire更新で詳細データ・DOM・画像へ展開する。
- Server pattern: thin Controllers, logic in app/Services/* (BattleService, ExplorationService, etc.)
- PWA Web Pushは、冒険者タブの設定から専用「スマホ通知」画面を開き、PWA導入手順・端末状態・通知種類を確認できる。種類はキャラクター単位、購読ON/OFFは端末単位で保存し、種類をOFFにしてもゲーム内の通知ベルは消さない。毎分の `web-push:dispatch` は時間経過で探索力がMAXになった対象者へ重複しないベル通知を作り、選択済みの新着だけを端末へ送る。端末情報は `web_push_subscriptions` へ暗号化保存する。`WEB_PUSH_MODE=off` が既定で、`allowlist` または `all` と完全なVAPID鍵が揃った時だけ購読APIと送信を開く。本番は2026-08-10に `all` へ切り替え、対応PWA端末を使う全冒険者へ公開した。端末文面は `WEB_PUSH_PREVIEW_MODE=generic` が既定で、`title` の時だけHTMLと余分な空白を除いた通知ベルのタイトルを最大60文字で表示し、通知ベル本文は送らない。管理者向けの不具合報告・新着メール・キャラ画像作成依頼は、本番ではヴァルの `character_id=5` に固定し、所有ユーザーが `admin` の時だけ通知する。本番では環境変数による別キャラクターへの上書きを許可しない。staging/local/testingの既定通知先は0で無効だが、検証時だけ `ADMIN_WEB_PUSH_CHARACTER_ID` で指定できる。未設定・不一致・非管理者では通知を作らず、通常プレイヤーの配信処理からも管理者通知種別を除外する。管理者通知と選択済み通常通知が同じ配信待ち区間にある場合は、両方の新着があることを1件の汎用文面で知らせる。POP3メールは管理画面を開いていない時も5分ごとの `contact-mail:import` で取り込む
- State management: Livewire component state + DB; no SPA framework
- アプリ起動時にはmigration・Seeder・プレイヤーデータ修復・公開リンク変更を行わない。街／職業EXPマスタ、職業履歴の整合性、必須カラムはリリース準備チェックで検証し、異常時はデプロイを停止する。
- 通常のPOSTフォームは `resources/js/app.js` が送信ボタンを処理中表示へ切り替えて再押下を止める。連続操作が必要な例外だけ `data-submit-lock="off"` で除外する。GETフォームは `data-submit-lock`、ページ遷移するボタン型リンクは `data-navigation-lock` を明示した箇所で同じスピナーを使う。Livewireは対象アクションの `wire:loading` で完了まで再押下を止める。全ボタンには `resources/css/app.css` の短い押下変形がある。
- メイン画面内のタブ導線は、`MainScreenShell` が現在タブと探索退出処理を管理する。街・探索・冒険者・市場・闘技場の重い `MainScreen` は初回選択時にだけ読み込み、その後はDOMへ保持してAlpineで即時切替する。MAP・設定・メッセージも必要時だけ読み込む。保持中のパネルは60秒経過後の再表示時にバックグラウンド更新し、探索退出時は探索パネルを即時無効化する。
- ホームの週間番付は新規表示では`AdventurerCardModal`へ直接イベントを送り、公開前から開かれている旧画面の`wire:click`だけは`StarTreeTowerRankingWidget::openWeeklyWinPlayerModal()`が互換処理として受ける。
- 闘技場タブは `ArenaNpcRankingService::screenEntries()` でTOP6と挑戦候補3件だけを取得し、上位6名を1位／2〜3位／4〜6位の3段配置でキャラ画像中心に表示する。画面表示時に全ランキングの戦力を組み立てず、同一リクエスト内の順位整合性確認と倉庫集計も各1回にまとめる。独立したTOP100番付は `lightweightRankingEntries()` で順位・画像・名前・Lvだけを取得し、職業・装備・印を使う戦力計算は行わない。4ポーズ対応キャラの画像は各閲覧者がページ内だけで順送りでき、再読み込みすると本人が保存した展示ポーズへ戻る。プレイヤーの詳細は名前を押した後に既存の冒険者カード、NPCの詳細はNPCモーダルで取得する。
- DB access: Eloquent models (snake_case columns); DTO/BattleActor use camelCase
- Auth/session: Google OAuth login, guest session is linkable to Google from the shared header, character via Auth::user()->characters()->first()
- Logging: battle_logs, player_lifecycle_events, gold_transactions, kiseki_transactions, admin_item_grant_logs, public_logs (bottom chat), admin analytics screens
- Admin job-art meta analytics: `/admin/job-art-analytics` reads the current `character_job_art_slots` snapshot for normal/boss/PvP and excludes admin/tester characters. It supports active-window/current-job/player-level filters, availability-based art adoption, ordered loadouts, co-selected pairs, per-player SP policy/loadout rows, and CSV export. It does not retain loadout history or structured cast telemetry, so adoption and lifetime wins must not be presented as causal balance proof.
- Battle: server-side auto turn-based; PRG pattern (redirect after POST); 3s cooldown via last_battle_at
- Player equipment performance: weapon, armor, and accessory fixed stats and legacy stored affix stats are migration-scaled once to version 2 (×8), except HP is corrected to ×4. Dynamically calculated engraving HP also uses the ×4 scale (rounding up), while its other stats remain ×8. Weapon STR/MAG use `round(B × (0.80 + W / 2400))`; armor DEF/SPR use `B + max(floor(W / 8), round(B × W / 2400))`, so they never fall below the pre-scale direct addition. Accessories add their fixed values directly; their non-HP enhancement result is kept at exactly ×8.
- Equipment proficiency penalty: production enables `EQUIPMENT_PROFICIENCY_PENALTY_ENABLED`, so all jobs may equip weapons and armor. Non-proficient weapons apply a category rate to base performance, + enhancement, engravings, and species killer damage (拳甲・刀85%、弓・斧・短剣75%、槍70%、剣・銃・杖・魔導具65%). Non-proficient armor applies `EQUIPMENT_NON_PROFICIENT_EFFECT_RATE` (65%) to base performance, + enhancement, engravings, and species resistance.

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

- 武器種「刀」はG〜Aの基本7段階から、聖剣・魔剣・迅刃の3系統へS〜EPICまで分岐する19武器として実装済み。王家の呪霊・星見天文ゴーレム・黒騎士・魔神の化身には各0.03%のS固有武器を追加し、それぞれ専用一本道でEPICまで進化する。刀19種と追加固有武器16種には専用画像がある。

詳細は docs/AI_CONTEXT_ARCHIVE.md（2026-07-02移設・全50項目）。恒久ルールは docs/DOMAIN_RULES.md が正。
該当機能に触るタスクではアーカイブの該当項目を検索して読むこと。ここには「隠し/停止中フラグ」と「最近の要点」だけを残す。

Hidden / disabled（承認なしに有効化しない）:
- エネミー図鑑は `/enemy-book` で常時利用できる。未発見の敵は名前・姿・詳細を伏せ、遭遇済み未討伐は名前と姿だけ、討伐済みは能力・ドロップを表示する。素材ドロップはアイテム図鑑の該当素材へ移動でき、装備ドロップはリンクしない。同じエリア・名前・ボス区分の重複敵IDは1体へまとめて履歴を合算し、過去戦闘ログがない攻略済みボスはエリア攻略記録から討伐済みへ補完する。通常探索・ボス・亜域・探索の地図の実戦を `BattleLogService` から記録し、既存戦闘ログは初期migrationで復元する。元敵を借りる特殊イベントは除外する。
- 英雄試練は追加コンテンツキー `hero_trials` の既定値をOFFとして先行配置する。OFF中は英雄試練殿を探索一覧へ出さず、試練殿・挑戦・結果の直URLと神殿の対応英雄職も閉じる。管理画面でONかつ期間内にした場合だけ公開する。試練殿には全10試練をネタバレなしのカードで並べ、実装済みの試練だけ挑戦導線を有効にする
- 高位職ID60〜99は未公開（ID44〜49の新上級職は公開済み。超級職ID50〜59は条件達成者にのみ神殿表示。ID39〜43は職業IDとして欠番）
- 冒険者支援パス30日は `SUPPORT_PASS_ENABLED=false` で非公開（管理画面からON可）。購入時は即時発動せず30日利用券が所持品に入り、使用時に初めて発動/延長する。公開時は補給商会で、100輝石・1キャラクター1回限りの「冒険者旅立ちセット」も販売し、支援パス30日利用券、探索力の薬3個、素材/装備倉庫拡張、限定カードフレームを一括付与する。未購入かつ鍛冶街グランベルグ到達前（`highest_city_id < 4`）の冒険者には、街ヘッダ直下でセットへの案内を表示する
- フェルディア地方は `FERDIA_REGION_ENABLED=false` 既定で非公開（管理画面の追加コンテンツ設定からON可）
- 素材交換所では、薬草の若葉5個とアークレアの粗素材2個から薬草1個、または世界樹の葉片1個と妖精粉3個から薬草2個を調合できる。獣牙3個と魔物の欠片2個から回復薬1個、魔鉱片3個と魔物の欠片2個から魔力水1個も調合できる。毎日10個の無料補給は維持する
- 冒険者協会の寄付・ランク別救助費軽減は停止中（DOMAIN_RULES参照）
- S→SS装飾品進化は未実装表示。古代片は敵ドロップに加え、フェルディア13探索地の輝く宝箱から1宝箱1.0%で1個追加抽選する
- 薬屋の探索補助品16種はすべて1回の調合で1個完成する。フェルディアの輝く宝箱は通常素材2〜4個のうち1枠を地域代表素材、残りを地域内の通常素材とし、古代片1.0%は別枠で維持する

Recent key points:
- 亜域探索では薬草・回復薬・魔力水を所持分から各10個まで持ち込み、通常探索と同じ戦闘結果欄から使用できる。街から記録済み入口へ直接入った場合も、サーバー側の亜域戦闘結果にある探索元エリアを持込文脈として使い、クライアント指定のエリアIDは信用しない。
- 装備図鑑は冒険者タブの「記録」から利用でき、武器の発見記録と進化系譜を表示する。所持中または過去に進化した武器を永続記録し、売却・進化後も発見済み状態を保持する。装備詳細は画面中央のポップアップで大きな武器画像と性能を表示する。防具図鑑は専用画像が揃うまで準備中として選択できない。
- 管理画面の実戦闘シミュレーションでは、選択した冒険者がマスター済みの冠位職だけを仮想職業として指定できる。現在のLv・基礎能力・装備を維持して職業効果だけを切り替え、戦闘中の一時変更と通常報酬は既存どおりtransaction rollbackで保存しない。実転職時のLv1化・基礎能力圧縮を再現する機能ではない。
- キャラアイコン制作の確認画面では、申請時の参考画像を最大4枚添付できる。画像は輝石消費前に検証し、提出後の非公開チャットに最初のプレイヤーメッセージとして保存する。
- 冒険者カード上部はキャラクター直下へ枠なしの所属を表示し、闘技場順位・冒険回数・冒険日数は表示しない。一言コメントは所属・HP/SPの直後へ繰り上げる。下部の「冒険の記録」は初期状態で閉じ、カードを開くリクエストでは集計しない。利用者が記録を最初に開いた時だけ専用 `AdventurerCardModal` が10分キャッシュ経由で戦闘・収集・育成記録を取得し、同じカードを開いている間は再取得しない。戦闘回数は実戦の勝利数+敗北数とし、旧形式の `victory` / `defeat` / `timeout` も読み替え、`turn_count=0` の非戦闘イベントは除外する。宝箱・秘境入口・亜域入口・ダンジョン主との遭遇では `characters.wins` も増やさない
- 街のお知らせは街ヘッダの街背景画像内で、街名・現在の冒険者数と連続した3行構成で表示する。管理画面で公開した先頭3件を `[1/3]` 形式で32秒かけて流し、`[3/3]` の後は切れ目なく `[1/3]` へ戻る。📢横の「一覧」からゲーム内の更新履歴モーダルを開く。公開中の項目がない時は従来の「「ヴァルゼリアの冒険者」β版稼働中！」だけを表示する。管理画面では表示中のお知らせを下書きと別枠にし、新しく「表示する」を押した項目を先頭へ追加したうえで、上下ボタンにより公開順を1件ずつ変更する。公開順は公開日より優先され、公開日は表示開始日の判定に使う。管理用更新サマリのプレイヤー向け最新50件は、`TownUpdateService` が非公開下書きとして自動取り込みする。管理者が編集した文言は再同期で上書きしない。不要な候補は完全削除し、削除済みキーを最大50件保持して再生成を防ぐ。
- キャラアイコン制作は追加コンテンツ管理上の既定値をONとし、冒険者メニューの「案内」から全冒険者がヒアリングシートを開いて自動下書き保存できる。全冒険者が確認画面へ進み、依頼ごとに40輝石（無償優先）で制作申請できる。提出後は管理人との非公開チャットで候補画像を調整でき、制作完了前は提出済み回答を追加消費なしで修正できる。回答修正は管理画面の依頼一覧へ未確認の「回答更新あり」として表示する。限定アイコンは1セット1キャラクターへ付与し、闘技場の共有展示ポーズとチャンプ本人限定のチャンプ画面内4ポーズ切替に対応する。他の閲覧者のチャンプカードには通常ポーズだけを表示する。限定4画像は正方形の96pxまたは128pxに対応し、新規制作分は128pxを推奨する（表示枠のCSSサイズは変えない）。
- 通常選択アイコンは `public/images/chara/poses/chara_NNN/` に通常・戦闘・勝利・敗北の画像がある場合、通常表示と戦闘結果で場面別に自動解決する。不足画像は通常ポーズ、通常ポーズもなければ従来の `public/images/chara/chara_NNN.webp` へフォールバックする。
- 敵固有ドロップの武器18種は、それぞれEPICまで既存武器へ合流しない専用一本道で進化する。通常14種は開始ランクF〜B、フェルディア4種はヒル・ホーク、ルイン・ギア、聖城の光霊、スノー・インプから0.03%で落ちる開始ランクS武器とする。進化先112武器は元武器の能力配分を武器ランク倍率で伸ばし、同武器種の現行進化素材を使う。全段階で個別の武器画像を表示し、フェルディア4種は武器マスタに固定された12%の飛行・機械・竜・悪魔特攻を進化後も保持する。`DROP_WPN_*`はランクを持っても`DropService`の汎用抽選から明示除外し、敵別`enemy_drops`だけで入手する。元武器の銘・個体特攻・品質・強化値は既存の進化処理どおり引き継ぐ。
- 武器マスタの固有特攻は通常の個体特攻と併存し、同じ種族なら加算してPvE直接ダメージへ1回適用する。合計上限は55%。固有特攻だけを持つ武器も装備市場へ出品できるが、ランダム特性の査定額には加えない。
- Lv40以上の相棒ヴァルモンは、牧場で個体ごとに絆技の発動スタイルと掛け声を選ぶ。均衡は5%・通常攻撃相当30%、Lv60の速攻は6%・25%、Lv80の豪撃は3%・50%。追撃ダメージは各スタイルの表示威力を基準に70〜130%で変動し、掛け声4種は性能差なし。通常・亜域・地図・ボスの対モンスター戦で最初のプレイヤー行動後に1戦1回だけ抽選し、初撃撃破時も勝敗確定前に専用技名・掛け声・情景文と「相棒による追撃」の表示を出す。PvP・チャンプ戦・星樹の塔は対象外。
- 新規キャラクターは1,000Gで開始する。`characters.wins + characters.losses` で数える累計100戦目までは、通常探索・10回探索・探索の地図で敗北しても、所持Gold・探索中の戦利品・ヴァルモンの卵を失わない。保護中は救助アイテムを消費せず、戦闘結果に「駆け出しの加護」を表示する。101戦目以降は既存の敗北ロストへ戻る。
- 生成バージョン6以降の新規探索地図は25%で単一種族地図を抽選する。特攻・耐性が設定された12種から、同じ地図Lv・戦力帯で異なる元モンスターを4体以上確保できる種族だけを選び、候補不足時は従来の混成地図にする。単一種族地図では「周辺の様子」を種族固有のフレーバーテキストへ置き換え、生成バージョン5以前の既存地図には遡及しない。
- 探索の地図は通常探索・通常ボス勝利で低確率に入手する個別コンテンツ。地図院で即時調査・公開し、新規公開地図は12時間または探索回数0まで共有探索できる。公開中は発見者ごとに3件までで、地図院一覧と公開前の詳細で現在の公開枠を確認できる。未調査・調査済み地図は破棄でき（調査費は返金しない）、公開中の本人地図は詳細から取り下げてすぐに枠を空けられる。取り下げ後は新規入場・再公開を止め、すでに開始された探索と確定済みの収益履歴は保持する。終了・取り下げ地図は6時間だけ終了印付きで表示する。入場料は街から入るたび1回だけで、入場中の×1/×10探索に追加料金はない。戦闘結果ではヘッダーの地図名と、枠なしの残り地図探索回数を確認できる。敵Lv・危険度・目安戦力・報酬傾向を確認でき、探索地種別に対応した背景画像をカードへ表示する。地図内で敗北した場合も通常探索と同じく、所持Goldの10%と入場後に得た素材・未装備／未保護の装備の50%を失い、探索中のヴァルモンの卵も失う。×10探索は最初の敗北または時間切れ敗北で停止してロストを1回だけ適用し、探索力が10未満なら残量分だけ実行して未実行の地図回数を返却する。探索予約は公開地図行ロックとリクエストUUIDで重複を防ぎ、地図情報の読込失敗を含む実行前エラーでは回数・料金・持ち込み状態を同じトランザクションで取り消す。5分以上未実行の予約は次回予約時に未消費回数と未実行時の入場料を回収する。新規地図の報酬傾向は8種類で、既存7傾向を各13%、古代片傾向を9%で抽選する。古代片傾向は通常敵Lv142以上となり、有効な古代片7種から地図ごとに固定した1種を表示して勝利ごとに0.38%で追加抽選する。既存の通常報酬フォールバック地図も通常敵Lv142以上なら同じ固定古代片抽選を行い、Lv141以下は報酬傾向を表示しない。地図を見つけた場合は通常探索・10回探索とも戦闘結果の獲得報酬に表示する。地図内では通常戦と共通の戦闘結果・ヴァルモン卵抽選を使うが、モンスター印は落とさない。管理者は `/admin/published-maps` で現在入場可能な地図の発見者・公開条件・敵・報酬詳細を確認できる。地図院は7月25日23:59まで街の施設一覧上部にPickup表示する。
- 公開地図の入場状態はDBの持ち込み記録から復元する。ブラウザを閉じた後もホームではなく地図院へ戻して同じ地図を追加料金なしで再開し、正式に探索を切り上げるまでは別地図への入場と、回復・探索継続以外の更新操作を止めて素材・装備などの探索中戦利品を保護する。
- 探索の地図の新規生成時の探索可能回数は、通常300〜600回・希少600〜900回・英雄900〜1,200回・伝説1,200〜1,500回。修練の導きは通常・希少が約1/3、英雄が2/7（約260〜340回）、伝説が1/4（300〜380回）となり、Job EXPは順に最大6/7/8。生成バージョン5以降の英雄・伝説地図は等級別に報酬傾向を強化し、勝利ごとに英雄0.15%・伝説0.35%の限定報酬枠も独立抽選する。生成バージョン4以前の地図には遡及しない。通常敵・精鋭敵・通常ボス・地図内の敵からの地図ドロップ率は基礎0.5%に統一し、実装記念ボーナスは終了した。地図内の敵は地図用の名称で表示しつつ、戦闘結果では選ばれた元モンスターの画像を表示する。
- PvEの敵→プレイヤー直接攻撃は、割合軽減式を既定で有効にしている。物理は `敵ATK² ÷ (敵ATK + 3.5 × プレイヤーDEF)`、魔法は `敵MAG² ÷ (敵MAG + 3.5 × プレイヤーSPR)`。会心は先にDEF/SPRを半減してから既存の1.5倍補正を掛ける。通常・強敵・レア・ボス・秘境・隠しボス・星樹の塔・敵技の直接攻撃が対象で、プレイヤー側攻撃、継続/反射/固定/割合ダメージ、PvP・ランク戦・チャンプ戦は対象外。環境変数を `false` にすると従来の減算式へ戻せる
- 追加ダンジョンは `region_depth_dungeons` マスタと `RegionDepthDungeonService` により管理する。街の探索一覧ではストーリーの下に暗色カードで表示し、黒炉深坑は危険度・連戦数・最高到達記録を通常探索の深度と分離して保持する。追加ダンジョンではモンスター印を抽選しない。
- 管理画面の `/admin/security-anomalies` は、10分で5,000戦以上の大量戦闘、Gold/輝石異常、通常報酬で3超・探索地図で8超のJob EXP、同一IP大量アカウント、装備・素材急増、管理者付与後の高額取引を5分ごとにルール検知する。案件は検知・確認中・問題なし・措置済みで管理し、状態変更者・日時・メモを履歴化する。ログインIPは平文保存せず、HMACハッシュとマスク表示だけを使い、元になるログイン観測レコードを90日保持する。観測は認証時と通常ページ表示時に行い、Livewireのタブ切り替え・pollingはログインとして重複記録しない。検知からの自動停止・自動回収は行わない。
- ユーザー個別調査は、初期表示で `player_lifecycle_events` の計測開始後ログインを最終記録日順に最大60件、冒険者アイコンとキャラクター名を大きく表示する3列カードで表示し、選択後に従来の個別調査を開く。アカウント表示名（`users.name`）はカードに出さない。カードからは対象キャラクターを選んだ状態の輝石付与・プレイヤー調整、送信・受信を含む対象者だけの公開ログ管理へ移動できる。選択した冒険者へは、不具合報告の有無にかかわらず管理人名義の個別メッセージを送信でき、同じ画面で返信履歴を確認する。ログインは日ごとに重複を抑止して記録するため、同一日内の再ログイン時刻までは区別しない。
- 管理画面共通ヘッダは、管理人スレッドで冒険者の返信が最新になっている人数を1分ごとに確認し、ログアウト横の通知ベルとユーザー調査メニューへ未対応件数を表示する。ユーザー調査メニューには新しい順に最大3名の冒険者名も表示し、4名以上は残数をまとめる。ベルを押すと新しい順に最大3件の冒険者名・返信内容・時刻をポップアップ表示し、各返信からユーザー個別調査へ移動できる。管理人が同じスレッドへ送信するか、ベル内のゲーム内確認モーダルから会話履歴を残したまま「対応済みにする」と未対応から外れる。対応済み後に冒険者が再返信した場合は再び未対応になり、問い合わせメール件数との合計は既存のfavicon・画面タイトルにも表示する。
- 地下の謎の穴は開拓度100でLv180の「深淵門の番人ヴェイルガード」に挑戦できる。火傷・回復阻害・予兆つき強攻撃を使い、撃破で同地点を踏破扱いにするが、アビスヴェイル本体は未実装で解放しない。
- 「市場・依頼」タブには素材市場・装備市場・調達依頼を別カードで表示する。素材市場は出品時の手数料がなく、冒険者出品が成立した時にだけ販売額の5%をGold sinkとして回収する。素材市場とは別に、装備市場では銘・個体特攻・武器固有特攻のいずれかを持つ武器と、銘または耐性付き防具を個体単位で売買する（2026-07-13裁定：匿名売買を廃止し出品者名を表示）。通常武器・通常防具・装飾品は対象外。`EquipmentMarketService` が行ロックとGoldService経由の支払い・受取を管理し、販売額の10%をGold sinkとして回収する。出品は査定額の50〜250%、72時間で期限切れ、購入後は72時間再出品できず、進化後にも制限を引き継ぐ。出品中の装備は装備・売却・強化・進化・ヴァルモンの餌消費ができず、餌候補にも表示しない。査定v2は装備本体へだけ品質・強化倍率を掛け、ランダム特性はI〜Vを5,000/25,000/150,000/450,000/1,200,000Gで評価し、武器固有特攻は表示するが査定額には加えない。2特性なら高い方100%・低い方60%で加算する。出品時に本体/特性/総額と査定バージョンを保存し、既存出品の価格・旧査定は変更しない。装備市場の出品・購入画面では、ランク/装備種/強化値・出品者名を日本語ラベルで表示し、装備の基本性能・銘・種族特攻/耐性の効果はカード分けせず色分けバッジで一覧表示する。出品価格は査定範囲内で変更できることを入力欄の近くに明記する。
- 銘・武器の種族特攻・防具の種族耐性は段階I〜Vを持つ。品質倍率は通常品1.00/良品1.15/逸品1.35、銘の基礎性能補正はI〜Vで8/16/24/32/40%、武器の種族特攻は6/12/18/24/30%、防具の種族耐性は5/10/15/20/25%に品質倍率を掛ける。耐性は最大35%まで、対応種族から受けるPvE直接ダメージの最終値を軽減する。銘は強化後の装備の最も高い基礎能力値を基準にし、生命の銘は算出値の3倍をHPへ加える。全能力を上げる調律の銘は、各能力の算出値を単能力銘の55%とし、防具ではHPを6倍、武器では3倍へ加える。鍛冶屋の統合画面は武器・防具の銘と、武器の特攻／防具の耐性を鍛錬・移しできる。鍛錬は武器同士または防具同士に限り、同じ装備種・同じ特性・同じ段階なら段階を上げ、それ以外は対象特性だけを移す。
- 敵種族は `enemies.species_key` の12種を特攻・耐性・誘魔香・戦闘結果表示の正とし、`family_key` は敵能力値生成用の系統として分離する。既存敵の明示割り当てと日本語表示名は `config/enemy_species.php` で管理し、未分類値を種族「通常」として表示しない。
- 装備強化はランク上限制（G〜E=+10、D〜B=+15、A=+20、S=+25、SS〜EPIC=+30）。武器・防具は+5まで各3%、以降は2%/1.5%/1.2%/1%/0.8%へ逓減して+30で合計47.5%上昇する。装飾品は元の非ゼロ能力値比率を保つ総量配分制で、単能力型のSS/SSS/EPICは+30時の正の能力値合計を200/300/400にする。ATK・DEF・SPD・MAG・SPR・LUKをすべて持つ全能力型は、単能力型目標の半分を各能力へ配分し、+30時に各100/150/200にする。G〜Sは既存の追加値曲線を維持し、Sの上限+25では合計+25を加算する。進化では選択した進化元個体の+値を進化先上限まで引き継ぐ。+6以降は石・高純度石・街素材・精錬核を段階的に消費し、成功率は100%。SSSのGoldは+1〜+10で30万G、+11〜+20で120万G、+21〜+30で350万G、合計500万Gの専用段階表を使う。市場の装備本体査定も+30まで同じ性能倍率を反映する。鍛冶屋の候補は銘・特攻・必要素材を一括取得し、種別ごとに20件ずつ段階表示する。
- 職業階層は8層（normal〜myth、EXP倍率1/2/5/8/10/15/22/30、転職引き継ぎ1/2・2/5・1/3）。北境の霊峰エルヴァンの最終ボスを倒すと冠位の証が撃破記録へ刻まれ、冠位職が神殿に表示される。Lv30以上かつ未使用BPなしなら超級職のマスターを問わず転職できる。冠位の証は素材・所持品・素材報酬として扱わない。ヴァルゼリア大陸の敵マスタは職業EXPを0〜3に収め、4以上を設定しない。職業EXPは各敵・モードの報酬設定または通常戦のLv差計算に従い、深度・亜域などの補正後も1回の報酬処理で最大3に制限する
- 武器・防具・装飾品の強化素材は到達強化値で固定し、ランク・現在地・`unlock_city_id`に依存しない。都市素材は強化値帯ごとに、+11〜+13は氷晶石/氷帝晶、+14〜+16は砂金石/砂王金晶、+17〜+20は魔導結晶/ルミナス魔晶、+21〜+23は瘴気の骨片/深魔骨核、+24〜+26は天空石/セレスティア星晶、+27〜+30は魔王城の黒晶/ヴァルゼリア黒核を使う。武器は強化石系、防具は同数の守護石系、装飾品は同数の調律石系を使い、都市素材・魔物の魔核・精錬核は共通にする。武器・防具・装飾品のGoldは+nに対して n×n×300G（+1=300G、+10=30,000G、+30=270,000G）。
- 星樹の塔は1〜100階マスタ、塔戦闘、戦闘後に通常探索に近い結果画面から次階へ進む導線、次階前の行動選択、50階以降5階ごとの挑戦中累積「星樹の構え」、一時中断/再開、軽量EXP/Job EXP報酬、行商人、行商人購入アイテムの塔内使用/次戦闘自動護符、ランキング、50/60/70/80/90/100階の公開ログ、10階刻み到達称号、50/70/90階初回到達の選択式武器宝箱、50階初回到達の冒険者カード背景自動付与（背景獲得の公開ログなし）、100階初回到達の冒険者カード装飾枠、エルフィアのダンジョン一覧からの導線、管理画面でのON/OFFと開催期間設定まで実装済み。ただし `STAR_TREE_TOWER_ENABLED=false` 既定で、管理設定がONかつ開催期間内の時だけダンジョン一覧に表示。汎用Gold/ランダムドロップ報酬は未実装
- フェルディア地方は `config/ferdia_world_map.php` と `FerdiaMapService` で本線13探索地・公開物語分岐4地点・アビス前段1地点＋3街のMAP状態を管理し、`extra_content.enabled.ferdia_unlocked` がONかつ期間内の時だけ街移動画面にタブ表示する。探索地は既存 `areas` / `character_area_progresses.development_point` を使い、フェルディアだけ勝利時開拓度が1〜2上がる。見晴らしの丘道・グランフォード外郭路・水門街道は開拓度150後の関門ボス撃破で次の街を解放し、北境の霊峰エルヴァンには最終ボスを置く。星詠みの廃塔・瀑布神殿アクエリス・風化列柱都市オルド・白潮灯台は本線の到達条件で必ず公開され、4地点すべての開拓度を最大にすると地下の謎の穴が恒久解放される。街はルヴァン、グランフォード、アーヴェンの3つを既存Cityとして登録し、街滞在時は通常の街施設を表示する。MAP上の探索地ボタンから探索タブへ移った場合だけ、街タブは「フェルディア簡易拠点」として主要施設を表示する。薬屋では50戦有効・同時1種の探索補助品を作れ、既存4種は1調合3個、冒険開始直後から調合できる誘魔香12種は1調合1個とする。誘魔香は通常探索で指定種族の出現重みを3倍にする。`ExplorationSupportService` は品目ごとの残数を保存するため、切り替え・解除後も再装備時に続きから使える。戦闘結果ではHP/SP直下に回復アイテム、その下に現在のもちものを表示し、下部モーダルで探索補助品を変更できる。使用中に別品へ切り替える場合は、残り戦数の保存と予備消費の有無を確認してから実行する。探索補助品は独立した追加コンテンツ `exploration_support` を既定ONとし、新規冒険者も最初の街から薬屋・もちものを利用できる。運営設定でOFFにした場合はデータを残したまま効果を凍結する。
- フェルディア地方の入口は、追加コンテンツが有効かつ期間内で、キャラクターが魔王城ヴァルゼリアの最終探索地 Area 70 のボスを撃破済みの場合だけ開く。未達成時はフェルディアタブと街・探索地への直接アクセスを閉じ、すでにフェルディア内にいる対象キャラクターは魔王城へ戻す。既存のフェルディア進行データは削除しない。
- 通常PvEの敵技は `enemy_actions` マスタで管理する。敵ターンごとに特徴行動を最大1回だけ抽選し、フェルディア敵には火傷・毒・出血・DEF低下・鈍足・回復阻害・現在HP割合・連続・溜め攻撃を設定済み。PvP・チャンプ戦・星樹の塔は対象外で、予兆技の自動防御作戦は将来拡張用のデータだけを保持する。
- 探索力上限は勝利数で250→350(1000勝)→450(2000勝)→500(3000勝)。探索力制はOFF初期・運営切替。探索力制ON時の通常探索と亜域探索は1回/10回のクイック選択または2〜50回の回数指定を利用でき、同じ連戦集計処理で選択回数分の探索力を1戦ずつ消費する。まとめ探索は選択回数分の探索力がそろっている場合だけ開始し、敗北・HP30%以下・特殊イベント・深度入口到達・エラーで途中停止する（特殊イベント・深度入口は通常探索だけ）。回数選択は同じ探索中に保持し、戦利品を持って帰ると1回へ戻す。探索の地図は従来の×1/×10仕様を維持する
- 輝石: 補給商会の小瓶は輝石10・薬は輝石25・各1日10個。補給商会商品は管理画面でキャンペーン価格と開始/終了日時を設定でき、期間中だけ割引価格になる。無償輝石は戦闘勝利0.1%・1日3個。管理画面から有償輝石付与可（監査ログ付き）。管理画面では選択キャラクターへのGold個別送付も理由必須で行え、Gold取引台帳と通知に記録する。通常プレイヤー全員へのお詫び配布では、探索力の小瓶/薬・個数・通知タイトル/本文を指定でき、管理者・検証用キャラクターを除外して監査ログ付きで一括付与する。同じ操作の再送では重複付与しない。2026/06/30以降の新規登録者には探索力の小瓶10個を継続配布し、通知タイトルには配布時点の日本時間の月を表示する。7月登録キャンペーンの初回配布済み冒険者には、対象数が211名と一致する場合だけ、探索力の薬5個を一度だけ追加送付できる
- 番付の総資産は手持ち+銀行。宿屋は直近7日想定利益で参加。番付案内バナーは2026-07-14まで
- 街の番付掲示板は、累計勝利数番付を残したまま週間勝利数増分番付を先頭に表示する。全タブ共通の星樹の塔・闘技場ランキング枠でも「週間勝利」を初期タブとして表示し、本人の勝利数・順位・見込み報酬と上位5名から詳細番付へ進める。上位5名と詳細番付では、冒険者名から既存の冒険者カードを開ける。日本時間の月曜9:00以上〜翌月曜9:00未満（表示上は翌月曜8:59まで）の `battle_logs` にある実戦勝利（通常系の `win` と亜域の既存 `victory`、獲得EXPあり）を集計し、非戦闘イベント・時間切れを除外する。同勝利数は同順位、同率50位は全員入賞とする。月曜9:05に前週分を確定し、失敗時は同時刻の日次実行で冪等に再試行する。1/2/3/4〜10/11〜20/21〜30/31〜50位へ無償輝石20/15/10/8/5/3/2個、51位以下かつ10勝以上へ参加賞1個を自動付与する。参加賞は上位報酬へ加算しない。管理者・検証用は集計外、ゲストは表示のみで、確定時にGoogle連携またはメール登録済みの通常アカウントだけが報酬対象。付与は週・冒険者の一意記録、行ロック、単一transaction、`kiseki_transactions`、通知ベルで冪等化し、上位10位の能力なし名誉表示を翌週の冒険者カードへ出す。初回対象は2026年7月27日9:00開始シーズンで、それ以前の勝利は集計・報酬対象外とする
- 週間勝利数番付の毎日9:05の確定処理は、設定された自動配布開始週から直前終了週までを確認し、season行が未作成の週も含めて未確定週を古い順に冪等回収する
- 2026-08-13運営裁定により、定期実行の自動配布は2026-08-10 9:00開始週以降だけを回収する。2026-07-27・2026-08-03開始週は未確定のまま自動対象外とし、明示的な手動確定経路だけを残す
- 通常探索・ボス・塔・PvP・チャンプ・NPCランク戦で使うプレイヤー技は、各戦闘用にセットした奥義へ統一した。職業固有必殺技は全経路で廃止し、奥義が発動しない手番は通常攻撃へ戻る。旧 `skills.skill_type=special` レコードと `special_skill_rate` カラムは履歴互換用に残すが、神殿・職業管理・技効果検証には現行能力として表示しない。
- 神殿の職業詳細は `JobArtV2CardDescriptionCatalog` の現行正本説明を優先し、正本がある奥義では旧マスタ由来の説明と数値効果ラベルを表示しない。通常職詳細と英雄職の間では、職業ごとの適正武器・適正防具を実データから表示する。
- Job Artのmaster正本は`database/data/job_arts.json`、`skills`の`skill_type=job_art`行はseed/import時点のruntime snapshot。公開済み79職（ID 1〜38・44〜79・95〜99）の237戦技は`JobArtSeeder::runForJobIds()`を使うdata migrationで同期し、既存skill IDを維持する。非公開ID 80〜94は同期対象外。migrationはnatural key `(job_id, learn_rank, skill_type)` の重複を更新前に拒否し、downはplayer参照保護のためno-op。v2の発動率・SP・resource・power等の実効overrideはmasterへ書き戻さない
- チャンプ戦は、画面表示時のチャンプIDと就任時刻を戦闘開始直前に再照合し、交代済みなら戦闘せず最新表示へ戻す。ホームのチャンプカードはタブ復帰時にも更新する。戦闘結果はキャラクター別の一時トークンでも保持し、ホームのLivewire更新と重なってセッション結果が失われても結果画面へ遷移する。各ラウンド開始時に双方の実効敏捷を比較し、高い側、同値なら挑戦者を先行とする。敏捷の時間制効果は次ラウンドから反映し、戦技の次ラウンド先行確定・後攻時再抽選は比較後に適用する。双方の通常行動後、実効敏捷が高い側にはプレイヤーPvPと共通の発動率・保証判定で1ラウンド最大1回の追加行動機会があり、追加行動ではターン数・ラウンド終了処理・クールダウンを進めない。撃破判定は挑戦者の行動でチャンプHPが0になった場合だけ成立し、挑戦者側の反動相打ちは撃破、チャンプ側の反動死は交代なしでHP1を維持する。
- 奥義v2試作はfeature flag既定OFF。`BATTLE_JOB_ART_DYNAMIC_SINGLE=true`でも`JobArtV2PrototypeCatalog`対応40職だけが1ターン1候補・不発後再抽選なしの選択方式を使い、対象外職とflag OFFは既存の複数抽選経路をそのまま使う。9種類のdeterministic slot条件は前方条件不成立時だけ後方slotを評価し、追加RNGを消費しない。さらに`BATTLE_JOB_ART_NORMALIZED_SP=true`の両flag ON時だけ、activeな現在職・継承Rank1/5/9へ発動率50/55/60%、温存40%、共通分母2000の上限制Hybrid SP式（Rank1: `min(最大SP×50, 4000+最大SP×40)`、Rank5: `min(最大SP×80, 6000+最大SP×65)`、Rank9: `min(最大SP×110, 8000+最大SP×90)`、継承は2000・本職だけ2500で除して切上げ、最低1SP）を戦闘と戦技画面で共通使用する
- `BATTLE_JOB_ART_HIT_RESOLUTION=true` はdynamic-singleとの両flag ONかつ現在職がprototype対応40職の時だけ、信頼済み分類のダメージ奥義を共通`ActionResolver`でHIT/MISS/EVADEへ解決する。既定OFFで、非ダメージ・分類不能奥義、対象外職と通常攻撃はlegacy経路を維持する。accuracy未指定の既存奥義はPvE・ボス・塔でlegacyの1Hit命中率、対人3経路でlegacyの基礎必中を再利用し、奥義全体を1回だけ抽選する。65の信頼済み現在職Rank5/9だけは同じ1回の抽選前に+5/+8ptを加えて既存clampを適用する。能動回避providerの本番既定値は0%
- `BATTLE_JOB_ART_DAMAGE_APPLICATION=true` はdynamic-single・hit-resolutionとの3flag ONかつ戦闘参加者にprototype対応40職が含まれる時だけ、通常攻撃・奥義・継続/反動ダメージなどの最終HP減算を共通`DamageApplicationService`へ委譲する。既定OFFで、既存`BattleActor::takeDamage()`のHP・不屈・死亡判定をそのまま再利用し、要求ダメージ、実HP減少、超過ダメージ、致死、発生源、HIT結果、Hit位置を追加の乱数やログなしで返す。MISS/EVADE、非ダメージ奥義、0ダメージは委譲しない
- `BATTLE_JOB_ART_RESOURCES=true` はdynamic-single・hit-resolution・damage-applicationとの4flag ONかつ行動者の現在職がprototype対応40職の時だけ、現在職主系譜の戦闘内0〜12ptリソースを1本だけ有効にする。Rank5は4、Rank9は12消費して戦闘外へ保存しない。同主系譜継承Rank1/5/9はこのresourceへproducer/consumer/finisherとして参加し、現在職Rank9が使用不能な時だけ同主系譜継承Rank9を優先候補化する。異系譜継承はforeign resource、finisher優先、current-job限定特殊効果を持ち込まない。60/61/63/65/66/67/68/69の系譜固有獲得eventは既存正本を維持し、現在職85 Rank5はFIELDS依存不成立または主場なしなら候補外にする
- `BATTLE_JOB_ART_FIELDS=true` はdynamic-single・hit-resolution・damage-application・resourcesとの全flag ONかつ参加者に対応済み場術職が含まれる時だけ有効。`BattleState` にDB非永続の主場1つ・副場1つとactor別の1ラウンドechoを保持し、主場は基本3ラウンド・最大5・1インスタンス1回だけ延長でき、生成/更新ラウンドは減算しない。信頼済み5種は星光（所有者の魔法ダメージ+10%）、旋律（所有者の奥義発動率+3pt）、聖域（所有者のHP回復+10%）、静寂（相手の資源獲得-1pt）、天測（所有者の命中+5pt・資源獲得+1pt）。本番生成は24 Rank1の聖域、46 Rank1の旋律、53/85 Rank1の星光、63 Rank1の固定5種cycle、現在職85 Rank9の1ラウンド旋律副場。53 Rank5は主場を+1、63 Rank5は上書きされた自分の旧場を1ラウンドecho、85 Rank5は主場がある時だけ星印4ptを消費して2ラウンド上書き不可にする。行動開始時スナップショットにより新しい場は同一行動へ自己適用しない
- `BATTLE_JOB_ART_PENETRATION=true` はdynamic-single・hit-resolution・damage-application・resourcesとの全flag ONかつ正式metadataを持つ貫通系現在職32/45/52/62が自職由来の信頼済みRank5/9を使う時だけ有効。既定OFFで、物理DEF低減率は32=30%/50%、45=25%/40%、52=35%/50%、62=35%/50%。HIT後の既存ダメージ計算入力へ適用し、既存値と加算せず最大値だけを採用して絶対上限50%とする。SPR、Actorの永続DEF、障壁・軽減、PvPのminimum/floor/capは変更しない。構えは次項の追加flagで62だけに接続し、Rank5使用済みによるRank9追加威力はv2で採用しない
- `BATTLE_JOB_ART_PENETRATION_STANCE=true` は上記penetrationまでの全依存flag ONかつ現在職62の時だけ有効。既定OFFで、`BattleActor` にDB非永続・非スタック・期限なしの1チャージ構えを保持する。自職Rank1は発動時に付与し、自職Rank5は開始時snapshotに構えがあれば35%貫通へ使用した後、HIT/MISS/EVADEを問わず行動後に再付与する。自職Rank9は開始時snapshotに構えがあれば50%貫通へ使用し、結果を問わず再付与しない。構えなしでもRank5/9は使用できるがv2貫通はなく、構え単独の倍率・命中補正はない。Rank5は既存CT2・回数制限なしの反復可能consumer、Rank9は一戦一度で、過去のRank5使用有無による追加倍率は設けない
- 代表4職（24/53/62/85）の縦切りE2Eを維持しつつ、現在はprototype対応40職が同じ通常/ボス/PvPセット、5枠/Cost9、dynamic-single、normalized SP、HIT解決、共通HP適用、系譜resourceを使う。loadout-v2とdynamic-singleの両flag ONかつ現在職がprototype対応職の場合、現在職自身の登録済みRank1/5/9同士だけはlegacy restriction groupの競合を無視して同じセットへ保存できる。継承・未登録・対象外職・依存flag OFFはlegacy制約を維持する。全flagは既定OFF
- `BATTLE_JOB_ART_LOADOUT_V2=true`かつprototype対応40職では、既存の奥義セット画面をプレイヤー向けに「戦技セット」と表示する。信頼済みmetadataだけを使い、Rank1 producer=`始動`、Rank5 consumer=`展開`、Rank9 finisher=`奥義`、現在職/継承と同系譜/系譜外badge、共通計算済みCost/SP/発動率/power、現在職主系譜resourceと固有効果を5枠へ表示する。9種類のslot条件は折りたたみ設定に保存し、異系譜継承へforeign resourceを表示しない。ホームメニューは管理画面の施設テキスト上書きを使うが、旧正本の「奥義」「最大3つ」が保存済みの場合だけ「戦技セット」「最大5つ」へ正規化し、その他の任意文言は維持する。内部の`JobArt`名・route・DB・マスタは変更せず、flag OFFは従来の「奥義セット」3枠/Cost5を維持する
- `BATTLE_JOB_ART_PRESETS` は既定OFF。LOADOUT_V2もONかつprototype対応40職の場合だけ「マイ戦技プリセット」を表示・操作できる。1キャラクター無料3件で、保存時の現在職と同じ職でのみ通常/ボス/PvPの現在タブへ適用可能。保存する正本は5枠の戦技ID・順番・発動方針・slot条件で、適用時に現行Cost・習得・restrictionを再検証する。戦闘はpreset tableを直接参照しない。冒険者パス・課金枠拡張は未実装
- v2の12pt経済校正では、既存Rank5を維持し、必要な現在職の信頼済みRank9だけを `JobArtV2PowerResolver` で実行時補正する。53=410、60=455、61=585、62=470、64=460、65=570、69=455。当時は66/67/68を固有効果込み比較でmaster 355維持としたが、67だけは2026-08-15の再裁定でmaster 315へ変更し、66/68は355を維持する。63はmaster powerを維持し、行動開始時の主場と本人の実上書き回数に基づく最大1.15倍だけを実行時に一度適用する。戦技セットの固定威力表示と実行時powerは同じResolverを使い、継承・対象外職・依存flag不成立時は既存マスタpowerへfail closedする。24/85と他のlegacy masterは変更せず、新しいpower専用flagは追加しない
- 全v2戦闘依存flagが成立したprototype対応40職では、通常・ボス・塔・PvP・チャンプ・NPC闘技場の戦闘結果に表示専用の「戦技の流れ」を出す。サーバーで戦闘全体を解決した後の最終HUDと折りたたみ履歴で、現在職主系譜resource 0〜12、奥義までの残量、場/echo/構え/HIT/MISS/EVADE/貫通/SP変化を表示する。resource barは常に1本で、同系譜継承の増減も同じbarへ反映し、異系譜resourceは生成しない。日本語ログは解析せず、表示値を戦闘判定へ戻さない。flag OFF・対象外職はHUDを出さず、RNGと勝敗結果を変更しない
