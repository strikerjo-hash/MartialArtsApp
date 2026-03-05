<?php
/**
 * includes/export_excel.php — Excel (.xlsx) export helper using PhpSpreadsheet.
 *
 * Provides a reusable function for generating formatted Excel downloads
 * from arrays of data. Used by report_export_excel.php and export_data.php.
 *
 * Depends on: vendor/autoload.php (PhpSpreadsheet via Composer)
 *
 * INSTALLATION:
 *   cd C:\xampp\htdocs\Procomp  (or wherever the app root is)
 *   composer install
 */

// Prevent double-loading (use define guard instead of function_exists because
// PHP hoists top-level function declarations at compile time, which causes
// function_exists to return true before the autoloader is loaded).
if (defined('EXCEL_EXPORT_LOADED')) {
    return;
}
define('EXCEL_EXPORT_LOADED', true);

$_excelNotInstalled = function(): void {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<div style="font-family:system-ui,sans-serif;max-width:500px;margin:60px auto;text-align:center;">';
    echo '<h2 style="color:#dc2626;">Excel Export Not Available</h2>';
    echo '<p>PhpSpreadsheet is not installed. To enable Excel exports, run this command in the application root folder:</p>';
    echo '<pre style="background:#f3f4f6;padding:12px;border-radius:6px;text-align:left;">cd ' . htmlspecialchars(dirname(__DIR__)) . "\n" . 'composer install</pre>';
    echo '<p>If Composer is not installed, <a href="https://getcomposer.org/download/">download it here</a>.</p>';
    echo '<p><a href="javascript:history.back()" style="color:#2563eb;">← Go Back</a></p>';
    echo '</div>';
    exit;
};

$vendorAutoload = realpath(__DIR__ . '/../vendor/autoload.php');
if (!$vendorAutoload || !file_exists($vendorAutoload)) {
    // Try alternative path
    $vendorAutoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}
if (!file_exists($vendorAutoload)) {
    $notInstalledFn = $_excelNotInstalled;
    function generateExcel(string $filename, array $sheets): void {
        // Capture message via closure is not possible here, so replicate inline
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo '<div style="font-family:system-ui,sans-serif;max-width:500px;margin:60px auto;text-align:center;">';
        echo '<h2 style="color:#dc2626;">Excel Export Not Available</h2>';
        echo '<p>PhpSpreadsheet is not installed. To enable Excel exports, run <code>composer install</code> in the application root folder.</p>';
        echo '<p>If Composer is not installed, <a href="https://getcomposer.org/download/">download it here</a>.</p>';
        echo '<p><a href="javascript:history.back()" style="color:#2563eb;">&larr; Go Back</a></p>';
        echo '</div>';
        exit;
    }
    function colLetter(int $index): string { $l=''; $index++; while($index>0){$index--;$l=chr(65+($index%26)).$l;$index=intdiv($index,26);} return $l; }
    return;
}

require_once $vendorAutoload;

// Verify PhpSpreadsheet is actually available after autoload
if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    function generateExcel(string $filename, array $sheets): void {
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo '<div style="font-family:system-ui,sans-serif;max-width:500px;margin:60px auto;text-align:center;">';
        echo '<h2 style="color:#dc2626;">Excel Export Not Available</h2>';
        echo '<p>PhpSpreadsheet was not found in vendor/. Run <code>composer install</code> in the application root folder.</p>';
        echo '<p><a href="javascript:history.back()" style="color:#2563eb;">&larr; Go Back</a></p>';
        echo '</div>';
        exit;
    }
    function colLetter(int $index): string { $l=''; $index++; while($index>0){$index--;$l=chr(65+($index%26)).$l;$index=intdiv($index,26);} return $l; }
    return;
}

/**
 * Convert a 0-based column index to an Excel column letter (A, B, ... Z, AA, AB, ...).
 */
function colLetter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }
    return $letter;
}

/**
 * Create and stream an Excel file from tabular data.
 *
 * @param string $filename  Download filename without extension
 * @param array  $sheets    Array of sheet definitions:
 *                          [
 *                            'title'   => 'Sheet Name' (max 31 chars),
 *                            'headers' => ['Col A', 'Col B', ...],
 *                            'rows'    => [['val1', 'val2', ...], ...],
 *                            'formats' => [0 => 'currency', 3 => 'date', 5 => 'percent'] (optional, col index => format)
 *                          ]
 */
function generateExcel(string $filename, array $sheets): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('MartialArtsApp Reports')
        ->setTitle($filename);

    $sheetIndex = 0;
    foreach ($sheets as $sheetData) {
        if ($sheetIndex > 0) {
            $spreadsheet->createSheet($sheetIndex);
        }
        $worksheet = $spreadsheet->setActiveSheetIndex($sheetIndex);

        // Sheet title (max 31 chars, no special chars)
        $safeTitle = substr(preg_replace('/[\\\\\/\*\?\[\]\:]/', '', $sheetData['title'] ?? 'Sheet ' . ($sheetIndex + 1)), 0, 31);
        $worksheet->setTitle($safeTitle);

        $headers = $sheetData['headers'] ?? [];
        $rows    = $sheetData['rows'] ?? [];
        $formats = $sheetData['formats'] ?? [];

        if (empty($headers)) {
            $sheetIndex++;
            continue;
        }

        $colCount = count($headers);
        $lastCol  = colLetter($colCount - 1);

        // --- Write headers (row 1) ---
        foreach ($headers as $colIdx => $header) {
            $cell = colLetter($colIdx) . '1';
            $worksheet->setCellValue($cell, $header);
        }

        // Style header row
        $headerRange = "A1:{$lastCol}1";
        $headerStyle = $worksheet->getStyle($headerRange);
        $headerStyle->getFont()->setBold(true)->setSize(10);
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F3F4F6');
        $headerStyle->getBorders()->getBottom()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)
            ->getColor()->setRGB('D1D5DB');

        // --- Write data rows (starting row 2) ---
        $rowNum = 2;
        foreach ($rows as $row) {
            $row = array_values($row); // ensure 0-indexed
            foreach ($row as $colIdx => $value) {
                $cell = colLetter($colIdx) . $rowNum;
                $worksheet->setCellValue($cell, $value);
            }
            $rowNum++;
        }

        $lastDataRow = max(2, $rowNum - 1);

        // --- Apply column formats ---
        foreach ($formats as $col => $format) {
            $colLtr = is_numeric($col) ? colLetter((int) $col) : $col;
            $range  = "{$colLtr}2:{$colLtr}{$lastDataRow}";

            switch ($format) {
                case 'currency':
                    $worksheet->getStyle($range)->getNumberFormat()
                        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
                    $worksheet->getStyle($range)->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                    break;
                case 'date':
                    $worksheet->getStyle($range)->getNumberFormat()
                        ->setFormatCode('YYYY-MM-DD');
                    break;
                case 'percent':
                    $worksheet->getStyle($range)->getNumberFormat()
                        ->setFormatCode('0.0%');
                    break;
                case 'number':
                    $worksheet->getStyle($range)->getNumberFormat()
                        ->setFormatCode('#,##0');
                    $worksheet->getStyle($range)->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                    break;
                case 'percent_display':
                    // Already formatted as "85.5%" string, just right-align
                    $worksheet->getStyle($range)->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                    break;
            }
        }

        // --- Auto-size columns ---
        foreach (range(0, $colCount - 1) as $colIdx) {
            $worksheet->getColumnDimension(colLetter($colIdx))->setAutoSize(true);
        }

        // --- Freeze header row ---
        $worksheet->freezePane('A2');

        $sheetIndex++;
    }

    $spreadsheet->setActiveSheetIndex(0);

    // Stream to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    exit;
}
