<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;

class Character extends Model
{
    use HasFactory;

    private const TESTER_EMAIL_PATTERN = 'tester_%@valzeria.local';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Character $character): void {
            if (Schema::hasColumn('characters', 'equipment_storage_limit') && $character->equipment_storage_limit === null) {
                $character->equipment_storage_limit = 300;
            }

            if ($character->explore_stamina !== null) {
                return;
            }

            $max = app(\App\Services\ExplorationStaminaService::class)->maxForCharacter($character);
            $character->explore_stamina = $max;
            $character->explore_stamina_max = $max;
            $character->explore_stamina_updated_at = $character->explore_stamina_updated_at ?: now();
        });
    }

    // 日付ミューテータの設定
    protected $casts = [
        'is_frozen' => 'boolean',
        'frozen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_battle_at' => 'datetime',
        'last_champ_battle_at' => 'datetime',
        'exploration_cooldown_until' => 'datetime',
        'explore_stamina' => 'integer',
        'explore_stamina_max' => 'integer',
        'explore_stamina_updated_at' => 'datetime',
        'inn_rescue_streak' => 'integer',
        'kiseki' => 'integer',
        'paid_kiseki' => 'integer',
        'free_kiseki' => 'integer',
        'money' => 'integer',
        'bank_gold' => 'integer',
        'material_storage_limit' => 'integer',
        'equipment_storage_limit' => 'integer',
        'guild_donation_total' => 'integer',
        'hp_fraction' => 'float',
        'mp_fraction' => 'float',
        'attack_fraction' => 'float',
        'defense_fraction' => 'float',
        'magic_fraction' => 'float',
        'speed_fraction' => 'float',
        'luck_fraction' => 'float',
        'spirit_fraction' => 'float',
        'beginner_mission_completed_keys' => 'array',
        'beginner_mission_reward_claimed' => 'boolean',
        'home_display_mode' => 'string',
        'job_art_activation_policy' => 'string',
        'profile_comment' => 'string',
        'profile_ranch_background' => 'string',
        'profile_frame_theme' => 'string',
        'profile_card_background' => 'string',
        'profile_card_frame' => 'string',
        'profile_avatar_frame' => 'string',
        'profile_valmon_case' => 'string',
        'private_chat_theme' => 'string',
        'chat_all_tab_visibility' => 'array',
    ];

    /**
     * ユーザーとのリレーション
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleToPublic($query)
    {
        return $query->whereDoesntHave('user', function ($userQuery): void {
            $userQuery->where('email', 'like', self::TESTER_EMAIL_PATTERN);
        });
    }

    public function scopeAdminTesters($query)
    {
        return $query->whereHas('user', function ($userQuery): void {
            $userQuery->where('email', 'like', self::TESTER_EMAIL_PATTERN);
        });
    }

    public function isAdminTester(): bool
    {
        return str_starts_with((string) ($this->user?->email ?? ''), 'tester_')
            && str_ends_with((string) ($this->user?->email ?? ''), '@valzeria.local');
    }

    /**
     * 現在の職業とのリレーション
     */
    public function jobClass()
    {
        return $this->belongsTo(JobClass::class, 'current_job_id');
    }

    public function currentJob()
    {
        return $this->belongsTo(JobClass::class, 'current_job_id');
    }

    public function currentCity()
    {
        return $this->belongsTo(City::class, 'current_city_id');
    }

    public function highestCity()
    {
        return $this->belongsTo(City::class, 'highest_city_id');
    }

    /**
     * これまでの転職履歴（マスターした職業など）
     */
    public function jobHistories()
    {
        return $this->hasMany(CharacterJob::class);
    }

    public function jobArtSlots()
    {
        return $this->hasMany(CharacterJobArtSlot::class);
    }

    /**
     * エリア進行状況とのリレーション
     */
    public function areaProgresses()
    {
        return $this->hasMany(CharacterAreaProgress::class);
    }

    public function cityDiscoveries()
    {
        return $this->hasMany(CharacterCityDiscovery::class);
    }

    /**
     * 戦闘ログとのリレーション
     */
    public function battleLogs()
    {
        return $this->hasMany(BattleLog::class);
    }

    public function goldTransactions()
    {
        return $this->hasMany(GoldTransaction::class);
    }

    public function marketListings()
    {
        return $this->hasMany(MarketListing::class, 'seller_character_id');
    }

    public function marketSales()
    {
        return $this->hasMany(MarketTransaction::class, 'seller_character_id');
    }

    public function marketPurchases()
    {
        return $this->hasMany(MarketTransaction::class, 'buyer_character_id');
    }

    public function notifications()
    {
        return $this->hasMany(CharacterNotification::class);
    }

    public function explorationState()
    {
        return $this->hasOne(CharacterExplorationState::class);
    }

    /**
     * 所持アイテム・装備とのリレーション
     */
    public function characterItems()
    {
        return $this->hasMany(CharacterItem::class);
    }

    /**
     * 所持素材とのリレーション
     */
    public function characterMaterials()
    {
        return $this->hasMany(CharacterMaterial::class);
    }

    public function namelessEquipments()
    {
        return $this->hasMany(PlayerNamelessEquipment::class);
    }

    public function consumableItems()
    {
        return $this->hasMany(CharacterConsumableItem::class);
    }

    public function valmons()
    {
        return $this->hasMany(PlayerValmon::class);
    }

    public function partnerValmon()
    {
        return $this->hasOne(PlayerValmon::class)->where('is_partner', true);
    }

    public function valmonEggs()
    {
        return $this->hasMany(PlayerValmonEgg::class);
    }

    public function titles()
    {
        return $this->hasMany(CharacterTitle::class);
    }

    /**
     * 転職履歴
     */
    public function jobChangeLogs()
    {
        return $this->hasMany(JobChangeLog::class);
    }

    /**
     * 闘技場ランキング
     */
    public function arenaRanking()
    {
        return $this->hasOne(ArenaRanking::class);
    }

    /**
     * 自分が挑んだ闘技場ログ
     */
    public function arenaAttackLogs()
    {
        return $this->hasMany(ArenaLog::class, 'attacker_id');
    }

    /**
     * 自分が挑まれた闘技場ログ
     */
    public function arenaDefenseLogs()
    {
        return $this->hasMany(ArenaLog::class, 'defender_id');
    }

    public function towerRuns()
    {
        return $this->hasMany(TowerRun::class);
    }

    public function towerWeeklyRecords()
    {
        return $this->hasMany(TowerWeeklyRecord::class);
    }

    public function towerCharacterRecords()
    {
        return $this->hasMany(TowerCharacterRecord::class);
    }
}
