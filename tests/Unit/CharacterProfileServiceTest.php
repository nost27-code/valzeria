<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\CharacterProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CharacterProfileServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('character_adventurer_card_assets');
        Schema::dropIfExists('characters');

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('character_adventurer_card_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('asset_type', 40);
            $table->string('asset_path', 120);
            $table->string('source', 40)->default('default');
            $table->timestamp('obtained_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'asset_type', 'asset_path']);
        });
    }

    public function test_adventurer_card_frames_are_available_only_when_owned(): void
    {
        $service = app(CharacterProfileService::class);
        $character = Character::query()->create(['name' => 'Profile Tester']);

        config(['app.env' => 'local']);

        $this->assertNotContains('images/profile/adventurer_card_frame91.webp', array_column($service->ownedAdventurerCardFrames($character), 'path'));
        $this->assertNotContains('images/profile/adventurer_avatar_frame91.webp', array_column($service->ownedAdventurerAvatarFrames($character), 'path'));
        $this->assertSame(
            'images/profile/adventurer_card_frame01.webp',
            $service->selectedAdventurerCardFrame($character, 'images/profile/adventurer_card_frame91.webp')
        );
        $this->assertSame(
            'images/profile/adventurer_avatar_frame01.webp',
            $service->selectedAdventurerAvatarFrame($character, 'images/profile/adventurer_avatar_frame91.webp')
        );

        DB::table('character_adventurer_card_assets')->insert([
            [
                'character_id' => $character->id,
                'asset_type' => 'card_frame',
                'asset_path' => 'images/profile/adventurer_card_frame91.webp',
                'source' => 'adventurer_departure_set',
                'obtained_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'character_id' => $character->id,
                'asset_type' => 'avatar_frame',
                'asset_path' => 'images/profile/adventurer_avatar_frame91.webp',
                'source' => 'adventurer_departure_set',
                'obtained_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $ownedCardFrames = $service->ownedAdventurerCardFrames($character);
        $ownedAvatarFrames = $service->ownedAdventurerAvatarFrames($character);
        $this->assertContains('images/profile/adventurer_card_frame91.webp', array_column($ownedCardFrames, 'path'));
        $this->assertContains('images/profile/adventurer_avatar_frame91.webp', array_column($ownedAvatarFrames, 'path'));
        $this->assertSame('冒険者', collect($ownedCardFrames)->firstWhere('path', 'images/profile/adventurer_card_frame91.webp')['label']);
        $this->assertSame('冒険者', collect($ownedAvatarFrames)->firstWhere('path', 'images/profile/adventurer_avatar_frame91.webp')['label']);
        $this->assertSame(
            'images/profile/adventurer_card_frame91.webp',
            $service->selectedAdventurerCardFrame($character, 'images/profile/adventurer_card_frame91.webp')
        );
        $this->assertSame(
            'images/profile/adventurer_avatar_frame91.webp',
            $service->selectedAdventurerAvatarFrame($character, 'images/profile/adventurer_avatar_frame91.webp')
        );
    }
}
