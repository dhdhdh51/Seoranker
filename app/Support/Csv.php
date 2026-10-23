<?php
declare(strict_types=1);

namespace BharatSeo\Support;

use BharatSeo\Core\ValidationException;

/** CSV helpers: safe upload handling, delimiter/encoding detection and formula-injection-safe output. */
final class Csv
{
    public const ALLOWED_MIME = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/tab-separated-values', 'application/octet-stream'];

    /** Neutralize spreadsheet formulas (CSV injection). */
    public static function safeCell(mixed $v): string
    {
        $s = (string)($v ?? '');
        if ($s !== '' && (in_array($s[0], ['=', '+', '-', '@', "\t", "\r", '|'], true) || preg_match('/^\s+[=+\-@]/', $s))) {
            // Allow plain negative numbers.
            if (preg_match('/^-\d+(\.\d+)?$/', $s)) {
                return $s;
            }
            return "'" . $s;
        }
        return $s;
    }

    /** @param resource $out */
    public static function writeRow($out, array $row): void
    {
        fputcsv($out, array_map([self::class, 'safeCell'], $row), ',', '"', '\\');
    }

    public static function detectDelimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|')];
        arsort($counts);
        $d = (string)array_key_first($counts);
        return $counts[$d] > 0 ? $d : ',';
    }

    /**
     * Validate an uploaded file (from $_FILES) and store a UTF-8 copy in $dir.
     * @return array{path:string, sha256:string, original:string, size:int}
     */
    public static function storeUpload(array $file, string $dir, int $maxBytes): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw ValidationException::single('file', 'Invalid upload.');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::single('file', 'The file is too large.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw ValidationException::single('file', 'Upload failed. Please choose a CSV file.');
        }
        $size = (int)$file['size'];
        if ($size <= 0 || $size > $maxBytes) {
            throw ValidationException::single('file', 'The file must be between 1 byte and ' . round($maxBytes / 1048576, 1) . ' MB.');
        }
        $original = basename((string)$file['name']);
        if (!preg_match('/\.(csv|tsv|txt)$/i', $original)) {
            throw ValidationException::single('file', 'Only .csv, .tsv or .txt files are accepted.');
        }
        $tmp = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmp) && !(defined('BHARATSEO_TESTING') && is_file($tmp))) {
            throw ValidationException::single('file', 'Invalid upload.');
        }
        $mime = function_exists('finfo_open') ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp) : 'text/plain';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw ValidationException::single('file', 'The file does not look like a CSV (detected ' . $mime . ').');
        }
        $raw = (string)file_get_contents($tmp);
        // Encoding normalization (Ahrefs/Semrush often export UTF-16).
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string)mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        if (str_contains($raw, "\0")) {
            throw ValidationException::single('file', 'The file contains binary data and was rejected.');
        }
        if (preg_match('/^\s*(<\?php|<script|<html|PK\x03\x04)/i', $raw)) {
            throw ValidationException::single('file', 'The file content is not CSV.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $name = bin2hex(random_bytes(16)) . '.csv';
        $path = rtrim($dir, '/') . '/' . $name;
        if (file_put_contents($path, $raw) === false) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        @chmod($path, 0640);
        return ['path' => $path, 'sha256' => hash('sha256', $raw), 'original' => mb_substr($original, 0, 255), 'size' => strlen($raw)];
    }

    /** Normalize a header label for alias matching. */
    public static function headerKey(string $h): string
    {
        $h = strtolower(trim($h, " \t\n\r\0\x0B\"'\xEF\xBB\xBF"));
        return trim(preg_replace('/[^a-z0-9]+/', '_', $h) ?? '', '_');
    }

    /**
     * Map CSV headers to canonical fields.
     * @param string[] $headers
     * @param array<string,string[]> $aliases canonical => alias keys (priority order)
     * @return array<string,int> canonical => column index
     */
    public static function mapHeaders(array $headers, array $aliases): array
    {
        $keys = array_map([self::class, 'headerKey'], $headers);
        $map = [];
        foreach ($aliases as $canonical => $list) {
            foreach ($list as $alias) {
                $idx = array_search($alias, $keys, true);
                if ($idx !== false) {
                    $map[$canonical] = (int)$idx;
                    break;
                }
            }
        }
        return $map;
    }
}
