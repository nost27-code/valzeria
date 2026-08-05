<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSidebarNewItemBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sidebar_displays_new_mail_and_bug_report_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ContactMessage::query()->create([
            'sender_email' => 'new@example.com',
            'subject' => '新着メール',
            'body' => '本文',
            'status' => 'new',
        ]);
        ContactMessage::query()->create([
            'sender_email' => 'read@example.com',
            'subject' => '既読メール',
            'body' => '本文',
            'status' => 'read',
            'read_at' => now(),
        ]);
        BugReport::query()->create([
            'body' => '新着の不具合報告1',
            'status' => 'new',
        ]);
        BugReport::query()->create([
            'body' => '新着の不具合報告2',
            'status' => 'new',
        ]);
        BugReport::query()->create([
            'body' => '確認済みの不具合報告',
            'status' => 'read',
            'read_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.bug-reports'));

        $response->assertOk();
        $response->assertSee('data-admin-mail-badge', false);
        $response->assertSee('data-admin-bug-report-badge', false);
        $response->assertSee('>1</span>', false);
        $response->assertSee('>2</span>', false);
        $this->assertSame(2, substr_count($response->getContent(), '<span data-admin-mail-badge'));
        $this->assertSame(2, substr_count($response->getContent(), '<span data-admin-bug-report-badge'));
    }

    public function test_badge_count_endpoint_returns_both_new_counts_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ContactMessage::query()->create([
            'sender_email' => 'new@example.com',
            'subject' => '新着メール',
            'body' => '本文',
            'status' => 'new',
        ]);
        BugReport::query()->create([
            'body' => '新着の不具合報告',
            'status' => 'new',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.contact-messages.badge-count'))
            ->assertOk()
            ->assertJsonPath('new_count', 1)
            ->assertJsonPath('bug_report_new_count', 1);
    }
}
