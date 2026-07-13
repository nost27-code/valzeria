<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class CharacterPublicLogVisibilityTest extends TestCase
{
    public function test_admin_character_is_excluded_from_public_logs(): void
    {
        $character = new Character();
        $character->setRelation('user', new User(['role' => 'admin']));

        $this->assertTrue($character->isExcludedFromPublicLogs());
    }

    public function test_regular_character_is_not_excluded_from_public_logs(): void
    {
        $character = new Character();
        $character->setRelation('user', new User(['role' => 'user']));

        $this->assertFalse($character->isExcludedFromPublicLogs());
    }

    public function test_admin_tester_character_is_excluded_from_public_logs(): void
    {
        $character = new Character();
        $character->setRelation('user', new User(['email' => 'tester_1@valzeria.local', 'role' => 'user']));

        $this->assertTrue($character->isExcludedFromPublicLogs());
    }
}
