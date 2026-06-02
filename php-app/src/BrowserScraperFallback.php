<?php

declare(strict_types=1);

namespace App;

final class BrowserScraperFallback
{
    public static function isConfigured(Config $config): bool
    {
        return (bool)$config->browserScraperUrl;
    }

    public static function isReachable(Config $config): bool
    {
        return (self::health($config)['status'] ?? '') === 'ok';
    }

    public static function extract(Config $config, string $url): array
    {
        if (!$config->browserScraperUrl) {
            throw new \RuntimeException('Browser scraper fallback is not configured.');
        }

        if (!self::isReachable($config)) {
            throw new \RuntimeException(
                'Browser scraper fallback is configured but not reachable. Start the Python scraper server or clear BROWSER_SCRAPER_URL.'
            );
        }

        $headers = ['Content-Type: application/json'];
        if ($config->browserScraperApiKey) {
            $headers[] = 'X-API-Key: ' . $config->browserScraperApiKey;
        }

        $body = Http::request('POST', rtrim($config->browserScraperUrl, '/') . '/extract', [
            'headers' => $headers,
            'json' => ['url' => $url],
            'timeout' => 120,
        ]);

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Browser scraper returned invalid JSON.');
        }

        if (($data['status'] ?? 'ok') !== 'ok') {
            throw new \RuntimeException((string)($data['error'] ?? 'Browser scraper failed.'));
        }

        $listing = $data['listing'] ?? $data;
        if (!is_array($listing) || empty($listing['schemaVersion'])) {
            throw new \RuntimeException('Browser scraper response did not contain a listing envelope.');
        }

        return $listing;
    }

    public static function health(Config $config): array
    {
        if (!$config->browserScraperUrl) {
            return ['configured' => false, 'status' => 'not-configured'];
        }

        try {
            $body = Http::request('GET', rtrim($config->browserScraperUrl, '/') . '/health', [
                'timeout' => 10,
            ]);
            $data = json_decode((string)$body, true);
            return [
                'configured' => true,
                'status' => $data['status'] ?? 'unknown',
                'url' => $config->browserScraperUrl,
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'status' => 'unreachable',
                'url' => $config->browserScraperUrl,
                'error' => $e->getMessage(),
            ];
        }
    }
}
