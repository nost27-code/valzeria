param(
    [Parameter(Mandatory = $true)]
    [string] $SpecPath
)

$ErrorActionPreference = 'Stop'
$specPath = (Resolve-Path -LiteralPath $SpecPath).Path
$targetPath = Join-Path $PSScriptRoot '..\..\app\Services\JobArtV2Rank5V6Catalog.php'
$tiers = @('基本', '中級', '上級', '超級', '冠位', '英雄', '伝説', '神話')
$rows = @()

foreach ($line in Get-Content -LiteralPath $specPath) {
    $columns = $line -split '\|'
    if ($columns.Count -lt 11 -or $tiers -notcontains $columns[2].Trim()) {
        continue
    }
    $jobCell = $columns[1].Trim()
    if ($jobCell -notmatch '^(\d+)') {
        continue
    }
    $jobId = [int] $Matches[1]
    $powerText = $columns[8].Trim().Replace('*', '')
    $rows += [pscustomobject]@{
        JobId = $jobId
        Name = $columns[5].Trim()
        Trigger = if ($columns[6] -match '反応') { 'reactive' } else { 'scheduled' }
        Power = if ($powerText -eq '—') { $null } else { [int] $powerText }
        Effect = $columns[9].Trim().Replace("'", "\\'")
    }
}

if ($rows.Count -ne 94 -or ($rows.JobId | Sort-Object -Unique).Count -ne 94) {
    throw "Rank5 specification rows must be 94; got $($rows.Count)."
}

# These four effects were finalized after the v6.1 table was generated.
# Keep the approved rulings here so every generated runtime/master/migration
# artifact is reproducible from the reviewed v6.1 source document.
$finalEffectByJob = @{
    7 = '攻撃なし。精神150%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを15%軽減する（1回）'
    10 = '威力100%の物理ダメージ。最大HP7%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを15%軽減する（1回）'
    47 = '攻撃なし。精神120%分、自分のHPを回復し、最大SP8%分、自分のSPを回復する。通常探索勝利時のGold獲得量を10%増やし、通常素材枠の抽選率を8ポイント、レア素材枠の抽選率を5ポイント上げる'
    84 = '威力244%の魔法ダメージ。直前に上書きされた自分の場を5ラウンドで再展開する。通常探索勝利時のGold獲得量を2%増やし、通常素材枠の抽選率を2ポイント上げる'
}
foreach ($row in $rows) {
    if ($finalEffectByJob.ContainsKey([int] $row.JobId)) {
        $row.Effect = $finalEffectByJob[[int] $row.JobId].Replace("'", "\'")
    }
}

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add('<?php')
$lines.Add('')
$lines.Add('namespace App\Services;')
$lines.Add('')
$lines.Add('use App\Models\Skill;')
$lines.Add('')
$lines.Add('/** Approved Rank5 v6.1 metadata. Runtime use is always feature-gated. */')
$lines.Add('final class JobArtV2Rank5V6Catalog')
$lines.Add('{')
$lines.Add('    /** @var array<int, array{name:string,power:?int,trigger_mode:string,effect_text:string}> */')
$lines.Add('    private const SPECS = [')
foreach ($row in $rows) {
    $power = if ($null -eq $row.Power) { 'null' } else { [string] $row.Power }
    $name = $row.Name.Replace("'", "\\'")
    $lines.Add("        $($row.JobId) => ['name' => '$name', 'power' => $power, 'trigger_mode' => '$($row.Trigger)', 'effect_text' => '$($row.Effect)'],")
}
$lines.Add('    ];')
$lines.Add('')
$lines.Add('    /** @var list<int> */')
$lines.Add('    private const ATTACKLESS_JOB_IDS = [7, 12, 23, 25, 38, 47];')
$lines.Add('')
$lines.Add('    /** @return array{name:string,power:?int,trigger_mode:string,effect_text:string}|null */')
$lines.Add('    public function forSkill(Skill $skill): ?array')
$lines.Add('    {')
$lines.Add("        if (! `$skill->isJobArt() || (int) `$skill->learn_rank !== 5) {")
$lines.Add('            return null;')
$lines.Add('        }')
$lines.Add('')
$lines.Add("        `$spec = self::SPECS[(int) `$skill->job_id] ?? null;")
$lines.Add("        return `$spec !== null && `$spec['name'] === (string) `$skill->name ? `$spec : null;")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    public function powerFor(Skill $skill): ?int')
$lines.Add('    {')
$lines.Add("        return `$this->forSkill(`$skill)['power'] ?? null;")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    public function triggerMode(Skill $skill): ?string')
$lines.Add('    {')
$lines.Add("        return `$this->forSkill(`$skill)['trigger_mode'] ?? null;")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    public function isReactive(Skill $skill): bool')
$lines.Add('    {')
$lines.Add("        return `$this->triggerMode(`$skill) === 'reactive';")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    public function isAttackless(Skill $skill): bool')
$lines.Add('    {')
$lines.Add("        return `$this->forSkill(`$skill) !== null")
$lines.Add("            && in_array((int) `$skill->job_id, self::ATTACKLESS_JOB_IDS, true);")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    public function effectText(Skill $skill): ?string')
$lines.Add('    {')
$lines.Add("        return `$this->forSkill(`$skill)['effect_text'] ?? null;")
$lines.Add('    }')
$lines.Add('')
$lines.Add('    /** @return array<int, array{name:string,power:?int,trigger_mode:string,effect_text:string}> */')
$lines.Add('    public function all(): array')
$lines.Add('    {')
$lines.Add('        return self::SPECS;')
$lines.Add('    }')
$lines.Add('}')

[System.IO.File]::WriteAllText(
    [System.IO.Path]::GetFullPath($targetPath),
    ($lines -join "`n") + "`n",
    [System.Text.UTF8Encoding]::new($false)
)

$masterPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\database\data\job_arts.json'))
$migrationDataPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\database\data\job_art_rank5_v6_1_migration.json'))
$baseSha = '8ccdd1d51537eafeda245c8e17c4b4373ee58acd'
$repoPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$baseMasterJson = (& git -C $repoPath show "${baseSha}:database/data/job_arts.json" | Out-String)
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($baseMasterJson)) {
    throw "Could not read job_arts.json from base SHA $baseSha."
}
$masterRows = $baseMasterJson | ConvertFrom-Json -AsHashtable
$specByJob = @{}
foreach ($row in $rows) { $specByJob[$row.JobId] = $row }
$attackless = @(7, 12, 23, 25, 38, 47)
$migration = [ordered]@{ old = [ordered]@{}; new = [ordered]@{} }

function Numeric-Power([object] $value) {
    if ($value -is [int] -or $value -is [long] -or $value -is [double]) { return [int] $value }
    if ([string] $value -match '(\d+)') { return [int] $Matches[1] }
    return 0
}

function Master-Int([hashtable] $row, [string] $key) {
    return $row.ContainsKey($key) -and $null -ne $row[$key] ? [int] $row[$key] : 0
}

function Template-HitCount([hashtable] $row) {
    if ($row.ContainsKey('hit_count')) { return [int] $row.hit_count }
    if (@('SELF_BUFF', 'ENEMY_DEBUFF', 'GUARD_BARRIER', 'HEAL', 'HEAL_CLEANSE', 'GUTS', 'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED', 'TIME_CONTROL_CURRENT_ONLY', 'V2_ROLE_EFFECT_ONLY') -contains [string] $row.effect_template) { return 0 }
    return 1
}

function Template-DamageType([hashtable] $row) {
    if ($row.ContainsKey('damage_type') -and -not [string]::IsNullOrWhiteSpace([string] $row.damage_type)) { return [string] $row.damage_type }
    if (@('MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF', 'MAGICAL_DAMAGE_REWARD') -contains [string] $row.effect_template) { return 'magical' }
    if (@('SELF_BUFF', 'ENEMY_DEBUFF', 'GUARD_BARRIER', 'HEAL', 'HEAL_CLEANSE', 'GUTS', 'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED', 'TIME_CONTROL_CURRENT_ONLY', 'V2_ROLE_EFFECT_ONLY') -contains [string] $row.effect_template) { return 'support' }
    return 'physical'
}

foreach ($master in $masterRows) {
    if ([int] $master.learn_rank -ne 5 -or -not $specByJob.ContainsKey([int] $master.job_id)) {
        continue
    }

    $spec = $specByJob[[int] $master.job_id]
    if ([string] $master.name -ne $spec.Name) {
        throw "Rank5 identity mismatch for job $($master.job_id)."
    }

    $key = "$($master.job_id):5"
    $oldPower = Numeric-Power $master.power_hint
    $newPower = if ($null -eq $spec.Power) { 0 } else { [int] $spec.Power }
    $old = [ordered]@{
        power = $oldPower
        power_multiplier = $oldPower / 100
        memo = [string] ($master.memo ?? '')
        description = [string] ($master.memo ?? '')
    }
    $new = [ordered]@{
        power = $newPower
        power_multiplier = $newPower / 100
        memo = $spec.Effect.Replace("\\'", "'")
        description = $spec.Effect.Replace("\\'", "'")
    }

    if ($attackless -contains [int] $master.job_id) {
        $old.hit_count = Template-HitCount $master
        $old.damage_type = Template-DamageType $master
        $new.hit_count = 0
        $new.damage_type = 'support'
        $master.hit_count = 0
        $master.damage_type = 'support'
    }

    switch ([int] $master.job_id) {
        8 {
            $old.effect_template = 'REWARD_GOLD'; $old.art_category = 'reward'; $old.limit_group = 'REWARD'; $old.hit_count = 1; $old.damage_type = 'support'; $old.gold_bonus_percent = 7; $old.drop_bonus_percent = 0
            $new.effect_template = 'PHYSICAL_DAMAGE_GOLD_REWARD'; $new.art_category = 'reward'; $new.limit_group = 'REWARD'; $new.hit_count = 1; $new.damage_type = 'physical'; $new.gold_bonus_percent = 7; $new.drop_bonus_percent = 0
            $master.effect_template = 'PHYSICAL_DAMAGE_GOLD_REWARD'; $master.hit_count = 1; $master.damage_type = 'physical'; $master.gold_bonus_percent = 7; $master.drop_bonus_percent = 0
        }
        20 {
            $old.effect_template = 'REWARD_MIXED'; $old.art_category = 'reward'; $old.limit_group = 'REWARD'; $old.hit_count = 1; $old.damage_type = 'support'; $old.gold_bonus_percent = 8; $old.drop_bonus_percent = 6
            $new.effect_template = 'PHYSICAL_DAMAGE_REWARD'; $new.art_category = 'reward'; $new.limit_group = 'REWARD'; $new.hit_count = 1; $new.damage_type = 'physical'; $new.gold_bonus_percent = 0; $new.drop_bonus_percent = 6
            $master.effect_template = 'PHYSICAL_DAMAGE_REWARD'; $master.hit_count = 1; $master.damage_type = 'physical'; $master.gold_bonus_percent = 0; $master.drop_bonus_percent = 6
        }
        11 {
            $old.effect_template = 'DAMAGE_BUFF'; $old.hit_count = 1; $old.damage_type = 'physical'
            $new.effect_template = 'PHYSICAL_DAMAGE'; $new.hit_count = 1; $new.damage_type = 'physical'
            $master.effect_template = 'PHYSICAL_DAMAGE'; $master.hit_count = 1; $master.damage_type = 'physical'
        }
        12 {
            $old.effect_template = 'SELF_BUFF'; $old.hit_count = 0; $old.damage_type = 'support'
            $new.effect_template = 'V2_ROLE_EFFECT_ONLY'; $new.hit_count = 0; $new.damage_type = 'support'
            $master.effect_template = 'V2_ROLE_EFFECT_ONLY'; $master.hit_count = 0; $master.damage_type = 'support'
        }
        29 {
            $old.effect_template = 'GUARD_BARRIER'; $old.hit_count = 0; $old.damage_type = 'support'
            $new.effect_template = 'MAGICAL_DAMAGE'; $new.hit_count = 1; $new.damage_type = 'magical'
            $master.effect_template = 'MAGICAL_DAMAGE'; $master.hit_count = 1; $master.damage_type = 'magical'
        }
        31 { $master.damage_type = 'physical' }
        44 {
            $old.effect_template = 'DAMAGE_BUFF'; $old.hit_count = 1; $old.damage_type = 'physical'
            $new.effect_template = 'PHYSICAL_DAMAGE'; $new.hit_count = 1; $new.damage_type = 'physical'
            $master.effect_template = 'PHYSICAL_DAMAGE'; $master.hit_count = 1; $master.damage_type = 'physical'
        }
        46 {
            $old.effect_template = 'MAGICAL_DAMAGE_BUFF'; $old.hit_count = 1; $old.damage_type = 'magical'
            $new.effect_template = 'MAGICAL_DAMAGE'; $new.hit_count = 1; $new.damage_type = 'magical'
            $master.effect_template = 'MAGICAL_DAMAGE'; $master.hit_count = 1; $master.damage_type = 'magical'
        }
        47 {
            $old.gold_bonus_percent = Master-Int $master 'gold_bonus_percent'; $old.drop_bonus_percent = Master-Int $master 'drop_bonus_percent'; $old.rare_bonus_percent = Master-Int $master 'rare_bonus_percent'; $old.mp_recover_percent = Master-Int $master 'mp_recover_percent'
            $new.gold_bonus_percent = 10; $new.drop_bonus_percent = 8; $new.rare_bonus_percent = 5; $new.mp_recover_percent = 8
            $master.gold_bonus_percent = 10; $master.drop_bonus_percent = 8; $master.rare_bonus_percent = 5; $master.mp_recover_percent = 8
        }
        50 {
            $old.effect_template = 'DAMAGE_BUFF'; $old.hit_count = 1; $old.damage_type = 'physical'
            $new.effect_template = 'PHYSICAL_DAMAGE'; $new.hit_count = 1; $new.damage_type = 'physical'
            $master.effect_template = 'PHYSICAL_DAMAGE'; $master.hit_count = 1; $master.damage_type = 'physical'
        }
        57 { $master.damage_type = 'magical' }
        77 { $master.damage_type = 'magical' }
        84 {
            $old.effect_template = 'MAGICAL_DAMAGE_REWARD'; $old.art_category = 'reward'; $old.limit_group = 'REWARD'; $old.damage_type = 'magical'; $old.gold_bonus_percent = 2; $old.drop_bonus_percent = 2; $old.reward_scope = 'mixed'
            $new.effect_template = 'MAGICAL_DAMAGE_REWARD'; $new.art_category = 'reward'; $new.limit_group = 'REWARD'; $new.damage_type = 'magical'; $new.gold_bonus_percent = 2; $new.drop_bonus_percent = 2; $new.reward_scope = 'mixed'
            $master.effect_template = 'MAGICAL_DAMAGE_REWARD'; $master.art_category = 'reward'; $master.limit_group = 'REWARD'; $master.damage_type = 'magical'; $master.gold_bonus_percent = 2; $master.drop_bonus_percent = 2; $master.reward_scope = 'mixed'
        }
        91 { $master.damage_type = 'magical' }
    }

    $master.power_hint = $newPower
    $master.memo = $spec.Effect.Replace("\\'", "'")
    $migration.old[$key] = $old
    $migration.new[$key] = $new
}

if ($migration.new.Count -ne 94) {
    throw "Migration rows must be 94; got $($migration.new.Count)."
}

$jsonOptions = @{ Depth = 20 }
[System.IO.File]::WriteAllText($masterPath, (($masterRows | ConvertTo-Json @jsonOptions) + "`n"), [System.Text.UTF8Encoding]::new($false))
[System.IO.File]::WriteAllText($migrationDataPath, (($migration | ConvertTo-Json @jsonOptions) + "`n"), [System.Text.UTF8Encoding]::new($false))
