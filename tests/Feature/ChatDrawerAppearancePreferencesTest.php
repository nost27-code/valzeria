<?php

namespace Tests\Feature;

use App\Livewire\ChatLog;
use App\Models\Character;
use App\Models\User;
use App\Support\ChatDrawerAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatDrawerAppearancePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawer_appearance_is_saved_per_character_and_can_be_reset(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id]);

        $component = Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->assertSet('drawerAppearance', ChatDrawerAppearance::defaults())
            ->call('setDrawerAppearanceColor', 'own_bubble_color', '#123456')
            ->call('setDrawerAppearanceColor', 'other_bubble_color', '#abcdef')
            ->call('setDrawerAppearanceColor', 'background_color', '#101820')
            ->call('setDrawerAppearanceFontSize', 'large')
            ->assertSet('drawerAppearance.own_bubble_color', '#123456')
            ->assertSet('drawerAppearance.other_bubble_color', '#abcdef')
            ->assertSet('drawerAppearance.background_color', '#101820')
            ->assertSet('drawerAppearance.font_size', 'large');

        $this->assertSame([
            'own_bubble_color' => '#123456',
            'other_bubble_color' => '#abcdef',
            'background_color' => '#101820',
            'font_size' => 'large',
        ], $character->refresh()->chat_drawer_preferences);

        $component
            ->call('resetDrawerAppearance')
            ->assertSet('drawerAppearance', ChatDrawerAppearance::defaults());

        $this->assertSame(ChatDrawerAppearance::defaults(), $character->refresh()->chat_drawer_preferences);
    }

    public function test_invalid_values_and_inline_chat_cannot_change_drawer_appearance(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id]);

        Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->call('setDrawerAppearanceColor', 'background_color', 'javascript:alert(1)')
            ->call('setDrawerAppearanceColor', 'unknown', '#123456')
            ->call('setDrawerAppearanceFontSize', 'giant')
            ->assertSet('drawerAppearance', ChatDrawerAppearance::defaults());

        Livewire::actingAs($user)
            ->test(ChatLog::class)
            ->call('setDrawerAppearanceColor', 'background_color', '#123456')
            ->call('setDrawerAppearanceFontSize', 'large')
            ->assertSet('drawerAppearance', ChatDrawerAppearance::defaults());

        $this->assertNull($character->refresh()->chat_drawer_preferences);
    }

    public function test_preset_updates_colors_preserves_font_size_and_rejects_unknown_keys(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id]);

        $component = Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->call('setDrawerAppearanceFontSize', 'large')
            ->call('applyDrawerAppearancePreset', 'dark')
            ->assertSet('drawerAppearance', [
                ...ChatDrawerAppearance::presetColors('dark'),
                'font_size' => 'large',
            ]);

        $this->assertSame([
            ...ChatDrawerAppearance::presetColors('dark'),
            'font_size' => 'large',
        ], $character->refresh()->chat_drawer_preferences);

        $component
            ->call('applyDrawerAppearancePreset', 'unknown')
            ->assertSet('drawerAppearance', [
                ...ChatDrawerAppearance::presetColors('dark'),
                'font_size' => 'large',
            ]);
    }

    public function test_drawer_tab_is_rendered_from_session_after_refresh_without_changing_inline_chat(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id]);

        Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->call('setTab', 'chat')
            ->assertSet('activeTab', 'chat');

        $this->assertSame('chat', session('chat_drawer_active_tab'));

        Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->assertSet('activeTab', 'chat')
            ->assertSet('drawerTabRestoredFromSession', true);

        Livewire::actingAs($user)
            ->test(ChatLog::class)
            ->assertSet('activeTab', 'all');
    }

    public function test_invalid_saved_drawer_tab_falls_back_to_all(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id, 'chat_drawer_active_tab' => 'invalid']);

        Livewire::actingAs($user)
            ->test(ChatLog::class, ['drawer' => true])
            ->assertSet('activeTab', 'all')
            ->assertSet('drawerTabRestoredFromSession', false);
    }

    public function test_all_appearance_controls_share_one_save_lock_and_busy_indicator(): void
    {
        [$user, $character] = $this->player();
        session(['current_character_id' => $character->id]);

        $html = Livewire::actingAs($user)->test(ChatLog::class, ['drawer' => true])->html();
        $document = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new \DOMXPath($document);

        $controls = $xpath->query('//*[@data-chat-appearance-controls]')->item(0);
        $status = $xpath->query('//*[@data-chat-appearance-saving]')->item(0);
        $this->assertNotNull($controls);
        $this->assertNotNull($status);
        $this->assertSame('disabled', $controls->getAttribute('wire:loading.attr'));
        $this->assertSame('status', $status->getAttribute('role'));

        $targets = 'setDrawerAppearanceColor,setDrawerAppearanceFontSize,applyDrawerAppearancePreset,resetDrawerAppearance';
        $this->assertSame($targets, $controls->getAttribute('wire:target'));
        $this->assertSame($targets, $status->getAttribute('wire:target'));
        $this->assertSame(3, $xpath->query('.//input[@type="color"]', $controls)->length);
        foreach (['setDrawerAppearanceFontSize', 'applyDrawerAppearancePreset', 'resetDrawerAppearance'] as $method) {
            $this->assertGreaterThan(0, $xpath->query('.//button[contains(@*[name()="wire:click"], "'.$method.'")]', $controls)->length);
        }
    }

    private function player(): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => 'チャット設定確認者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);

        return [$user, $character];
    }
}
