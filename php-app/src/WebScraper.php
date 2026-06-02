<?php

declare(strict_types=1);

namespace App;

final class WebScraper
{
    public static function extractTextFromUrl(string $url, ?Config $config = null): string
    {
        try {
            $text = self::extractTextDirect($url);
            if (self::looksBlocked($text)) {
                throw new \RuntimeException('URL returned a readable block/access-denied page instead of listing content.');
            }
        } catch (\Throwable $primaryError) {
            if (!$config || !$config->remoteFetchUrlTemplate) {
                throw $primaryError;
            }
            $text = self::extractTextViaRemoteFetcher($url, $config, $primaryError);
        }

        if (self::len($text) < 80) {
            throw new \RuntimeException(
                'URL page did not return enough readable text. This site may require JavaScript/browser rendering, login, or may block shared-hosting cURL requests.'
            );
        }

        return $text;
    }

    private static function extractTextDirect(string $url): string
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'php_scraper_cookie_') ?: null;
        try {
            $html = (string)Http::request('GET', $url, [
                'headers' => self::browserHeaders($url),
                'referer' => self::origin($url),
                'cookieFile' => $cookieFile,
                'timeout' => 45,
                'expectHtml' => true,
            ]);
        } finally {
            if ($cookieFile && is_file($cookieFile)) {
                @unlink($cookieFile);
            }
        }
        $html = self::toUtf8($html);
        return self::htmlToListingText($html);
    }

    private static function extractTextViaRemoteFetcher(string $url, Config $config, \Throwable $primaryError): string
    {
        $remoteUrl = self::remoteUrl($url, $config);
        $headers = [];
        if ($config->remoteFetchApiKey && $config->remoteFetchApiKeyHeader) {
            $headers[] = $config->remoteFetchApiKeyHeader . ': ' . $config->remoteFetchApiKey;
        }

        $body = (string)Http::request('GET', $remoteUrl, [
            'headers' => $headers,
            'timeout' => 120,
        ]);
        $text = self::remoteBodyToText($body, $config);
        if (self::len($text) < 80 || self::looksBlocked($text)) {
            throw new \RuntimeException(
                'Direct PHP cURL failed and REMOTE_FETCH_URL_TEMPLATE did not return enough readable listing text. '
                . 'Direct error: ' . $primaryError->getMessage()
            );
        }

        return $text;
    }

    private static function remoteUrl(string $url, Config $config): string
    {
        $template = (string)$config->remoteFetchUrlTemplate;
        return strtr($template, [
            '{url}' => rawurlencode($url),
            '{url_raw}' => $url,
            '{api_key}' => rawurlencode((string)$config->remoteFetchApiKey),
        ]);
    }

    private static function remoteBodyToText(string $body, Config $config): string
    {
        $trimmed = trim($body);
        if ($config->remoteFetchJsonField || str_starts_with($trimmed, '{')) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                $value = self::valueAtPath($data, (string)($config->remoteFetchJsonField ?: ''));
                if (is_string($value) && $value !== '') {
                    $body = $value;
                }
            }
        }

        $body = self::toUtf8($body);
        if (preg_match('/<html|<body|<table|<div|<script/iu', $body)) {
            return self::htmlToListingText($body);
        }

        return self::plainTextToListingText($body);
    }

    private static function valueAtPath(array $data, string $path): mixed
    {
        if ($path === '') {
            foreach (['html', 'body', 'content', 'text', 'markdown', 'data'] as $key) {
                if (isset($data[$key])) {
                    return $data[$key];
                }
            }
            return null;
        }

        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function browserHeaders(string $url): array
    {
        $origin = self::origin($url);
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: ja-JP,ja;q=0.9,en-US;q=0.7,en;q=0.6',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/',
            'Upgrade-Insecure-Requests: 1',
        ];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        return $host ? $scheme . '://' . $host : $url;
    }

    private static function toUtf8(string $html): string
    {
        if (preg_match('/<meta[^>]+charset=["\']?([^"\'\s>]+)/iu', $html, $m)) {
            $charset = strtoupper(trim($m[1]));
            if ($charset && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($html, 'UTF-8', $charset);
            }
        }

        if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && !mb_check_encoding($html, 'UTF-8')) {
            return mb_convert_encoding($html, 'UTF-8', 'SJIS-win,EUC-JP,JIS,UTF-8');
        }

        return $html;
    }

    private static function htmlToListingText(string $html): string
    {
        $chunks = [];
        $chunks[] = self::titleAndMeta($html);
        $chunks[] = self::jsonLdText($html);
        $chunks[] = self::tableText($html);

        $body = preg_replace('#<(script|style|noscript|svg|canvas)\b[^>]*>.*?</\1>#isu', ' ', $html) ?: $html;
        $body = preg_replace('#<(br|/p|/div|/li|/tr|/th|/td)\b[^>]*>#iu', "\n", $body) ?: $body;
        $chunks[] = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = implode("\n", array_filter($chunks));
        $lines = preg_split('/\R/u', $text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t　]+/u', ' ', $line) ?: '');
            if ($line === '' || self::len($line) < 2) {
                continue;
            }
            if (preg_match('/^(cookie|javascript|css|menu|ログイン|会員登録)$/iu', $line)) {
                continue;
            }
            $clean[$line] = true;
        }

        return self::slice(implode("\n", array_keys($clean)), 0, 60000);
    }

    private static function looksBlocked(string $text): bool
    {
        return preg_match('/(アクセスができません|Attention Required|Cloudflare|Access Denied|Forbidden|お使いの環境をご確認)/iu', $text) === 1;
    }

    private static function plainTextToListingText(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t　]+/u', ' ', strip_tags($line)) ?: '');
            if ($line === '' || self::len($line) < 2) {
                continue;
            }
            $clean[$line] = true;
        }
        return self::slice(implode("\n", array_keys($clean)), 0, 60000);
    }


    private static function titleAndMeta(string $html): string
    {
        $out = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
            $out[] = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match_all('/<meta[^>]+(?:name|property)=["\'](?:description|og:title|og:description)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/isu', $html, $m)) {
            foreach ($m[1] as $value) {
                $out[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return implode("\n", $out);
    }

    private static function jsonLdText(string $html): string
    {
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#isu', $html, $matches)) {
            return '';
        }

        $out = [];
        foreach ($matches[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (is_array($data)) {
                self::flattenJson($data, $out);
            }
        }
        return implode("\n", array_unique(array_filter($out)));
    }

    private static function flattenJson(mixed $value, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($v) || is_numeric($v)) {
                    $out[] = (is_string($k) ? $k . ': ' : '') . (string)$v;
                } else {
                    self::flattenJson($v, $out);
                }
            }
        }
    }

    private static function tableText(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        if (!$loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//tr') ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query('.//th|.//td', $tr) ?: [] as $cell) {
                $value = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?: '');
                if ($value !== '') {
                    $cells[] = $value;
                }
            }
            if ($cells) {
                $rows[] = implode(': ', $cells);
            }
        }

        foreach ($xpath->query('//*[contains(@class, "price") or contains(@class, "address") or contains(@class, "spec") or contains(@class, "data")]') ?: [] as $node) {
            $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?: '');
            if ($value !== '') {
                $rows[] = $value;
            }
        }

        return implode("\n", array_unique($rows));
    }

    private static function len(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function slice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }
}
