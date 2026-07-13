# 実装指示書: 探索の中断復帰まわりの不具合修正（処理中デッドエンド・エリアずれ）

> 出典: 2026-07-11〜12 城下チャットのプレイヤー報告（らびっちょさん・南瓜の王冠さん）。
> 原因調査は完了済み。本書は「決定済みの修正方針」であり、実装者は方針の再検討をせず本書の通りに実装すること。
> 判断に迷う差分が出た場合は実装を止めて報告する（勝手に仕様を発明しない）。

## 背景（プレイヤー報告の要約）

1. **「探索処理中です」画面からのデッドエンド**: サーバー負荷が高い時間帯に「探索処理中です、少し時間をおいて…」が表示されると、画面上の選択肢が「戦利品を持って帰る」しかなく、押すと強制帰還になる。探索を続ける正規の導線がない。
2. **ブラウザバック復帰で探索場所がずれる**: 上記のデッドエンドを回避するためにプレイヤーがブラウザの「戻る」で復帰すると、探索エリア・深度が別の場所に変わる。「鉄鉱山の最深層を進んでいたのに、機械兵工場の表層に移動していた」という実害報告あり。

2つは連鎖している。1の正規導線がないこと（原因A）がブラウザバックという非正規手段を誘発し、2のガード不足（原因B）で実害になっている。**両方を直すこと。片方だけでは再発する。**

## 原因（調査済み）

### 原因A: busyエラー時に「探索を続ける」ボタンが描画されない

- `BattleController::redirectExploreRequestBusy()`（`app/Http/Controllers/BattleController.php:717`）は、2秒クールタイム（`EXPLORE_REQUEST_DELAY_SECONDS`）中の非AJAXリクエストを `battle.result` へ `result.error = '探索処理中です。少し待ってからもう一度お試しください。'` 付きでリダイレクトする。
- `resources/views/battle/result.blade.php` のフッターボタン分岐は、エラー文言の `str_contains` で分岐している:
  - `'連続で戦闘'` を含む → カウントダウン付き「探索を続ける」ボタン（1585行）
  - `'探索力'` を含む → 「街へ戻る」ボタン（1599行）
  - **`'探索処理中'` はどちらにも該当せず、どの分岐にも落ちない → フッターにボタンが一切出ない**
- 結果、画面に残る導線はレイアウト共通の退出ボタンのみ。`facility.blade.php:98` で戦闘結果画面の退出ラベルは「戦利品を持って帰る」になっており、これが `battle.resume.return`（`returnToTown`）へ飛んで `ExplorationStateService::reset()` を実行し、探索度・深度・連戦が全消去される。

### 原因B: `continue_chain` 時にエリア整合性チェックがない

- `BattleController::explore()`（81行〜）は `continue_chain=1` の場合、**POSTされた `$areaId` と現在の探索状態 `CharacterExplorationState.area_id` の一致を検証しない**。
- ブラウザバックは bfcache / 履歴キャッシュから**過去の別エリアの戦闘結果画面**を表示しうる。その画面の「もう一度探索する」フォームは古いエリアIDに向けてPOSTするため、現在の探索と無関係なエリアで探索が続行される。
- さらに `ExplorationStateService` 側は `recordVictory()` → `getOrStart($enemy->area_id)` の流れで状態のエリアを上書き・再スタートするため、深度は `surface` に戻る。これが「最深層→別ダンジョンの表層」の正体。
- 正規の復帰画面 `battle/resume.blade.php` は `$state->area_id` に対してPOSTするので安全。問題は古いページのフォームだけ。

## 対象ファイル

| ファイル | 修正内容 |
|---|---|
| `app/Http/Controllers/BattleController.php` | (1) busy時のリダイレクト先を復帰画面に変更 (2) `continue_chain` のエリア整合性ガード追加 |
| `resources/views/battle/result.blade.php` | 保険として「探索処理中」エラー分岐を追加（原則コントローラ側で到達しなくなるが、既存セッション残留分のため） |
| `app/Http/Middleware/` または `routes/web.php` | 戦闘結果・復帰画面への `Cache-Control: no-store` 付与 |
| `tests/Unit/` または `tests/Feature/` | ガードのテスト追加 |

---

## Phase 1: busy時は復帰画面（battle.resume）へ流す【原因A対応】

`redirectExploreRequestBusy()` の非AJAX分岐を変更する。

- 現状: `battle.result` へ error 付きリダイレクト → ボタンなし画面。
- 変更後: **探索状態が生きている場合（`hasActiveExploration()` が true）は `battle.resume`（探索中断・再開画面）へリダイレクト**し、flashメッセージで「探索処理が混み合っています。少し待ってから『探索を続ける』を押してください。」を表示する。
  - `battle/resume.blade.php` には既に「探索を続ける」（`$state->area_id` へ `continue_chain=1` POST）と「街に帰還する」の両ボタンがあるため、ビュー側の新規実装は不要。flashメッセージの表示欄だけ追加する。
- 探索状態がない場合（探索開始直後の連打など）は現状どおり `battle.result` の error 表示でよいが、Phase 2 の保険分岐によりボタンが出るようにする。
- AJAX分岐（409 + `Retry-After`）は変更しない。

## Phase 2: result.blade.php に「探索処理中」分岐を保険で追加【原因A対応】

`str_contains((string) $result['error'], '探索処理中')` の分岐を1585行の「連続で戦闘」分岐と同様の形で追加する。

- カウントダウン付き「探索を続ける」ボタン（`continue_chain=1`、待機秒数は `EXPLORE_REQUEST_DELAY_SECONDS` = 2秒固定でよい）。
- 分岐の並び順に注意: 既存の `'探索力'` 分岐より**前**に置くこと（「探索処理中」の文言は「探索力」を含まないため現状は衝突しないが、文言変更に備えて順序で守る）。

## Phase 3: `continue_chain` のエリア整合性ガード【原因B対応】

`BattleController::explore()` の先頭付近（`canEnterArea` チェックの後）に追加する。

```
if ($request->boolean('continue_chain')) {
    $state = app(ExplorationStateService::class)->currentFor($character);
    $hasActive = app(ExplorationStateService::class)->hasActiveExploration($character);
    if ($hasActive && (int) $state->area_id !== $areaId) {
        // 古いページのフォームからのPOST。現在の探索を壊さず復帰画面へ。
        return redirect()->route('battle.resume')
            ->with('message', '別の探索が進行中です。こちらの画面から再開してください。');
    }
}
```

- **探索状態を書き換えない**こと。redirect のみ。ここで reset や startAtDepth を呼ぶと本末転倒。
- ボス戦（`bossBattle`）側にも同種の `continue_chain` 経路があるか確認し、あれば同じガードを入れる。
- `travelDiscoveredArea()` は意図的にエリアを切り替える正規経路なので、ガードの対象外（`continue_chain=false` にマージ済みであることを確認するだけでよい）。

## Phase 4: 戦闘結果画面のキャッシュ抑止

`battle.result`・`battle.resume` を返すルートに `Cache-Control: no-store` を付与するミドルウェアを追加する（bfcacheで古い結果画面が復元されるのを抑止し、ブラウザバック時はサーバーへ再リクエストさせる）。

- ブラウザバック時の再GETは既存の `exploreGetFallback` / `resumeExploration` に落ち、Phase 1〜3 の導線で安全に復帰できる。
- 全ルートに付けない。対象は戦闘・探索系ビューのみ（負荷とUX悪化を避ける）。

---

## QAチェックリスト

1. 探索中（探索度>0）に探索ボタンを2秒以内に連打 → 復帰画面に飛び、「探索を続ける」で**同じエリア・同じ深度・同じ探索度**のまま続行できる。
2. 復帰画面で「街に帰還する」→ 従来どおり帰還し、戦利品は所持品に残る。
3. エリアAで最深層探索中に、エリアBの古い戦闘結果ページから「もう一度探索する」をPOST（ブラウザバック or フォーム改変で再現）→ エリアBの探索は始まらず、復帰画面に飛ぶ。DBの `character_exploration_states` の `area_id` / `depth_tier` / `exploration_point` が**一切変化していない**ことを確認。
4. 探索状態なし（新規開始直後）での連打 → エラー表示にカウントダウン付き「探索を続ける」ボタンが出る。デッドエンドにならない。
5. ×10探索（AJAX経路）のbusy時は従来どおり409 + リトライで、挙動が変わっていない。
6. 深度入口（depth_gate）確認画面 → 「進む」の流れが従来どおり動く（`skip_explore_request_delay` 経路のデグレ確認）。
7. ブラウザバックで戦闘結果画面に戻る → キャッシュ画面ではなくサーバーへ再リクエストされる（DevToolsのNetworkで確認）。

## 完了条件

- 上記QA 1〜7 が全て通ること。
- `php artisan test` の既存テストが通ること。Phase 3 のガードにはFeatureテスト（エリア不一致POSTで状態が変化しないこと）を1本以上追加すること。

## スコープ外（本タスクでは触らない）

- サーバー負荷そのものの対策（レンタルサーバーが重い問題は別課題。本タスクは負荷時でも進行が壊れないようにする対症設計）。
- クールタイム秒数（2秒）の調整。
- 「探索処理中」文言の変更。
