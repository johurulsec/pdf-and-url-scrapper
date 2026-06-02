<?php

declare(strict_types=1);

namespace App;

final class Normalization
{
    private const STRUCTURE_MAP = [
        '鉄筋コンクリート' => 'RC', 'RC' => 'RC',
        '鉄骨鉄筋コンクリート' => 'SRC', 'SRC' => 'SRC',
        '重量鉄骨' => 'heavy_steel', '軽量鉄骨' => 'light_steel',
        '鉄骨' => 'S', '木造' => 'W',
    ];

    private const TENURE_MAP = [
        '所有権' => 'ownership',
        '借地権' => 'leasehold',
        '敷地権' => 'site_right',
    ];

    public static function priceJpy(string $text): ?int
    {
        $text = str_replace(',', '', $text);
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(億|万)?円?/u', $text, $m)) {
            return null;
        }
        $val = (float)$m[1];
        $unit = $m[2] ?? '';
        return match ($unit) {
            '億' => (int)round($val * 100000000),
            '万' => (int)round($val * 10000),
            default => (int)round($val),
        };
    }

    public static function areaM2(string $text): ?float
    {
        $text = trim(str_replace(',', '', $text));
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(m2|㎡|m²|坪|m|ｍ)/iu', $text, $m)) {
            return null;
        }
        $val = (float)$m[1];
        return ($m[2] === '坪') ? round($val * 3.305785, 2) : round($val, 2);
    }

    public static function ratioPercent(string $text): ?int
    {
        if (preg_match('/([0-9]{1,3})\s*(?:%|％)/u', $text, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/([0-9]{1,3})\s*\/\s*([0-9]{1,3})/u', $text, $m) && (int)$m[2] !== 0) {
            return (int)round(100 * (int)$m[1] / (int)$m[2]);
        }
        return null;
    }

    public static function direction(string $text): ?string
    {
        foreach (['北西', '南西', '北東', '南東', '北', '東', '南', '西'] as $dir) {
            if (str_contains($text, $dir)) {
                return $dir;
            }
        }
        return null;
    }

    public static function meters(string $text): ?float
    {
        $text = str_replace('ｍ', 'm', $text);
        return preg_match('/([0-9]+(?:\.[0-9]+)?)\s*m/u', $text, $m) ? (float)$m[1] : null;
    }

    public static function walkMinutes(string $text): ?int
    {
        return preg_match('/徒歩\s*([0-9]+)\s*分/u', $text, $m) ? (int)$m[1] : null;
    }

    public static function tenure(string $text): ?string
    {
        foreach (self::TENURE_MAP as $key => $value) {
            if (str_contains($text, $key)) {
                return $value;
            }
        }
        return null;
    }

    public static function structure(string $text): ?string
    {
        foreach (self::STRUCTURE_MAP as $key => $value) {
            if (str_contains($text, $key)) {
                return $value;
            }
        }
        return null;
    }
}
