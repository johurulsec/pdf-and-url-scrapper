<?php

declare(strict_types=1);

namespace App;

final class PdfText
{
    public static function pages(string $pdfBytes): array
    {
        $bin = self::pdftotextPath();
        if (!$bin || !function_exists('proc_open')) {
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'php_pdf_extract_');
        if (!$tmp) {
            return [];
        }

        $pdfPath = $tmp . '.pdf';
        @unlink($tmp);
        if (file_put_contents($pdfPath, $pdfBytes) === false) {
            return [];
        }

        try {
            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open([$bin, '-layout', '-enc', 'UTF-8', $pdfPath, '-'], $descriptor, $pipes);
            if (!is_resource($process)) {
                return [];
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0 || trim($stdout) === '') {
                return [];
            }

            $pages = preg_split("/\f/u", self::toUtf8($stdout)) ?: [];
            return array_values(array_filter(array_map(
                fn (string $page): string => trim(preg_replace('/[ \t　]+/u', ' ', $page) ?: $page),
                $pages
            ), fn (string $page): bool => $page !== ''));
        } finally {
            if (is_file($pdfPath)) {
                @unlink($pdfPath);
            }
        }
    }

    private static function pdftotextPath(): ?string
    {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function toUtf8(string $text): string
    {
        if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'SJIS-win,EUC-JP,JIS,UTF-8');
        }
        return $text;
    }
}
