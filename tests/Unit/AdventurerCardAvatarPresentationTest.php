<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdventurerCardAvatarPresentationTest extends TestCase
{
    public function test_avatar_image_uses_more_of_its_frame(): void
    {
        $source = file_get_contents(resource_path('views/livewire/city-header.blade.php'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/\.adventurer-card-avatar img\s*\{[^}]*max-width:\s*82%;[^}]*max-height:\s*82%;[^}]*\}/s',
            $source,
        );
    }

    public function test_profile_summary_places_unframed_affiliation_under_avatar_and_removes_activity_badges(): void
    {
        $source = file_get_contents(resource_path('views/livewire/city-header.blade.php'));

        $this->assertIsString($source);

        $heroStart = strpos($source, '<div class="adventurer-card-hero"');
        $heroEnd = strpos($source, 'data-profile-six-hero-section', $heroStart);

        $this->assertIsInt($heroStart);
        $this->assertIsInt($heroEnd);

        $heroSource = substr($source, $heroStart, $heroEnd - $heroStart);
        $avatarPosition = strpos($heroSource, '<div class="adventurer-card-avatar">');
        $affiliationPosition = strpos($heroSource, 'data-adventurer-card-affiliation');
        $commentPosition = strpos($heroSource, 'data-adventurer-card-comment');

        $this->assertIsInt($avatarPosition);
        $this->assertIsInt($affiliationPosition);
        $this->assertIsInt($commentPosition);
        $this->assertGreaterThan($avatarPosition, $affiliationPosition);
        $this->assertGreaterThan($affiliationPosition, $commentPosition);
        $this->assertStringContainsString('x-text="playerInfo.guild"', $heroSource);
        $this->assertStringNotContainsString('adventurer-card-badges', $heroSource);
        $this->assertStringNotContainsString('>闘技場順位<', $heroSource);
        $this->assertStringNotContainsString('>冒険回数<', $heroSource);
        $this->assertStringNotContainsString('>冒険日数<', $heroSource);

        $this->assertMatchesRegularExpression(
            '/\.adventurer-card-affiliation\s*\{(?![^}]*border:)(?![^}]*background:)(?![^}]*box-shadow:)[^}]*\}/s',
            $source,
        );
    }
}
