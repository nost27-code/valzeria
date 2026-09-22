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
