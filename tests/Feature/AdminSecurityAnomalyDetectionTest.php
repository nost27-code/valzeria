<?php

namespace Tests\Feature;

use App\Livewire\Admin\SecurityAnomalyManager;
use App\Models\AdminItemGrantLog;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\SecurityAnomalyCase;
use App\Models\User;
use App\Services\Admin\SecurityAnomalyDetectionService;
use App\Services\Admin\SecurityLoginObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSecurityAnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_anomaly_center_and_close_a_case_with_audit_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $case = SecurityAnomalyCase::query()->create([
            'rule_key' => 'rapid_battles', 'fingerprint' => hash('sha256', 'test-case'), 'severity' => 'critical',
            'status' => 'detected', 'user_id' => $user->id, 'title' => '短時間の大量戦闘', 'summary' => 'テスト検知',
            'first_detected_at' => now(), 'last_detected_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.security-anomalies'))->assertOk()->assertSee('異常検知・不正調査');
        $this->actingAs($user)->get(route('admin.security-anomalies'))->assertRedirect('/admin/login');
        Livewire::actingAs($user)->test(SecurityAnomalyManager::class)->assertForbidden();

        Livewire::actingAs($admin)->test(SecurityAnomalyManager::class)
            ->call('selectCase', $case->id)
            ->set('resolutionNote', '通常プレイの範囲であることをログで確認')
            ->call('updateStatus', 'cleared')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('security_anomaly_cases', ['id' => $case->id, 'status' => 'cleared', 'reviewed_by' => $admin->id]);
        $this->assertDatabaseHas('security_anomaly_case_events', ['security_anomaly_case_id' => $case->id, 'from_status' => 'detected', 'to_status' => 'cleared', 'admin_user_id' => $admin->id]);

        Livewire::actingAs($admin)->test(SecurityAnomalyManager::class)
            ->call('selectCase', $case->id)
            ->set('resolutionNote', '追加ログも確認済み')
            ->call('saveNote');
        $this->assertDatabaseHas('security_anomaly_case_events', ['security_anomaly_case_id' => $case->id, 'from_status' => 'cleared', 'to_status' => 'cleared', 'note' => '追加ログも確認済み']);
    }

    public function test_scan_detects_rapid_battles_and_unexpected_job_exp_without_duplicate_cases(): void
    {
        config()->set('security_anomaly_detection.rules.rapid_battles.threshold', 2);
        $character = $this->createCharacter();
        [$areaId, $enemyId] = $this->battleMasterIds();

        foreach ([7, 8] as $jobExp) {
            BattleLog::query()->create([
                'character_id' => $character->id, 'area_id' => $areaId, 'enemy_id' => $enemyId,
                'battle_type' => 'normal', 'result' => 'win', 'exp_gained' => 1, 'gold_gained' => 1,
                'job_exp_gained' => $jobExp, 'level_up_count' => 0, 'log_text' => 'test', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $service = app(SecurityAnomalyDetectionService::class);
        $service->scan();
        $service->scan();

        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'rapid_battles', 'character_id' => $character->id]);
        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'unexpected_job_exp', 'character_id' => $character->id]);
        $this->assertSame(1, SecurityAnomalyCase::query()->where('rule_key', 'rapid_battles')->count());
        $this->assertSame(1, SecurityAnomalyCase::query()->where('rule_key', 'unexpected_job_exp')->count());
        $this->assertSame(2, SecurityAnomalyCase::query()->where('rule_key', 'unexpected_job_exp')->firstOrFail()->detection_count);
    }

    public function test_scan_detects_abnormal_gold_and_shared_ip_without_storing_plain_ip(): void
    {
        config()->set('security_anomaly_detection.rules.gold_change.total_threshold', 100);
        config()->set('security_anomaly_detection.rules.gold_change.single_threshold', 100);
        config()->set('security_anomaly_detection.rules.shared_ip.account_threshold', 2);
        $first = $this->createCharacter();
        $second = $this->createCharacter();

        GoldTransaction::query()->create(['character_id' => $first->id, 'type' => 'test', 'amount' => 500, 'balance_after' => 500]);
        $observer = app(SecurityLoginObservationService::class);
        $observer->observe($first->user, '203.0.113.42');
        $observer->observe($second->user, '203.0.113.42');

        app(SecurityAnomalyDetectionService::class)->scan();

        $observer->observe($first->user, '203.0.113.42');
        app(SecurityAnomalyDetectionService::class)->scan();

        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'gold_change', 'character_id' => $first->id]);
        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'shared_ip']);
        $observation = DB::table('security_login_observations')->first();
        $this->assertSame('203.0.113.xxx', $observation->masked_ip);
        $this->assertNotSame('203.0.113.42', $observation->ip_hash);
        $this->assertSame(1, SecurityAnomalyCase::query()->where('rule_key', 'shared_ip')->firstOrFail()->detection_count);
    }

    public function test_ip_observation_is_atomic_and_normalizes_ipv6(): void
    {
        $character = $this->createCharacter();
        $observer = app(SecurityLoginObservationService::class);

        $observer->observe($character->user, '2001:db8::1');
        $observer->observe($character->user, '2001:0db8:0000:0000:0000:0000:0000:0001');

        $this->assertDatabaseCount('security_login_observations', 1);
        $this->assertSame(2, (int) DB::table('security_login_observations')->value('observation_count'));
        $this->assertSame('2001:0db8:0000:xxxx::*', DB::table('security_login_observations')->value('masked_ip'));
    }

    public function test_inventory_growth_uses_first_scan_as_baseline_then_detects_net_increase(): void
    {
        config()->set('security_anomaly_detection.rules.inventory_growth.equipment_threshold', 1);
        config()->set('security_anomaly_detection.rules.inventory_growth.material_threshold', 5);
        $character = $this->createCharacter();
        $itemId = $this->equipmentItemId();
        $materialId = $this->materialId();
        $service = app(SecurityAnomalyDetectionService::class);

        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $itemId]);
        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $materialId, 'quantity' => 1]);
        $service->scan();
        $this->assertDatabaseMissing('security_anomaly_cases', ['rule_key' => 'inventory_growth']);

        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $itemId]);
        CharacterMaterial::query()->where('character_id', $character->id)->where('material_id', $materialId)->update(['quantity' => 10]);
        $service->scan();

        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'inventory_growth', 'character_id' => $character->id]);
    }

    public function test_high_value_trade_after_admin_grant_is_detected(): void
    {
        config()->set('security_anomaly_detection.rules.admin_grant_trade.price_threshold', 100);
        $admin = User::factory()->create(['role' => 'admin']);
        $seller = $this->createCharacter();
        $buyer = $this->createCharacter();
        $materialId = $this->materialId();

        $grant = AdminItemGrantLog::query()->create([
            'character_id' => $seller->id, 'admin_user_id' => $admin->id, 'grant_type' => 'material',
            'target_type' => 'material', 'target_id' => (string) $materialId, 'target_name' => 'テスト素材', 'quantity' => 10,
        ]);
        DB::table('market_transactions')->insert([
            'listing_id' => 999, 'seller_character_id' => $seller->id, 'buyer_character_id' => $buyer->id,
            'listing_type' => 'material', 'material_id' => $materialId, 'quantity' => 1, 'unit_price' => 500,
            'total_price' => 500, 'sale_fee' => 25, 'seller_received' => 475, 'created_at' => now()->addMinute(),
        ]);

        app(SecurityAnomalyDetectionService::class)->scan();

        $case = SecurityAnomalyCase::query()->where('rule_key', 'admin_grant_trade')->firstOrFail();
        $this->assertSame($seller->id, $case->character_id);
        $this->assertSame($grant->id, $case->evidence['grant_log_id']);
    }

    public function test_closed_case_stays_closed_until_new_evidence_creates_a_new_case(): void
    {
        config()->set('security_anomaly_detection.rules.gold_change.total_threshold', 100);
        config()->set('security_anomaly_detection.rules.gold_change.single_threshold', 100);
        $character = $this->createCharacter();
        $service = app(SecurityAnomalyDetectionService::class);

        GoldTransaction::query()->create(['character_id' => $character->id, 'type' => 'test', 'amount' => 500, 'balance_after' => 500]);
        $service->scan();
        $firstCase = SecurityAnomalyCase::query()->where('rule_key', 'gold_change')->firstOrFail();
        $firstCase->update(['status' => 'cleared', 'resolved_at' => now(), 'resolution_note' => '確認済み']);

        $service->scan();
        $this->assertSame(1, SecurityAnomalyCase::query()->where('rule_key', 'gold_change')->count());
        $this->assertSame('cleared', $firstCase->fresh()->status);

        GoldTransaction::query()->create(['character_id' => $character->id, 'type' => 'test', 'amount' => 600, 'balance_after' => 1_100]);
        $service->scan();

        $this->assertSame(2, SecurityAnomalyCase::query()->where('rule_key', 'gold_change')->count());
        $this->assertDatabaseHas('security_anomaly_cases', ['rule_key' => 'gold_change', 'status' => 'detected', 'character_id' => $character->id]);
    }

    public function test_high_value_purchase_after_admin_gold_grant_is_detected_for_buyer(): void
    {
        config()->set('security_anomaly_detection.rules.admin_grant_trade.price_threshold', 100);
        config()->set('security_anomaly_detection.rules.gold_change.total_threshold', 10_000);
        config()->set('security_anomaly_detection.rules.gold_change.single_threshold', 10_000);
        $seller = $this->createCharacter();
        $buyer = $this->createCharacter();
        $materialId = $this->materialId();

        $grant = GoldTransaction::query()->create([
            'character_id' => $buyer->id, 'type' => 'admin_grant', 'amount' => 1_000,
            'balance_after' => 1_000, 'source_type' => 'admin_grant',
        ]);
        DB::table('market_transactions')->insert([
            'listing_id' => 1000, 'seller_character_id' => $seller->id, 'buyer_character_id' => $buyer->id,
            'listing_type' => 'material', 'material_id' => $materialId, 'quantity' => 1, 'unit_price' => 500,
            'total_price' => 500, 'sale_fee' => 25, 'seller_received' => 475, 'created_at' => now()->addMinute(),
        ]);

        app(SecurityAnomalyDetectionService::class)->scan();

        $case = SecurityAnomalyCase::query()->where('rule_key', 'admin_grant_trade')->firstOrFail();
        $this->assertSame($buyer->id, $case->character_id);
        $this->assertSame($grant->id, $case->evidence['grant_log_id']);
        $this->assertSame('購入', $case->evidence['trade_action']);
    }

    private function createCharacter(): Character
    {
        $user = User::factory()->create(['role' => 'user']);

        return Character::query()->create(['user_id' => $user->id, 'name' => '調査対象'.$user->id]);
    }

    /** @return array{int,int} */
    private function battleMasterIds(): array
    {
        $areaId = (int) (DB::table('areas')->value('id') ?? DB::table('areas')->insertGetId(['name' => 'テスト地域', 'slug' => 'security-test-area', 'created_at' => now(), 'updated_at' => now()]));
        $enemyId = (int) (DB::table('enemies')->where('area_id', $areaId)->value('id') ?? DB::table('enemies')->insertGetId(['area_id' => $areaId, 'name' => 'テスト敵', 'created_at' => now(), 'updated_at' => now()]));

        return [$areaId, $enemyId];
    }

    private function equipmentItemId(): int
    {
        return (int) (DB::table('items')->where('type', 'weapon')->value('id')
            ?? DB::table('items')->insertGetId(['name' => 'テスト剣', 'type' => 'weapon', 'created_at' => now(), 'updated_at' => now()]));
    }

    private function materialId(): int
    {
        return (int) (DB::table('materials')->value('id')
            ?? DB::table('materials')->insertGetId(['material_code' => 'SECURITY_TEST_MAT', 'name' => 'テスト素材', 'category' => 'テスト', 'rarity' => 'N', 'created_at' => now(), 'updated_at' => now()]));
    }
}
