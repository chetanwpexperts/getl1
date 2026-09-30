<?php

namespace Tests\Unit;

use App\Support\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class SpreadsheetReaderTest extends TestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ssr');
        file_put_contents($path, $contents);

        return $this->tmp[] = $path;
    }

    /** Minimal real XLSX: shared strings + a numeric cell + an inline string. */
    public static function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_int($value)) {
                    $cells .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                } else {
                    $strings[] = htmlspecialchars((string) $value, ENT_XML1);
                    $cells .= "<c r=\"{$ref}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    public function test_reads_xlsx(): void
    {
        $path = $this->tmp[] = self::makeXlsx([
            ['Company name', 'Mobile'],
            ['Sharma & Sons', 9876543210],
        ]);

        $this->assertSame([['Company name', 'Mobile'], ['Sharma & Sons', '9876543210']], SpreadsheetReader::read($path, 'xlsx'));
    }

    public function test_reads_csv_with_bom_and_quotes(): void
    {
        $path = $this->file("\xEF\xBB\xBFcompany,phone\n\"Tricity, Packers\",9876500001\n\n");

        $this->assertSame([['company', 'phone'], ['Tricity, Packers', '9876500001']], SpreadsheetReader::read($path, 'csv'));
    }

    public function test_rejects_binary_disguised_as_csv(): void
    {
        $this->expectException(RuntimeException::class);
        SpreadsheetReader::read($this->file("MZ\0\0\0binary"), 'csv');
    }

    public function test_rejects_non_zip_xlsx(): void
    {
        $this->expectException(RuntimeException::class);
        SpreadsheetReader::read($this->file('<?php echo 1;'), 'xlsx');
    }

    public function test_rejects_xml_entities_xxe(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xxe');
        $this->tmp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        $this->expectException(RuntimeException::class);
        SpreadsheetReader::read($path, 'xlsx');
    }

    public function test_caps_rows(): void
    {
        $path = $this->file(str_repeat("a,9876543210\n", SpreadsheetReader::MAX_ROWS + 1));

        $this->expectException(RuntimeException::class);
        SpreadsheetReader::read($path, 'csv');
    }
}
