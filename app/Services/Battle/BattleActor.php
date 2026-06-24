<?php

namespace App\Services\Battle;

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
    public string $jobArtActivationPolicy = 'normal';
    public ?string $jobKey = null;
    public array $battleTypeWeights = ['physical' => 1.0, 'speed' => 0.0, 'magical' => 0.0];
    public ?string $normalAttackType = null;

    public bool $isDefending = false;
    public int $damageReductionRate = 0;
    public bool $gutsReady = false;

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
        $this->battleTypeWeights = BattleTypeAffinity::normalize($stats['battle_type_weights'] ?? []);
        $this->normalAttackType = $this->normalizeNormalAttackType($stats['normal_attack_type'] ?? null);

        $this->originalModel = $originalModel;
    }

    public function isDead(): bool
    {
        return $this->hp <= 0;
    }

    public function takeDamage(int $damage): void
    {
        $this->hp -= $damage;
        if ($this->hp <= 0 && $this->gutsReady) {
            $this->hp = 1;
            $this->gutsReady = false;
            return;
        }

        if ($this->hp < 0) {
            $this->hp = 0;
        }
    }

    public function healHp(int $amount): void
    {
        $this->hp += $amount;
        if ($this->hp > $this->maxHp) {
            $this->hp = $this->maxHp;
        }
    }

    public function consumeMp(int $amount): bool
    {
        if ($this->mp >= $amount) {
            $this->mp -= $amount;
            return true;
        }
        return false;
    }

    public function usesMagForNormalAttack(): bool
    {
        if ($this->normalAttackType !== null) {
            return $this->normalAttackType === 'magical';
        }

        return $this->jobKey !== null && in_array($this->jobKey, self::MAG_NORMAL_ATTACK_JOB_KEYS, true);
    }

    private function normalizeNormalAttackType(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['physical', 'magical'], true)) {
            return $value;
        }

        return null;
    }
}
