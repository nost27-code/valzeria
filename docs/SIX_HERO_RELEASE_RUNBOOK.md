# 六英雄戦 本番公開・運用Runbook

最終更新: 2026-08-21

## 目的と停止条件

このRunbookは、六英雄戦を`SIX_HERO_UI_ENABLED=false`のまま配備し、DB・既存ゲーム・六英雄戦の健全性を確認してから公開するための固定手順です。競技データを直接修復する手順ではありません。

次のいずれかに該当したら公開を停止し、flagをOFFのまま維持します。

- `six-heroes:release-check`にFAILがある
- migration、既存機能smoke test、六極殿smoke testのいずれかが失敗する
- rank欠番・重複・負rank、DailyUsage上限超過、Champion欠損、identity欠損を検知する
- 終了Seasonの確定を阻害する長時間pending battleがある
- 対象commit以外の差分、未確認migration、取得確認できないbackupが混在する

## 事前条件

- 本番DB製品とversionを確認し、Phase 6Dで検証した系統との差異を記録する。
- release対象をdirty worktreeから分離し、対象commit SHAと差分一覧を確定する。
- GitHub Actionsの本番Environment承認者、作業者、監視担当、rollback判断者を決める。
- `SIX_HERO_UI_ENABLED=false`であることを確認する。既定値もfalseのままにする。
- 既存の`Deploy production`ワークフローと`migration_mode=backward_compatible`を使用する。手元のdirty treeや未commitファイルを直接配備しない。

## 公開手順

### 1. DB製品・version確認

本番DB接続先で製品/versionを確認し、作業記録へ残します。接続文字列、host、user、passwordはログやチャットへ貼り付けません。

Phase 6Dで実証した既定Release baselineはMySQL 8.4以上です。本番でMariaDB等を使う場合は、その製品/versionでPhase 6D相当の検証を完了したうえで、検証済みの最低versionを明示します。

2026-08-22の本番配備時に、ステージング・本番とも`MariaDB 10.5.13`であることを確認しました。機能flag OFFでのコード・schema配備は行えますが、flag ONは同versionの隔離DBでPhase 6Dの並行Gateを再実行し、Release baseline設定をMariaDBへ更新してから判断します。

```dotenv
SIX_HERO_EXPECTED_DATABASE_PRODUCT=mysql
SIX_HERO_MINIMUM_DATABASE_VERSION=8.4.0
```

```sql
SELECT VERSION();
```

`six-heroes:health-check` / `six-heroes:release-check`は、検出した製品が`SIX_HERO_EXPECTED_DATABASE_PRODUCT`と異なる場合、versionを比較できない場合、または`SIX_HERO_MINIMUM_DATABASE_VERSION`未満の場合にFAILします。想定外の製品またはversion差がある場合は値だけを緩めず、同系統DBでの再検証が完了するまでここで停止します。

### 2. 本番DB backup

DB全体のbackupを取得し、次を確認します。

- backup jobの成功だけでなく、成果物の存在・size・時刻を確認する
- 復元先と復元手順を特定する
- 作業記録へbackup識別子を残す。ただし秘密情報は残さない

重要: Phase 5Cの`character_id_snapshot`を追加したmigrationをrollbackすると、既に削除されたCharacterの永久identityはDBから復元できません。このmigrationより前へrollbackする可能性が少しでもある場合、必ず先に復元確認済みbackupを取得してください。

### 3. flag OFFのままcode deploy

共有環境の設定が次であることを確認します。

```dotenv
SIX_HERO_UI_ENABLED=false
```

GitHub Actionsの`Deploy production`を、確認文字列`deploy-production`、対象`main` SHA、`migration_mode=backward_compatible`で実行します。今回はRunbookの記載のみであり、この操作自体は自動実行しません。

### 4. migration

Actionsのrelease処理が同一artifactで`php artisan migrate --force`を完了したことを確認します。失敗時はflagをOFFのままにし、手動で途中状態を推測修復しません。

配備先で次を確認します。

```bash
php artisan migrate:status
```

六英雄関連migrationがすべて`Ran`であること、既存migrationに予期しないpendingがないことを記録します。

### 5. release-check

初回公開で現在Seasonがまだ無い場合は、危険な直接insertを行わず既存の冪等Commandだけを実行します。

```bash
php artisan six-heroes:ensure-current-season
php artisan six-heroes:initialize-current-rankings
php artisan six-heroes:release-check
```

機械取得する場合は次を使います。JSONには接続情報や秘密情報を含めません。

```bash
php artisan six-heroes:release-check --json
```

WARNINGは内容と理由を記録します。FAILまたは非0 exit codeなら公開停止です。pending待ちの場合はBattleを自動変更せず、完了後に既存初期化/確定処理とrelease-checkを再実行します。

### 6. flag OFFで既存機能smoke test

認証済みテストCharacterで最低限、次を確認します。

- ログイン、街、探索、通常戦闘、宿屋
- 通常戦闘と既存の共通PvP戦闘回帰が正常である
- 管理画面の「六英雄戦運用」が表示され、Health Check結果と6Room概要を読める
- home下部Navigationの「闘技場」から従来闘技場が開き、旧`/colosseum/ranking`・プレイヤー対戦Routeを利用できる
- 従来闘技場のNPC自動順位戦がSchedulerへ1日3回分登録されている
- `/six-heroes`、六極殿画面、冒険者カードの六英雄実績はflag OFFで非公開
- schedulerとapplication logに新しい継続エラーがない

既存機能が失敗した場合は公開を停止します。

### 7. 設定を再読込してflag ON

共有本番環境の設定を次へ変更します。

```dotenv
SIX_HERO_UI_ENABLED=true
```

設定変更後、配備先の標準手順でconfig cacheを再生成します。

```bash
php artisan config:clear
php artisan config:cache
```

次で`Master switch: ON`を確認し、FAILが無いことを再確認します。

```bash
php artisan six-heroes:release-check
```

設定再読込またはrelease-checkに失敗した場合は、直ちにflagをOFFへ戻して再読込します。

### 8. 六極殿smoke test

認証済みのテストCharacterで次を確認します。

- home下部Navigationに「闘技場」が表示され、タップすると同じ`/home`内へライト表示の六極殿が開く
- `/six-heroes`と旧`/colosseum/ranking`がhomeの「闘技場」タブへ互換redirectする
- 旧通常闘技場のプレイヤー対戦Routeが404で、NPC自動順位戦がSchedulerへ登録されていない
- 6Room、現在首位、自分の順位、選択Roomの残り公式戦、前月英雄/空位、歴代英雄が表示される
- 未登録Roomへ既存参加登録Service経由で登録できる
- 相性確認で順位、公式counter、DailyUsage、BattleLogが変化しない
- 公式戦の確認modalと「開始後は勝敗にかかわらず1回消費」が表示される
- 許可したテスト対戦1件で結果、順位変動または競合時説明、残り回数が最新状態になる
- 冒険者カードの六英雄実績が表示される
- desktopと実効300px幅で操作不能な横崩れがない

実プレイヤーの順位や挑戦回数を検証目的で編集しません。

### 9. 公開告知

smoke test完了後にのみ、管理画面の更新履歴候補を確認・編集して公開します。内部診断情報、DB version、Character ID、BattleLog IDは告知へ含めません。

## 公開後監視

### 最初の1時間

15分ごとを目安に次を確認します。

```bash
php artisan six-heroes:health-check
```

- FAIL 0件
- stale pending battle 0件
- failed Battleの増加傾向
- DailyUsageの各Room回数が5を超えず、日次合計と一致している
- 6Roomのrankが1..Nでdense、一意、負rankなし
- application error、DB deadlock、CPU、DB負荷、相性確認request量

異常時はまずflagをOFFへ戻してconfigを再読込し、六英雄の新規player actionを閉じて従来闘技場へ戻します。BattleLogやRankingを直接変更しません。

### 24時間

- `six-heroes:health-check`と管理画面のPASS/WARNING/FAIL
- 各Roomで1日5戦へ到達した枠とattempt上限
- failed/stale Battleの件数と原因傾向
- 6Roomの登録数・公式戦数・rank整合性
- 共通PvP戦闘処理と基本ゲームループのerror率
- DB/CPU/query latencyと相性確認の利用量

### 最初の月末

- 締切前に開始した公式戦が締切後も旧Seasonへ反映される
- `started` / `resolved`が残る間はfinalizeが保留される
- pending解消後、各RoomのChampion/空位がちょうど6件作成される
- `character_id_snapshot`と`character_name_snapshot`が非空位英雄に保存される
- 翌月Rankingが6Roomまとめて一度だけ初期化され、前月順位をdenseに引き継ぐ
- 月間counterが0、rank 1以外の`first_place_since`がnullである
- carryover二重生成、Champion二重生成、rank欠番・重複・負rankがない

月末処理は待機loopや手動強制確定を使わず、既存Schedulerまたは管理画面の安全な再試行だけを使用します。

## 障害時の安全な操作

許可される管理操作は次だけです。

- 現在Seasonの確認
- 現在Ranking初期化の再試行
- 終了Season確定の再試行

禁止事項:

- 強制finalize
- Ranking、勝敗counter、DailyUsage、BattleLog、Championの直接編集・削除
- stale battleの自動`failed` / `expired`化
- 管理画面からのfeature flag変更

code rollbackが必要な場合も、最初にflagをOFFへ戻してconfigを再読込します。DB migration rollbackはcodeとの互換性とbackupを確認して個別判断し、特にPhase 5C以前へ戻す場合はhistorical identity保護のためbackupなしで実行してはいけません。
