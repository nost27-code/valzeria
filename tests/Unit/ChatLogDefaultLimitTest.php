<?php

namespace Tests\Unit;

use App\Livewire\ChatLog;
use Tests\TestCase;

class ChatLogDefaultLimitTest extends TestCase
{
    public function test_home_chat_defaults_to_fifty_logs(): void
    {
        $component = new ChatLog();

        $this->assertSame(50, $component->logLimit);
    }
}
