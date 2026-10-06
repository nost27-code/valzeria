# 名もなき工房の有効化設定

2026-10-05の有効化準備に対する2026-10-06の整合性修正。本修正は本番未反映で、本番設定変更・migration・準備コマンド・街登録・正式ONは実行しない。以下の本番手順は、将来別途明示承認されたタスクでのみ実施する。

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

2026-10-06の整合性修正は本番未反映。実行時と準備確認は共通の読取専用 `NamelessSchemaService` を使う。必要列の正本は同クラスの `COLUMNS` とし、不足を自動修復・削除しない。

| テーブル | 必要列（全て） |
|---|---|
| `player_nameless_equipments` | id, character_id, kind, custom_name, equipment_type, forge_level, base_power, power_per_level, is_equipped, growth_exp, revision, acquisition_source, is_locked, created_at, updated_at |
| `player_relics` | id, character_id, effect_key, rank, is_locked, nameless_equipment_id, character_item_id, slot_number, growth_progress, created_at, updated_at |
| `nameless_workshop_operations` | id, character_id, request_uuid, action, payload_hash, result, created_at, updated_at |
| `nameless_equipment_discoveries` | id, character_id, kind, equipment_type, created_at, updated_at |
| `nameless_ruin_progress` | id, character_id, zone_key, unlocked_depth, created_at, updated_at |

MariaDBではInnoDB、idのunsigned bigint相当・auto increment・主キー、所有者/装着先のunsigned bigint以上、ランク/進捗のunsigned tinyint以上、進行/改訂のunsigned int以上、成長EXPのunsigned bigint以上を検査する。フラグはtinyint、日時はnullable timestamp/datetime、文字列はmigrationで定めた長さ以上を必要とする。kindはweapon/armor/accessoryのenum、resultはJSONまたは単独のJSON_VALID検査を強制する制約付きlongtext（OR 1などで無条件に通す弱い制約は拒否）。各列のnullableと書込みが依存する既定値も検査する（詳細値はCOLUMNS）。生成列は拒否する。

ソケットの2組のunique、操作のcharacter_id/request_uuid、発見のcharacter_id/equipment_type、進行のcharacter_id/zone_keyのuniqueを要求する。古いcharacter_id/kindの一意制約は拒否する。所有者FKはcharacters.idへcascade、装着先FKは各武具.idへset nullを要求する。対象5本の移行記録がない場合も準備未完了。SQLiteは検査できる型/制約を使い、MariaDB固有の幅・unsigned・JSON制約・engineは隔離MariaDBで別途検証する。

`nameless:prepare --json` の `schema_problems` は `table:column_missing:effect_key`、`table:type:rank:expected=tinyint:actual=varchar(3)`、`table:unique:...`、`migrations:pending:...` 等の具体的診断を返す。`constraint_problems` は互換用に同じ診断を返す。プレイヤー向け画面は準備中表示に留める。

DB移行と街登録はOFFのまま準備できる。`php artisan nameless:prepare --json` は読取だけで、対象5本の未適用・必要schema・enum・unique・外部キー・街数を報告する。準備未完了（街が0件/重複を含む）は非0終了とする。

明示されたDB準備タスクでは `php artisan nameless:prepare --apply --register-town` を使う。正式スイッチOFFを要求し、対象5本だけを適用する。MariaDBでは移行と街登録それぞれにadvisory lockを取得し、同時登録を直列化する。途中のDDLが成功してmigration記録だけ未完了の場合、存在する対象列/テーブル/索引を再作成せず、最後に制約を検証する。不整合な型・欠落した外部キーを無条件で修復せず停止する。既存データを削除するdownは運用復旧に使わない。

`php artisan nameless:install-town --prepare-off` でも、対象移行・制約確認済みのOFF状態で街だけを登録できる。オプションなしの旧コマンド名・別名は従来どおり設定ONとschema準備完了を要求する。OFF中は登録済み街もMAP・専用操作へ公開しない。

準備確認後、別途明示された有効化タスクで正式設定をONにし、公開手順でconfig cacheを再生成する。APP_ENVをlocalへ変更して開放しない。

公開前に認証済みの探索・報酬・育成・装着・持ち替え・売却・進化・敗北を確認する。OFFへ戻す場合もconfig cacheを公開手順で再生成し、既存資産の保護・継承を備えたコードを維持する。旧OFF配備版へコードだけ戻すことや、資産を削除するmigration downを通常の復旧手順にしない。

今回の改善はOFFの準備コードと既存5本の非破壊的な再実行対策。新しいテーブル/カラム/費用は追加しない。本番のmigration・街登録・設定変更は実施しない。

塔・国家レイドは人間裁定により能力上昇と特殊効果を適用する。塔は共通の装着・開始・勝利回復経路を接続。正式レイドは出撃snapshotへ効果を固定し、特攻/耐性/直接効果/反応を既存の倍率・capの中で解決する。通常戦闘・塔はOFFで新しい効果を停止する。正式レイドは下記の承認契約と出撃snapshotを使う。

`scripts/verify/nameless-mariadb.php all --confirm-isolated-database` は検証専用。APP_ENV=testing、明示された127.0.0.1の3306以外のport、DB_DATABASE=valzeria_nameless_verify_*、DB接続情報をprocess環境へ設定し、config cacheなしで実行する。既存DB・DB_URL・接続redirectを拒否する。新しい使い捨てDBに基準schemaを作り、5本の移行・既存個体保持・OFF維持・同時街登録・同UUID再送・競合UUID育成・transaction rollbackを検証する。本番と同じMariaDB 10.5.26の隔離ローカル環境で合格済み。本番DB上での実行と正式ONの実プレイ、実機スマホは未確認。

所持枠満杯時の救済は対応済み。深層報酬の調査指摘は別途残る。

## レイド承認と公開時の注意（2026-10-06、本番未反映）

`nameless_relic_combat` にON/OFF・効果定義・戦闘設定を保存しruleset hashへ含める。匿名シミュレーションは同じ契約とprofile model `turn-by-turn-live-defense-relic-contract-v2` を要求する。古い匿名成果物を自動昇格させない。承認後のON/OFF・効果設定変更は新規受付を停止し、再生成・再検証・新しい開催承認が必要。既存開催の承認JSON/hashを上書きして開放しない。

受付時の `admission.relic_rules` と個人能力/効果snapshotで準備・戦闘・共闘精算を固定する。全体OFFへの変更後も受付済み出撃の契約をプロセス内だけで復元し、終了・例外時に設定/cacheを戻す。再送は元の出撃を返す。契約のない旧OFF開催・出撃は元のhashとOFFを維持する。承認契約のない旧ON出撃は戦闘せず既存の返却・期限回収を使う。設定の切替だけで未承認の効果を加えたり、途中で能力と特殊効果を分離しない。

将来の本番反映は別途明示承認を受けて行う。OFFで対象差分を配備し、設定/cache・既存開催/hash・未精算出撃を確認する。DB準備が承認された場合だけ対象5本の移行と診断・街登録を実施し、資産保持を照合する。ON公開前にはON契約でシミュレーション成果物を再生成・検証し、新しい開催を承認する。実機と認証済みプレイも確認する。今回、本番配備・設定変更・migration・準備コマンド・街登録は実行しない。

隔離CIは `verify-nation-raid-phase4-mariadb.yml`。mysql/mariadbドライバをmax-parallel=1で直列実行し、既存の出撃/報酬競合検証と `scripts/verify/nameless-schema-mariadb.php` の型/列/JSON/unique/移行記録不足・資産保持検証を実行する。後者はtesting・loopback・明示された使い捨てDBのみ受け付ける。元のMariaDB検証スクリプトと接続制限が異なるため混同しない。結果は当該PRと同一commitのCI記録を参照する。他の既存指摘は本修正の解消対象に含めない。
