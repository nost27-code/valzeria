# 冒険都市ヴァルゼリア 戦闘システム実装指示書

## 1. 目的

本実装では、FFA風ブラウザRPG「冒険都市ヴァルゼリア」における戦闘システムを実装する。

本ゲームの戦闘は、昔ながらのCGIゲームらしいシンプルな自動戦闘を基本とする。

ただし、単純な通常攻撃の殴り合いだけではなく、以下の要素によって育成の意味と職業ごとの個性を出す。

* 職業補正による能力差
* 物理攻撃と魔法攻撃の違い
* 素早さによる先制・回避
* 運によるクリティカル・特殊判定
* 職業スキルの自動発動
* 状態異常
* 敵タイプによる相性
* 戦闘ログによる臨場感
* 戦闘勝利時の経験値・ゴールド・職業経験値・ドロップ報酬

本システムの中心は、プレイヤーに「自分の職業や育成方針が戦闘結果に影響している」と感じさせること。

戦闘操作はシンプルでよい。
ただし、内部計算は今後拡張しやすい形にする。

## 2. 基本方針

戦闘は完全オートで進行する。

プレイヤーは戦闘前に以下を準備する。

* 現在職業
* 装備
* 継承スキル
* 挑戦するエリアまたは敵

戦闘中に手動でコマンド選択は行わない。

戦闘開始後は、システムが自動で以下を処理する。

* 先制判定
* ターン進行
* 通常攻撃
* スキル発動判定
* 命中判定
* 回避判定
* クリティカル判定
* ダメージ計算
* 状態異常処理
* 勝敗判定
* 戦闘ログ生成
* 報酬付与

この方針により、FFA風ゲームらしいテンポの良さを保ちながら、職業・能力値・スキル・装備の差が出る戦闘にする。

## 3. 戦闘の全体フロー

戦闘処理は以下の順番で進める。

```text
1. プレイヤー情報取得
2. 敵情報取得
3. プレイヤー最終ステータス計算
4. 敵最終ステータス計算
5. 戦闘開始ログ生成
6. 先制判定
7. ターン開始
8. 状態異常の継続処理
9. 行動順決定
10. 行動者のスキル発動判定
11. 攻撃種別決定
12. 命中・回避判定
13. クリティカル判定
14. ダメージ計算
15. HP反映
16. 状態異常付与判定
17. 戦闘不能判定
18. 最大ターン到達判定
19. 勝敗確定
20. 報酬計算
21. 経験値・ゴールド・職業経験値・ドロップ付与
22. 戦闘結果ログ表示
```

## 4. 戦闘形式

### 4.1 基本形式

戦闘は 1対1 を基本とする。

初期実装では以下の形式にする。

* プレイヤー1人 対 敵1体
* 完全オート戦闘
* ターン制
* 最大ターン数あり
* 勝利、敗北、時間切れ敗北の3パターン

パーティ戦や複数敵との戦闘は後続実装でよい。

### 4.2 最大ターン数

初期実装では最大ターン数を 30ターン とする。

30ターン以内に敵を倒せなかった場合、プレイヤーの敗北扱いにする。

理由：

* 防御特化職が無限に粘る問題を防ぐ
* 戦闘ログが長くなりすぎるのを防ぐ
* 周回テンポを保つ
* 攻撃力不足の状態で無理に高難度へ行くことを防ぐ

将来的にボス戦のみ最大50ターンなど、敵ごとに設定できるようにする。

## 5. 能力値の役割

本ゲームでは以下の能力値を戦闘に使用する。

| 能力  | 役割                  |
| --- | ------------------- |
| HP  | 0になると敗北。耐久力         |
| MP  | スキル・魔法の使用資源         |
| 攻撃  | 物理ダメージに影響           |
| 防御  | 物理ダメージ軽減に影響         |
| 魔力  | 魔法ダメージに影響           |
| 精神  | 魔法ダメージ軽減・回復効果に影響    |
| 素早さ | 先制、行動順、回避に影響        |
| 運   | クリティカル、ドロップ、特殊判定に影響 |

## 6. ステータス計算方針

### 6.1 最終ステータスの計算順

プレイヤーの最終ステータスは、以下の順番で計算する。

```text
最終ステータス
= 基礎ステータス
× 現在職業補正
× マスターボーナス補正
× 装備補正
× バフ・デバフ補正
```

計算順は必ず統一すること。

戦闘処理内で直接計算式を分散させず、PlayerStatusService で集約する。

### 6.2 職業補正

職業補正は jobs テーブルの各 rate を使用する。

例：

* 攻撃 225 → 2.25倍
* 防御 75 → 0.75倍
* 魔力 50 → 0.50倍

計算例：

```php
$finalAtk = floor($baseAtk * ($job->atk_rate / 100));
```

### 6.3 敵ステータス

敵もプレイヤーと同じ能力値を持つ。

敵ステータス：

* HP
* MP
* 攻撃
* 防御
* 魔力
* 精神
* 素早さ
* 運

敵もスキル、属性、状態異常耐性を持てるように設計する。

## 7. 物理攻撃と魔法攻撃

### 7.1 物理攻撃

物理攻撃は、攻撃と防御を元に計算する。

基本式：

```text
物理基礎ダメージ = 攻撃側の攻撃 × スキル倍率
防御軽減後ダメージ = 物理基礎ダメージ × 100 ÷ (100 + 防御側の防御)
```

最低ダメージは 1 とする。

最終的に乱数補正をかける。

```text
最終物理ダメージ = 防御軽減後ダメージ × 乱数補正
```

乱数補正は 0.90 から 1.10 とする。

### 7.2 魔法攻撃

魔法攻撃は、魔力と精神を元に計算する。

基本式：

```text
魔法基礎ダメージ = 攻撃側の魔力 × スキル倍率
精神軽減後ダメージ = 魔法基礎ダメージ × 100 ÷ (100 + 防御側の精神)
```

最低ダメージは 1 とする。

最終的に乱数補正をかける。

```text
最終魔法ダメージ = 精神軽減後ダメージ × 乱数補正
```

乱数補正は 0.90 から 1.10 とする。

### 7.3 通常攻撃

通常攻撃は物理攻撃として扱う。

通常攻撃のスキル倍率は 1.00 とする。

```text
通常攻撃ダメージ = 攻撃 × 1.00 × 100 ÷ (100 + 防御)
```

## 8. ダメージ計算の意図

防御や精神を単純な引き算にしない。

理由は、攻撃力が低い場合にダメージが0になりやすく、ゲームとして気持ちよくないため。

本仕様では割合軽減にする。

例：

攻撃300、防御100の場合

```text
300 × 100 ÷ (100 + 100) = 150
```

攻撃300、防御300の場合

```text
300 × 100 ÷ (100 + 300) = 75
```

防御が高いほどダメージは減るが、完全に0にはなりにくい。

これにより、防御職も強くなりすぎず、攻撃職も最低限ダメージを出せる。

## 9. 命中・回避判定

### 9.1 基本命中率

基本命中率は 90％ とする。

そこに攻撃側と防御側の素早さ差を反映する。

```text
命中率 = 90 + 攻撃側命中補正 - 防御側回避補正
```

初期実装では、命中率は以下の式にする。

```text
命中率 = 90 + ((攻撃側の素早さ - 防御側の素早さ) ÷ 20)
```

下限と上限を設定する。

```text
最低命中率：60％
最高命中率：98％
```

### 9.2 回避型職業への配慮

忍者や盗賊のような素早さ特化職は、回避で強みが出る。

ただし、回避しすぎると戦闘が不安定になりすぎるため、最低命中率を60％にする。

これにより、どれだけ素早さ差があっても完全回避職にはならない。

### 9.3 命中補正・回避補正

スキルや装備で命中率・回避率に補正を入れられるようにする。

例：

* 狙撃手スキル：命中率＋10％
* 忍者スキル：回避率＋10％
* 暗闇状態：命中率－30％

最終的な命中率にも下限・上限を適用する。

## 10. クリティカル判定

### 10.1 基本クリティカル率

基本クリティカル率は 5％ とする。

運によってクリティカル率を上げる。

```text
クリティカル率 = 5 + (攻撃側の運 ÷ 100)
```

例：

運100 → 6％
運300 → 8％
運500 → 10％

### 10.2 上限

クリティカル率の上限は 30％ とする。

装備、スキル、マスターボーナスを含めても、初期実装では最大30％。

ただし、特定のスキル効果中だけ一時的に上限を超える仕様は後続で検討してよい。

### 10.3 クリティカル倍率

クリティカル発生時、最終ダメージを 1.5倍 にする。

```text
クリティカルダメージ = 通常ダメージ × 1.5
```

剣聖や狙撃手などの一部職業・スキルでは、クリティカル倍率を上げてもよい。

ただし初期実装では固定1.5倍でよい。

## 11. 先制・行動順

### 11.1 基本方針

基本的には素早さが高い方が先に行動する。

ただし、毎回完全固定にすると結果が単調になるため、少し乱数を入れる。

```text
行動値 = 素早さ × 乱数補正
```

乱数補正は 0.85 から 1.15 とする。

行動値が高い方が先に行動する。

### 11.2 先制スキル

盗賊・忍者系には、戦闘開始時の先制スキルを持たせてもよい。

例：

* 盗賊：10％で先制攻撃
* 忍者：20％で先制攻撃
* 神速系職業：30％で先制攻撃

先制攻撃が発動した場合、通常の行動順に関係なく最初に行動する。

### 11.3 同値の場合

行動値が同じ場合はプレイヤー優先でよい。

理由はプレイヤー体験を少し優先するため。

## 12. スキル仕様

### 12.1 基本方針

スキルは完全自動発動とする。

プレイヤーは戦闘中にスキルを選ばない。

各ターン、行動者ごとにスキル発動判定を行う。

発動条件を満たしたスキルの中から、発動可能なものを抽選する。

### 12.2 スキルの種類

スキル種別は以下とする。

| 種別              | 内容        |
| --------------- | --------- |
| physical_attack | 物理攻撃スキル   |
| magic_attack    | 魔法攻撃スキル   |
| heal            | 回復スキル     |
| buff            | 自己強化・能力上昇 |
| debuff          | 敵弱体化      |
| status          | 状態異常付与    |
| passive         | 常時発動      |
| start_battle    | 戦闘開始時発動   |
| end_battle      | 戦闘終了時発動   |

### 12.3 スキル発動率

スキルには発動率を設定する。

例：

| スキル    |         発動率 |
| ------ | ----------: |
| 強斬り    |         20％ |
| ファイア   |         25％ |
| ヒール    | HP50％以下で30％ |
| 影避け    |     被攻撃時15％ |
| 商人の目利き |   戦闘勝利時100％ |

### 12.4 MP消費

攻撃魔法・回復魔法・強力なスキルはMPを消費する。

MPが不足している場合、そのスキルは発動しない。

物理スキルはMP消費なしでもよいが、強力なものはMPまたはスタミナ相当のリソースを消費してもよい。

初期実装では、MP消費だけで管理する。

### 12.5 スキル抽選

1ターンに発動できるメインスキルは1つまでとする。

複数スキルが発動条件を満たす場合は、優先度と発動率で判定する。

初期実装ではシンプルに以下でよい。

```text
1. 発動可能スキルを取得
2. priority の高い順に並べる
3. 上から順に発動率判定
4. 最初に成功したスキルを発動
5. 成功しなければ通常攻撃
```

## 13. 初期スキル案

### 13.1 一般職スキル

| 職業   | スキル  | 種別              | 効果             |
| ---- | ---- | --------------- | -------------- |
| 剣士   | 強斬り  | physical_attack | 攻撃1.3倍         |
| 戦士   | 渾身撃  | physical_attack | 攻撃1.5倍、命中－10％  |
| 盗賊   | 不意打ち | physical_attack | 先制時に攻撃1.4倍     |
| 弓使い  | 狙い撃ち | physical_attack | 攻撃1.25倍、命中＋10％ |
| 格闘家  | 連撃   | physical_attack | 攻撃0.75倍を2回     |
| 魔法使い | ファイア | magic_attack    | 魔力1.5倍         |
| 僧侶   | ヒール  | heal            | HPを精神0.8倍分回復   |
| 商人   | 目利き  | end_battle      | ゴールド獲得＋5％      |

### 13.2 中級職スキル

| 職業   | スキル     | 種別              | 効果                 |
| ---- | ------- | --------------- | ------------------ |
| 魔法剣士 | 魔法斬り    | magic_attack    | 魔力＋攻撃を参照する複合攻撃     |
| 聖騎士  | 聖なる守り   | buff            | 3ターン防御＋25％         |
| 狂戦士  | 捨て身撃    | physical_attack | 攻撃2.0倍、自分にも反動      |
| 忍者   | 影縫い     | status          | ダメージ＋麻痺付与判定        |
| 狙撃手  | 急所狙い    | physical_attack | 攻撃1.4倍、クリティカル率＋15％ |
| 司祭   | グレートヒール | heal            | HPを精神1.5倍分回復       |
| 旅商人  | 値切りの勘   | end_battle      | ゴールド獲得＋10％         |
| 錬金術師 | 爆薬瓶     | magic_attack    | 魔力1.4倍、低確率で火傷      |

### 13.3 上級職スキル

| 職業   | スキル   | 種別              | 効果                 |
| ---- | ----- | --------------- | ------------------ |
| 勇者   | 英雄の一撃 | physical_attack | 攻撃1.6倍、安定高命中       |
| 剣聖   | 一閃    | physical_attack | 攻撃2.2倍、クリティカル率＋10％ |
| 大賢者  | メテオ   | magic_attack    | 魔力2.2倍、MP消費大       |
| 暗黒騎士 | 暗黒剣   | physical_attack | 攻撃2.3倍、自HP消費       |
| 黄金商人 | 黄金の嗅覚 | end_battle      | ゴールドとレア取引判定に補正     |

## 14. 複合攻撃の扱い

魔法剣士などは、攻撃と魔力を両方参照する複合スキルを持つ。

例：

```text
魔法斬り基礎値 = 攻撃 × 0.8 + 魔力 × 0.8
```

防御側の軽減は、防御と精神の平均を使う。

```text
複合軽減値 = (防御 + 精神) ÷ 2
```

最終式：

```text
魔法斬りダメージ = (攻撃 × 0.8 + 魔力 × 0.8) × 100 ÷ (100 + 複合軽減値)
```

このようにすると、魔法剣士のようなハイブリッド職が機能しやすい。

## 15. 回復スキル

### 15.1 回復量

回復スキルは精神を参照する。

基本式：

```text
回復量 = 精神 × スキル倍率 × 回復補正
```

回復補正には、僧侶系のマスターボーナスや装備補正を含める。

### 15.2 回復発動条件

回復スキルは、HPが一定以下のときのみ発動候補に入れる。

例：

* ヒール：HP50％以下
* グレートヒール：HP40％以下
* 自動回復系：ターン終了時

### 15.3 回復の注意点

回復が強すぎると戦闘が長引く。

そのため、以下の制約を設ける。

* MPを消費する
* 発動率を設定する
* 最大ターン数を設定する
* 回復量は最大HPを超えない
* 同一ターンに複数回復は原則不可

## 16. 状態異常仕様

### 16.1 初期実装する状態異常

初期実装では、以下の状態異常を実装する。

| 状態異常      | 効果                   |
| --------- | -------------------- |
| poison    | 毒。ターン終了時に最大HPの5％ダメージ |
| paralysis | 麻痺。一定確率で行動不能         |
| sleep     | 睡眠。行動不能。被ダメージで解除判定   |
| silence   | 沈黙。魔法スキル使用不可         |
| burn      | 火傷。物理攻撃力低下＋継続ダメージ    |
| blind     | 暗闇。命中率低下             |

### 16.2 状態異常の基本ルール

* 状態異常には継続ターン数を持たせる
* 同じ状態異常を重ねがけした場合は、原則としてターン数を上書きする
* 毒や火傷のダメージでHPが0になることを許可する
* ボスには状態異常耐性を持たせる
* 状態異常付与率には上限と下限を設定する

### 16.3 状態異常付与率

基本式：

```text
付与率 = スキル基本付与率 + 攻撃側の運補正 - 防御側の運補正 - 敵耐性
```

初期実装では複雑にしすぎず、以下でよい。

```text
付与率 = スキル基本付与率 - 対象の状態異常耐性
```

下限・上限：

```text
最低付与率：5％
最高付与率：80％
```

完全無効の敵は、耐性値ではなく immune フラグで管理する。

## 17. 属性仕様

### 17.1 初期実装方針

属性は最初から重く作りすぎない。

初期実装では、以下の属性を用意する。

| 属性      | 用途  |
| ------- | --- |
| none    | 無属性 |
| fire    | 火   |
| ice     | 氷   |
| thunder | 雷   |
| light   | 光   |
| dark    | 闇   |

### 17.2 属性相性

初期実装では、複雑なじゃんけん相性は作らない。

敵ごとに弱点属性と耐性属性を持たせる。

例：

| 敵     | 弱点    | 耐性   |
| ----- | ----- | ---- |
| スライム  | fire  | none |
| アンデッド | light | dark |
| 火の精霊  | ice   | fire |
| 闇の魔族  | light | dark |

### 17.3 属性倍率

| 相性 |   倍率 |
| -- | ---: |
| 弱点 | 1.5倍 |
| 通常 | 1.0倍 |
| 耐性 | 0.5倍 |
| 無効 |   0倍 |

属性倍率はダメージ計算の最後に反映する。

```text
最終ダメージ = ダメージ × 属性倍率
```

## 18. 敵タイプ仕様

敵にはタイプを持たせる。

| 敵タイプ    | 特徴           |
| ------- | ------------ |
| beast   | 獣系。素早い       |
| armored | 甲殻・重装系。防御が高い |
| undead  | 不死系。光や回復に弱い  |
| demon   | 魔族系。魔法攻撃が強い  |
| dragon  | 竜系。高HP・高火力   |
| thief   | 盗賊系。回避が高い    |
| spirit  | 精霊系。魔法耐性が高い  |
| slime   | 粘体系。初心者向け    |

敵タイプは、スキルや職業特攻の判定に使用する。

例：

* 弓使い系：beast にダメージ＋20％
* 聖騎士系：undead にダメージ＋20％
* 竜騎士系：dragon にダメージ＋30％

初期実装では特攻処理は未実装でもよいが、enemy_type はDBに持たせておく。

## 19. 報酬仕様

### 19.1 報酬の種類

戦闘勝利時に以下を付与する。

* 通常経験値
* ゴールド
* 職業経験値
* 通常ドロップ
* レアドロップ
* 素材
* 称号・実績進行

初期実装では、経験値・ゴールド・職業経験値・ドロップのみでよい。

### 19.2 ゴールド補正

商人系の職業やマスターボーナスにより、獲得ゴールドを補正する。

```text
獲得ゴールド = 基礎ゴールド × ゴールド補正
```

ゴールド補正は積み上げすぎると経済が壊れるため、初期上限を 2.0倍 とする。

```text
ゴールド補正上限：200％
```

### 19.3 ドロップ判定

ドロップは敵ごとに設定する。

ドロップテーブル例：

| item_key  | drop_rate | rarity   |
| --------- | --------: | -------- |
| herb      |        30 | common   |
| iron_ore  |        10 | uncommon |
| old_sword |         3 | rare     |

drop_rate はパーセントで管理する。

### 19.4 運によるドロップ補正

運はドロップ率に直接大きく影響させない。

以下のように控えめにする。

```text
ドロップ補正 = 1 + min(運 ÷ 1000, 0.30)
```

つまり運が高くても、運によるドロップ率補正は最大＋30％まで。

例：

運100 → 1.10倍
運300 → 1.30倍
運500 → 1.30倍 上限到達

黄金商人や盗賊系の職業補正を別途加える場合も、最終ドロップ補正上限を設定する。

```text
最終ドロップ補正上限：2.0倍
```

## 20. 職業経験値との連携

戦闘勝利時、現在職業に職業経験値を付与する。

職業経験値は JobService に委譲する。

```php
$jobResult = $jobService->addJobExp($player, $enemy->job_exp_reward);
```

戦闘サービス側では、職業レベルアップやマスター処理を直接書かない。

JobService の戻り値を受け取り、戦闘ログに反映する。

例：

```text
職業経験値を5獲得した。
剣士の職業レベルが7に上がった。
剣士をマスターした。
新しい職業「魔法剣士」が解放された。
```

## 21. 敗北時仕様

### 21.1 初期実装

敗北時のペナルティは軽めにする。

初期実装では以下を推奨する。

* 通常経験値なし
* 職業経験値なし
* ゴールド獲得なし
* ドロップなし
* 所持金減少なし
* 装備ロストなし

理由は、現代のブラウザゲームとして理不尽さを避けるため。

### 21.2 将来的な拡張

後続で以下を検討してよい。

* 敗北時に少量の職業経験値
* HP回復費用
* ダンジョン探索失敗扱い
* 連勝ボーナスリセット
* 高難度エリアのみ挑戦料消費

初期実装では不要。

## 22. 戦闘ログ仕様

### 22.1 基本方針

戦闘ログは本ゲームの重要な面白さになる。

FFA風ゲームは画面演出が少ないため、ログで気持ちよさを作る。

ログは短く、分かりやすく、職業らしさが出る文章にする。

### 22.2 ログの種類

| 種類            | 例              |
| ------------- | -------------- |
| battle_start  | スライムが現れた！      |
| turn_start    | 1ターン目          |
| normal_attack | 剣士の攻撃！         |
| skill_attack  | 剣士は強斬りを放った！    |
| magic_attack  | 魔法使いはファイアを唱えた！ |
| heal          | 僧侶はヒールを唱えた！    |
| miss          | 攻撃は外れた！        |
| critical      | 会心の一撃！         |
| damage        | スライムに120ダメージ！  |
| status_apply  | スライムは毒にかかった！   |
| status_damage | 毒により30ダメージ！    |
| defeated      | スライムを倒した！      |
| victory       | 戦闘に勝利した！       |
| defeat        | 戦闘に敗北した        |
| reward        | 経験値を獲得した       |
| job_exp       | 職業経験値を獲得した     |
| job_level_up  | 職業レベルが上がった     |
| job_mastered  | 職業をマスターした      |
| job_unlocked  | 新しい職業が解放された    |

### 22.3 ログ例

```text
スライムが現れた！

1ターン目
剣士の攻撃！
スライムに42ダメージ！

スライムの攻撃！
剣士に8ダメージ！

2ターン目
剣士は強斬りを放った！
会心の一撃！
スライムに95ダメージ！

スライムを倒した！
戦闘に勝利した！

経験値を20獲得した。
ゴールドを12G獲得した。
職業経験値を5獲得した。
```

### 22.4 職業らしいログ

職業ごとにログ文言を変えると、戦闘の味が出る。

例：

剣士：

```text
剣士は鋭く踏み込み、強斬りを放った！
```

魔法使い：

```text
魔法使いは火球を生み出し、敵へ放った！
```

忍者：

```text
忍者は影に溶け、敵の背後を取った！
```

黄金商人：

```text
黄金商人は戦利品の価値を見抜いた！
```

ログ文言はDBまたは設定ファイルで管理してもよい。

初期実装ではコード内定義でもよいが、将来的にはマスターデータ化する。

## 23. DB設計

### 23.1 enemies テーブル

敵の基本情報を管理する。

```php
Schema::create('enemies', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->string('name');

    $table->string('enemy_type')->default('slime');
    $table->string('element')->default('none');

    $table->unsignedInteger('level')->default(1);

    $table->unsignedInteger('hp');
    $table->unsignedInteger('mp')->default(0);
    $table->unsignedInteger('atk');
    $table->unsignedInteger('def');
    $table->unsignedInteger('mag')->default(0);
    $table->unsignedInteger('spr')->default(0);
    $table->unsignedInteger('spd')->default(0);
    $table->unsignedInteger('luck')->default(0);

    $table->unsignedInteger('exp_reward')->default(0);
    $table->unsignedInteger('gold_reward')->default(0);
    $table->unsignedInteger('job_exp_reward')->default(0);

    $table->unsignedTinyInteger('max_turns')->default(30);

    $table->boolean('is_boss')->default(false);
    $table->boolean('is_active')->default(true);

    $table->timestamps();
});
```

### 23.2 enemy_resistances テーブル

敵の属性耐性・弱点を管理する。

```php
Schema::create('enemy_resistances', function (Blueprint $table) {
    $table->id();

    $table->foreignId('enemy_id')->constrained('enemies')->cascadeOnDelete();

    $table->string('element');
    $table->integer('rate')->default(100);

    $table->timestamps();

    $table->unique(['enemy_id', 'element']);
});
```

rate の意味：

| rate | 意味      |
| ---: | ------- |
|  150 | 弱点。1.5倍 |
|  100 | 通常      |
|   50 | 耐性。0.5倍 |
|    0 | 無効      |

### 23.3 enemy_status_resistances テーブル

敵の状態異常耐性を管理する。

```php
Schema::create('enemy_status_resistances', function (Blueprint $table) {
    $table->id();

    $table->foreignId('enemy_id')->constrained('enemies')->cascadeOnDelete();

    $table->string('status_key');
    $table->unsignedTinyInteger('resistance_rate')->default(0);
    $table->boolean('is_immune')->default(false);

    $table->timestamps();

    $table->unique(['enemy_id', 'status_key']);
});
```

resistance_rate は付与率から差し引く値。

例：

* resistance_rate 20 → 付与率－20％
* is_immune true → 完全無効

### 23.4 skills テーブル

スキルマスターを管理する。

```php
Schema::create('skills', function (Blueprint $table) {
    $table->id();

    $table->string('key')->unique();
    $table->string('name');

    $table->enum('skill_type', [
        'physical_attack',
        'magic_attack',
        'hybrid_attack',
        'heal',
        'buff',
        'debuff',
        'status',
        'passive',
        'start_battle',
        'end_battle'
    ]);

    $table->string('element')->default('none');

    $table->unsignedSmallInteger('power_rate')->default(100);
    $table->unsignedTinyInteger('activation_rate')->default(100);
    $table->unsignedInteger('mp_cost')->default(0);

    $table->unsignedTinyInteger('priority')->default(100);

    $table->string('condition_type')->nullable();
    $table->integer('condition_value')->nullable();

    $table->string('status_key')->nullable();
    $table->unsignedTinyInteger('status_rate')->nullable();
    $table->unsignedTinyInteger('status_turns')->nullable();

    $table->text('description')->nullable();
    $table->text('log_message')->nullable();

    $table->boolean('is_active')->default(true);

    $table->timestamps();
});
```

### 23.5 job_skills テーブル

職業とスキルの紐づけを管理する。

```php
Schema::create('job_skills', function (Blueprint $table) {
    $table->id();

    $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
    $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();

    $table->unsignedTinyInteger('learn_job_level')->default(1);
    $table->boolean('is_master_skill')->default(false);
    $table->boolean('can_inherit')->default(false);

    $table->timestamps();

    $table->unique(['job_id', 'skill_id']);
});
```

### 23.6 enemy_skills テーブル

敵とスキルの紐づけを管理する。

```php
Schema::create('enemy_skills', function (Blueprint $table) {
    $table->id();

    $table->foreignId('enemy_id')->constrained('enemies')->cascadeOnDelete();
    $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();

    $table->unsignedTinyInteger('use_rate')->default(100);

    $table->timestamps();

    $table->unique(['enemy_id', 'skill_id']);
});
```

### 23.7 enemy_drops テーブル

敵のドロップを管理する。

```php
Schema::create('enemy_drops', function (Blueprint $table) {
    $table->id();

    $table->foreignId('enemy_id')->constrained('enemies')->cascadeOnDelete();

    $table->string('item_key');
    $table->unsignedInteger('drop_rate')->default(0);
    $table->string('rarity')->default('common');
    $table->unsignedInteger('min_quantity')->default(1);
    $table->unsignedInteger('max_quantity')->default(1);

    $table->timestamps();
});
```

drop_rate はパーセントの100倍で管理してもよい。

例：

* 30％ → 3000
* 3％ → 300
* 0.5％ → 50

小数ドロップ率を扱いたい場合は、10000分率にする。

推奨は10000分率。

## 24. サービス設計

戦闘関連ロジックは BattleService に集約する。

Controller に戦闘計算を直接書かない。

### 24.1 BattleService の責務

BattleService は以下を担当する。

* 戦闘開始処理
* プレイヤーステータス取得
* 敵ステータス取得
* ターン進行
* 行動順判定
* スキル発動判定
* 命中判定
* ダメージ計算
* 状態異常処理
* 勝敗判定
* 戦闘ログ生成
* 報酬計算
* JobService との連携

### 24.2 関連サービス

| サービス                | 責務             |
| ------------------- | -------------- |
| BattleService       | 戦闘全体の進行        |
| DamageCalculator    | ダメージ計算         |
| SkillService        | スキル発動判定・効果適用   |
| StatusEffectService | 状態異常処理         |
| RewardService       | 報酬計算           |
| PlayerStatusService | プレイヤー最終ステータス計算 |
| JobService          | 職業経験値・マスター処理   |

初期実装では BattleService にある程度まとめてもよいが、DamageCalculator と RewardService は分けた方がよい。

## 25. BattleService メソッド案

```php
class BattleService
{
    public function fight(Player $player, Enemy $enemy): BattleResult
    {
        // 戦闘全体を実行する
    }

    private function initializeBattle(Player $player, Enemy $enemy): BattleState
    {
        // 戦闘状態を初期化する
    }

    private function processTurn(BattleState $state): void
    {
        // 1ターン分の処理
    }

    private function decideActionOrder(BattleActor $playerActor, BattleActor $enemyActor): array
    {
        // 行動順を決める
    }

    private function processAction(BattleState $state, BattleActor $actor, BattleActor $target): void
    {
        // 1行動分の処理
    }

    private function checkBattleEnd(BattleState $state): bool
    {
        // 勝敗判定
    }

    private function buildResult(BattleState $state): BattleResult
    {
        // 戦闘結果を作成する
    }
}
```

## 26. BattleState 設計

戦闘中の状態を管理するため、専用DTOまたはクラスを作る。

```php
class BattleState
{
    public BattleActor $player;
    public BattleActor $enemy;

    public int $turn = 1;
    public int $maxTurns = 30;

    public array $logs = [];

    public bool $isFinished = false;
    public string $result = 'running'; // running / victory / defeat / timeout

    public array $rewards = [];
}
```

## 27. BattleActor 設計

プレイヤーと敵を同じ形式で扱うためのクラス。

```php
class BattleActor
{
    public string $type; // player / enemy
    public string $name;

    public int $maxHp;
    public int $hp;

    public int $maxMp;
    public int $mp;

    public int $atk;
    public int $def;
    public int $mag;
    public int $spr;
    public int $spd;
    public int $luck;

    public array $skills = [];
    public array $statusEffects = [];

    public ?string $element = null;
    public ?string $enemyType = null;
}
```

プレイヤーも敵も BattleActor に変換してから戦闘処理を行う。

## 28. ダメージ計算クラス

DamageCalculator を作成する。

### 28.1 メソッド案

```php
class DamageCalculator
{
    public function calculatePhysicalDamage(
        BattleActor $attacker,
        BattleActor $defender,
        int $powerRate = 100,
        string $element = 'none',
        bool $isCritical = false
    ): int {
        // 物理ダメージ計算
    }

    public function calculateMagicDamage(
        BattleActor $attacker,
        BattleActor $defender,
        int $powerRate = 100,
        string $element = 'none',
        bool $isCritical = false
    ): int {
        // 魔法ダメージ計算
    }

    public function calculateHybridDamage(
        BattleActor $attacker,
        BattleActor $defender,
        int $powerRate = 100,
        string $element = 'none',
        bool $isCritical = false
    ): int {
        // 複合ダメージ計算
    }
}
```

### 28.2 物理ダメージ例

```php
public function calculatePhysicalDamage(
    BattleActor $attacker,
    BattleActor $defender,
    int $powerRate = 100,
    string $element = 'none',
    bool $isCritical = false
): int {
    $base = $attacker->atk * ($powerRate / 100);

    $reduced = $base * 100 / (100 + $defender->def);

    $randomRate = random_int(90, 110) / 100;
    $damage = $reduced * $randomRate;

    $damage *= $this->getElementRate($defender, $element);

    if ($isCritical) {
        $damage *= 1.5;
    }

    return max(1, (int) floor($damage));
}
```

## 29. 命中判定実装

```php
private function isHit(BattleActor $attacker, BattleActor $defender, int $hitBonus = 0): bool
{
    $hitRate = 90 + (($attacker->spd - $defender->spd) / 20) + $hitBonus;

    $hitRate = max(60, min(98, $hitRate));

    return random_int(1, 100) <= $hitRate;
}
```

暗闇などの状態異常がある場合は hitBonus にマイナス補正を入れる。

## 30. クリティカル判定実装

```php
private function isCritical(BattleActor $attacker, int $criticalBonus = 0): bool
{
    $criticalRate = 5 + floor($attacker->luck / 100) + $criticalBonus;

    $criticalRate = max(0, min(30, $criticalRate));

    return random_int(1, 100) <= $criticalRate;
}
```

## 31. スキル発動判定実装

```php
private function selectSkill(BattleActor $actor, BattleActor $target): ?Skill
{
    $skills = collect($actor->skills)
        ->filter(fn ($skill) => $this->canUseSkill($actor, $target, $skill))
        ->sortBy('priority');

    foreach ($skills as $skill) {
        if (random_int(1, 100) <= $skill->activation_rate) {
            return $skill;
        }
    }

    return null;
}
```

## 32. スキル使用条件

```php
private function canUseSkill(BattleActor $actor, BattleActor $target, Skill $skill): bool
{
    if (!$skill->is_active) {
        return false;
    }

    if ($actor->mp < $skill->mp_cost) {
        return false;
    }

    if ($this->hasStatus($actor, 'silence') && in_array($skill->skill_type, ['magic_attack', 'heal'])) {
        return false;
    }

    if ($skill->condition_type === 'hp_below_percent') {
        $hpRate = ($actor->hp / $actor->maxHp) * 100;
        return $hpRate <= $skill->condition_value;
    }

    return true;
}
```

## 33. 状態異常処理

### 33.1 ターン開始時処理

ターン開始時に、行動不能系の状態を判定する。

* sleep
* paralysis

麻痺は50％で行動不能。

睡眠は原則行動不能。

```php
private function canAct(BattleActor $actor): bool
{
    if ($this->hasStatus($actor, 'sleep')) {
        return false;
    }

    if ($this->hasStatus($actor, 'paralysis')) {
        return random_int(1, 100) > 50;
    }

    return true;
}
```

### 33.2 ターン終了時処理

ターン終了時に、毒や火傷のダメージを処理する。

```php
private function processEndTurnStatusDamage(BattleActor $actor, array &$logs): void
{
    if ($this->hasStatus($actor, 'poison')) {
        $damage = max(1, floor($actor->maxHp * 0.05));
        $actor->hp = max(0, $actor->hp - $damage);
        $logs[] = "{$actor->name}は毒により{$damage}ダメージを受けた。";
    }

    if ($this->hasStatus($actor, 'burn')) {
        $damage = max(1, floor($actor->maxHp * 0.03));
        $actor->hp = max(0, $actor->hp - $damage);
        $logs[] = "{$actor->name}は火傷により{$damage}ダメージを受けた。";
    }
}
```

### 33.3 継続ターン減少

ターン終了時に状態異常の残りターンを減らす。

0になった状態異常は解除する。

## 34. 戦闘結果クラス

```php
class BattleResult
{
    public string $result; // victory / defeat / timeout

    public array $logs = [];

    public int $exp = 0;
    public int $gold = 0;
    public int $jobExp = 0;

    public array $drops = [];

    public array $jobResult = [];

    public int $playerHpAfter = 0;
    public int $playerMpAfter = 0;
}
```

## 35. プレイヤーHP・MPの扱い

### 35.1 初期実装方針

戦闘終了後、HP・MPを永続的に減らすかどうかはゲームテンポに大きく影響する。

初期実装では、以下を推奨する。

* 戦闘中はHP・MPを減らす
* 戦闘終了後、HPは全回復
* MPは戦闘終了後も減ったままにするか、全回復にするかはゲーム方針次第

FFA風のテンポを優先するなら、初期実装ではHP・MPともに戦闘ごとに全回復でよい。

理由：

* 周回テンポが良い
* 回復施設の実装を後回しにできる
* 戦闘バランス調整が簡単
* 初心者が詰まりにくい

ただし、宿屋など施設の意味を持たせたい場合は、MPのみ持ち越しもあり。

### 35.2 推奨

初期実装では、HP・MPは戦闘ごとに全回復。

宿屋や回復施設を作り込む段階で、MP持ち越しや疲労度を検討する。

## 36. Controller 設計

### 36.1 ルーティング案

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/battle/{enemy}', [BattleController::class, 'show'])->name('battle.show');
    Route::post('/battle/{enemy}/fight', [BattleController::class, 'fight'])->name('battle.fight');
});
```

### 36.2 BattleController@fight

```php
public function fight(Enemy $enemy, BattleService $battleService)
{
    $player = auth()->user()->player;

    if (!$enemy->is_active) {
        abort(404);
    }

    $result = $battleService->fight($player, $enemy);

    return view('battle.result', [
        'result' => $result,
    ]);
}
```

Controller では戦闘計算をしない。

## 37. 戦闘画面UI仕様

### 37.1 戦闘前画面

表示項目：

* 敵名
* 敵レベル
* 敵タイプ
* 推奨レベル
* 報酬目安
* 消費するものがあれば表示
* 戦うボタン

### 37.2 戦闘結果画面

表示項目：

* 勝敗
* 戦闘ログ
* 獲得経験値
* 獲得ゴールド
* 獲得職業経験値
* ドロップアイテム
* 職業レベルアップ
* 職業マスター
* 新職業解放
* もう一度戦うボタン
* エリアに戻るボタン

### 37.3 周回導線

FFA風ゲームでは「もう一度戦う」が重要。

戦闘結果画面には必ず以下を置く。

```text
もう一度戦う
別の敵を探す
冒険都市に戻る
```

## 38. 初期敵データ案

### 38.1 初心者向け

| 敵     | type  | HP | 攻撃 | 防御 | 魔力 | 精神 | 素早さ |  運 | 職業経験値 |
| ----- | ----- | -: | -: | -: | -: | -: | --: | -: | ----: |
| スライム  | slime | 40 |  8 |  5 |  0 |  3 |   5 |  5 |     3 |
| 角ウサギ  | beast | 55 | 12 |  6 |  0 |  5 |  15 |  8 |     3 |
| 森ネズミ  | beast | 45 | 10 |  4 |  0 |  4 |  18 | 10 |     3 |
| 見習い盗賊 | thief | 80 | 18 | 10 |  0 |  8 |  25 | 15 |     5 |

### 38.2 序盤向け

| 敵     | type    |  HP | 攻撃 | 防御 | 魔力 | 精神 | 素早さ |  運 | 職業経験値 |
| ----- | ------- | --: | -: | -: | -: | -: | --: | -: | ----: |
| ゴブリン  | demon   | 120 | 25 | 15 |  5 | 10 |  18 | 10 |     5 |
| 甲殻虫   | armored | 140 | 20 | 35 |  0 | 10 |   8 |  8 |     5 |
| 闇コウモリ | beast   |  90 | 22 | 10 | 10 | 15 |  35 | 15 |     5 |
| さまよう骨 | undead  | 150 | 28 | 20 |  0 |  8 |  10 |  5 |     8 |

### 38.3 エリアボス

| 敵     | type    |  HP | 攻撃 | 防御 | 魔力 | 精神 | 素早さ |  運 | 職業経験値 |
| ----- | ------- | --: | -: | -: | -: | -: | --: | -: | ----: |
| 森の主   | beast   | 500 | 60 | 40 | 10 | 30 |  45 | 20 |    15 |
| 盗賊団長  | thief   | 450 | 70 | 30 |  0 | 25 |  65 | 35 |    15 |
| 古びた鎧兵 | armored | 650 | 55 | 80 |  0 | 50 |  20 | 10 |    15 |

## 39. バランス調整方針

### 39.1 職業補正 2.25 前提の注意

職業補正が最大2.25倍まであるため、職業ごとの差はかなり大きくなる。

そのため、敵側にも以下のような違いを持たせる。

* 防御が高い敵
* 精神が高い敵
* 素早い敵
* 魔法攻撃が強い敵
* 状態異常を使う敵
* 特定属性に弱い敵

これにより、1つの職業だけで全敵を簡単に倒す状態を防ぐ。

### 39.2 剣聖・暗黒騎士対策

攻撃2.25系の職業が強くなりすぎる可能性がある。

対策：

* 防御が高い敵を用意する
* 回避が高い敵を用意する
* 状態異常に弱い設計にする
* 長期戦で不利になる敵を用意する
* MPやHP消費スキルを使わせる

### 39.3 大賢者対策

魔力2.25系の職業が強くなりすぎる可能性がある。

対策：

* 精神が高い敵を用意する
* 魔法耐性を持つ敵を用意する
* 沈黙を使う敵を用意する
* MP管理を必要にする
* 物理耐久が低い弱点を突く敵を用意する

### 39.4 黄金商人対策

運2.25やゴールド補正が経済を壊す可能性がある。

対策：

* ゴールド補正上限を設ける
* ドロップ補正上限を設ける
* 高額アイテムの価格を慎重に調整する
* ゴールド以外の報酬も重要にする
* ボス報酬には商人補正を一部制限する

## 40. テスト観点

### 40.1 ダメージ計算テスト

* 物理ダメージが正しく計算される
* 魔法ダメージが正しく計算される
* 複合ダメージが正しく計算される
* 防御が高い敵へのダメージが減る
* 精神が高い敵への魔法ダメージが減る
* 最低ダメージが1になる
* クリティカル時に1.5倍になる
* 属性弱点で1.5倍になる
* 属性耐性で0.5倍になる
* 属性無効で0になる

### 40.2 命中・回避テスト

* 基本命中率が90％前後になる
* 素早さ差で命中率が変わる
* 命中率が60％未満にならない
* 命中率が98％を超えない
* 暗闇で命中率が下がる
* 命中補正スキルが反映される

### 40.3 スキルテスト

* スキルが発動率に応じて発動する
* MP不足時に魔法スキルが発動しない
* HP条件付き回復スキルが条件達成時のみ発動する
* priority の高いスキルから判定される
* スキル未発動時は通常攻撃になる
* 沈黙時に魔法スキルが使えない

### 40.4 状態異常テスト

* 毒でターン終了時にダメージを受ける
* 麻痺で一定確率で行動不能になる
* 睡眠で行動不能になる
* 沈黙で魔法が使えなくなる
* 火傷で継続ダメージを受ける
* 暗闇で命中率が下がる
* 状態異常ターンが減少する
* ターン0で状態異常が解除される
* immune の敵には状態異常が入らない

### 40.5 報酬テスト

* 勝利時に経験値が付与される
* 勝利時にゴールドが付与される
* 勝利時に職業経験値が付与される
* 敗北時に報酬が付与されない
* ゴールド補正が反映される
* ゴールド補正が上限を超えない
* ドロップ判定が動く
* 運によるドロップ補正が上限を超えない

### 40.6 戦闘終了テスト

* 敵HPが0で勝利になる
* プレイヤーHPが0で敗北になる
* 最大ターン到達で時間切れ敗北になる
* 毒ダメージで敵を倒した場合も勝利になる
* 毒ダメージでプレイヤーが倒れた場合は敗北になる

## 41. 実装フェーズ

### Phase 1：基礎戦闘

実装内容：

* enemies テーブル
* BattleService
* BattleActor
* BattleState
* BattleResult
* 通常攻撃
* 物理ダメージ計算
* ターン進行
* 勝敗判定
* 戦闘ログ
* 経験値・ゴールド付与

完了条件：

* プレイヤーが敵1体と戦える
* 勝敗が決まる
* 戦闘ログが表示される
* 勝利時に報酬が入る

### Phase 2：職業連携

実装内容：

* PlayerStatusService 連携
* 職業補正反映
* マスターボーナス反映
* 職業経験値付与
* 職業レベルアップログ
* 職業マスターログ
* 新職業解放ログ

完了条件：

* 職業ごとに戦闘能力が変わる
* 勝利時に職業経験値が入る
* 職業レベルアップが戦闘結果に表示される

### Phase 3：スキル実装

実装内容：

* skills テーブル
* job_skills テーブル
* SkillService
* 自動発動スキル
* MP消費
* 物理スキル
* 魔法スキル
* 回復スキル

完了条件：

* 職業ごとのスキルが自動発動する
* MP不足時は発動しない
* スキルログが表示される

### Phase 4：状態異常・属性

実装内容：

* 状態異常処理
* 属性倍率
* enemy_resistances
* enemy_status_resistances
* 毒、麻痺、睡眠、沈黙、火傷、暗闇

完了条件：

* 状態異常が戦闘に影響する
* 属性弱点・耐性がダメージに反映される

### Phase 5：ドロップ・報酬拡張

実装内容：

* enemy_drops
* RewardService
* 通常ドロップ
* レアドロップ
* 運補正
* ゴールド補正上限
* ドロップ補正上限

完了条件：

* 敵ごとにドロップが設定できる
* 運や職業補正が報酬に反映される
* 経済バランスが壊れないよう上限が効く

### Phase 6：バランス調整

実装内容：

* 敵ステータス調整
* 職業別勝率確認
* 戦闘ターン数確認
* スキル発動率調整
* 報酬量調整
* ログ文言調整

完了条件：

* 初心者職でも序盤敵に勝てる
* 上級職が強すぎない
* 職業ごとに得意不得意がある
* 周回テンポが悪くない
* 戦闘ログが気持ちよく読める

## 42. 実装優先度まとめ

最優先：

1. 1対1の自動戦闘
2. ターン制
3. 通常攻撃
4. 物理ダメージ計算
5. 勝敗判定
6. 戦闘ログ
7. 経験値・ゴールド付与
8. 職業経験値付与
9. 職業補正反映

次に実装：

1. スキル自動発動
2. 魔法攻撃
3. 回復スキル
4. クリティカル
5. 命中・回避
6. 属性
7. 状態異常

後続で実装：

1. ドロップテーブル
2. レアドロップ
3. 敵スキル
4. ボス固有行動
5. 複数敵
6. パーティ戦
7. PvP
8. 戦闘ランキング

## 43. 注意すべき設計ミス

### 43.1 Controller に戦闘計算を書かない

戦闘処理は複雑化しやすい。

Controller に処理を書くと、後で修正不能になる。

必ず BattleService や DamageCalculator に分離する。

### 43.2 職業補正を無視しない

転職システムで補正を大きくしたため、戦闘計算に必ず現在職業補正を反映する。

ここが反映されないと、転職の意味がなくなる。

### 43.3 運を強くしすぎない

運は面白い能力だが、ゴールドやドロップに直結させすぎると経済が壊れる。

必ず上限を設定する。

### 43.4 回避を強くしすぎない

回避型職業は面白いが、完全回避に近づくとゲームが壊れる。

命中率には最低値を設ける。

### 43.5 回復を強くしすぎない

回復が強すぎると戦闘が終わらない。

最大ターン数、MP消費、発動率で制御する。

### 43.6 ログを軽視しない

FFA風ゲームでは、ログが演出になる。

単に「30ダメージ」ではなく、職業やスキルの個性が伝わるログにする。

## 44. 最終的な完成イメージ

プレイヤーは冒険先で敵と出会い、自動戦闘を行う。

剣士なら、強斬りで安定した物理ダメージを出す。

魔法使いなら、低耐久ながらファイアで高火力を狙う。

盗賊や忍者なら、素早さと回避で敵の攻撃をかわす。

僧侶や司祭なら、回復しながら粘り強く戦う。

剣聖は圧倒的な物理火力を持つが、魔法や状態異常に弱い。

大賢者は強力な魔法を放てるが、物理耐久が低い。

黄金商人は戦闘能力では最強ではないが、報酬面で強みを持つ。

このように、戦闘システムは職業システムと連動して、以下の体験を作る。

```text
職業を選ぶ
↓
戦い方が変わる
↓
得意な敵・苦手な敵が生まれる
↓
育成方針を考える
↓
職業をマスターする
↓
さらに上位職を目指す
```

戦闘はシンプルでよい。

ただし、内部では職業・能力・スキル・敵タイプ・報酬がつながっている状態を目指す。

以上。
