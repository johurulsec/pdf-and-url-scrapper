<?php

declare(strict_types=1);

namespace App;

final class ExactExtractors
{
    public static function extractFromText(string $text): array
    {
        $out = [];
        $clean = str_replace(',', '', $text);
        $lines = self::cleanLines($text);
        if (preg_match('/(?:(?<oku>[0-9]+(?:\.[0-9]+)?)\s*億)?\s*(?:(?<man>[0-9]+(?:\.[0-9]+)?)\s*万)?\s*円/u', $clean, $m) && (($m['oku'] ?? '') !== '' || ($m['man'] ?? '') !== '')) {
            $out['価格'] = (string)((int)round((float)($m['oku'] ?: 0) * 100000000 + (float)($m['man'] ?: 0) * 10000)) . '円';
        } elseif (preg_match('/(?<n>[0-9]+(?:\.[0-9]+)?)\s*円/u', $clean, $m)) {
            $out['価格'] = (string)((int)(float)$m['n']) . '円';
        }

        foreach ([['土地面積', '敷地面積'], ['延床面積', '建物面積|延床面積|延床|床面積']] as [$key, $words]) {
            if (preg_match('/(?:' . $words . ')[^0-9]*([0-9]+(?:\.[0-9]+)?)\s*(?:m2|㎡|m²|坪)/iu', $text, $m)) {
                $out[$key] = $m[1] . '㎡';
            }
        }

        if (preg_match('/建(?:ぺい|蔽)率\s*([0-9]+(?:\.[0-9]+)?)\s*(?:%|％)/u', $text, $m)) {
            $out['建ぺい率'] = (string)((int)(float)$m[1]) . '%';
        }
        if (preg_match('/容積率\s*([0-9]+(?:\.[0-9]+)?)\s*(?:%|％)(?:（\s*([0-9]+(?:\.[0-9]+)?)\s*％\s*）)?/u', $text, $m)) {
            $out['容積率'] = (string)((int)(float)$m[1]) . '%' . (isset($m[2]) && $m[2] !== '' ? '（' . (int)(float)$m[2] . '％）' : '');
        }

        if (preg_match('/(公営水道|上水道|水道)/u', $text)) {
            $out['水道'] = '公営水道';
        }
        if (preg_match('/(公共下水|下水)/u', $text)) {
            $out['下水'] = '公共下水';
        }
        if (str_contains($text, '都市ガス')) {
            $out['都市ガス'] = '都市ガス';
        }
        if (str_contains($text, '電気')) {
            $out['電気'] = '電気';
        }
        if (preg_match('/駐車(?:場)?\s*(無し|なし)/u', $text)) {
            $out['駐車場'] = '無し';
        } elseif (preg_match('/駐車(?:場)?\s*(有|あり|有り)/u', $text)) {
            $out['駐車場'] = '有';
        }
        if (preg_match('/(北西|南西|北東|南東|北|東|南|西)(?:側)?/u', $text, $m)) {
            $out['方位'] = $m[1];
        }
        if (preg_match('/幅員\s*約?([0-9]+(?:\.[0-9]+)?)\s*m/u', $text, $m)) {
            $out['幅員'] = $m[1] . 'm';
        }
        if (str_contains($text, '公道')) {
            $out['道路'] = '公道';
        } elseif (str_contains($text, '私道')) {
            $out['道路'] = '私道';
        }
        if (preg_match('/徒歩\s*([0-9]+)\s*分/u', $text, $m)) {
            $out['徒歩'] = $m[1] . '分';
        }

        foreach ([
            '所在地' => '所在地',
            '住所' => '住所',
            '築年月' => '築年月',
            '建物構造' => '構造',
            '構造' => '構造',
            '用途地域' => '用途地域',
            '土地権利' => '所有権',
            '権利' => '所有権',
            '現況' => '現況',
        ] as $label => $key) {
            if (empty($out[$key]) && ($value = self::valueAfterLabel($lines, $label))) {
                $out[$key] = $value;
            }
        }
        if ($value = self::valueAfterLabel($lines, '主要採光面')) {
            $out['方位'] = $value;
        }

        foreach ([
            ['専有面積', '延床面積'],
            ['建物面積', '建物面積'],
            ['延床面積', '延床面積'],
            ['土地面積', '土地面積'],
            ['敷地面積', '土地面積'],
        ] as [$label, $key]) {
            if (empty($out[$key]) && ($value = self::valueAfterLabel($lines, $label)) && preg_match('/[0-9]/', $value)) {
                $out[$key] = $value;
            }
        }

        if (empty($out['間取り']) && ($floorPlan = self::floorPlan($lines))) {
            $out['間取り'] = $floorPlan;
        }

        if (empty($out['沿線']) || empty($out['駅']) || empty($out['徒歩'])) {
            self::extractTransport($text, $out);
        }

        return $out;
    }

    private static function cleanLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t　]+/u', ' ', $line) ?: '');
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    private static function valueAfterLabel(array $lines, string $label): ?string
    {
        foreach ($lines as $i => $line) {
            if ($line === $label) {
                return self::usefulValue($lines[$i + 1] ?? null);
            }
            if (str_starts_with($line, $label . ' ')) {
                return self::usefulValue(trim(substr($line, strlen($label))));
            }
            if (str_starts_with($line, $label . ':')) {
                return self::usefulValue(trim(substr($line, strlen($label) + 1)));
            }
        }
        return null;
    }

    private static function usefulValue(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || in_array($value, ['地図', '不動産用語', 'もっと見る'], true)) {
            return null;
        }
        return $value;
    }

    private static function extractTransport(string $text, array &$out): void
    {
        $lines = self::cleanLines($text);
        $transportLine = null;
        foreach ($lines as $i => $line) {
            if ($line === '交通') {
                $transportLine = $lines[$i + 1] ?? null;
                break;
            }
        }
        $transportLine ??= self::firstMatchingLine($lines, '/線\s+[^駅]+駅\s+徒歩\s*[0-9]+\s*分/u');
        if (!$transportLine || !preg_match('/([^、。\n]+線)\s+([^駅\s]+駅)\s+徒歩\s*([0-9]+)\s*分/u', $transportLine, $m)) {
            return;
        }
        $out['沿線'] ??= trim($m[1]);
        $out['駅'] ??= trim($m[2]);
        $out['徒歩'] ??= $m[3] . '分';
    }

    private static function floorPlan(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^([0-9]+[SLDKR]+)$/u', $line, $m)) {
                return $m[1];
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/(?:間取り|プラン)[:： ]+([0-9]+[SLDKR]+)/u', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private static function firstMatchingLine(array $lines, string $pattern): ?string
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                return $line;
            }
        }
        return null;
    }
}
