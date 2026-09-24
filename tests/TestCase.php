<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabaseで再利用されるCharacter IDへ、前のテストの能力値を持ち込まない。
        \App\Services\CharacterStatusService::clearRequestCache();
    }
}
