<?php

namespace App\Services\Battle;

use App\Services\JobArtV2PreparedEffectState;
use App\Services\JobArtV2TimedEffectState;

class BattleActor
{
    public string $name;
    public bool $isPlayer;

    public int $hp;
    public int $maxHp;
    public int $mp;
    public int $maxMp;

    public int $str; // 物理攻撃力
    public int $def; // 物理防御力
    public int $agi; // 素早さ・回避・命中
    public int $mag; // 魔法攻撃力
    public int $spr; // 精神力・魔法防御力
    public int $luk; // 運・クリティカル率

    public int $baseStr;
    public int $baseDef;
    public int $baseAgi;
    public int $baseMag;
    public int $baseSpr;
    public int $baseLuk;

    public array $conditions = []; // 状態異常 ('poison' => 3 など)
    public array $buffs = [];      // ステータスバフ

    public $originalModel; // 元の Character または Enemy モデルへの参照
    public ?\App\Models\Skill $skill = null; // 装備または職業に紐づく固有スキル
    public array $jobArts = [];
    public array $jobArtRates = [];
    public array $jobArtOrigins = [];
    public array $jobArtPolicies = [];
    public array $jobArtConditions = [];
    public string $jobArtActivationPolicy = 'normal';
    public ?int $currentJobId = null;
    public ?string $jobKey = null;
    public array $battleTypeWeights = ['physical' => 1.0, 'speed' => 0.0, 'magical' => 0.0];
    public ?string $normalAttackType = null;
    public ?string $speciesKey = null;
    /** @var list<string> */
    public array $speciesKeys = [];
    public ?string $weaponKillerSpeciesKey = null;
    public float $weaponKillerDamageRate = 0.0;
    /** @var list<array{source: string, species_key: string, damage_rate: float}> */
    public array $weaponKillerEffects = [];
    public ?string $armorResistSpeciesKey = null;
    public float $armorSpeciesDamageReductionRate = 0.0;

    public bool $isDefending = false;
    public int $damageReductionRate = 0;
    public bool $gutsReady = false;
    public bool $gutsJustTriggered = false;

    /** @var array<string, int> 戦闘終了時に破棄する奥義v2試作用リソース。 */
    private array $resources = [];

    /** @var array<string, int> */
    private array $resourceCaps = [];

    /** 戦闘終了時に破棄する、竜冠槍将の貫通用1チャージ構え。 */
    private bool $piercingStance = false;

    /** 戦闘終了時に破棄する、崩し系譜のDEF/SPR一時低下。 */
    private ?\App\Services\JobArtV2BreakDebuffState $breakDebuffState = null;

    /** 戦闘終了時に破棄する、反撃系譜の2ラウンド構え。 */
    private ?\App\Services\JobArtV2CounterStanceState $counterStanceState = null;

    /** 戦闘終了時に破棄する、守護系譜の次回直接ダメージ軽減。 */
    private ?\App\Services\JobArtV2GuardState $jobArtV2GuardState = null;

    /** @var array<string, JobArtV2TimedEffectState> 戦闘終了時に破棄する時間制効果。 */
    private array $jobArtV2TimedEffects = [];

    /** @var array<string, JobArtV2PreparedEffectState> 戦闘終了時に破棄する準備効果。 */
    private array $jobArtV2PreparedEffects = [];

    /** 直前の自分の行動終了後から、直接物理攻撃を受領したか。 */
    private bool $physicalAttackReceivedSinceOwnAction = false;

    /** 黄金鑑定による、戦闘終了時に破棄する鑑定済みマーカー。 */
    private bool $jobArtV2Appraised = false;

    /** このアクターが受けたダメージの累計（DOT・追撃含む全経路）。戦闘ログ集計に使う。 */
    public int $totalDamageTaken = 0;

    private const MAG_NORMAL_ATTACK_JOB_KEYS = [
        'mage',
        'priest',
        'magic_swordsman',
        'magic_thief',
        'magic_archer',
        'bard',
        'bishop',
        'apothecary',
        'alchemist',
        'grand_sage',
        'phantom_king',
        'machinist_king',
        'priest_warrior',
        'merchant_sage_king',
        'abyss_walker',
        'ancient_alchemist_king',
        'time_space_king',
    ];

    public function __construct(string $name, bool $isPlayer, array $stats, $originalModel = null)
    {
        $this->name = $name;
        $this->isPlayer = $isPlayer;
        
        $this->maxHp = $stats['max_hp'] ?? 100;
        $this->hp = $stats['hp'] ?? $this->maxHp;
        
        $this->maxMp = $stats['max_mp'] ?? 0;
        $this->mp = $stats['mp'] ?? $this->maxMp;

        $this->str = $this->baseStr = $stats['str'] ?? 10;
        $this->def = $this->baseDef = $stats['def'] ?? 10;
        $this->agi = $this->baseAgi = $stats['agi'] ?? 10;
        $this->mag = $this->baseMag = $stats['mag'] ?? 10;
        $this->spr = $this->baseSpr = $stats['spr'] ?? 10;
        $this->luk = $this->baseLuk = $stats['luk'] ?? 10;
        $this->jobKey = isset($stats['job_key']) ? (string) $stats['job_key'] : null;
        $this->currentJobId = isset($stats['current_job_id']) ? (int) $stats['current_job_id'] : null;
        $this->battleTypeWeights = BattleTypeAffinity::normalize($stats['battle_type_weights'] ?? []);
        $this->normalAttackType = $this->normalizeNormalAttackType($stats['normal_attack_type'] ?? null);
        $speciesKey = trim((string) ($stats['species_key'] ?? ''));
        $this->speciesKeys = array_values(array_unique(array_filter(array_map(
            static fn ($candidate): string => trim((string) $candidate),
            (array) ($stats['species_keys'] ?? [])
        ))));
        if ($speciesKey !== '' && ! in_array($speciesKey, $this->speciesKeys, true)) {
            array_unshift($this->speciesKeys, $speciesKey);
        }
        $this->speciesKey = $this->speciesKeys[0] ?? null;
        $this->weaponKillerSpeciesKey = isset($stats['weapon_killer_species_key']) ? (string) $stats['weapon_killer_species_key'] : null;
        $this->weaponKillerDamageRate = max(0.0, (float) ($stats['weapon_killer_damage_rate'] ?? 0.0));
        foreach ((array) ($stats['weapon_killer_effects'] ?? []) as $effect) {
            $speciesKey = trim((string) ($effect['species_key'] ?? ''));
            $damageRate = max(0.0, (float) ($effect['damage_rate'] ?? 0.0));
            if ($speciesKey === '' || $damageRate <= 0) {
                continue;
            }

            $this->weaponKillerEffects[] = [
                'source' => (string) ($effect['source'] ?? 'affix'),
                'species_key' => $speciesKey,
                'damage_rate' => $damageRate,
            ];
        }
        if ($this->weaponKillerEffects === [] && $this->weaponKillerSpeciesKey && $this->weaponKillerDamageRate > 0) {
            $this->weaponKillerEffects[] = [
                'source' => 'affix',
                'species_key' => $this->weaponKillerSpeciesKey,
                'damage_rate' => $this->weaponKillerDamageRate,
            ];
        }
        if ($this->weaponKillerEffects !== [] && $this->weaponKillerSpeciesKey === null) {
            $legacyEffect = collect($this->weaponKillerEffects)
                ->firstWhere('source', 'affix')
                ?? $this->weaponKillerEffects[0];
            $this->weaponKillerSpeciesKey = $legacyEffect['species_key'];
            $this->weaponKillerDamageRate = $legacyEffect['damage_rate'];
        }
        $this->armorResistSpeciesKey = isset($stats['armor_resist_species_key']) ? (string) $stats['armor_resist_species_key'] : null;
        $this->armorSpeciesDamageReductionRate = max(0.0, (float) ($stats['armor_species_damage_reduction_rate'] ?? 0.0));

        $this->originalModel = $originalModel;
    }

    public function isDead(): bool
    {
        return $this->hp <= 0;
    }

    public function takeDamage(int $damage): void
    {
        $this->totalDamageTaken += max(0, $damage);
        $this->hp -= $damage;
        if ($this->hp <= 0 && $this->gutsReady) {
            $this->hp = 1;
            $this->gutsReady = false;
            $this->gutsJustTriggered = true;
            return;
        }

        if ($this->hp < 0) {
            $this->hp = 0;
        }
    }

    public function healHp(int $amount): int
    {
        $amount = max(0, (int) floor($amount * (1 - $this->conditionRate('recovery_block'))));
        $before = $this->hp;
        $this->hp += $amount;
        if ($this->hp > $this->maxHp) {
            $this->hp = $this->maxHp;
        }

        return $this->hp - $before;
    }

    public function consumeMp(int $amount): bool
    {
        if ($this->mp >= $amount) {
            $this->mp -= $amount;
            return true;
        }
        return false;
    }

    public function configureResource(string $resourceKey, int $cap): void
    {
        $cap = max(0, $cap);
        $this->resourceCaps[$resourceKey] = $cap;
        if (array_key_exists($resourceKey, $this->resources)) {
            $this->resources[$resourceKey] = max(0, min($cap, $this->resources[$resourceKey]));
        }
    }

    public function getResource(string $resourceKey): int
    {
        return (int) ($this->resources[$resourceKey] ?? 0);
    }

    public function setResource(string $resourceKey, int $points): int
    {
        $cap = $this->resourceCap($resourceKey);
        $this->resources[$resourceKey] = max(0, min($cap, $points));

        return $this->resources[$resourceKey];
    }

    public function addResource(string $resourceKey, int $points): int
    {
        return $this->setResource($resourceKey, $this->getResource($resourceKey) + max(0, $points));
    }

    public function canSpendResource(string $resourceKey, int $points): bool
    {
        return $points >= 0 && $this->getResource($resourceKey) >= $points;
    }

    public function spendResource(string $resourceKey, int $points): bool
    {
        if (!$this->canSpendResource($resourceKey, $points)) {
            return false;
        }

        $this->setResource($resourceKey, $this->getResource($resourceKey) - $points);

        return true;
    }

    public function resourceCap(string $resourceKey): int
    {
        return (int) ($this->resourceCaps[$resourceKey] ?? 0);
    }

    public function hasPiercingStance(): bool
    {
        return $this->piercingStance;
    }

    public function setPiercingStance(bool $active): void
    {
        $this->piercingStance = $active;
    }

    public function breakDebuffState(): ?\App\Services\JobArtV2BreakDebuffState
    {
        return $this->breakDebuffState;
    }

    public function replaceBreakDebuffState(?\App\Services\JobArtV2BreakDebuffState $state): void
    {
        $this->breakDebuffState = $state;
    }

    public function counterStanceState(): ?\App\Services\JobArtV2CounterStanceState
    {
        return $this->counterStanceState;
    }

    public function replaceCounterStanceState(?\App\Services\JobArtV2CounterStanceState $state): void
    {
        $this->counterStanceState = $state;
    }

    public function jobArtV2GuardState(): ?\App\Services\JobArtV2GuardState
    {
        return $this->jobArtV2GuardState;
    }

    public function replaceJobArtV2GuardState(?\App\Services\JobArtV2GuardState $state): void
    {
        $this->jobArtV2GuardState = $state;
    }

    public function jobArtV2TimedEffect(string $key): ?JobArtV2TimedEffectState
    {
        return $this->jobArtV2TimedEffects[$key] ?? null;
    }

    public function replaceJobArtV2TimedEffect(JobArtV2TimedEffectState $state): void
    {
        $this->jobArtV2TimedEffects[$state->key] = $state;
    }

    public function removeJobArtV2TimedEffect(string $key): ?JobArtV2TimedEffectState
    {
        $state = $this->jobArtV2TimedEffects[$key] ?? null;
        unset($this->jobArtV2TimedEffects[$key]);

        return $state;
    }

    /** @return list<JobArtV2TimedEffectState> */
    public function jobArtV2TimedEffects(): array
    {
        return array_values($this->jobArtV2TimedEffects);
    }

    public function jobArtV2PreparedEffect(string $key): ?JobArtV2PreparedEffectState
    {
        return $this->jobArtV2PreparedEffects[$key] ?? null;
    }

    public function replaceJobArtV2PreparedEffect(JobArtV2PreparedEffectState $state): void
    {
        $this->jobArtV2PreparedEffects[$state->key] = $state;
    }

    public function removeJobArtV2PreparedEffect(string $key): ?JobArtV2PreparedEffectState
    {
        $state = $this->jobArtV2PreparedEffects[$key] ?? null;
        unset($this->jobArtV2PreparedEffects[$key]);

        return $state;
    }

    /** @return list<JobArtV2PreparedEffectState> */
    public function jobArtV2PreparedEffects(): array
    {
        return array_values($this->jobArtV2PreparedEffects);
    }

    public function markPhysicalAttackReceivedSinceOwnAction(): void
    {
        $this->physicalAttackReceivedSinceOwnAction = true;
    }

    public function consumePhysicalAttackReceivedSinceOwnActionSnapshot(): bool
    {
        $received = $this->physicalAttackReceivedSinceOwnAction;
        $this->physicalAttackReceivedSinceOwnAction = false;

        return $received;
    }

    public function markJobArtV2Appraised(): void
    {
        $this->jobArtV2Appraised = true;
    }

    public function isJobArtV2Appraised(): bool
    {
        return $this->jobArtV2Appraised;
    }

    public function usesMagForNormalAttack(): bool
    {
        if ($this->normalAttackType === 'adaptive') {
            return $this->mag > $this->str;
        }

        if ($this->normalAttackType !== null) {
            return $this->normalAttackType === 'magical';
        }

        return $this->jobKey !== null && in_array($this->jobKey, self::MAG_NORMAL_ATTACK_JOB_KEYS, true);
    }

    public function effectiveStr(): int
    {
        return $this->applyJobArtV2TimedStatModifier(
            $this->effectiveStat($this->str, 'atk_down'),
            'str',
        );
    }

    public function effectiveDef(): int
    {
        return $this->applyJobArtV2TimedStatModifier(
            $this->effectiveStatWithBreak($this->def, 'def_down'),
            'def',
        );
    }

    public function effectivePercentageDef(): float
    {
        return $this->effectivePercentageDefenseStat($this->def, 'def_down', 'def');
    }

    public function effectiveAgi(): int
    {
        return $this->applyJobArtV2TimedStatModifier(
            $this->effectiveStat($this->agi, 'slow'),
            'agi',
        );
    }

    public function effectiveMag(): int
    {
        return $this->applyJobArtV2TimedStatModifier(
            $this->effectiveStat($this->mag, 'mag_down'),
            'mag',
        );
    }

    public function effectiveSpr(): int
    {
        return $this->applyJobArtV2TimedStatModifier(
            $this->effectiveStatWithBreak($this->spr, 'spr_down'),
            'spr',
        );
    }

    public function effectivePercentageSpr(): float
    {
        return $this->effectivePercentageDefenseStat($this->spr, 'spr_down', 'spr');
    }

    public function hybridAttackPower(string $scaling, bool $useEffectiveStats): int
    {
        $str = $useEffectiveStats ? $this->effectiveStr() : $this->str;
        $mag = $useEffectiveStats ? $this->effectiveMag() : $this->mag;

        return $scaling === 'max'
            ? max($str, $mag)
            : (int) floor(($str + $mag) / 2);
    }

    public function effectiveLuk(): int
    {
        return $this->applyJobArtV2TimedStatModifier($this->luk, 'luk');
    }

    public function conditionRate(string $key): float
    {
        $condition = $this->conditions[$key] ?? null;

        return is_array($condition) ? max(0.0, min(1.0, (float) ($condition['rate'] ?? 0))) : 0.0;
    }

    private function effectiveStat(int $value, string $conditionKey): int
    {
        return max(1, (int) floor($value * (1 - $this->conditionRate($conditionKey))));
    }

    private function effectiveStatWithBreak(int $value, string $conditionKey): int
    {
        $effective = $this->effectiveStat($value, $conditionKey);
        $breakRate = max(0.0, min(1.0, $this->breakDebuffState?->rate ?? 0.0));

        return max(1, (int) floor($effective * (1 - $breakRate)));
    }

    private function effectivePercentageDefenseStat(int $value, string $conditionKey, string $stat): float
    {
        // The percentage-defense formula intentionally permits zero defense.
        // Apply each debuff layer in the same order as effectiveDef/effectiveSpr,
        // but retain zero instead of the legacy minimum-stat clamp of one.
        $effective = max(0, (int) floor($value * (1 - $this->conditionRate($conditionKey))));
        $breakRate = max(0.0, min(1.0, $this->breakDebuffState?->rate ?? 0.0));
        $effective = max(0, (int) floor($effective * (1 - $breakRate)));

        return (float) $this->applyJobArtV2TimedStatModifier($effective, $stat, 0);
    }

    private function applyJobArtV2TimedStatModifier(int $value, string $stat, int $minimum = 1): int
    {
        $modifier = 0.0;
        foreach ($this->jobArtV2TimedEffects as $effect) {
            if ($effect->isExpired()) {
                continue;
            }

            $modifier += (float) ($effect->statModifiers[$stat] ?? 0.0);
        }

        if (abs($modifier) < 0.0000001) {
            return $value;
        }

        // Decimal rates such as 15% can be represented as 1.149999... in
        // binary floating point. Keep the specified floor rule without losing
        // a whole stat point at exact integer boundaries.
        return max($minimum, (int) floor(($value * (1 + $modifier)) + 1.0e-9));
    }

    private function normalizeNormalAttackType(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['physical', 'magical', 'adaptive'], true)) {
            return $value;
        }

        return null;
    }
}
