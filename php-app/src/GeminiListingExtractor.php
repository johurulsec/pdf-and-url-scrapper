<?php

declare(strict_types=1);

namespace App;

final class GeminiListingExtractor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
あなたは日本の不動産広告(PDF/画像/Webページ本文)から項目を抽出するエージェントです。
出力は JSON のみ。説明文やマークダウンは禁止。値は原文のまま日本語・単位で返す。
JSONのキーは以下の日本語キーのみを使用し、存在しないものは欠落可:
種類, 価格, 値段, 販売価格, 所在, 所在地, 住所, 土地面積, 延床面積, 建物面積,
建ぺい率, 容積率, 用途地域, 道路, 幅員, 間口, 方位, 接道, 地目, 所有権, 借地権, 敷地権,
共有持分, 共有持分分子, 共有持分分母, 現況, 沿線, 駅, 徒歩, 交通,
築年月, 建築年月, 増改築, リノベーション, 間取り, 構造, 鉄筋コンクリート, 鉄骨鉄筋コンクリート, 鉄骨, 重量鉄骨, 軽量鉄骨, 木造,
駐車場, 車庫, 備考, 容積率但し書き, 道路メモ
PROMPT;

    private const PRIORITY_KEYS = [
        '価格', '値段', '販売価格', '土地面積', '敷地面積', '建物面積', '延床面積', '延床',
        '建ぺい率', '建蔽率', '容積率', '幅員', '間口', '徒歩',
    ];

    public function __construct(private Config $config)
    {
    }

    public function runFileExtraction(string $fileBytes, string $filename): array
    {
        $start = microtime(true);
        $mime = $this->mimeFor($filename, $fileBytes);
        $pdfTextPages = $mime === 'application/pdf' ? PdfText::pages($fileBytes) : [];
        $textHint = $this->config->includeOcrHint ? $this->textHint($pdfTextPages) : '';
        $exactRaw = $pdfTextPages ? ExactExtractors::extractFromText(implode("\n\n", $pdfTextPages)) : [];

        $parts = [
            ['text' => self::SYSTEM_PROMPT],
        ];
        if ($textHint !== '') {
            $parts[] = ['text' => "参考テキスト:\n" . substr($textHint, 0, 12000)];
        }
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($fileBytes)]];

        try {
            $modelRaw = $this->callModel($parts);
            $raw = $this->mergeRaw($modelRaw, $exactRaw);
            return $this->buildEnvelope($raw, $filename, null, 'inline-file', $start);
        } catch (\Throwable $modelError) {
            if (!$exactRaw) {
                throw $modelError;
            }
            $env = $this->buildEnvelope($exactRaw, $filename, null, 'inline-file', $start);
            $env['meta']['modelFallback'] = 'exact-pdf-text';
            $env['meta']['modelFallbackReason'] = $modelError->getMessage();
            $this->saveEnvelope($env);
            return $env;
        }
    }

    public function runUrlExtraction(string $url): array
    {
        $start = microtime(true);
        $browserScraperSkippedReason = null;

        if ($this->shouldUseBrowserScraperFirst($url, $browserScraperSkippedReason)) {
            $env = BrowserScraperFallback::extract($this->config, $url);
            $env['meta']['phpFallback'] = 'browser-scraper';
            $env['meta']['phpFallbackReason'] = 'rakumachi-preferred-browser-rendering';
            $this->saveEnvelope($env);

            return [
                'requestId' => $env['requestId'],
                'downloadUrl' => '/download/' . $env['requestId'],
                'data' => $env,
            ];
        }

        try {
            $text = WebScraper::extractTextFromUrl($url, $this->config);
        } catch (\Throwable $primaryError) {
            if (
                !$this->isRakumachiUrl($url)
                || !BrowserScraperFallback::isConfigured($this->config)
            ) {
                throw $primaryError;
            }
            if (!BrowserScraperFallback::isReachable($this->config)) {
                throw new \RuntimeException(
                    'Rakumachi page could not be fetched by PHP cURL, and the configured browser scraper is not reachable. '
                    . 'PHP error: ' . $primaryError->getMessage()
                    . ($browserScraperSkippedReason ? ' Browser scraper: ' . $browserScraperSkippedReason : '')
                );
            }
            $env = BrowserScraperFallback::extract($this->config, $url);
            $env['meta']['phpFallback'] = 'browser-scraper';
            $env['meta']['phpFallbackReason'] = $primaryError->getMessage();
            $this->saveEnvelope($env);
        }

        if (!isset($env)) {
            $exactRaw = ExactExtractors::extractFromText($text);
            try {
                $modelRaw = $this->callModel([
                    ['text' => self::SYSTEM_PROMPT . "\n\nWebページ本文:\n" . substr($text, 0, 50000)],
                ]);
                $raw = $this->mergeRaw($modelRaw, $exactRaw);
                $env = $this->buildEnvelope($raw, null, $url, 'live-url', $start);
            } catch (\Throwable $modelError) {
                if (!$exactRaw) {
                    throw $modelError;
                }
                $env = $this->buildEnvelope($exactRaw, null, $url, 'live-url', $start);
                $env['meta']['modelFallback'] = 'exact-text';
                $env['meta']['modelFallbackReason'] = $modelError->getMessage();
                $this->saveEnvelope($env);
            }
        }

        return [
            'requestId' => $env['requestId'],
            'downloadUrl' => '/download/' . $env['requestId'],
            'data' => $env,
        ];
    }

    private function shouldUseBrowserScraperFirst(string $url, ?string &$skippedReason = null): bool
    {
        if (!$this->config->preferBrowserScraperForRakumachi || !$this->isRakumachiUrl($url)) {
            return false;
        }

        if (!BrowserScraperFallback::isConfigured($this->config)) {
            $skippedReason = 'BROWSER_SCRAPER_URL is not configured.';
            return false;
        }

        $health = BrowserScraperFallback::health($this->config);
        if (($health['status'] ?? '') !== 'ok') {
            $skippedReason = (string)($health['error'] ?? 'BROWSER_SCRAPER_URL health check did not return ok.');
            return false;
        }

        return true;
    }

    private function isRakumachiUrl(string $url): bool
    {
        return str_contains(parse_url($url, PHP_URL_HOST) ?: '', 'rakumachi.jp');
    }

    public static function errorEnvelope(Config $config, string $url, string $error): array
    {
        return [
            'schemaVersion' => $config->schemaVersion,
            'requestId' => null,
            'locale' => 'en-US',
            'source' => [
                'fileName' => null,
                'url' => $url,
                'runAt' => '',
                'mode' => 'live-url',
                'model' => $config->geminiModel,
            ],
            'listing' => null,
            'raw' => ['groups' => []],
            'meta' => ['durationMs' => null, 'tokens' => [], 'error' => $error],
        ];
    }

    private function callModel(array $parts): array
    {
        $lastError = null;
        foreach ([0, 2, 6] as $attempt => $sleepSeconds) {
            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
            try {
                return $this->callModelOnce($parts);
            } catch (\Throwable $e) {
                $lastError = $e;
                if (!$this->isTransientModelError($e->getMessage()) || $attempt === 2) {
                    break;
                }
            }
        }

        throw $lastError ?: new \RuntimeException('Gemini request failed.');
    }

    private function callModelOnce(array $parts, bool $allowJsonRetry = true): array
    {
        if (!$this->config->googleApiKey) {
            throw new \RuntimeException('Set GOOGLE_API_KEY.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->config->geminiModel) . ':generateContent';
        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.2,
                'topK' => 32,
                'responseMimeType' => 'application/json',
            ],
        ];

        $body = Http::request('POST', $url, [
            'headers' => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->config->googleApiKey,
            ],
            'json' => $payload,
            'timeout' => 120,
        ]);

        $data = json_decode((string)$body, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            throw new \RuntimeException('model-empty-response');
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            if ($allowJsonRetry) {
                $retryParts = $parts;
                if (isset($retryParts[0]['text'])) {
                    $retryParts[0]['text'] .= "\n必ずJSONのみで返してください。";
                } else {
                    array_unshift($retryParts, ['text' => self::SYSTEM_PROMPT . "\n必ずJSONのみで返してください。"]);
                }
                return $this->callModelOnce($retryParts, false);
            }
            throw new \RuntimeException('Bad JSON from model: ' . substr($text, 0, 500));
        }
        return $json;
    }

    private function isTransientModelError(string $message): bool
    {
        return str_contains($message, 'HTTP 429')
            || str_contains($message, 'HTTP 500')
            || str_contains($message, 'HTTP 502')
            || str_contains($message, 'HTTP 503')
            || str_contains($message, 'HTTP 504')
            || str_contains($message, 'RESOURCE_EXHAUSTED')
            || str_contains($message, 'UNAVAILABLE')
            || str_contains($message, 'model-empty-response');
    }

    private function buildEnvelope(array $raw, ?string $filename, ?string $url, string $mode, float $start): array
    {
        $requestId = bin2hex(random_bytes(8));
        $listing = NormalizeFields::standardize($raw);
        $env = [
            'schemaVersion' => $this->config->schemaVersion,
            'requestId' => $requestId,
            'locale' => $this->config->locale,
            'source' => [
                'fileName' => $filename,
                'url' => $url,
                'runAt' => gmdate('c'),
                'mode' => $mode,
                'model' => $this->config->geminiModel,
            ],
            'listing' => $listing,
            'raw' => ['groups' => $raw['groups'] ?? []],
            'meta' => ['durationMs' => (int)round((microtime(true) - $start) * 1000), 'tokens' => []],
        ];

        $address = (string)($env['listing']['address']['full'] ?? '');
        if ($address !== '' && ($latLng = Geocode::address($address, $this->config))) {
            $env['listing']['address']['lat'] = $latLng[0];
            $env['listing']['address']['lng'] = $latLng[1];
        }

        if ($this->config->autoTranslateAllToEn) {
            Translate::entireDocumentToEnglish($env, $this->config);
        }

        $this->saveEnvelope($env);

        return $env;
    }

    private function saveEnvelope(array $env): void
    {
        $requestId = $env['requestId'] ?? bin2hex(random_bytes(8));
        file_put_contents(
            $this->config->outputDir . '/' . $requestId . '.json',
            json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function mergeRaw(array $modelRaw, array $exactRaw): array
    {
        foreach ($exactRaw as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($key, self::PRIORITY_KEYS, true) || $this->config->strictVerbatim || !array_key_exists($key, $modelRaw)) {
                $modelRaw[$key] = $value;
            }
        }
        return $modelRaw;
    }

    private function textHint(array $pages): string
    {
        $out = [];
        foreach ($pages as $i => $text) {
            $text = trim((string)$text);
            if ($text !== '') {
                $out[] = '[PAGE ' . ($i + 1) . '] ' . $text;
            }
        }
        return implode("\n\n", $out);
    }

    private function mimeFor(string $filename, string $bytes): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'application/octet-stream',
        };
    }
}
