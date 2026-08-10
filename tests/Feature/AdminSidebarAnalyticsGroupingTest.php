<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSidebarAnalyticsGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_infrequently_used_analytics_are_grouped_under_other_in_the_admin_sidebar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('admin.bug-reports'))
            ->assertOk()
            ->getContent();

        $otherPosition = strpos($html, '>その他<');

        $this->assertNotFalse($otherPosition);
        $this->assertSame(2, substr_count($html, '>その他<'));

        foreach (['宿屋売上分析', '冒険者分布マップ', '運営分析', '統計分析'] as $label) {
            $labelPosition = strpos($html, $label);

            $this->assertNotFalse($labelPosition);
            $this->assertGreaterThan($otherPosition, $labelPosition);
            $this->assertSame(2, substr_count($html, $label));
        }
    }
}
