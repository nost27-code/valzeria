<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLogServiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_drop_logs_remain_hidden_for_admin_and_tester_characters(): void
    {
        $admin = $this->adminCharacter();
        $tester = Character::query()->create([
            'user_id' => User::factory()->create(['email' => 'tester_droplog@valzeria.local'])->id,
            'name' => '装備ログ検証者',
        ]);
        $drops = [
            ['item_name' => '逸品剣', 'rank' => 'A', 'affix_quality' => 'excellent'],
            ['item_name' => '希少鎧', 'rank' => 'SSS'],
            ['item_name' => '希少飾り', 'rank' => 'EPIC'],
        ];

        foreach ([$admin, $tester] as $character) {
            app(PublicLogService::class)->addEquipmentDropLogs($character, $drops);
        }

        $this->assertDatabaseMissing('public_logs', ['type' => 'drop']);
    }

    public function test_region_depth_record_is_published_for_an_admin_character_only_as_an_explicit_exception(): void
    {
        $character = $this->adminCharacter();
        $service = app(PublicLogService::class);

        $service->addLog('region_depth_dungeon', '【黒炉深坑・終炉到達】記録テストさんが危険度770%へ到達しました！', $character, 2);
        $service->addLog('area', '【解放】記録テストさんが新たな領域を発見しました！', $character, 2);

        $this->assertDatabaseHas('public_logs', [
            'type' => 'region_depth_dungeon',
            'character_id' => $character->id,
        ]);
        $this->assertDatabaseMissing('public_logs', [
            'type' => 'area',
            'character_id' => $character->id,
        ]);
    }

    public function test_recent_logs_can_fetch_the_latest_fifty_rare_drops_without_other_types_consuming_the_limit(): void
    {
        foreach (range(1, 60) as $number) {
            PublicLog::query()->create([
                'type' => 'drop',
                'message' => "レアドロップ{$number}",
                'importance' => 1,
                'created_at' => now()->addSeconds($number),
                'updated_at' => now()->addSeconds($number),
            ]);
            PublicLog::query()->create([
                'type' => 'chat',
                'message' => "チャット{$number}",
                'importance' => 1,
                'created_at' => now()->addSeconds($number),
                'updated_at' => now()->addSeconds($number),
            ]);
        }

        $logs = app(PublicLogService::class)->getRecentLogs(50, null, ['drop']);

        $this->assertCount(50, $logs);
        $this->assertTrue($logs->every(fn (PublicLog $log): bool => $log->type === 'drop'));
        $this->assertSame('レアドロップ60', $logs->first()->message);
        $this->assertSame('レアドロップ11', $logs->last()->message);
    }

    public function test_recent_logs_apply_all_tab_filters_before_the_fifty_log_limit(): void
    {
        foreach (range(1, 60) as $number) {
            PublicLog::query()->create([
                'type' => 'chat',
                'message' => "表示チャット{$number}",
                'importance' => 1,
                'created_at' => now()->addSeconds($number * 2),
                'updated_at' => now()->addSeconds($number * 2),
            ]);
            PublicLog::query()->create([
                'type' => 'newcomer',
                'message' => "新しい冒険者テスト{$number}がヴァルゼリアの地に降り立ちました。",
                'importance' => 1,
                'created_at' => now()->addSeconds(($number * 2) + 1),
                'updated_at' => now()->addSeconds(($number * 2) + 1),
            ]);
        }

        $logs = app(PublicLogService::class)->getRecentLogs(50, null, null, [], false);

        $this->assertCount(50, $logs);
        $this->assertTrue($logs->every(fn (PublicLog $log): bool => $log->type === 'chat'));
        $this->assertSame('表示チャット60', $logs->first()->message);
        $this->assertSame('表示チャット11', $logs->last()->message);
    }

    public function test_newcomer_exception_does_not_restore_private_logs_to_the_all_tab_query(): void
    {
        $sender = $this->regularCharacter('送信者');
        $receiver = $this->regularCharacter('受信者');
        PublicLog::query()->create([
            'type' => 'private',
            'character_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'message' => '新しい冒険者を装った文がヴァルゼリアの地に降り立ちました。',
            'importance' => 1,
        ]);

        $logs = app(PublicLogService::class)->getRecentLogs(
            50,
            $sender->id,
            null,
            ['private', 'admin_private', 'admin_private_reply', 'admin_reply_resolved'],
            true,
        );

        $this->assertTrue($logs->every(fn (PublicLog $log): bool => $log->type !== 'private'));
    }

    public function test_recent_logs_version_changes_when_a_visible_message_is_created_edited_or_deleted(): void
    {
        $service = app(PublicLogService::class);
        $emptyVersion = $service->getRecentLogsVersion(50);

        $log = PublicLog::query()->create([
            'type' => 'chat',
            'message' => '更新前',
            'importance' => 1,
        ]);
        $createdVersion = $service->getRecentLogsVersion(50);

        $log->forceFill(['message' => '更新後'])->save();
        $editedVersion = $service->getRecentLogsVersion(50);

        $log->delete();
        $deletedVersion = $service->getRecentLogsVersion(50);

        $this->assertNotSame($emptyVersion, $createdVersion);
        $this->assertNotSame($createdVersion, $editedVersion);
        $this->assertSame($emptyVersion, $deletedVersion);
    }

    public function test_recent_logs_version_ignores_private_messages_for_unrelated_characters(): void
    {
        $viewer = $this->regularCharacter('閲覧者');
        $sender = $this->regularCharacter('送信者');
        $receiver = $this->regularCharacter('受信者');
        $service = app(PublicLogService::class);
        $before = $service->getRecentLogsVersion(50, $viewer->id);

        PublicLog::query()->create([
            'type' => 'private',
            'character_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'message' => '閲覧対象外の手紙',
            'importance' => 1,
        ]);

        $this->assertSame($before, $service->getRecentLogsVersion(50, $viewer->id));
        $this->assertNotSame($before, $service->getRecentLogsVersion(50, $receiver->id));
    }

    private function adminCharacter(): Character
    {
        $user = User::factory()->create(['role' => 'admin']);

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '記録テスト',
            'hp_base' => 100,
            'mp_base' => 10,
            'attack_base' => 10,
            'defense_base' => 10,
            'speed_base' => 10,
            'magic_base' => 10,
            'spirit_base' => 10,
            'luck_base' => 10,
            'current_hp' => 100,
            'current_mp' => 10,
        ]);
    }

    private function regularCharacter(string $name): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'hp_base' => 100,
            'mp_base' => 10,
            'attack_base' => 10,
            'defense_base' => 10,
            'speed_base' => 10,
            'magic_base' => 10,
            'spirit_base' => 10,
            'luck_base' => 10,
            'current_hp' => 100,
            'current_mp' => 10,
        ]);
    }
}
