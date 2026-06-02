<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public string $rootDir;
    public string $outputDir;
    public string $env;
    public string $locale;
    public string $schemaVersion;
    public ?string $googleApiKey;
    public string $geminiModel;
    public bool $strictVerbatim;
    public int $maxPages;
    public bool $includeOcrHint;
    public bool $autoTranslateAllToEn;
    public bool $outputEnglishOnly;
    public bool $preserveOriginalJa;
    public ?string $googleTranslateApiKey;
    public ?string $googleMapsApiKey;
    public ?string $browserScraperUrl;
    public ?string $browserScraperApiKey;
    public bool $preferBrowserScraperForRakumachi;
    public ?string $remoteFetchUrlTemplate;
    public ?string $remoteFetchApiKey;
    public ?string $remoteFetchApiKeyHeader;
    public ?string $remoteFetchJsonField;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;
        $this->loadEnv($rootDir . '/.env');
        $this->loadEnv(dirname($rootDir) . '/.env');

        $this->outputDir = $rootDir . '/outputs';
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0775, true);
        }

        $this->env = getenv('ENV') ?: 'dev';
        $this->locale = getenv('LOCALE') ?: 'ja-JP';
        $this->schemaVersion = getenv('SCHEMA_VERSION') ?: '1.0';
        $this->googleApiKey = getenv('GOOGLE_API_KEY') ?: null;
        $this->geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
        $this->strictVerbatim = $this->envBool('STRICT_VERBATIM', false);
        $this->maxPages = max(1, (int)(getenv('MAX_PAGES') ?: 4));
        $this->includeOcrHint = $this->envBool('INCLUDE_OCR_HINT', true);
        $this->autoTranslateAllToEn = $this->envBool('AUTO_TRANSLATE_ALL_TO_EN', true);
        $this->outputEnglishOnly = $this->envBool('OUTPUT_ENGLISH_ONLY', true);
        $this->preserveOriginalJa = $this->envBool('PRESERVE_ORIGINAL_JA', !$this->outputEnglishOnly);
        $this->googleTranslateApiKey = getenv('GOOGLE_TRANSLATE_API_KEY') ?: null;
        $this->googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: null;
        $this->browserScraperUrl = getenv('BROWSER_SCRAPER_URL') ?: null;
        $this->browserScraperApiKey = getenv('BROWSER_SCRAPER_API_KEY') ?: null;
        $this->preferBrowserScraperForRakumachi = $this->envBool('PREFER_BROWSER_SCRAPER_FOR_RAKUMACHI', false);
        $this->remoteFetchUrlTemplate = getenv('REMOTE_FETCH_URL_TEMPLATE') ?: null;
        $this->remoteFetchApiKey = getenv('REMOTE_FETCH_API_KEY') ?: null;
        $this->remoteFetchApiKeyHeader = getenv('REMOTE_FETCH_API_KEY_HEADER') ?: null;
        $this->remoteFetchJsonField = getenv('REMOTE_FETCH_JSON_FIELD') ?: null;
    }

    private function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes'], true);
    }
}
