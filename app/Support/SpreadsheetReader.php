<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal, dependency-free reader for small CSV and XLSX files (first sheet only).
 *
 * Built for user uploads, so it is defensive:
 * - hard caps on rows and columns
 * - XLSX: checks it's a real zip, caps entry count and uncompressed size (zip-bomb guard),
 *   parses XML with network access and entity expansion disabled
 * - values come back as trimmed strings with control characters removed
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 501;          // header + 500 data rows
    private const MAX_COLS = 30;
    private const MAX_UNCOMPRESSED = 20 * 1024 * 1024;
    private const MAX_ZIP_ENTRIES = 200;

    /** @return list<list<string>> rows, header first */
    public static function read(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => self::readCsv($path),
            'xlsx' => self::readXlsx($path),
            default => throw new RuntimeException('Unsupported file type. Upload .xlsx or .csv'),
        };
    }

    private static function readCsv(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if (str_contains($head, "\0")) {
            throw new RuntimeException('This does not look like a CSV text file.');
        }

        $delimiter = substr_count($head, ';') > substr_count($head, ',') ? ';' : ',';
        $fh = fopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException('Could not read the file.');
        }

        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                fclose($fh);
                throw new RuntimeException('Too many rows. Import up to '.(self::MAX_ROWS - 1).' suppliers at a time.');
            }
            if ($row === [null]) {
                continue; // blank line
            }
            $rows[] = array_map([self::class, 'clean'], array_slice($row, 0, self::MAX_COLS));
        }
        fclose($fh);

        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]); // UTF-8 BOM from Excel
        }

        return $rows;
    }

    private static function readXlsx(string $path): array
    {
        if ((string) file_get_contents($path, false, null, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('This does not look like an .xlsx file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Could not open the .xlsx file.');
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new RuntimeException('The file structure is not supported.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
                if ($total > self::MAX_UNCOMPRESSED) {
                    throw new RuntimeException('The file is too large once opened.');
                }
            }

            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach (self::xml($xml)->si as $si) {
                    // A shared string is either <t> or several rich-text runs <r><t>
                    $text = isset($si->t) ? (string) $si->t : '';
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                    $shared[] = $text;
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/[^/]+\.xml$#', (string) $name)) {
                        $sheetXml = $zip->getFromIndex($i);
                        break;
                    }
                }
            }
            if ($sheetXml === false) {
                throw new RuntimeException('No worksheet found in the file.');
            }

            $rows = [];
            foreach (self::xml($sheetXml)->sheetData->row as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw new RuntimeException('Too many rows. Import up to '.(self::MAX_ROWS - 1).' suppliers at a time.');
                }
                $values = [];
                $next = 0;
                foreach ($row->c as $c) {
                    // Some generators omit the cell reference; fall back to position.
                    $col = isset($c['r']) ? self::colIndex((string) $c['r']) : $next;
                    if ($col === null || $col >= self::MAX_COLS) {
                        continue;
                    }
                    $next = $col + 1;
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        default => (string) $c->v,
                    };
                    $values[$col] = self::clean($value);
                }
                if ($values === []) {
                    continue;
                }
                $max = max(array_keys($values));
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $values[$i] ?? '';
                }
                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('The file structure is not supported.');
        }
        $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($el === false) {
            throw new RuntimeException('The file is damaged or not a real spreadsheet.');
        }

        return $el;
    }

    /** "C12" → 2 */
    private static function colIndex(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function clean(?string $v): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v) ?? '');
    }
}
