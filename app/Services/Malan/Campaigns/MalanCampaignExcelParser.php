<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Parse campaign contact spreadsheets (CSV / XLSX) into name+phone(+city) rows.
 */
class MalanCampaignExcelParser
{
    /**
     * @return list<array{name:?string,phone:string,city:?string}>
     */
    public function parse(UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Could not read uploaded file.');
        }

        return match ($ext) {
            'csv', 'txt' => $this->parseCsv($path),
            'xlsx' => $this->parseXlsx($path),
            default => throw new RuntimeException('Supported formats: .xlsx, .csv'),
        };
    }

    /**
     * @return list<array{name:?string,phone:string,city:?string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open CSV file.');
        }

        $rows = [];
        $headers = null;
        try {
            while (($cols = fgetcsv($handle)) !== false) {
                if ($cols === [null] || $cols === false) {
                    continue;
                }
                $normalized = array_map(fn ($v) => trim((string) $v), $cols);
                if ($headers === null) {
                    $headers = $this->mapHeaders($normalized);
                    // If first row looks like data (has a phone), treat as data.
                    if ($headers === null) {
                        $row = $this->rowFromValues($normalized);
                        if ($row !== null) {
                            $rows[] = $row;
                        }
                        $headers = ['name' => 0, 'phone' => 1, 'city' => 2];
                    }
                    continue;
                }

                $row = $this->rowFromMapped($normalized, $headers);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->uniqueByPhone($rows);
    }

    /**
     * Minimal XLSX reader (first sheet, shared strings).
     *
     * @return list<array{name:?string,phone:string,city:?string}>
     */
    private function parseXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open XLSX file.');
        }

        try {
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($sharedXml) && $sharedXml !== '') {
                $shared = $this->parseSharedStrings($sharedXml);
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (! is_string($sheetXml) || $sheetXml === '') {
                throw new RuntimeException('XLSX has no sheet1.');
            }

            $matrix = $this->parseSheetRows($sheetXml, $shared);
        } finally {
            $zip->close();
        }

        if ($matrix === []) {
            return [];
        }

        $headers = $this->mapHeaders($matrix[0]);
        $start = 1;
        if ($headers === null) {
            $headers = ['name' => 0, 'phone' => 1, 'city' => 2];
            $start = 0;
        }

        $rows = [];
        for ($i = $start; $i < count($matrix); $i++) {
            $row = $this->rowFromMapped($matrix[$i], $headers);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $this->uniqueByPhone($rows);
    }

    /**
     * @return list<string>
     */
    private function parseSharedStrings(string $xml): array
    {
        $out = [];
        if (! preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $matches)) {
            return $out;
        }

        foreach ($matches[1] as $si) {
            if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $si, $tMatches)) {
                $out[] = html_entity_decode(implode('', $tMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            } else {
                $out[] = '';
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function parseSheetRows(string $xml, array $shared): array
    {
        $rows = [];
        if (! preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $xml, $rowMatches)) {
            return $rows;
        }

        foreach ($rowMatches[1] as $rowXml) {
            $cells = [];
            if (! preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                $rows[] = [];
                continue;
            }

            foreach ($cellMatches as $cell) {
                $attrs = $cell[1];
                $inner = $cell[2];
                $col = 0;
                if (preg_match('/\br="([A-Z]+)\d+"/i', $attrs, $ref)) {
                    $col = $this->columnIndex($ref[1]);
                }
                $type = null;
                if (preg_match('/\bt="([^"]+)"/', $attrs, $t)) {
                    $type = $t[1];
                }

                $value = '';
                if ($type === 'inlineStr') {
                    if (preg_match('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $inner, $tm)) {
                        $value = html_entity_decode($tm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    $raw = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($type === 's') {
                        $idx = (int) $raw;
                        $value = $shared[$idx] ?? '';
                    } else {
                        $value = $raw;
                    }
                }

                $cells[$col] = trim($value);
            }

            if ($cells === []) {
                continue;
            }
            ksort($cells);
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * @param  list<string>  $cols
     * @return array{name:?int,phone:?int,city:?int}|null
     */
    private function mapHeaders(array $cols): ?array
    {
        $map = ['name' => null, 'phone' => null, 'city' => null];
        $found = false;
        foreach ($cols as $i => $raw) {
            $h = Str::lower(trim($raw));
            $h = str_replace(['_', '-', ' '], '', $h);
            if (in_array($h, ['name', 'fullname', 'fullnameالاسم', 'fullnameالكامل', 'fullnameالزبون', 'fullnameالعميل', 'fullnameالشخص'], true)
                || str_contains($h, 'name')
                || str_contains($h, 'اسم')) {
                $map['name'] = $i;
                $found = true;
            } elseif (in_array($h, ['phone', 'mobile', 'tel', 'telephone', 'whatsapp', 'رقم', 'جوال', 'موبايل', 'تلفون', 'هاتف', 'phonenumber', 'رقمالهاتف', 'رقمجوال'], true)
                || str_contains($h, 'phone')
                || str_contains($h, 'mobile')
                || str_contains($h, 'رقم')) {
                $map['phone'] = $i;
                $found = true;
            } elseif (in_array($h, ['city', 'town', 'area', 'بلده', 'بلدة', 'مدينة', 'المنطقة', 'منطقة'], true)
                || str_contains($h, 'city')
                || str_contains($h, 'بلد')
                || str_contains($h, 'مدين')) {
                $map['city'] = $i;
                $found = true;
            }
        }

        if (! $found || $map['phone'] === null) {
            // Heuristic: if any cell looks like a phone, this isn't a header row.
            foreach ($cols as $col) {
                if ($this->looksLikePhone($col)) {
                    return null;
                }
            }

            return null;
        }

        return $map;
    }

    /**
     * @param  list<string>  $cols
     * @param  array{name:?int,phone:?int,city:?int}  $headers
     * @return array{name:?string,phone:string,city:?string}|null
     */
    private function rowFromMapped(array $cols, array $headers): ?array
    {
        $phoneIdx = $headers['phone'] ?? 1;
        $nameIdx = $headers['name'] ?? 0;
        $cityIdx = $headers['city'] ?? null;

        $phone = isset($cols[$phoneIdx]) ? trim((string) $cols[$phoneIdx]) : '';
        if ($phone === '' || ! $this->looksLikePhone($phone)) {
            // Fallback: scan row for a phone-like cell.
            foreach ($cols as $idx => $val) {
                if ($this->looksLikePhone($val)) {
                    $phone = trim((string) $val);
                    if ($nameIdx === $idx) {
                        $nameIdx = $idx === 0 ? 1 : 0;
                    }
                    break;
                }
            }
        }

        if ($phone === '' || ! $this->looksLikePhone($phone)) {
            return null;
        }

        $name = isset($cols[$nameIdx]) ? trim((string) $cols[$nameIdx]) : null;
        if ($name === '' || $this->looksLikePhone($name)) {
            $name = null;
        }

        $city = null;
        if ($cityIdx !== null && isset($cols[$cityIdx])) {
            $city = trim((string) $cols[$cityIdx]);
            $city = $city !== '' ? $city : null;
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'city' => $city,
        ];
    }

    /**
     * @param  list<string>  $cols
     * @return array{name:?string,phone:string,city:?string}|null
     */
    private function rowFromValues(array $cols): ?array
    {
        return $this->rowFromMapped($cols, ['name' => 0, 'phone' => 1, 'city' => 2]);
    }

    private function looksLikePhone(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }

    /**
     * @param  list<array{name:?string,phone:string,city:?string}>  $rows
     * @return list<array{name:?string,phone:string,city:?string}>
     */
    private function uniqueByPhone(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $digits = preg_replace('/\D+/', '', $row['phone']) ?? '';
            if ($digits === '' || isset($seen[$digits])) {
                continue;
            }
            $seen[$digits] = true;
            $out[] = $row;
        }

        return $out;
    }
}
