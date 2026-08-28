<?php

declare(strict_types=1);

namespace Valzeria\Deploy;

use RuntimeException;

final class LegacyDeployEndpointGuard
{
    public const REQUIRED_CONTRACT_VERSION = 2;

    private const CONTRACT_HEADER = 'X-Valzeria-Deploy-Contract';

    /**
     * @param  null|callable(string): array<array-key, mixed>  $headersProvider
     */
    public static function assertMigrationRequestIsSafe(
        string $endpoint,
        string $migrationMode,
        ?callable $headersProvider = null,
    ): void {
        if ($migrationMode === 'none') {
            return;
        }

        $headers = $headersProvider !== null
            ? $headersProvider($endpoint)
            : self::fetchHeaders($endpoint);
        $contractVersion = self::contractVersion($headers);

        if ($contractVersion !== null && $contractVersion >= self::REQUIRED_CONTRACT_VERSION) {
            return;
        }

        throw new RuntimeException(
            '接続先APIでmigration前の安全確認を保証できないため、migration付きデプロイを中止しました。'
            .' GitHub Actionsのremote-release.shを使用してください。'
            .' 旧ZIP経路を先に更新する場合は、DEPLOY_MIGRATION_MODE=noneでAPIを更新してから再実行してください。',
        );
    }

    /** @return array<array-key, mixed> */
    private static function fetchHeaders(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null)) {
            return [];
        }

        $probeEndpoint = $endpoint
            .(str_contains($endpoint, '?') ? '&' : '?')
            .'contract_probe='.bin2hex(random_bytes(8));
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $headers = @get_headers($probeEndpoint, true, $context);

        return is_array($headers) ? $headers : [];
    }

    /** @param array<array-key, mixed> $headers */
    private static function contractVersion(array $headers): ?int
    {
        $versions = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, self::CONTRACT_HEADER) === 0) {
                foreach (is_array($value) ? $value : [$value] as $candidate) {
                    self::collectVersion($versions, $candidate);
                }

                continue;
            }

            if (is_int($name) && is_string($value)) {
                $pattern = '/^'.preg_quote(self::CONTRACT_HEADER, '/').':\s*(.+)$/iD';
                if (preg_match($pattern, trim($value), $matches) === 1) {
                    self::collectVersion($versions, $matches[1]);
                }
            }
        }

        return $versions === [] ? null : max($versions);
    }

    /** @param list<int> $versions */
    private static function collectVersion(array &$versions, mixed $candidate): void
    {
        $value = trim((string) $candidate);
        if (preg_match('/^\d+$/D', $value) === 1) {
            $versions[] = (int) $value;
        }
    }
}
