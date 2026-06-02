<?php

declare(strict_types=1);

namespace App;

final class Translate
{
    private const SKIP_PATHS = [
        ['schemaVersion'],
        ['requestId'],
        ['locale'],
        ['source', 'runAt'],
        ['source', 'mode'],
        ['source', 'model'],
        ['meta', 'normalizedLocale'],
    ];

    public static function entireDocumentToEnglish(array &$result, Config $config): void
    {
        if ($config->preserveOriginalJa) {
            $result['raw']['originalJa'] ??= self::copyWithoutOriginal($result);
        } else {
            unset($result['raw']['originalJa']);
        }

        $paths = [];
        self::collect($result, [], $paths);
        if (!$paths) {
            $result['locale'] = 'en-US';
            $result['meta']['normalizedLocale'] = 'en-US';
            return;
        }

        $texts = array_map(fn ($item) => $item[1], $paths);
        $translated = self::textsToEnglish($texts, $config);
        foreach ($paths as $i => [$path]) {
            self::assign($result, $path, $translated[$i] ?? $texts[$i]);
        }
        $result['locale'] = 'en-US';
        $result['meta']['normalizedLocale'] = 'en-US';

        if ($config->outputEnglishOnly) {
            unset($result['raw']['originalJa']);
        }
    }

    private static function textsToEnglish(array $texts, Config $config): array
    {
        if (!$config->googleTranslateApiKey) {
            return $texts;
        }

        $out = [];
        foreach (array_chunk($texts, 100) as $chunk) {
            try {
                $url = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($config->googleTranslateApiKey);
                $body = Http::request('POST', $url, [
                    'headers' => ['Content-Type: application/json'],
                    'json' => ['q' => $chunk, 'target' => 'en', 'format' => 'text', 'source' => 'ja'],
                    'timeout' => 20,
                ]);
                $data = json_decode((string)$body, true);
                foreach ($data['data']['translations'] ?? [] as $idx => $row) {
                    $out[] = $row['translatedText'] ?? $chunk[$idx];
                }
            } catch (\Throwable) {
                array_push($out, ...$chunk);
            }
        }
        return $out;
    }

    private static function collect(mixed $value, array $path, array &$bag): void
    {
        if (self::shouldSkip($path)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::collect($v, [...$path, $k], $bag);
            }
        } elseif (is_string($value) && $value !== '') {
            $bag[] = [$path, $value];
        }
    }

    private static function shouldSkip(array $path): bool
    {
        if (count($path) >= 2 && $path[0] === 'raw' && $path[1] === 'originalJa') {
            return true;
        }

        foreach (self::SKIP_PATHS as $skip) {
            if ($path === $skip) {
                return true;
            }
        }

        return false;
    }

    private static function assign(array &$root, array $path, mixed $value): void
    {
        $cur = &$root;
        foreach (array_slice($path, 0, -1) as $p) {
            $cur = &$cur[$p];
        }
        $cur[$path[array_key_last($path)]] = $value;
    }

    private static function copyWithoutOriginal(array $result): array
    {
        unset($result['raw']['originalJa']);
        return $result;
    }
}
