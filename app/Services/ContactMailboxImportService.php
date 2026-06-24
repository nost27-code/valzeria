<?php

namespace App\Services;

use App\Models\ContactMessage;
use RuntimeException;

class ContactMailboxImportService
{
    private $stream = null;

    public function import(): array
    {
        $host = (string) config('contact_mail.host');
        $port = (int) config('contact_mail.port', 995);
        $username = (string) config('contact_mail.username');
        $password = (string) config('contact_mail.password');
        $limit = max(1, min(100, (int) config('contact_mail.limit', 30)));
        $address = (string) config('contact_mail.address', 'info@valzeria.com');
        $scheme = config('contact_mail.encryption', 'ssl') === 'ssl' ? 'ssl://' : '';

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('メール受信設定が未設定です。');
        }

        $this->connect($scheme . $host, $port);

        try {
            $this->command('USER ' . $username);
            $this->command('PASS ' . $password);

            $uidMap = $this->uidList();
            $imported = 0;
            $skipped = 0;
            $checked = 0;

            foreach (array_reverse($uidMap, true) as $messageNumber => $uid) {
                if ($checked >= $limit) {
                    break;
                }
                $checked++;

                if (ContactMessage::where('source', 'pop3')->where('external_uid', $uid)->exists()) {
                    $skipped++;
                    continue;
                }

                $raw = $this->retrieve($messageNumber);
                $parsed = $this->parseMessage($raw);

                ContactMessage::create([
                    'recipient_email' => $address,
                    'source' => 'pop3',
                    'external_uid' => $uid,
                    'received_at' => $parsed['received_at'],
                    'sender_name' => $parsed['sender_name'],
                    'sender_email' => $parsed['sender_email'],
                    'category' => 'other',
                    'subject' => $parsed['subject'],
                    'body' => $parsed['body'],
                    'status' => 'new',
                ]);

                $imported++;
            }

            return [
                'checked' => $checked,
                'imported' => $imported,
                'skipped' => $skipped,
            ];
        } finally {
            $this->quit();
        }
    }

    private function connect(string $host, int $port): void
    {
        $this->stream = @fsockopen($host, $port, $errno, $errstr, 20);

        if (!$this->stream) {
            throw new RuntimeException("メールサーバーに接続できませんでした。({$errno}: {$errstr})");
        }

        stream_set_timeout($this->stream, 20);
        $this->expectOk($this->readLine());
    }

    private function uidList(): array
    {
        $this->writeLine('UIDL');
        $this->expectOk($this->readLine());

        $rows = $this->readMultiline();
        $map = [];

        foreach ($rows as $row) {
            [$number, $uid] = array_pad(explode(' ', trim($row), 2), 2, null);
            if (ctype_digit((string) $number) && $uid) {
                $map[(int) $number] = $uid;
            }
        }

        return $map;
    }

    private function retrieve(int $messageNumber): string
    {
        $this->writeLine('RETR ' . $messageNumber);
        $this->expectOk($this->readLine());

        return implode("\n", $this->readMultiline());
    }

    private function command(string $command): string
    {
        $this->writeLine($command);
        $line = $this->readLine();
        $this->expectOk($line);

        return $line;
    }

    private function quit(): void
    {
        if (!is_resource($this->stream)) {
            return;
        }

        @fwrite($this->stream, "QUIT\r\n");
        @fclose($this->stream);
        $this->stream = null;
    }

    private function writeLine(string $line): void
    {
        fwrite($this->stream, $line . "\r\n");
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new RuntimeException('メールサーバーから応答を受信できませんでした。');
        }

        return rtrim($line, "\r\n");
    }

    private function readMultiline(): array
    {
        $lines = [];

        while (($line = fgets($this->stream)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '.') {
                break;
            }

            $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }

        return $lines;
    }

    private function expectOk(string $line): void
    {
        if (!str_starts_with($line, '+OK')) {
            throw new RuntimeException('メールサーバーエラー: ' . $line);
        }
    }

    private function parseMessage(string $raw): array
    {
        [$headerText, $bodyText] = $this->splitHeaderAndBody($raw);
        $headers = $this->parseHeaders($headerText);
        $from = $this->decodeHeader((string) ($headers['from'] ?? ''));
        [$senderName, $senderEmail] = $this->parseAddress($from);
        $subject = trim($this->decodeHeader((string) ($headers['subject'] ?? '件名なし'))) ?: '件名なし';
        $receivedAt = $this->parseDate((string) ($headers['date'] ?? ''));

        return [
            'sender_name' => $senderName,
            'sender_email' => $senderEmail ?: 'unknown@example.com',
            'subject' => mb_substr($subject, 0, 160),
            'body' => mb_substr($this->decodeBody($bodyText, $headers), 0, 5000),
            'received_at' => $receivedAt,
        ];
    }

    private function splitHeaderAndBody(string $raw): array
    {
        $normalized = str_replace("\r\n", "\n", $raw);
        $parts = explode("\n\n", $normalized, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $headerText) as $line) {
            if (preg_match('/^\s+/', $line) && $current) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $current = strtolower(trim($name));
                $headers[$current] = trim($value);
            }
        }

        return $headers;
    }

    private function decodeHeader(string $value): string
    {
        return preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]+)\?=/', function (array $matches) {
            $charset = strtoupper($matches[1]);
            $encoding = strtoupper($matches[2]);
            $text = $matches[3];
            $decoded = $encoding === 'B'
                ? base64_decode($text, true)
                : quoted_printable_decode(str_replace('_', ' ', $text));

            if ($decoded === false) {
                return $matches[0];
            }

            return mb_convert_encoding($decoded, 'UTF-8', $charset);
        }, $value) ?? $value;
    }

    private function parseAddress(string $from): array
    {
        if (preg_match('/^(.*)<([^>]+)>$/', $from, $matches)) {
            $name = trim($matches[1], " \t\n\r\0\x0B\"'");
            return [$name !== '' ? mb_substr($name, 0, 100) : null, trim($matches[2])];
        }

        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return [null, $from];
        }

        return [mb_substr($from, 0, 100) ?: null, 'unknown@example.com'];
    }

    private function parseDate(string $date): ?\DateTimeInterface
    {
        try {
            return $date !== '' ? new \DateTimeImmutable($date) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeBody(string $body, array $headers): string
    {
        $contentType = strtolower((string) ($headers['content-type'] ?? ''));

        if (str_contains($contentType, 'multipart/')) {
            $boundary = $this->extractBoundary($contentType);
            if ($boundary) {
                $body = $this->extractMultipartBody($body, $boundary);
            }
        }

        $transferEncoding = strtolower((string) ($headers['content-transfer-encoding'] ?? ''));
        if ($transferEncoding === 'base64') {
            $body = (string) base64_decode(preg_replace('/\s+/', '', $body), true);
        } elseif ($transferEncoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace("/[ \t]+\n/", "\n", $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return trim($body) ?: '(本文なし)';
    }

    private function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractMultipartBody(string $body, string $boundary): string
    {
        $parts = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\s*/', $body) ?: [];
        foreach ($parts as $part) {
            [$partHeadersText, $partBody] = $this->splitHeaderAndBody(trim($part));
            $partHeaders = $this->parseHeaders($partHeadersText);
            $contentType = strtolower((string) ($partHeaders['content-type'] ?? ''));

            if (str_contains($contentType, 'text/plain')) {
                return $this->decodeBody($partBody, $partHeaders);
            }
        }

        foreach ($parts as $part) {
            [$partHeadersText, $partBody] = $this->splitHeaderAndBody(trim($part));
            $partHeaders = $this->parseHeaders($partHeadersText);
            $contentType = strtolower((string) ($partHeaders['content-type'] ?? ''));

            if (str_contains($contentType, 'text/html')) {
                return $this->decodeBody($partBody, $partHeaders);
            }
        }

        return $body;
    }
}
