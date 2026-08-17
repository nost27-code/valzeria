<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContactMailboxImportCommandTest extends TestCase
{
    public function test_command_skips_cleanly_when_mailbox_is_not_configured(): void
    {
        config()->set('contact_mail.host', null);
        config()->set('contact_mail.username', null);
        config()->set('contact_mail.password', null);

        $this->artisan('contact-mail:import')
            ->expectsOutput('skipped=mailbox_not_configured')
            ->assertSuccessful();
    }
}
