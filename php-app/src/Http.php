<?php

declare(strict_types=1);

namespace App;

final class Http
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : [];
    }

    public static function request(string $method, string $url, array $options = []): array|string
    {
        $ch = curl_init($url);
        $headers = $options['headers'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $options['maxRedirects'] ?? 5,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $options['userAgent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
        ]);
        if (!empty($options['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
        }
        if (!empty($options['cookieFile'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $options['cookieFile']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $options['cookieFile']);
        }
        if (array_key_exists('json', $options)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (array_key_exists('body', $options)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException($err ?: 'HTTP request failed.');
        }
        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status} while fetching {$effectiveUrl}: " . substr($body, 0, 1000));
        }
        if (($options['expectHtml'] ?? false) && $contentType && !str_contains(strtolower($contentType), 'html')) {
            throw new \RuntimeException("URL did not return HTML. Content-Type: {$contentType}");
        }

        return $body;
    }
}
