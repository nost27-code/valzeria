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
}
