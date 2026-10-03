<?php

namespace Tests\Unit;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

final class ContactSmtpConfigTest extends TestCase
{
    public function test_contact_smtp_uses_a_supported_secure_scheme(): void
    {
        $this->assertSame('smtps', config('mail.mailers.contact_smtp.scheme'));
        $this->assertInstanceOf(
            SmtpTransport::class,
            app(MailManager::class)->mailer('contact_smtp')->getSymfonyTransport(),
        );
    }
}
