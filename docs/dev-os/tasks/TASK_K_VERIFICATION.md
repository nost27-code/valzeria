# 確認指示書: TASK_K（奥義フィードバック対応）の実装検証

> 対象: [TASK_K_JOB_ART_FEEDBACK_FIXES.md](TASK_K_JOB_ART_FEEDBACK_FIXES.md) に基づいて実装済みのコード。
> このタスクは**実装ではなく検証**。コードは変更しない。全項目を実行し、結果を「✅/❌/該当なし」で1件ずつ報告すること。
> ❌が出た場合はコードを直さず、該当ファイル・行・実際の出力を添えて報告する。

## 前提

- 作業ディレクトリ: リポジトリルート
- PowerShellから `php artisan tinker` が使えること
- 事前に `php artisan db:seed --class=JobArtSeeder --force` を実行し、`database/data/job_arts.json` の内容がDBに反映されていること

---

## 1. 構文チェック

以下を実行し、全て `No syntax errors detected` になることを確認する。

```powershell
php -l app/Services/BattleService.php
php -l app/Services/ChampBattleService.php
php -l app/Services/PvPBattleService.php
php -l app/Services/ArenaNpcBattleService.php
php -l app/Services/TowerBattleService.php
php -l app/Services/Battle/BattleActor.php
php -l app/Services/Admin/SkillEffectPreviewService.php
php -l app/Services/JobArtBattleSupportService.php
```

JSONマスタの整合性:

```powershell
node -e "JSON.parse(require('fs').readFileSync('database/data/job_arts.json','utf8')); console.log('valid')"
```

`valid` が出力されること。

---

## 2. バフ/デバフログの具体化（Phase 1-A）

以下をtinkerで実行し、出力に**ステータス名と%が含まれる**ことを確認する（「戦闘力が高まった」「守りが乱れた」のような曖昧な文言が**出ないこと**）。

```php
$service = app(App\Services\BattleService::class);
$ref = new ReflectionClass($service);

$attacker = new App\Services\Battle\BattleActor("勇者", true, [
    "max_hp"=>1000,"hp"=>1000,"max_mp"=>100,"mp"=>100,
    "str"=>150,"def"=>100,"agi"=>100,"mag"=>80,"spr"=>90,"luk"=>50,
]);
$defender = new App\Services\Battle\BattleActor("敵", false, [
    "max_hp"=>1000,"hp"=>1,"max_mp"=>0,"mp"=>0,
    "str"=>100,"def"=>100,"agi"=>100,"mag"=>100,"spr"=>100,"luk"=>50,
]);
$state = new App\Services\Battle\BattleState($attacker, $defender, "pve");
$skill = new App\Models\Skill();
$skill->power = 150;

$m = $ref->getMethod("applySelfBuff"); $m->setAccessible(true);
$m->invoke($service, $attacker, $state, $skill);

$m2 = $ref->getMethod("applyEnemyDebuff"); $m2->setAccessible(true);
$m2->invoke($service, $defender, $state, $skill);

echo implode("\n---\n", $state->logs);
```

**期待結果の例**:
```
勇者 のATKが 15% / DEFが 7% 上昇した！
---
敵 のDEFが 15% / SPRが 7% 低下した！
```

- [ ] ATK/DEF または MAG/SPR のステ名と%が明記されている
- [ ] 「戦闘力が高まった」「守りが乱れた」という曖昧な文言が残っていない

`ChampBattleService` / `PvPBattleService` / `ArenaNpcBattleService` にも同名の `applyJobArtTemplateEffects`（内部でSELF_BUFF/ENEMY_DEBUFFを処理）があるので、`grep -rn "戦闘力が高まった\|守りが乱れた\|動きが鈍った" app/Services` を実行し、**該当箇所がゼロ件**であることも確認する。

---

## 3. GUTS（踏みとどまり）発動ログ（Phase 1-B）

```php
$service = app(App\Services\BattleService::class);
$ref = new ReflectionClass($service);

$defender = new App\Services\Battle\BattleActor("敵", false, [
    "max_hp"=>1000,"hp"=>50,"max_mp"=>0,"mp"=>0,
    "str"=>100,"def"=>100,"agi"=>100,"mag"=>100,"spr"=>100,"luk"=>50,
]);
$defender->gutsReady = true;
$attacker = new App\Services\Battle\BattleActor("勇者", true, ["max_hp"=>1000,"hp"=>1000]);
$state = new App\Services\Battle\BattleState($attacker, $defender, "pve");

$defender->takeDamage(9999);
echo "hp=".$defender->hp." triggered=".($defender->gutsJustTriggered ? "1" : "0")."\n";

$mGuts = $ref->getMethod("logGutsIfTriggered"); $mGuts->setAccessible(true);
$mGuts->invoke($service, $defender, $state);
echo implode("\n", $state->logs);
```

**期待結果**: `hp=1 triggered=1` の後、`不屈の精神で致死ダメージを耐えた！（HP1）` を含むログが出力される。

- [ ] 上記ログが出力される
- [ ] `grep -rn "->takeDamage(" app/Services` で洗い出した全箇所（BattleService/ChampBattleService/PvPBattleService/ArenaNpcBattleService）の近くに `logGutsIfTriggered` またはインライン相当の判定が入っていることをコードで確認した

---

## 4. バリア軽減率の統一（Phase 1-C）

以下を実行し、**4サービス全てで同じ%**になることを確認する（金剛不壊: `damage_reduction_percent=25`, 継承rate=0.7の想定）。

```php
$skill = new App\Models\Skill();
$skill->damage_reduction_percent = 25;
$skill->power = 89;

foreach (["BattleService","ChampBattleService","PvPBattleService","ArenaNpcBattleService"] as $cls) {
    $service = app("App\\Services\\$cls");
    $ref = new ReflectionClass($service);
    $m = $ref->getMethod("jobArtGuardReduction"); $m->setAccessible(true);
    echo "$cls: " . $m->invoke($service, $skill, 0.7) . "%\n";
}
```

**期待結果**: 4行とも `17%`。

- [ ] 4サービス全て同じ%（17%）である
- [ ] 1つでも値が異なる場合は、`jobArtGuardReduction` の実装差分を報告する

---

## 5. job_arts.json 個別データ修正（Phase 1-D）

以下をtinkerで実行し、17件（+ 魔法剣の1件）が期待通りのテンプレート/構造化フィールドになっているか確認する。

```php
$targets = [
    [2,9,'巨人断ち','DAMAGE_BUFF'],
    [4,9,'五月雨流星射ち','MULTI_HIT'],
    [8,9,'大番振る舞い','GUARD_BARRIER'],
    [9,1,'属性付与','DAMAGE_BUFF'],
    [9,5,'魔法剣','HYBRID_DAMAGE'],
    [12,5,'勝利の采配','SELF_BUFF'],
    [13,1,'闘争本能','SELF_BUFF'],
    [13,5,'闘技連斬','MULTI_HIT'],
    [14,5,'暴走撃','PHYSICAL_DAMAGE'],
    [15,5,'ガーディアンブロウ','DAMAGE_GUARD_BARRIER'],
    [16,9,'傭兵団の総攻撃','DAMAGE_DEBUFF'],
    [20,9,'大商隊の守護','GUARD_BARRIER'],
    [22,1,'魔矢装填','MAGICAL_DAMAGE'],
    [22,5,'エレメントアロー','DAMAGE_BUFF'],
    [22,9,'星霊連弓','DAMAGE_DEBUFF'],
    [23,5,'勇気の旋律','SELF_BUFF'],
    [23,9,'英雄譚の終章','GUARD_BARRIER'],
    [26,1,'錬成火花','MAGICAL_DAMAGE'],
];
foreach ($targets as [$jid, $rank, $name, $expectedTpl]) {
    $s = App\Models\Skill::where('job_id',$jid)->where('learn_rank',$rank)->where('skill_type','job_art')->first();
    $ok = $s && $s->name === $name && $s->effect_template === $expectedTpl;
    echo ($ok ? "OK  " : "NG  ") . "$name (job$jid r$rank): actual=" . ($s ? $s->effect_template : 'NOT FOUND') . "\n";
}
```

- [ ] 18行全て `OK` である（`NG` があれば行を報告）

暴走撃の反動データ:
```php
$s = App\Models\Skill::where('job_id',14)->where('learn_rank',5)->first();
echo $s->self_damage_percent; // 期待値: 8
```
- [ ] `8` が出力される

星霊連弓のダメージが0でないこと:
```php
$enemy = App\Models\Enemy::first();
$service = app(App\Services\Admin\SkillEffectPreviewService::class);
$job = App\Models\JobClass::where('key','magic_archer')->first();
$skills = App\Models\Skill::where('job_id',$job->id)->where('skill_type','job_art')->orderBy('learn_rank')->get();
$r = $service->preview(['max_hp'=>2000,'max_mp'=>200,'str'=>100,'def'=>100,'agi'=>100,'mag'=>150,'spr'=>100,'luk'=>50], $job, $enemy, $skills);
foreach ($r['turns'] as $t) { echo $t['label']." dmg=".$t['damage']."\n"; }
```
- [ ] 「星霊連弓」の `dmg` が `0` でない

---

## 6. 回復奥義のスケール再設計（Phase 2）

```php
$enemy = App\Models\Enemy::first();
$service = app(App\Services\Admin\SkillEffectPreviewService::class);
$job = App\Models\JobClass::where('key','priest')->first();
$skills = App\Models\Skill::where('job_id',$job->id)->where('skill_type','job_art')->orderBy('learn_rank')->get();
$r = $service->preview(['max_hp'=>3000,'max_mp'=>300,'str'=>80,'def'=>80,'agi'=>80,'mag'=>80,'spr'=>200,'luk'=>50], $job, $enemy, $skills);
foreach ($r['turns'] as $t) { echo $t['label']." ".implode(' / ', $t['effects'])."\n"; }
```

- [ ] ヒール(★1)・癒しの祈り(★5)・聖域展開(★9)の回復量/効果が**それぞれ明確に異なる**（旧仕様では全て56相当付近に均されていたバグが直っている）
- [ ] `grep -rn "max(80," app/Services` を実行し、ヒット箇所が**バリア軽減率のフォールバック計算（jobArtGuardReduction内、コメントで「継承倍率でスケール済み」と書かれている箇所）のみ**であることを確認する（回復計算側のmax(80,…)が残っていたら❌）

---

## 7. 継承倍率のUI表示（Phase 1-E）

- [ ] `resources/views/job-arts/partials/slot-card.blade.php` に `$artInheritedRate` を使った `継承 XX%` 表示があることをコードで確認した
- [ ] `resources/views/job-arts/index.blade.php` の凡例（バッジの見方パネル）に「威力・効果量が本来の70〜85%になります」という説明文があることを確認した
- [ ] 可能であれば `/job-arts`（または相当のルート）をブラウザで開き、継承奥義のスロットに実際に `継承 70%` 等の表示が出ることをスクリーンショットで確認する

---

## 8. 既存QAチェックリストとの突合

[QA_CHECKLIST.md](../QA_CHECKLIST.md) の「追加QA: 戦闘ロジックに触れる場合」を実施する:

- [ ] 勝利時のGoldが毎戦固定報酬になっていない
- [ ] 敗北時のHP/SP、探索状態が意図通り
- [ ] レベルアップ時にLv255上限、BP+1、HP/SPクランプが守られる
- [ ] BattleResult / battle_logs / 結果画面 / 公開ログの表示が矛盾しない

また「追加QA: マスタデータ変更に触れる場合」のうち該当する項目:

- [ ] job_arts.json の変更が JobArtSeeder 経由でDBに反映されている（`updateOrCreate` のキーである job_id/learn_rank/skill_type が変わっていないため既存行が更新される想定）

---

## 報告フォーマット

各セクション番号（1〜8）ごとに ✅/❌/該当なし を一覧化し、❌の項目のみ詳細（実行したコマンド・期待値・実際の出力・関連ファイルパス）を記載すること。全て✅の場合もその旨を明記して終了する。
