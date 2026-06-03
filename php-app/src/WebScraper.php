<?php

declare(strict_types=1);

namespace App;

final class WebScraper
{
    private const IMAGE_EXTENSION = '(?:jpe?g|png|webp|gif)';
    private const IMAGE_URL_SUFFIX = self::IMAGE_EXTENSION . '(?:[?#][^"\'>\s)]*)?';

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

    public static function extractImageUrlsFromUrl(string $url, ?Config $config = null, int $limit = 0): array
    {
        try {
            $html = self::fetchHtmlDirect($url);
        } catch (\Throwable $primaryError) {
            if (!$config || !$config->remoteFetchUrlTemplate) {
                return [];
            }

            try {
                $html = self::extractHtmlViaRemoteFetcher($url, $config);
            } catch (\Throwable) {
                return [];
            }
        }

        return self::imageUrlsFromHtml(self::toUtf8($html), $url, $limit);
    }

    private static function extractTextDirect(string $url): string
    {
        return self::htmlToListingText(self::toUtf8(self::fetchHtmlDirect($url)));
    }

    private static function fetchHtmlDirect(string $url): string
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'php_scraper_cookie_') ?: null;
        try {
            return (string)Http::request('GET', $url, [
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

    private static function extractHtmlViaRemoteFetcher(string $url, Config $config): string
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
        $trimmed = trim($body);
        if ($config->remoteFetchJsonField || str_starts_with($trimmed, '{')) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                $value = self::valueAtPath($data, (string)($config->remoteFetchJsonField ?: ''));
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $body;
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

    private static function imageUrlsFromHtml(string $html, string $pageUrl, int $limit): array
    {
        $candidates = [];
        foreach (self::orderedGalleryImageUrls($html, $pageUrl) as $url) {
            $candidates[$url] = true;
        }
        if ($candidates && self::prefersOrderedGalleryOnly($pageUrl)) {
            $urls = array_keys($candidates);
            if (str_contains(parse_url($pageUrl, PHP_URL_HOST) ?: '', 'homes.co.jp')) {
                $countHint = self::homesImageCountHint($html);
                if ($countHint > 0) {
                    $urls = array_slice($urls, 0, $countHint);
                }
            }
            return $limit > 0 ? array_slice($urls, 0, $limit) : $urls;
        }

        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            foreach ($dom->getElementsByTagName('img') as $img) {
                foreach (['src', 'data-src', 'data-original', 'data-lazy', 'data-srcset', 'srcset'] as $attr) {
                    if ($img->hasAttribute($attr)) {
                        self::addImageCandidate($candidates, $img->getAttribute($attr), $pageUrl);
                    }
                }
            }

            foreach ($dom->getElementsByTagName('source') as $source) {
                foreach (['srcset', 'data-srcset'] as $attr) {
                    if ($source->hasAttribute($attr)) {
                        self::addImageCandidate($candidates, $source->getAttribute($attr), $pageUrl);
                    }
                }
            }
        }

        if (preg_match_all('/(?:https?:)?\/\/[^"\'<>\s)]+?\.' . self::IMAGE_URL_SUFFIX . '/iu', $html, $matches)) {
            foreach ($matches[0] as $candidate) {
                self::addImageCandidate($candidates, $candidate, $pageUrl);
            }
        }

        $urls = array_keys($candidates);
        return $limit > 0 ? array_slice($urls, 0, $limit) : $urls;
    }

    private static function orderedGalleryImageUrls(string $html, string $pageUrl): array
    {
        $gallery = [];

        if (preg_match_all('/<[^>]+class=["\'][^"\']*(?:js-lightboxItem|carousel_item-object)[^"\']*["\'][^>]*>/isu', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (!preg_match('/\bdata-src=["\']([^"\']+)["\']/isu', $tag, $src)) {
                    continue;
                }

                $id = 100000 + count($gallery);
                if (preg_match('/\bdata-id=["\']?([0-9]+)/isu', $tag, $idMatch)) {
                    $id = (int)$idMatch[1];
                }

                $normalized = self::normalizedImageUrl($src[1], $pageUrl);
                if ($normalized) {
                    $gallery[$id . '-' . count($gallery)] = $normalized;
                }
            }
        }

        if (preg_match_all('#<photo-slider-photo\b[^>]*\bdata-index=["\']?([0-9]+)[^>]*>.*?<img\b[^>]*\bsrc=["\']([^"\']+)["\']#isu', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $normalized = self::normalizedImageUrl($match[2], $pageUrl);
                if ($normalized) {
                    $gallery[(int)$match[1] . '-' . count($gallery)] = $normalized;
                }
            }
        }

        if (preg_match_all('#<li\b[^>]*class=["\'][^"\']*prg-galleryItem[^"\']*["\'][^>]*\bdata-index=["\']?([0-9]+)[^>]*>.*?<img\b[^>]*\bsrc=["\']([^"\']+)["\']#isu', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $normalized = self::normalizedImageUrl($match[2], $pageUrl);
                if ($normalized) {
                    $gallery[(int)$match[1] . '-' . count($gallery)] = $normalized;
                }
            }
        }

        if (preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']*/image_files/path/[^"\']*width=572[^"\']*height=418[^"\']*)["\']#isu', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $normalized = self::normalizedImageUrl($src, $pageUrl);
                if ($normalized) {
                    $gallery[100000 + count($gallery)] = $normalized;
                }
            }
        }

        if (!$gallery && str_contains(parse_url($pageUrl, PHP_URL_HOST) ?: '', 'homes.co.jp')) {
            $gallery = self::homesOrderedImages($html, $pageUrl);
        }

        if (!$gallery && preg_match_all('/\b(?:data-src|rel)=["\']([^"\']*\/front\/gazo\/bukken\/[^"\']+)["\']/isu', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $normalized = self::normalizedImageUrl($src, $pageUrl);
                if ($normalized) {
                    $gallery[] = $normalized;
                }
            }
        }

        ksort($gallery, SORT_NATURAL);
        return array_values(array_unique($gallery));
    }

    private static function homesOrderedImages(string $html, string $pageUrl): array
    {
        $start = stripos($html, '<photo-slider');
        if ($start === false) {
            $start = stripos($html, 'prg-galleryItems');
        }
        if ($start === false) {
            return [];
        }

        $countHint = self::homesImageCountHint($html);
        $chunkLength = $countHint > 0 ? max(30000, $countHint * 2500) : 90000;
        $chunk = substr($html, $start, $chunkLength);
        if (!preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']*image[0-9]?\.homes\.jp[^"\']*)["\']#isu', $chunk, $matches)) {
            return [];
        }

        $gallery = [];
        foreach ($matches[1] as $src) {
            $normalized = self::normalizedImageUrl($src, $pageUrl);
            if ($normalized) {
                $gallery[] = $normalized;
            }
            if ($countHint > 0 && count(array_unique($gallery)) >= $countHint) {
                break;
            }
        }

        return array_values(array_unique($gallery));
    }

    private static function prefersOrderedGalleryOnly(string $pageUrl): bool
    {
        $host = parse_url($pageUrl, PHP_URL_HOST) ?: '';
        return str_contains($host, 'suumo.jp')
            || str_contains($host, 'homes.co.jp')
            || str_contains($host, 'athome.co.jp');
    }

    private static function homesImageCountHint(string $html): int
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/"number_of_image"\s*:\s*\[\s*"?([0-9]+)/iu', $decoded, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/number_of_image(?:&quot;|")\s*:\s*(?:\[(?:&quot;|")?)?([0-9]+)/iu', $html, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/>\s*1\s*\/\s*([0-9]{1,3})\s*</u', $html, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private static function addImageCandidate(array &$out, string $value, string $pageUrl): void
    {
        foreach (preg_split('/\s*,\s*/u', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $part) {
            $absolute = self::normalizedImageUrl($part, $pageUrl);
            if ($absolute) {
                $out[$absolute] = true;
            }
        }
    }

    private static function normalizedImageUrl(string $value, string $pageUrl): ?string
    {
        $url = trim(preg_replace('/\s+\d+[wx]$/iu', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }
        if (preg_match('#(?:icon|logo|sprite|loading|blank|noimage|map|qr|button|banner|spacer|pagetop|jjcommon|assets/suumo/img/include|btn\.|homes-kun|header-footer|static_app_contents|/assets/(?:common|pc|images|img|css|js)/)#iu', $url)) {
            return null;
        }

        $absolute = self::canonicalImageUrl(self::absoluteUrl($url, $pageUrl));
        if (!$absolute || !filter_var($absolute, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = parse_url($absolute, PHP_URL_PATH) ?: '';
        $query = parse_url($absolute, PHP_URL_QUERY) ?: '';
        $full = $path . ($query ? '?' . $query : '');
        $decodedFull = rawurldecode($full);
        if (
            preg_match('/\.' . self::IMAGE_EXTENSION . '$/iu', $path)
            || preg_match('#/front/gazo/bukken/#iu', $absolute)
            || preg_match('#/image_files/path/#iu', $absolute)
            || preg_match('#^https://image[0-9]?\.homes\.jp/.*/image\.php\?#iu', $absolute)
            || preg_match('/\.' . self::IMAGE_EXTENSION . '(?:[?&]|$)/iu', $decodedFull)
        ) {
            return $absolute;
        }

        return null;
    }

    private static function canonicalImageUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $query = parse_url($url, PHP_URL_QUERY) ?: '';

        if (str_contains($host, 'athome.co.jp') && str_contains($path, '/image_files/path/')) {
            return self::origin($url) . $path;
        }

        if (preg_match('/^image[0-9]?\.homes\.jp$/iu', $host) && str_ends_with($path, '/image.php')) {
            parse_str($query, $params);
            if (!empty($params['file']) && is_string($params['file'])) {
                return self::origin($url) . $path . '?file=' . rawurlencode($params['file']);
            }
        }

        return $url;
    }

    private static function absoluteUrl(string $url, string $pageUrl): ?string
    {
        if (str_starts_with($url, '//')) {
            return (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }
        if (preg_match('#^https?://#iu', $url)) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return self::origin($pageUrl) . $url;
        }

        $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
        $base = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return self::origin($pageUrl) . $base . $url;
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
