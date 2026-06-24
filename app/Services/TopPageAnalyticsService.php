<?php

namespace App\Services;

use App\Models\TopPageEvent;
use App\Models\TopPageVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TopPageAnalyticsService
{
    public function recordVisit(Request $request): TopPageVisit
    {
        $visitUuid = (string) Str::uuid();
        $referer = $this->trimString($request->headers->get('referer'), 1000);
        $landingUrl = $this->trimString($request->fullUrl(), 1000);

        return TopPageVisit::create([
            'visit_uuid' => $visitUuid,
            'user_id' => $request->user()?->id,
            'visited_at' => now(),
            'referer' => $referer,
            'referer_host' => $this->hostFromUrl($referer),
            'landing_url' => $landingUrl,
            'utm_source' => $this->trimString($request->query('utm_source'), 120),
            'utm_medium' => $this->trimString($request->query('utm_medium'), 120),
            'utm_campaign' => $this->trimString($request->query('utm_campaign'), 180),
            'user_agent' => $this->trimString($request->userAgent(), 500),
            'device_type' => $this->deviceType((string) $request->userAgent()),
            'ip_hash' => $this->ipHash($request),
        ]);
    }

    public function recordEvent(Request $request, string $visitUuid, string $eventName, array $metadata = []): ?TopPageEvent
    {
        $eventName = $this->normalizeEventName($eventName);
        if ($eventName === '') {
            return null;
        }

        $visit = TopPageVisit::where('visit_uuid', $visitUuid)->first();

        if ($eventName === 'page_dwell') {
            $duration = max(0, min(86400, (int) ($metadata['duration_seconds'] ?? 0)));
            if ($visit && $duration > 0) {
                $visit->update([
                    'left_at' => now(),
                    'duration_seconds' => max((int) ($visit->duration_seconds ?? 0), $duration),
                ]);
            }
        }

        return TopPageEvent::create([
            'visit_uuid' => $visitUuid ?: null,
            'top_page_visit_id' => $visit?->id,
            'user_id' => $request->user()?->id,
            'event_name' => $eventName,
            'metadata' => $this->safeMetadata($metadata),
            'occurred_at' => now(),
        ]);
    }

    private function normalizeEventName(string $eventName): string
    {
        $eventName = Str::of($eventName)->lower()->replaceMatches('/[^a-z0-9_\-]/', '_')->trim('_')->toString();

        return mb_substr($eventName, 0, 80);
    }

    private function safeMetadata(array $metadata): array
    {
        return collect($metadata)
            ->only(['label', 'href', 'duration_seconds'])
            ->map(fn ($value) => is_scalar($value) ? $this->trimString((string) $value, 500) : null)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    private function trimString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function hostFromUrl(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $host ? mb_substr((string) $host, 0, 255) : null;
    }

    private function deviceType(string $userAgent): string
    {
        $ua = mb_strtolower($userAgent);

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        if ($ua !== '') {
            return 'desktop';
        }

        return 'unknown';
    }

    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();
        if (!$ip) {
            return null;
        }

        return hash('sha256', $ip . '|' . config('app.key'));
    }
}
