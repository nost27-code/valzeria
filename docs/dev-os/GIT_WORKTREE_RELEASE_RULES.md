# Git・worktree・本番公開の運用ルール

この手順は、複数のAIエージェントや作業を並行しても、`main`・Git履歴・本番公開の対応を崩さないための運用ルールです。
公開手段そのものは [GITHUB_ACTIONS_DEPLOY.md](../GITHUB_ACTIONS_DEPLOY.md) を正とし、この文書は「どの作業ツリーから、どのコミットを公開してよいか」を定めます。

## 正本と作業場所

| 場所 | 役割 | 守ること |
|---|---|---|
| `origin/main` | 共有する本番候補の正本 | 公開対象は必ずここに含まれる特定コミットにする。force pushしない。 |
| ローカル `main` | 同期・統合のための基準 | 直接編集しない。常にクリーンで `origin/main` と一致させる。 |
| 機能ブランチ + worktree | 実装・テストの作業場所 | 1機能または1修正だけを扱う。別件を混ぜない。 |
| 公開用worktree | ZIPフォールバック公開だけで使う隔離場所 | 対象コミットだけをチェックアウトし、未コミット変更を持ち込まない。 |

`main` がdirtyになった場合は、push・公開・追加実装を止める。まず内容をブランチまたは名前付きstashへ退避し、`origin/main` と差分がないクリーンな状態へ戻す。

## 開始時の手順

1. `main` で `git fetch origin` を実行する。
2. `git status --branch --short` を確認する。未コミット変更があれば、既存作業の整理を先に行う。
3. 新しい作業は `origin/main` から専用worktreeを作る。例:

   ```powershell
   git worktree add C:\tmp\ffa-feature-<short-name> -b codex/<short-name> origin/main
   ```

4. 作業開始時に、担当機能・変更対象・migration有無・公開予定の有無を明記する。

## 並行作業のルール

- 同じworktreeで複数のCodexや人が実装しない。
- 並行実装は、ブランチとworktreeを完全に分けた場合だけ許可する。
- 同じ画面、Service、設定、マスタデータ、migration、ルートを触る作業は直列化する。
- 読み取りだけの調査は並行してよい。ただし、調査結果でコードを書き始める前に担当範囲を再確認する。
- `main` への統合、migration、本番公開は常に1件ずつ行う。

## コミットと統合のルール

- 1コミットは、レビュー・ロールバック・公開の単位になる1つの意味ある変更にする。
- `git add .` を使わず、対象ファイルを明示してstageする。
- commit前に、少なくとも `git diff --cached` と対象テスト・構文チェックを確認する。
- 変更を統合する直前に `origin/main` を取り込み、競合と最新状態でのテストを確認する。
- 不要な画像、添付ファイル、一時スクリプト、ローカル設定、ビルド成果物をcommitへ入れない。
- stashは一時退避だけに使う。stashごとに用途を名前へ残し、作業を再開したらブランチへ戻すか不要と確認して整理する。

## 本番公開のルール

1. 公開するコミットSHAと変更範囲を先に確定する。
2. そのコミットが `main` にpush済みであることを確認する。
3. [GITHUB_ACTIONS_DEPLOY.md](../GITHUB_ACTIONS_DEPLOY.md) の本番ワークフローを標準経路として使う。
4. migration、Seeder、既存データ更新・削除、課金・通貨・認証変更を含む場合は、影響・復旧方法・明示承認を確認してから公開する。
5. 公開後は、公開コミットSHA、migration有無、実行した確認、公開時刻を記録し、対象画面と基本導線を確認する。

### ZIPフォールバックの条件

`local_deploy.php` は現在の作業ツリーをZIP化するため、dirtyなworktreeから実行してはならない。

ZIPフォールバックを使う場合は、明示承認のうえで次を満たす公開専用worktreeからだけ実行する。

- 公開対象コミットをcheckoutしている。
- `git status --short` が空である。
- 公開対象外の未追跡ファイルがない。
- 実行前後のコミットSHAと公開確認結果を残す。

緊急ホットフィックスで例外的にローカルZIP公開した場合も、直後に同じ内容をcommit・pushして、Git履歴を本番状態へ追いつかせる。次の作業や公開を始める前に、`main` と `origin/main` を再同期する。

## 作業終了時の完了条件

通常作業を終える前に、次を確認する。

```powershell
git status --branch --short
git log --oneline origin/main..HEAD
git stash list
```

- `main` では未コミット変更を残さず、`git diff --quiet HEAD origin/main` で追跡先と一致することを確認する。
- 機能worktreeには、次に再開する担当範囲だけを残す。
- 未整理のstash・未追跡ファイル・未pushコミットがある場合は、所有者と扱いを明記する。
- 本番に出した内容とGitのコミットが対応していることを確認する。
