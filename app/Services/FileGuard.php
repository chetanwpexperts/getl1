<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * One gate for every user upload. Decides the file type from its bytes, not its name.
 *
 * - PDF: must start with %PDF-, refused if it carries active content (JavaScript, launch
 *   actions, embedded files). Content inside compressed streams isn't visible to this scan,
 *   so files are always served as downloads, never rendered inline.
 * - JPG/PNG: must decode as an image of sane dimensions.
 * - XLSX/DOCX: must be a real Office zip with the expected parts and no macros.
 *
 * Rejections are written to the security log.
 */
class FileGuard
{
    public const PDF = 'pdf';
    public const JPG = 'jpg';
    public const PNG = 'png';
    public const XLSX = 'xlsx';
    public const DOCX = 'docx';

    private const IMAGE_MIMES = ['image/jpeg' => self::JPG, 'image/png' => self::PNG];
    private const ZIP_MIMES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * @param  list<string>  $allowed  e.g. [FileGuard::PDF, FileGuard::JPG, FileGuard::PNG]
     * @return string extension to store the file under
     */
    public function check(UploadedFile $file, array $allowed, int $maxKb, string $kind, string $field = 'file'): string
    {
        $reject = function (string $reason) use ($file, $kind, $field, $allowed, $maxKb) {
            SecurityLog::warning('upload_rejected', [
                'kind' => $kind, 'reason' => $reason,
                'client_name' => substr($file->getClientOriginalName(), 0, 120),
                'size' => $file->getSize(),
            ]);

            $types = strtoupper(implode(', ', $allowed));
            throw ValidationException::withMessages([$field => "Upload a {$types} file (max ".round($maxKb / 1024, 1).' MB).']);
        };

        if (! $file->isValid() || $file->getSize() === 0 || $file->getSize() > $maxKb * 1024) {
            $reject('invalid_or_size');
        }

        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $clientExt = strtolower($file->getClientOriginalExtension());

        if ($mime === 'application/pdf' && in_array(self::PDF, $allowed, true)) {
            $bytes = (string) file_get_contents($path, false, null, 0, $maxKb * 1024);
            if (! str_starts_with($bytes, '%PDF-')) {
                $reject('pdf_header');
            }
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b#', $bytes)) {
                $reject('pdf_active_content');
            }

            return self::PDF;
        }

        if (isset(self::IMAGE_MIMES[$mime]) && in_array(self::IMAGE_MIMES[$mime], $allowed, true)) {
            $info = @getimagesize($path);
            if ($info === false || $info[0] < 20 || $info[1] < 20 || $info[0] > 12000 || $info[1] > 12000) {
                $reject('image_unreadable');
            }

            return self::IMAGE_MIMES[$mime];
        }

        if (in_array($mime, self::ZIP_MIMES, true) && in_array($clientExt, [self::XLSX, self::DOCX], true)
            && in_array($clientExt, $allowed, true)) {
            $this->checkOffice($path, $clientExt) || $reject('office_structure');

            return $clientExt;
        }

        $reject('mime:'.$mime);
    }

    /** Display name for downloads: letters, digits, space . _ - only, max 100 chars. */
    public static function safeName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN _.-]+/u', '', $base) ?: 'document';

        return \Illuminate\Support\Str::limit(trim($base), 100, '').'.'.$ext;
    }

    /** A real .xlsx/.docx: Office zip with its main part, no macros, no zip bomb. */
    private function checkOffice(string $path, string $ext): bool
    {
        if ((string) file_get_contents($path, false, null, 0, 4) !== "PK\x03\x04") {
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->numFiles > 500 || $zip->locateName('[Content_Types].xml') === false) {
                return false;
            }
            $main = $ext === self::XLSX ? 'xl/workbook.xml' : 'word/document.xml';
            if ($zip->locateName($main) === false) {
                return false;
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > 50 * 1024 * 1024 || str_contains(strtolower((string) $stat['name']), 'vbaproject.bin')) {
                    return false; // zip bomb or macros
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }
}
