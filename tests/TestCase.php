<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $databaseCooldownFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseCooldownFile = sys_get_temp_dir().'/valzeria-db-cooldown-'.bin2hex(random_bytes(12));
        $this->app->instance(\App\Support\DatabaseConnectionCooldown::class,
            new \App\Support\DatabaseConnectionCooldown($this->databaseCooldownFile));

        // RefreshDatabaseで再利用されるCharacter IDへ、前のテストの能力値を持ち込まない。
        \App\Services\CharacterStatusService::clearRequestCache();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->databaseCooldownFile)) {
                @unlink($this->databaseCooldownFile);
            }
        }
    }
}
