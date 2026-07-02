# 本番移設用 DB / Service 対応メモ

この試験実装では DB を作らず、週末カードシーズン仕様を `localStorage` 上の状態で代替している。
本番移設時は添付仕様書の `valmon_tower_*` テーブルへ移す。

## テーブル対応

| 仕様テーブル | 試験実装での対応 | 本番移設時 |
|---|---|---|
| `valmon_tower_seasons` | `currentSeason()` | `ValmonTowerSeasonService::currentSeason()` |
| `valmon_tower_card_masters` | `config.js` の `cards` | migration + seeder |
| `valmon_tower_season_card_pool` | `activeCardCodes` / `dormantCardCodes` | シーズン生成時に80/20を保存 |
| `valmon_tower_player_states` | `state.tower` | `lockForUpdate()` で進行状態を更新 |
| `valmon_tower_player_cards` | `state.tower.ownedCardCodes` | シーズン所持カードとして保存 |
| `valmon_tower_deck_cards` | `state.tower.deckCardCodes` | 装備上限検証後に保存 |
| `valmon_tower_card_choices` | `run.pendingChoices` の `card_reward` | 候補生成時に保存し、選択まで固定 |
| `valmon_tower_floor_results` | `run.events` | 階層挑戦ログ保存 |
| `valmon_tower_bp_transactions` | `bp` / `spentBp` / `upgrades` | BP獲得・消費履歴として保存 |
| `valmon_tower_rankings` | `state.rankings[seasonCode]` | `ValmonTowerRankingService::updateRanking()` |
| `valmon_tower_coin_advances` | 未実装 | 課金先取りを入れる段階で追加 |

## Service 対応

| 本番Service | 試験実装の主な関数 |
|---|---|
| `ValmonTowerSeasonService` | `currentSeason`, `seasonCardPool` |
| `ValmonTowerCoinService` | `naturalUnlockedCoinCount`, `availableCoinCount` |
| `ValmonTowerCardChoiceService` | `cardChoiceOptions`, `beginCoinCardChoice`, `beginBpCardChoice`, `applyCardChoice` |
| `ValmonTowerDeckService` | `toggleOrb`, `renderOrbList` |
| `ValmonTowerBpService` | `applyFloorClearRewards`, `spendBp` |
| `ValmonTowerProgressService` | `advanceRun`, `resolveCombat`, `resolveTrap`, `resolveTreasure`, `resolveRest`, `resolveOmen` |
| `ValmonTowerRankingService` | `updateRanking`, `sortRanking`, `isBetterRanking` |

## 本番実装時に必ずサーバー側で再検証するもの

- 対象ヴァルモンがログインキャラクターの所有物か
- 現在シーズンが開催中か
- pending choice がある場合、新しいカード3択を生成できないか
- 金貨が足りるか
- 深層BPが足りるか
- デッキカードがすべて所持カードか
- デッキ装備数が上限内か
- 休眠カードが取得候補に入っていないか
- 同じ階層の初回カード報酬を二重取得していないか
- 同じ階層のBP報酬を二重取得していないか
- 探索力が1以上あるか

## 注意

この試験実装は UI/ゲーム感触の確認用であり、本番の不正対策・DB transaction・排他制御は未実装。
