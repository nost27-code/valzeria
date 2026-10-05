# 名もなき工房の有効化設定

2026-10-05の有効化準備修正。今回の配備は修正コードのみで、機能OFF・migration実行なし・工房街登録なしとする。街登録、migration、正式ONは別途明示された公開タスクで実施する。

| 設定 | 挙動 |
|---|---|
| `NAMELESS_RELICS_ENABLED=false` または未指定 | 全環境で既定OFF。工房・遺跡・追加表示・能力加算・特殊効果を停止する。 |
| `NAMELESS_RELICS_ENABLED=true` | 全環境で同じ機能設定を使う。必要なテーブル・カラムが未完了なら工房を開かない。 |
| 旧 `NAMELESS_RELICS_LOCAL_ENABLED` | 正式キー未指定のlocal/testingだけで互換使用する。production/stagingでは旧キーだけでは有効にならない。 |

武具の成長曲線と種別表示も同じ設定を参照する。能力目標、費用、ドロップ、ランク育成ルールは変更していない。装着済み遺物の売却・所有者変更・素材化の保護、進化先への継承、敗北喪失時の取り外しは、該当schemaが存在すればOFFでも全環境で維持する。

正式公開の準備では、本番用MariaDB隔離環境で以下の5本を順に検証する。無関係な未適用migrationを一括実行しない。

1. `2026_10_03_010000_create_nameless_relic_prototype_tables`
2. `2026_10_03_030000_enable_nameless_equipment_collection`
3. `2026_10_04_060000_add_growth_progress_to_player_relics`
4. `2026_10_05_070000_extend_nameless_equipment_kind_for_accessories`
5. `2026_10_05_080000_add_ordinary_equipment_relic_sockets`

ゲートは遺物・操作台帳・発見記録・進行テーブルと、通常装備ソケット・育成進捗・本体の追加カラムを検査する。kindのenum・unique制約・外部キーの移行完了も公開手順で確認する。

DB移行と街登録はOFFのまま準備できる。`php artisan nameless:prepare --json` は読取だけで、対象5本の未適用・必要schema・enum・unique・外部キー・街数を報告する。準備未完了（街が0件/重複を含む）は非0終了とする。

明示されたDB準備タスクでは `php artisan nameless:prepare --apply --register-town` を使う。正式スイッチOFFを要求し、対象5本だけを適用する。MariaDBでは移行と街登録それぞれにadvisory lockを取得し、同時登録を直列化する。途中のDDLが成功してmigration記録だけ未完了の場合、存在する対象列/テーブル/索引を再作成せず、最後に制約を検証する。不整合な型・欠落した外部キーを無条件で修復せず停止する。既存データを削除するdownは運用復旧に使わない。

`php artisan nameless:install-town --prepare-off` でも、対象移行・制約確認済みのOFF状態で街だけを登録できる。オプションなしの旧コマンド名・別名は従来どおり設定ONとschema準備完了を要求する。OFF中は登録済み街もMAP・専用操作へ公開しない。

準備確認後、別途明示された有効化タスクで正式設定をONにし、公開手順でconfig cacheを再生成する。APP_ENVをlocalへ変更して開放しない。

公開前に認証済みの探索・報酬・育成・装着・持ち替え・売却・進化・敗北を確認する。OFFへ戻す場合もconfig cacheを公開手順で再生成し、既存資産の保護・継承を備えたコードを維持する。旧OFF配備版へコードだけ戻すことや、資産を削除するmigration downを通常の復旧手順にしない。

今回の改善はOFFの準備コードと既存5本の非破壊的な再実行対策。新しいテーブル/カラム/費用は追加しない。本番のmigration・街登録・設定変更は実施しない。

塔・国家レイドは人間裁定により能力上昇と特殊効果を適用する。塔は共通の装着・開始・勝利回復経路を接続。正式レイドは出撃snapshotへ効果を固定し、特攻/耐性/直接効果/反応を既存の倍率・capの中で解決する。OFFは効果停止を維持する。

`scripts/verify/nameless-mariadb.php all --confirm-isolated-database` は検証専用。APP_ENV=testing、明示された127.0.0.1の3306以外のport、DB_DATABASE=valzeria_nameless_verify_*、DB接続情報をprocess環境へ設定し、config cacheなしで実行する。既存DB・DB_URL・接続redirectを拒否する。新しい使い捨てDBに基準schemaを作り、5本の移行・既存個体保持・OFF維持・同時街登録・同UUID再送・競合UUID育成・transaction rollbackを検証する。本番と同じMariaDB 10.5.26の隔離ローカル環境で合格済み。本番DB上での実行と正式ONの実プレイ、実機スマホは未確認。

所持枠満杯時の救済は対応済み。深層報酬の調査指摘は別途残る。
