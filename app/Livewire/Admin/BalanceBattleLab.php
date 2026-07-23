<?php

namespace App\Livewire\Admin;

use App\Models\Enemy;
use App\Models\Item;
use App\Models\JobClass;
use App\Services\EquipmentEnhancementService;
use Illuminate\Support\Collection;
use Livewire\Component;

class BalanceBattleLab extends Component
{
    private const GROWTH_MULTIPLIER = 1.12;

    private const BASE_STATS = [
        'max_hp' => 100,
        'max_mp' => 0,
        'str' => 10,
        'def' => 8,
        'agi' => 8,
        'mag' => 8,
        'spr' => 10,
        'luk' => 5,
    ];

    private const BP_GAINS = [
        'hp' => 10,
        'mp' => 5,
        'str' => 1,
        'def' => 1,
        'agi' => 1,
        'mag' => 1,
        'spr' => 1,
        'luk' => 1,
    ];

    public ?int $selectedJobId = null;
    public int $playerLevel = 30;
    public int $jobRank = 10;
    public bool $jobChangeMode = false;
    public array $jobChangeBaseStats = [];
    public ?string $jobChangeSourceLabel = null;

    /** @var array<string, int> */
    public array $bp = [
        'hp' => 0,
        'mp' => 0,
        'str' => 0,
        'def' => 0,
        'agi' => 0,
        'mag' => 0,
        'spr' => 0,
        'luk' => 0,
    ];

    public ?int $weaponId = null;
    public ?int $armorId = null;
    public ?int $accessoryId = null;
    public int $weaponEnhance = 0;

    public string $enemySearch = '';
    public ?int $selectedEnemyId = null;
    public array $result = [];
    public array $presets = [];

    public function mount(): void
    {
        $this->selectedJobId = JobClass::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
        $this->presets = session('admin.balance_battle_lab.presets', []);
    }

    public function updated($name): void
    {
        if ($name !== 'enemySearch') {
            $this->result = [];
        }
    }

    public function selectEnemy(int $enemyId): void
    {
        $this->selectedEnemyId = $enemyId;
        $this->result = [];
    }

    public function runSimulation(): void
    {
        $this->validate([
            'selectedJobId' => ['required', 'integer', 'exists:job_classes,id'],
            'playerLevel' => ['required', 'integer', 'min:1', 'max:255'],
            'jobRank' => ['required', 'integer', 'min:1', 'max:50'],
            'weaponEnhance' => ['required', 'integer', 'min:0', 'max:3'],
            'selectedEnemyId' => ['required', 'integer', 'exists:enemies,id'],
            'bp.hp' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.mp' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.str' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.def' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.agi' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.mag' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.spr' => ['required', 'integer', 'min:0', 'max:999'],
            'bp.luk' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $player = $this->playerStats();
        $enemy = $this->selectedEnemy();
        if (! $enemy) {
            $this->result = [];
            return;
        }

        $enemyStats = $this->enemyStats($enemy);
        $playerPhysical = $this->averagePhysicalDamage($player['str'], $enemyStats['def']);
        $playerMagical = $this->averageMagicalDamage($player['mag'], $enemyStats['spr']);
        $playerDamage = max($playerPhysical, $playerMagical);
        $playerAttackType = $playerMagical > $playerPhysical ? '魔法' : '物理';

        $enemyPhysical = $this->averageEnemyPhysicalDamage($enemyStats['str'], $player['def']);
        $enemyMagical = $this->averageEnemyMagicalDamage($enemyStats['mag'], $player['spr']);
        $enemyDamage = max($enemyPhysical, $enemyMagical);
        $enemyAttackType = $enemyMagical > $enemyPhysical ? '魔法' : '物理';

        $turnsToDefeatEnemy = (int) ceil($enemyStats['max_hp'] / max(1, $playerDamage));
        $turnsToDefeatPlayer = (int) ceil($player['max_hp'] / max(1, $enemyDamage));
        $margin = round($turnsToDefeatPlayer / max(1, $turnsToDefeatEnemy), 2);

        $this->result = [
            'player' => $player,
            'enemy' => $enemyStats,
            'player_physical_damage' => $playerPhysical,
            'player_magical_damage' => $playerMagical,
            'player_damage' => $playerDamage,
            'player_attack_type' => $playerAttackType,
            'enemy_physical_damage' => $enemyPhysical,
            'enemy_magical_damage' => $enemyMagical,
            'enemy_damage' => $enemyDamage,
            'enemy_attack_type' => $enemyAttackType,
            'turns_to_defeat_enemy' => $turnsToDefeatEnemy,
            'turns_to_defeat_player' => $turnsToDefeatPlayer,
            'margin' => $margin,
            'judgement' => $this->judgement($margin, $turnsToDefeatEnemy),
        ];
    }

    public function savePreset(int $slot): void
    {
        if ($slot < 1 || $slot > 3) {
            return;
        }

        $this->presets[$slot] = [
            'saved_at' => now()->format('Y/m/d H:i'),
            'selectedJobId' => $this->selectedJobId,
            'playerLevel' => $this->playerLevel,
            'jobRank' => $this->jobRank,
            'jobChangeMode' => $this->jobChangeMode,
            'jobChangeBaseStats' => $this->jobChangeBaseStats,
            'jobChangeSourceLabel' => $this->jobChangeSourceLabel,
            'bp' => $this->bp,
            'weaponId' => $this->weaponId,
            'armorId' => $this->armorId,
            'accessoryId' => $this->accessoryId,
            'weaponEnhance' => $this->weaponEnhance,
            'selectedEnemyId' => $this->selectedEnemyId,
            'enemyName' => $this->selectedEnemy()?->name,
            'jobName' => $this->selectedJob()?->name,
        ];

        session(['admin.balance_battle_lab.presets' => $this->presets]);
    }

    public function loadPreset(int $slot): void
    {
        $preset = $this->presets[$slot] ?? null;
        if (! is_array($preset)) {
            return;
        }

        $this->selectedJobId = $preset['selectedJobId'] ?? $this->selectedJobId;
        $this->playerLevel = (int) ($preset['playerLevel'] ?? $this->playerLevel);
        $this->jobRank = (int) ($preset['jobRank'] ?? $this->jobRank);
        $this->jobChangeMode = (bool) ($preset['jobChangeMode'] ?? false);
        $this->jobChangeBaseStats = (array) ($preset['jobChangeBaseStats'] ?? []);
        $this->jobChangeSourceLabel = $preset['jobChangeSourceLabel'] ?? null;
        $this->bp = array_replace($this->bp, (array) ($preset['bp'] ?? []));
        $this->weaponId = $preset['weaponId'] ?? null;
        $this->armorId = $preset['armorId'] ?? null;
        $this->accessoryId = $preset['accessoryId'] ?? null;
        $this->weaponEnhance = (int) ($preset['weaponEnhance'] ?? 0);
        $this->selectedEnemyId = $preset['selectedEnemyId'] ?? null;
        $this->result = [];
    }

    public function clearPreset(int $slot): void
    {
        if ($slot < 1 || $slot > 3) {
            return;
        }

        unset($this->presets[$slot]);
        session(['admin.balance_battle_lab.presets' => $this->presets]);
    }

    public function captureJobChangeBase(): void
    {
        $sourceStats = $this->calculatePlayerStats(false, true, false);
        $this->jobChangeBaseStats = [];

        foreach ($sourceStats as $key => $value) {
            $minimum = in_array($key, ['max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'], true) ? 1 : 0;
            $this->jobChangeBaseStats[$key] = max($minimum, (int) floor(((int) $value) / 2));
        }

        $this->jobChangeMode = true;
        $this->jobChangeSourceLabel = sprintf(
            '%s / Lv%d / Rank%d',
            $this->selectedJob()?->name ?? '職業未設定',
            $this->playerLevel,
            $this->jobRank
        );
        $this->playerLevel = 1;
        $this->jobRank = 1;
        $this->result = [];
    }

    public function resetSimulation(): void
    {
        $this->selectedJobId = JobClass::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
        $this->playerLevel = 30;
        $this->jobRank = 10;
        $this->jobChangeMode = false;
        $this->jobChangeBaseStats = [];
        $this->jobChangeSourceLabel = null;
        $this->bp = [
            'hp' => 0,
            'mp' => 0,
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
            'luk' => 0,
        ];
        $this->weaponId = null;
        $this->armorId = null;
        $this->accessoryId = null;
        $this->weaponEnhance = 0;
        $this->selectedEnemyId = null;
        $this->result = [];
    }

    public function render()
    {
        return view('livewire.admin.balance-battle-lab', [
            'jobs' => $this->jobs(),
            'weapons' => $this->equipmentOptions('weapon'),
            'armors' => $this->equipmentOptions('armor'),
            'accessories' => $this->equipmentOptions('accessory'),
            'enemyCandidates' => $this->enemyCandidates(),
            'selectedJob' => $this->selectedJob(),
            'selectedEnemy' => $this->selectedEnemy(),
            'playerStats' => $this->playerStats(),
            'jobChangeMode' => $this->jobChangeMode,
            'jobChangeBaseStats' => $this->jobChangeBaseStats,
            'jobChangeSourceLabel' => $this->jobChangeSourceLabel,
            'bpSpent' => array_sum(array_map('intval', $this->bp)),
            'bpAvailable' => max(0, $this->playerLevel - 1),
        ])->layout('components.layouts.admin');
    }

    private function jobs(): Collection
    {
        return JobClass::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function equipmentOptions(string $type): Collection
    {
        return Item::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('required_level')
            ->orderBy('id')
            ->get();
    }

    private function enemyCandidates(): Collection
    {
        $query = Enemy::query()
            ->with('area.city')
            ->orderBy('level')
            ->orderBy('id');

        $search = trim($this->enemySearch);
        if ($search !== '') {
            $query->where(function ($enemyQuery) use ($search) {
                $enemyQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('id', $search)
                    ->orWhereHas('area', fn ($areaQuery) => $areaQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('area.city', fn ($cityQuery) => $cityQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        return $query->limit(1000)->get();
    }

    private function selectedJob(): ?JobClass
    {
        return $this->selectedJobId ? JobClass::find($this->selectedJobId) : null;
    }

    private function selectedEnemy(): ?Enemy
    {
        return $this->selectedEnemyId ? Enemy::with('area.city')->find($this->selectedEnemyId) : null;
    }

    private function playerStats(): array
    {
        return $this->calculatePlayerStats(true, true, true);
    }

    private function calculatePlayerStats(bool $allowJobChangeBase, bool $withBp, bool $withEquipment): array
    {
        $job = $this->selectedJob();
        $stats = $allowJobChangeBase && $this->jobChangeMode && $this->jobChangeBaseStats !== []
            ? array_replace(self::BASE_STATS, $this->jobChangeBaseStats)
            : self::BASE_STATS;

        if ($job) {
            for ($level = 2; $level <= $this->playerLevel; $level++) {
                $stats['max_hp'] += (int) floor(8.25 * self::GROWTH_MULTIPLIER * (((int) ($job->hp_rate ?? 100)) / 100));
                $stats['max_mp'] += (int) floor(4.95 * self::GROWTH_MULTIPLIER * (((int) ($job->mp_rate ?? 100)) / 100));
                $stats['str'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->attack_rate ?? 100)) / 100));
                $stats['def'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->defense_rate ?? 100)) / 100));
                $stats['agi'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->speed_rate ?? 100)) / 100));
                $stats['mag'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->magic_rate ?? 100)) / 100));
                $stats['spr'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->spirit_rate ?? 100)) / 100));
                $stats['luk'] += (int) floor(3.85 * self::GROWTH_MULTIPLIER * (((int) ($job->luck_rate ?? 100)) / 100));
            }

            $rank = max(1, $this->jobRank);
            $stats['max_hp'] += (int) ((int) ($job->bonus_hp ?? 0) * $rank * 0.5);
            $stats['max_mp'] += (int) ((int) ($job->bonus_mp ?? 0) * $rank * 0.5);
            $stats['str'] += (int) ((int) ($job->bonus_str ?? 0) * $rank * 0.5);
            $stats['def'] += (int) ((int) ($job->bonus_def ?? 0) * $rank * 0.5);
            $stats['agi'] += (int) ((int) ($job->bonus_spd ?? 0) * $rank * 0.5);
            $stats['mag'] += (int) ((int) ($job->bonus_mag ?? 0) * $rank * 0.5);
            $stats['spr'] += (int) ((int) ($job->bonus_spr ?? 0) * $rank * 0.5);
            $stats['luk'] += (int) ((int) ($job->bonus_luk ?? 0) * $rank * 0.5);
        }

        if ($withBp) {
            foreach (self::BP_GAINS as $stat => $gain) {
                $key = $stat === 'hp' ? 'max_hp' : ($stat === 'mp' ? 'max_mp' : $stat);
                $stats[$key] += max(0, (int) ($this->bp[$stat] ?? 0)) * $gain;
            }
        }

        if ($withEquipment) {
            foreach ($this->selectedEquipment() as $slot => $item) {
                $enhanceLevel = $slot === 'weapon' ? $this->weaponEnhance : 0;
                $stats['max_hp'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->hp_bonus ?? 0), $enhanceLevel);
                $stats['max_mp'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->mp_bonus ?? 0), $enhanceLevel);
                $stats['str'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->str_bonus ?? 0), $enhanceLevel);
                $stats['def'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->def_bonus ?? 0), $enhanceLevel);
                $stats['agi'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->agi_bonus ?? 0), $enhanceLevel);
                $stats['mag'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->mag_bonus ?? 0), $enhanceLevel);
                $stats['spr'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->spr_bonus ?? 0), $enhanceLevel);
                $stats['luk'] += EquipmentEnhancementService::bonusWithEnhancement((int) ($item->luk_bonus ?? 0), $enhanceLevel);
            }
        }

        return array_map(fn ($value) => max(0, (int) $value), $stats);
    }

    /**
     * @return array<string, Item>
     */
    private function selectedEquipment(): array
    {
        $ids = [
            'weapon' => $this->weaponId,
            'armor' => $this->armorId,
            'accessory' => $this->accessoryId,
        ];

        $items = [];
        foreach ($ids as $slot => $id) {
            if ($id) {
                $item = Item::where('type', $slot)->find($id);
                if ($item) {
                    $items[$slot] = $item;
                }
            }
        }

        return $items;
    }

    private function enemyStats(Enemy $enemy): array
    {
        $enemy->loadMissing('area');
        $str = (int) $enemy->str;
        $mag = (int) $enemy->mag;

        if (($enemy->area?->city_id ?? 0) >= 1 && ($enemy->area?->city_id ?? 0) <= 3) {
            $str = max(1, (int) floor($str * 0.92));
            $mag = max(1, (int) floor($mag * 0.92));
        }

        return [
            'name' => $enemy->name,
            'level' => (int) $enemy->level,
            'max_hp' => (int) $enemy->max_hp,
            'str' => $str,
            'def' => (int) $enemy->def,
            'agi' => (int) $enemy->agi,
            'mag' => $mag,
            'spr' => (int) ($enemy->spr ?? $enemy->def),
            'luk' => (int) ($enemy->luk ?? 10),
        ];
    }

    private function averagePhysicalDamage(int $attack, int $defense): int
    {
        return max(1, (int) floor($attack - ($defense / 2)));
    }

    private function averageMagicalDamage(int $magic, int $spirit): int
    {
        return max(1, (int) floor($magic - ($spirit / 2)));
    }

    private function averageEnemyPhysicalDamage(int $attack, int $defense): int
    {
        return $this->averageEnemyPercentageDefenseDamage($attack, $defense);
    }

    private function averageEnemyMagicalDamage(int $magic, int $spirit): int
    {
        return $this->averageEnemyPercentageDefenseDamage($magic, $spirit);
    }

    private function averageEnemyPercentageDefenseDamage(int $attack, int $defense): int
    {
        if (! config('battle.pve_enemy_percentage_defense.enabled', false)) {
            return max(1, (int) floor($attack - ($defense / 2)));
        }

        $attack = max(1, $attack);
        $defense = max(0, $defense);
        $coefficient = max(0.0, (float) config('battle.pve_enemy_percentage_defense.defense_coefficient', 3.5));

        return max(1, (int) floor(($attack * $attack) / ($attack + ($coefficient * $defense))));
    }

    private function judgement(float $margin, int $turnsToDefeatEnemy): array
    {
        if ($margin < 0.9) {
            return ['label' => '危険', 'class' => 'bg-red-50 text-red-700 ring-red-200', 'message' => '推奨条件では負けやすい想定です。装備更新・Lv上げ・敵火力調整を確認してください。'];
        }

        if ($turnsToDefeatEnemy <= 4 || $margin >= 2.4) {
            return ['label' => '易しすぎ', 'class' => 'bg-sky-50 text-sky-700 ring-sky-200', 'message' => 'かなり余裕があります。ボスならHPか有効火力を上げる余地があります。'];
        }

        if ($turnsToDefeatEnemy >= 20 && $margin <= 1.15) {
            return ['label' => '重い', 'class' => 'bg-orange-50 text-orange-700 ring-orange-200', 'message' => '勝てても長期戦です。周回テンポを考えるならHPか防御寄りを下げてもよさそうです。'];
        }

        return ['label' => '適正', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'message' => '疑似対戦上はおおむね適正圏です。'];
    }
}
