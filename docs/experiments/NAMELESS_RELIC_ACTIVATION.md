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

その後、正式設定をONにして公開手順でconfig cacheを再生成し、`php artisan nameless:install-town` で工房街を登録する。旧名 `nameless:install-local-town` も使用できる。コマンドは設定ONとschema準備完了を要求する。街が未登録なら入口は出ず、利用できない。APP_ENVをlocalへ変更して開放しない。

公開前に認証済みの探索・報酬・育成・装着・持ち替え・売却・進化・敗北を確認する。OFFへ戻す場合もconfig cacheを公開手順で再生成し、既存資産の保護・継承を備えたコードを維持する。旧OFF配備版へコードだけ戻すことや、資産を削除するmigration downを通常の復旧手順にしない。

DBスキーマ変更は今回の修正に含まない。実MariaDBでの移行・同時操作、正式ONの実プレイ、実機スマホは未確認。所持枠満杯時の救済は、育成途中の余剰品をランク/進捗の再確認付きで破棄する修正により対応済み。装着中・保護中・最高ランク1個は保持する。塔・国家レイドの効果範囲、深層報酬は別の調査指摘として残る。
