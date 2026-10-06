# 遺物・無もなき工房街の公開と停止

2026-10-06確認。この文書の配備前に確認した本番コードの基準SHAは `8f840c518217451038595ffa55684244e5355671`。本番機能はOFF。対象5本のmigration適用、全必要列・型・制約の診断、工房街1件の登録は完了。対象バックアップを隔離MariaDBに復元して件数・日本語を照合した。既存所有資産を初期化していない。

公開前の②～⑥のOFF準備・確認と、公開後の最終確認を区別する。本番をONにする操作は明示されたON指示の後だけ行う。

## 公開直前の読み取り確認

1. `/home/nos27/valzeria.com/valzeria_current/.release-sha` と最新配備を照合する。別作業で進んだ場合は影響差分を確認してから進める。
2. 同ディレクトリで `/usr/bin/php8.4 artisan nameless:prepare --json`。終了0、enabled=false、pending_migrations=[]、schema_ready=true、schema_problems=[]、ready=true、town_count=1を要求する。runtimeのready=falseはOFFによる正常な閉鎖。
3. HTTP `/system/health` のok=true、工房への認証済みGETは404、MAP入口なし。failed_jobs・配備後ログ・進行中レイド出撃を再確認する。
4. 実ON操作直前に、共有.envと対象資産・設定の新しいバックアップを保護領域に保存する。本タスクのバックアップは変更対象テーブルと参照schemaの範囲で、DB全体のバックアップではない。通常プレイで増える街の地図研究所寄与値・updated_atを全行比較の停止条件に使わず、街マスタと今回の対象を区別する。

## 明示された公開指示の後だけ行う操作

現時点の正式キーは共有.envに未記載（既定OFF）。`valzeria_current/.env` の実体が共有.envであることを確認し、NAMELESS_RELICS_ENABLEDの有効な行を**1行だけ** `NAMELESS_RELICS_ENABLED=true` として追加する。既に行がある場合はその1行を変更し、重複追加しない。APP_ENVや他の機能設定を変えない。

同じcurrentディレクトリで順に実行する。

```sh
/usr/bin/php8.4 artisan config:cache
/usr/bin/php8.4 artisan nameless:prepare --json
```

確認時点のヴァルゼリア常駐workerは0件。検出されたqueue:work 1件は別アプリであり対象外。別アプリのworkerや監視cronを変更しない。公開直前にValzeriaのworkerが新たに稼働していた場合だけ、その管理方式を確認し、Valzeriaのcurrentからqueue:restartを実行して新workerへの設定反映を確認する。コマンド終了0だけで反映完了とは扱わない。

準備確認はenabled=true、全不足0件、街1件、runtime ready=trueを要求する。追加のmigrationや街再登録を通常の公開操作へ混ぜない。旧ローカルキーやAPP_ENV=localを使わない。

## 公開後に行う最終確認

認証済みアカウントでMAPから工房街へ入り、探索・ボス・報酬・育成・装着・持ち替え・売却保護・進化を確認し、HTTPヘルス・ログ・応答時間を監視する。これらの本番ON実操作は今回のOFFタスクでは実施していない。PCの375/390px検証は完了、実機スマホは今回の合意対象外。

塔・レイドは能力と特殊効果を反映する。既存の本番レイド2件はcompleted、started出撃0件だった。次のレイド開催はON契約のsnapshot/シミュレーションを再生成・検証し、人間が新しい開催を承認する。OFF承認を再利用したり、既存ruleset/hashを上書きしない。隔離の参考1000seedと機能テストは本番の参加モデル・開催バランスの裁定を代替しない。

## 問題時の停止

現行コードとDBを保持したまま、同じ正式キー1行を `NAMELESS_RELICS_ENABLED=false` に変更し、config:cacheを上と同じように実行する。Valzeriaのworkerが稼働していた場合のみqueue:restartも行う。runtime ready=false、工房/遺跡/入口/追加表示の閉鎖を読み戻す。schema_ready=trueと登録済み街1件が残るのは正常。装着資産の保護・進化継承はOFFでも維持する。受付済みの正式レイドは保存された承認契約で精算する。

migration down、truncate、全体seed、既存所有者/装着先の一括書換え、保護・継承を欠く旧コードへの巻戻しは通常復旧に使わない。バックアップは原因調査・対象限定の復旧計画に使い、通常プレイで進んだ資産を丸ごと巻き戻さない。

## 公開告知の下書き（未投稿）

> MAPに「無もなき工房街」と遺跡探索が登場します。遺跡で見つけた遺物を名もなき武具やSSS・EPIC装備に組み込み、自分の戦い方に合わせて育てられます。
>
> 同じ効果・同じランクの遺物は、I〜VIは本体を含め3個、VII・VIIIは5個で次のランクへ。素材は少しずつ投入でき、途中の進捗も保存されます。深い遺跡ほど高ランクの遺物が出やすく、名もなき武具の獲得率も深度1の0.1%から深度100の1%へ上がります。
>
> 武具は装備倉庫、遺物は素材倉庫の枠を使います。装着済みの遺物も所持枠に含まれます。遺物が付いた装備は、取り外してから売却できます。

告知は正式ONの読戻し成功後に管理画面から公開する。今回の確認作業ではtop_updatesへの投稿・公開日時変更・プレイヤー向け送信は行っていない。

## 証跡と限界

`storage/app/private/research/relic-launch-20261006/final-checks/` の本番prepare/verify/readback、backup復元、native/shared-race、直列テスト、ブラウザ画像/寸法、ON性能、レイド参考成果物、cache切替検証が根拠。詳細な時点と実行結果は同フォルダの最終報告を正本とする。バックアップの初回照合・CLI描画プローブの失敗・SSH/ブラウザ接続の時間切れも記録し、実行の試みを成功と扱わない。
