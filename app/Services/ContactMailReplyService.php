<?php

namespace App\Services;

use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\AdminMailMessage;
use App\Models\Character;
use App\Models\User;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ContactMailReplyService
{
    public function send(ContactMessage $message, string $body, ?User $adminUser = null): ContactMessageReply
    {
        $fromEmail = (string) config('contact_mail.address', 'info@valzeria.com');
        $fromName = (string) config('contact_mail.from_name', 'ヴァルゼリアの冒険者 運営');
        $toEmail = (string) $message->sender_email;
        $subject = $this->replySubject((string) $message->subject);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('返信先メールアドレスが不正です。');
        }

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($toEmail)
            ->subject($subject)
            ->text($body);

        $this->mailer()->send($email);

        $reply = ContactMessageReply::create([
            'contact_message_id' => $message->id,
            'admin_user_id' => $adminUser?->id,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => now(),
        ]);

        $message->update([
            'status' => 'replied',
            'read_at' => $message->read_at ?? now(),
            'replied_at' => now(),
        ]);

        return $reply;
    }

    public function sendNew(Character $character, string $subject, string $body, ?User $adminUser = null): AdminMailMessage
    {
        $character->loadMissing('user');

        $fromEmail = (string) config('contact_mail.address', 'info@valzeria.com');
        $fromName = (string) config('contact_mail.from_name', 'ヴァルゼリアの冒険者 運営');
        $toEmail = (string) $character->user?->email;
        $subject = mb_substr(trim($subject), 0, 160);

        if ($subject === '') {
            throw new RuntimeException('件名を入力してください。');
        }

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('送信先メールアドレスが不正です。');
        }

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($toEmail)
            ->subject($subject)
            ->text($body);

        $this->mailer()->send($email);

        return AdminMailMessage::create([
            'user_id' => $character->user_id,
            'character_id' => $character->id,
            'admin_user_id' => $adminUser?->id,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => now(),
        ]);
    }

    private function mailer(): Mailer
    {
        $host = (string) config('contact_mail.smtp_host');
        $port = (int) config('contact_mail.smtp_port', 465);
        $username = (string) config('contact_mail.username');
        $password = (string) config('contact_mail.password');
        $encryption = (string) config('contact_mail.smtp_encryption', 'ssl');

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('メール送信設定が未設定です。');
        }

        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
        $transport->setUsername($username);
        $transport->setPassword($password);

        return new Mailer($transport);
    }

    private function replySubject(string $subject): string
    {
        $subject = trim($subject) ?: 'お問い合わせ';

        if (preg_match('/^Re:/i', $subject)) {
            return mb_substr($subject, 0, 160);
        }

        return mb_substr('Re: ' . $subject, 0, 160);
    }
}
