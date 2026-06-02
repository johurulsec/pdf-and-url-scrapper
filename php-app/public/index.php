<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Normalization.php';
require_once __DIR__ . '/../src/NormalizeFields.php';
require_once __DIR__ . '/../src/ExactExtractors.php';
require_once __DIR__ . '/../src/PdfText.php';
require_once __DIR__ . '/../src/Geocode.php';
require_once __DIR__ . '/../src/Translate.php';
require_once __DIR__ . '/../src/WebScraper.php';
require_once __DIR__ . '/../src/BrowserScraperFallback.php';
require_once __DIR__ . '/../src/GeminiListingExtractor.php';

use App\Config;
use App\GeminiListingExtractor;
use App\Http;

$config = new Config(dirname(__DIR__));
$extractor = new GeminiListingExtractor($config);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir)) ?: '/';
}

try {
    if ($method === 'GET' && $path === '/healthz') {
        Http::json(['status' => 'ok']);
    }

    if ($method === 'GET' && $path === '/health/browser-scraper') {
        Http::json(App\BrowserScraperFallback::health($config));
    }

    if ($method === 'POST' && $path === '/extract') {
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Http::json(['detail' => 'Missing file field.'], 400);
        }

        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        if ($bytes === false || $bytes === '') {
            Http::json(['detail' => 'Empty file.'], 400);
        }

        $result = $extractor->runFileExtraction($bytes, $_FILES['file']['name'] ?? 'upload');
        Http::json($result);
    }

    if ($method === 'POST' && $path === '/extract/url') {
        $body = Http::jsonBody();
        $url = trim((string)($body['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            Http::json(['detail' => 'Invalid url.'], 400);
        }
        try {
            Http::json($extractor->runUrlExtraction($url));
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $isGatewayError = str_contains($message, 'HTTP ')
                || str_contains($message, 'URL ')
                || str_contains($message, 'cURL')
                || str_contains($message, 'scraper')
                || str_contains($message, 'readable HTML')
                || str_contains($message, 'browser rendering');
            $isGeminiError = str_contains($message, 'generativelanguage.googleapis.com')
                || str_contains($message, 'GOOGLE_API_KEY')
                || str_contains($message, 'model-')
                || str_contains($message, 'Bad JSON from model');
            $isRakumachi = str_contains(parse_url($url, PHP_URL_HOST) ?: '', 'rakumachi.jp');
            $isHomes = str_contains(parse_url($url, PHP_URL_HOST) ?: '', 'homes.co.jp');

            $status = $isGeminiError ? 503 : ($isGatewayError ? 502 : 500);
            $hint = 'The PHP app could not complete URL extraction. Test POST /debug/url first to see the exact fetch result.';
            if ($isGeminiError) {
                $hint = 'The URL was fetched, but Gemini/API processing failed. Check GOOGLE_API_KEY, GEMINI_MODEL, API enablement, and quota.';
            } elseif ($isRakumachi) {
                $hint = 'Rakumachi blocks plain PHP cURL for these pages. On Hostinger/shared PHP, configure REMOTE_FETCH_URL_TEMPLATE with a scraping API that returns HTML or text.';
            } elseif ($isHomes) {
                $hint = 'HOMES is usually fetchable with PHP cURL. If this still fails, inspect /debug/url and check whether Gemini/API processing is returning an error.';
            }

            Http::json([
                'detail' => $message,
                'url' => $url,
                'hint' => $hint,
                'remoteFetchConfigured' => (bool)$config->remoteFetchUrlTemplate,
                'browserScraperConfigured' => App\BrowserScraperFallback::isConfigured($config),
            ], $status);
        }
    }

    if ($method === 'POST' && $path === '/debug/url' && $config->env !== 'prod') {
        $body = Http::jsonBody();
        $url = trim((string)($body['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            Http::json(['detail' => 'Invalid url.'], 400);
        }
        try {
            $text = App\WebScraper::extractTextFromUrl($url, $config);
            Http::json([
                'url' => $url,
                'method' => $config->remoteFetchUrlTemplate ? 'php-curl-or-remote-fetch' : 'php-curl',
                'textLength' => strlen($text),
                'preview' => substr($text, 0, 3000),
            ]);
        } catch (Throwable $e) {
            if (App\BrowserScraperFallback::isConfigured($config)) {
                try {
                    $listing = App\BrowserScraperFallback::extract($config, $url);
                    Http::json([
                        'url' => $url,
                        'method' => 'browser-scraper-fallback',
                        'primaryError' => $e->getMessage(),
                        'requestId' => $listing['requestId'] ?? null,
                        'listingPreview' => $listing['listing'] ?? null,
                    ]);
                } catch (Throwable $fallbackError) {
                    Http::json([
                        'detail' => 'PHP cURL failed and browser fallback also failed.',
                        'url' => $url,
                        'primaryError' => $e->getMessage(),
                        'fallbackError' => $fallbackError->getMessage(),
                    ], 502);
                }
            }
            Http::json([
                'detail' => $e->getMessage(),
                'url' => $url,
                'diagnosis' => 'The PHP app could not fetch enough readable HTML/text from this URL before Gemini extraction.',
                'remoteFetchConfigured' => (bool)$config->remoteFetchUrlTemplate,
            ], 502);
        }
    }

    if ($method === 'POST' && $path === '/extract/url-batch') {
        $body = Http::jsonBody();
        $urls = $body['urls'] ?? [];
        if (!is_array($urls)) {
            Http::json(['detail' => 'urls must be an array.'], 400);
        }

        $items = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            try {
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('Invalid url.');
                }
                $result = $extractor->runUrlExtraction($url);
                $items[] = [
                    'requestId' => $result['requestId'],
                    'downloadUrl' => '/download/' . $result['requestId'],
                    'url' => $url,
                    'status' => 'success',
                    'data' => $result['data'] ?? $result,
                ];
            } catch (Throwable $e) {
                $items[] = [
                    'url' => $url,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'data' => GeminiListingExtractor::errorEnvelope($config, $url, $e->getMessage()),
                ];
            }
        }
        Http::json(['items' => $items]);
    }

    if ($method === 'GET' && preg_match('#^/download/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $file = $config->outputDir . '/' . basename($m[1]) . '.json';
        if (!is_file($file)) {
            Http::json(['detail' => 'Result not found'], 404);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }

    Http::json(['detail' => 'Not found'], 404);
} catch (Throwable $e) {
    Http::json(['detail' => $e->getMessage()], 500);
}
