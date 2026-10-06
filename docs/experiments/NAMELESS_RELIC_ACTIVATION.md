# 名もなき工房の有効化設定

2026-10-06の本番配備基準はbd909cf66d9fc25c474c0ade73f141cc60cd1199。本版に含まれる共通倉庫・深度別武具獲得率は、後続の機能OFF配備対象。実際の最新SHAと成功はrelease-sha/Actionsと読戻しで確認する。staging→productionへ同一SHA・migration_mode=noneで配備済み。本番の読取確認ではNAMELESS_RELICS_ENABLED=false、対象5本未適用、工房街0件。コード配備とDB準備完了を区別する。本番の準備・設定変更は、対象操作が明示されたタスクでのみ実施する。

| 設定 | 挙動 |
|---|---|
| `NAMELESS_RELICS_ENABLED=false` または未指定 | 全環境で既定OFF。工房・遺跡・追加表示・能力加算・特殊効果を停止する。 |
| `NAMELESS_RELICS_ENABLED=true` | 全環境で同じ機能設定を使う。対象5本の移行記録・全必要列・型・制約が未完了なら工房を開かない。 |
| 旧 `NAMELESS_RELICS_LOCAL_ENABLED` | 正式キー未指定のlocal/testingだけで互換使用する。production/stagingでは旧キーだけでは有効にならない。 |

武具の成長曲線と種別表示も同じ設定を参照する。能力目標、費用、ドロップ、ランク育成ルールは変更していない。装着済み遺物の売却・所有者変更・素材化の保護、進化先への継承、敗北喪失時の取り外しは、該当schemaが存在すればOFFでも全環境で維持する。

正式公開の準備では、本番用MariaDB隔離環境で以下の5本を順に検証する。無関係な未適用migrationを一括実行しない。

1. `2026_10_03_010000_create_nameless_relic_prototype_tables`
2. `2026_10_03_030000_enable_nameless_equipment_collection`
3. `2026_10_04_060000_add_growth_progress_to_player_relics`
4. `2026_10_05_070000_extend_nameless_equipment_kind_for_accessories`
5. `2026_10_05_080000_add_ordinary_equipment_relic_sockets`

2026-10-06の整合性修正は本番OFF配備済み。実行時と準備確認は共通の読取専用 `NamelessSchemaService` を使う。必要列の正本は同クラスの `COLUMNS` とし、不足を自動修復・削除しない。

| テーブル | 必要列（全て） |
|---|---|
| `player_nameless_equipments` | id, character_id, kind, custom_name, equipment_type, forge_level, base_power, power_per_level, is_equipped, growth_exp, revision, acquisition_source, is_locked, created_at, updated_at |
| `player_relics` | id, character_id, effect_key, rank, is_locked, nameless_equipment_id, character_item_id, slot_number, growth_progress, created_at, updated_at |
| `nameless_workshop_operations` | id, character_id, request_uuid, action, payload_hash, result, created_at, updated_at |
| `nameless_equipment_discoveries` | id, character_id, kind, equipment_type, created_at, updated_at |
| `nameless_ruin_progress` | id, character_id, zone_key, unlocked_depth, created_at, updated_at |

MariaDBではInnoDB、idのunsigned bigint相当・auto increment・主キー、所有者/装着先のunsigned bigint以上、ランク/進捗のunsigned tinyint以上、進行/改訂のunsigned int以上、成長EXPのunsigned bigint以上を検査する。フラグはtinyint、日時はnullable timestamp/datetime、文字列はmigrationで定めた長さ以上を必要とする。kindはweapon/armor/accessoryのenum、resultはJSONまたは単独のJSON_VALID検査を強制する制約付きlongtext（OR 1などで無条件に通す弱い制約は拒否）。各列のnullableと書込みが依存する既定値も検査する（詳細値はCOLUMNS）。生成列は拒否する。

ソケットの2組のunique、操作のcharacter_id/request_uuid、発見のcharacter_id/equipment_type、進行のcharacter_id/zone_keyのuniqueを要求する。古いcharacter_id/kindの一意制約は拒否する。所有者FKはcharacters.idへcascade、装着先FKは各武具.idへset nullを要求する。参照先の既存characters/character_itemsテーブルの実在・id型/主キー・InnoDBも確認する。対象5本の移行記録がない場合も準備未完了。SQLiteは検査できる型/制約を使い、MariaDB固有の幅・unsigned・JSON制約・engineは隔離MariaDBで別途検証する。

`nameless:prepare --json` の `schema_problems` は `table:column_missing:effect_key`、`table:type:rank:expected=tinyint:actual=varchar(3)`、`table:unique:...`、`migrations:pending:...` 等の具体的診断を返す。`constraint_problems` は互換用に同じ診断を返す。プレイヤー向け画面は準備中表示に留める。

DB移行と街登録はOFFのまま準備できる。`php artisan nameless:prepare --json` は読取だけで、対象5本の未適用・必要schema・enum・unique・外部キー・街数を報告する。準備未完了（街が0件/重複を含む）は非0終了とする。

明示されたDB準備タスクでは `php artisan nameless:prepare --apply --register-town` を使う。正式スイッチOFFを要求し、対象5本だけを適用する。MariaDBでは移行と街登録それぞれにadvisory lockを取得し、同時登録を直列化する。途中のDDLが成功してmigration記録だけ未完了の場合、存在する対象列/テーブル/索引を再作成せず、最後に制約を検証する。不整合な型・欠落した外部キーを無条件で修復せず停止する。既存データを削除するdownは運用復旧に使わない。

`php artisan nameless:install-town --prepare-off` でも、対象移行・制約確認済みのOFF状態で街だけを登録できる。オプションなしの旧コマンド名・別名は従来どおり設定ONとschema準備完了を要求する。OFF中は登録済み街もMAP・専用操作へ公開しない。

準備確認後、別途明示された有効化タスクで正式設定をONにし、公開手順でconfig cacheを再生成する。APP_ENVをlocalへ変更して開放しない。

公開前に認証済みの探索・報酬・育成・装着・持ち替え・売却・進化・敗北を確認する。OFFへ戻す場合もconfig cacheを公開手順で再生成し、既存資産の保護・継承を備えたコードを維持する。旧OFF配備版へコードだけ戻すことや、資産を削除するmigration downを通常の復旧手順にしない。

改善コードは本番OFF配備済み。既存5本の非破壊的な再実行対策を含み、追加のテーブル/カラム/費用は設けていない。本番の対象migration・街登録・設定値変更は未実施。

塔・国家レイドは人間裁定により能力上昇と特殊効果を適用する。塔は共通の装着・開始・勝利回復経路を接続。正式レイドは出撃snapshotへ効果を固定し、特攻/耐性/直接効果/反応を既存の倍率・capの中で解決する。通常戦闘・塔はOFFで新しい効果を停止する。正式レイドは下記の承認契約と出撃snapshotを使う。

`scripts/verify/nameless-mariadb.php all --confirm-isolated-database` は検証専用。APP_ENV=testing、明示された127.0.0.1の3306以外のport、DB_DATABASE=valzeria_nameless_verify_*、DB接続情報をprocess環境へ設定し、config cacheなしで実行する。既存DB・DB_URL・接続redirectを拒否する。新しい使い捨てDBに基準schemaを作り、5本の移行・既存個体保持・OFF維持・同時街登録・同UUID再送・競合UUID育成・transaction rollbackを検証する。本番と同じMariaDB 10.5.26の隔離ローカル環境で合格済み。本番DB上での実行と正式ONの実プレイ、実機スマホは未確認。

所持枠満杯時の救済は対応済み。深度31以降も高ランク抽選が改善する変更は、2026-10-06の人間裁定により深度100まで延長しOFF配備済み。深層抽選未改善という旧指摘はこの変更で解消した。長期の収集期間や本番の資産分布に基づく経済検証まで完了したという意味ではない。

## 今夜の公開に向けた進行条件（2026-10-06）

- ①公開仕様: 人間裁定により武具60個・遺物300個の固定上限を廃止。名もなき武具は装備倉庫、遺物は素材倉庫の空き枠を1個体につき1枠使う。装備/装着/保護/育成中も計上し、取り外しでは所持枠は増えない。購入拡張・街踏破による既存の容量増加は共用する。共通集計はOFFでも所有資産を数え、関連テーブル/所有者列がない未準備DBは0件として通常倉庫を維持する。この変更は機能OFFの配備対象。
- 育成3/5個・SSS2枠/EPIC3枠・能力目標・深度100の遺物ランク抽選補正は維持する。武具本体の獲得率は追加裁定により、深度1の0.1%から深度100の1%まで直線補間（0.01%単位に丸め、深度50は0.55%）へ変更。遺物ランク抽選とは別で、各勝利につき独立1回、武具は最大1個、天井なし。通常敵・挑戦ボス・再遭遇ボス・ゴブリンとも同じ現在深度を使う。入場制限なし・共通探索力（通常1/ボス3）は現行設定を維持する。武具獲得率の変更は機能OFFの配備対象。
- ②検証: 同じゲームコードの隔離ON環境で認証済み操作と375/390px幅を最終確認する。小さく分けて直列実行し、実機スマホは合意どおり今回の対象外とする。
- ③本番準備: 事前バックアップとOFF確認後、対象5本だけを `nameless:prepare --apply --register-town --json` で準備し、資産保持を照合する。全migration・全体seedを実行しない。
- 準備完了はコマンド終了0に加え、enabled=false、pending_migrations=[]、schema_ready=true、schema_problems=[]、town_count=1を確認する。Serviceのreadyだけでは街登録とOFFを保証しない。
- ④準備後もOFFでMAP/専用操作を閉じ、通常探索・装備・倉庫・売却の回帰を確認する。⑤古い文書と公開告知、⑥有効化/復旧の手順を整える。ここまでで本番をONにしない。
- 最終公開は別のON指示を受け、NAMELESS_RELICS_ENABLED=trueと設定cache再生成を行う。常駐workerがある場合は新設定の反映も必要。公開後の認証済み最終確認とログ/応答時間の監視は残る。
- 問題時は現行コードのまま設定OFFとcache更新で停止し、所有資産とDBを保持する。破壊的downや旧ローカル限定版へのコード巻戻しを通常復旧に使わない。
- 最新の本番読取では既存レイド2件はcompleted、started出撃0件。公開直前にも再確認する。次回レイドはON契約の再検証・新しい開催承認が必要で、本番OFFのままON契約の正式承認までは行えない。

本節の手順は完了記録ではない。①の倉庫・武具獲得率裁定は確定・実装済み。関連190テスト/6,602 assertionsを小さく分割して直列確認済み。今回の依頼はコードのOFF配備のみ（migration_mode=none）で、②の最新変更のMariaDB/スマホ幅確認、③以降の本番DB準備・正式ONは未完了。

## レイド承認と公開時の注意（2026-10-06、本番OFF配備済み）

`nameless_relic_combat` にON/OFF・効果定義・戦闘設定を保存しruleset hashへ含める。匿名シミュレーションは同じ契約とprofile model `turn-by-turn-live-defense-relic-contract-v2` を要求する。古い匿名成果物を自動昇格させない。承認後のON/OFF・効果設定変更は新規受付を停止し、再生成・再検証・新しい開催承認が必要。既存開催の承認JSON/hashを上書きして開放しない。

受付時の `admission.relic_rules` と個人能力/効果snapshotで準備・戦闘・共闘精算を固定する。全体OFFへの変更後も受付済み出撃の契約をプロセス内だけで復元し、終了・例外時に設定/cacheを戻す。再送は元の出撃を返す。契約のない旧OFF開催・出撃は元のhashとOFFを維持する。承認契約のない旧ON出撃は戦闘せず既存の返却・期限回収を使う。設定の切替だけで未承認の効果を加えたり、途中で能力と特殊効果を分離しない。

OFF配備と設定/cache・既存開催/hash・未精算出撃の読取照合は完了。DB準備が別途指示された場合だけ対象5本の移行と診断・街登録を実施し、資産保持を照合する。本番をOFFに維持したまま、隔離環境をONにして新しい匿名snapshot・シミュレーションを生成/検証し、承認・実操作・画面・性能の経路を確認できる。本番のON契約の正式承認には本番の現行設定と契約が一致する必要がある。承認経路の機能テストは開催バランスの人間裁定に代わらない。本番ONと認証済み最終確認は別途行い、実機スマホとPCのスマホ幅確認も区別する。

隔離CIは `verify-nation-raid-phase4-mariadb.yml`。mysql/mariadbドライバをmax-parallel=1で直列実行し、既存の出撃/報酬競合検証と `scripts/verify/nameless-schema-mariadb.php` の型/列/JSON/unique/移行記録不足・資産保持検証を実行する。後者はtesting・loopback・明示された使い捨てDBのみ受け付ける。元のMariaDB検証スクリプトと接続制限が異なるため混同しない。結果は当該PRと同一commitのCI記録を参照する。他の既存指摘は本修正の解消対象に含めない。

## OFF維持・隔離ONでの確認（2026-10-06）

当初の本番読取専用確認（25c5cfe6配備時点）では実SHA25c5cfe6、enabled/readyともfalse、対象5本未適用、工房街0件、既存武具のkind/追加列/旧uniqueと関連4テーブルの不足を検出した。DB/設定/承認JSONの書換えはしていない。

同じコードの隔離MariaDB 10.5.26で、対象5本のOFF適用・既存武具/Character保持・街登録2操作・同UUID/競合UUID育成・部分DDL再実行・装着制約・rollbackの8確認が成功。ローカルSQLiteの207テスト/6,653 assertionsを1クラスずつ直列実行し、OFF/ON・探索勝敗/再送・育成・装着/持替・売却/素材/餌保護・進化・塔・レイド契約を確認した。これらは本番の実プレイヤー操作の証拠ではない。

最初のON性能測定では、構造検査の繰返しが遅延要因として残った。loopback MariaDB・buffer pool64MiB・架空キャラクター1件・各3サンプルの中央値は、gate OFF0.02ms/ON185.93ms、装備画面OFF32.65ms/ON2,627.51ms、工房画面ON2,661.50ms。ON装備1描画で構造照会674回、探索は2,206〜3,114msだった。この指摘に対する一括取得の改善と同条件比較は次節に記録する。HTTP全体/同時多数プレイヤー/本番の応答時間を保証する計測ではない。

実際のON匿名snapshot生成は架空の有効プレイヤー1人・抽出エラー0で `validation.ready=true`。同じsnapshotによる1000seedの参考シミュレーションが完走し、OFF成果物のON流用拒否と、隔離DBでのON契約承認・承認前のOFF切替拒否も確認した。snapshot抽出とシミュレーション前後で資産fingerprintは一致した。本番プレイヤーの母集団や正式開催の承認を代替する結果ではない。

レイドシミュレーターは `PARTICIPATION_MODEL_AUTHORITATIVE=false` により、現行ではON成果物も参考用。`--allow-reference-profile` が必要で、snapshotのreadyや1000seedの完走だけを正式バランス承認の根拠にしない。承認Serviceは管理者/根拠文字列/現行契約/hashを検査するがsimulation artifactを機械的には照合しない。参加モデルの較正・本番の母集団/経済検証・開催バランスの裁定は別の残条件。

認証済みの隔離ブラウザで育成・取り外し・再装着・通常探索・ボス挑戦を実操作し、素材2個の消費・装着遺物保持・売却保護・深度2解放・ボス遺物報酬をDB照合した。装備画面の実CSS幅375/390/1280pxでは横はみ出しを検出せず、確認経路のブラウザconsole errorは0件。塔・正式レイド・進化等は上記テストで検証しており、全経路をブラウザ操作した意味ではない。

ユーザー裁定により今回のスマホ確認はPCのスマホ幅まで。実機スマホと本番ONの最終検証は未確認。今回の文書整理で、別件のSQLite紋章migration等を解消扱いにしない。

## 構造検査の一括取得による性能改善（2026-10-06、OFF配備対象）

`NamelessSchemaService` はMariaDBの対象テーブル・列・索引・外部キー・JSON CHECKを5照会でまとめて読み、対象5本の移行履歴を1照会で確認する。列/索引/FKはLaravelのprocessorで正規化し、全照会はwrite PDOを使う。native JSONでは不要なMariaDB固有CHECK照会を行わない。SQLiteは既存のSchema APIを使い、検査内で同じ索引等を再取得しない。

取得配列は検査1回のローカル変数だけ。成功も失敗もrequest/worker/永続cacheへ保存しないため、同instanceで列や制約・移行記録が変更された場合は次の検査で再確認する。型・nullable・既定値・生成列・engine・unique・FK・JSON制約・未移行の拒否を維持し、資産・DB構造・費用・効果・正式設定は変更しない。OFF runtime gateの構造照会は0件。

同じ隔離MariaDB 10.5.26/buffer pool64MiB/架空1人に対し旧検査と新検査を各3サンプル直列比較した。探索は同じ資源状態にそろえ、外側transactionでrollbackした。能力結果と全対象資産fingerprintは一致。

| 対象 | 修正前中央値 | 修正後中央値 | ON構造照会 前→後 |
|---|---:|---:|---:|
| gate ON | 276.62ms | 64.96ms | 51→5 |
| 能力計算 ON | 1,718.52ms | 517.84ms | 311→35 |
| 装備表示 ON | 3,549.27ms | 934.79ms | 674→76 |
| 工房表示 ON | 3,810.42ms | 839.32ms | 622→70 |
| 探索 ON | 3,399.40ms | 777.30ms | 571→65 |
| 装備表示 OFF | 31.75ms | 31.70ms | 5→5 |

ローカル209テスト/6,662 assertionsと、mysql/mariadb両接続ラベルでMariaDBの13確認ずつが成功。正常時6照会・反復時の再照会・OFF時0件、同instanceのDDL変更と復元、型/制約不整合、資産保持を検証した。これは本番ON性能・多数同時プレイヤーの保証や、参考用レイド参加モデルの正式承認を意味しない。今回の配備は機能OFFかつmigration_mode=noneで、本番DB準備・街登録・正式ONは行わない。
