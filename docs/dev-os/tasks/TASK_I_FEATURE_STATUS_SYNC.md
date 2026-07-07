# 調査指示書: docs/FEATURE_STATUS.md の実態同期

## 目的

FEATURE_STATUS.md の未確認行（`?`）をコードを根拠に埋め、AIエージェントが「実装済みかどうか」を毎回コードから探し直すトークン浪費と誤判定をなくす。今後の自律運用（定期監査エージェント）の基準表にする。

## 背景

- docs/dev-os/README.md「次に整備すべきタスク」4番。
- AI_CONTEXT.md の「Known gaps」にも「FEATURE_STATUS.md is not yet synced against actual code」と明記されている。
- これは**調査タスク**であり、ゲームコードの変更は行わない。

## 現状の問題

1. Legend は `D/P/N/?/X` だが、21行目 Adventurer support pass に凡例にない **`B`** が使われている。
2. `?` のまま放置の行: Login / Battle / Market / Exploration（部分）/ Public logs（部分）/ Valmon（部分）。
3. Evidence 列が `<file/route>` プレースホルダのままの行がある。

## 実装対象

1. **凡例違反の修正**: `B` を凡例内の適切な値に置き換える（実態は「実装済みだがフラグOFFで非公開」なので、`P` にして Notes に「SUPPORT_PASS_ENABLED=false で非公開」を維持、または凡例に新ステータスを追加提案。**勝手に凡例を増やさず、どちらにすべきか報告に含めて人間の承認を得る**）。
2. **`?` 行の調査と確定**: 各行についてコードを実際に読み、以下を埋める:
   - St: D（動作コードあり・機能一式そろう）/ P（一部のみ）/ N（未実装）。**コードが証明しない限りDにしない**（AGENTS.md「Do not mark a feature implemented unless code exists」）。
   - Evidence: 実在するService/Controller/route/テーブル名（推測で書かない。ファイルを開いて確認したものだけ)
   - Notes: 1〜3行に収める。長文化しない（詳細は AI_CONTEXT_ARCHIVE.md / DOMAIN_RULES.md の領分）。
   - 調査の起点: routes/web.php、docs/CODEMAP.md、app/Services/、app/Livewire/。
3. **既存の長文Notesの圧縮は対象外だが**、明らかに事実と食い違う記述を見つけた場合は行を直さず「要裁定リスト」として報告する。

## 実装対象外（重要）

- ゲームコード（app/、database/、routes/）の変更は一切しない。読むだけ。
- FEATURE_STATUS.md への新機能行の追加は最小限（調査中に「表にない主要機能」を見つけたら、行追加ではなく報告に列挙して人間の判断を仰ぐ)。
- AI_CONTEXT.md / DOMAIN_RULES.md の更新はしない（食い違いは報告のみ）。

## DB変更: なし

## 調査の進め方（推奨）

- 1行ずつ「route → Controller/Livewire → Service → 画面」の順で存在確認する。
- 「全機能を動かして検証」までは不要。**コードの存在と結線の確認**で D/P/N を判定してよい。動作未確認の注意点は Notes に「動作未検証」と書く。
- Login はGoogle OAuth + 1アカウント1キャラのガード（CharacterCreate / CharacterSelect の二重ガード）まで確認する。

## 完了条件

- [ ] FEATURE_STATUS.md に `?` と `<file/route>` プレースホルダが残っていない（意図的に残す場合は理由を報告）。
- [ ] 全ステータス値が凡例内である（`B` の扱いは人間承認を経て確定）。
- [ ] 要裁定リスト（コードとdocsの食い違い、表にない主要機能）が報告に含まれている。空なら「なし」と明記。
- [ ] AI_CONTEXT.md「Known gaps」の該当行の更新要否を報告（更新自体は本タスク外）。

## 更新情報サマリ: 不要（AI向けdocs整理のみ）
