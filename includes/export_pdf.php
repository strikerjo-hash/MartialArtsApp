<?php
/**
 * includes/export_pdf.php — PDF generation helper using DomPDF.
 *
 * Provides reusable functions for generating PDF downloads from HTML content.
 * Used by report_export_pdf.php and any other page that needs PDF output.
 *
 * Depends on: libs/autoload.php (DomPDF autoloader)
 */

// Prevent double-loading (use define guard instead of function_exists because
// PHP hoists top-level function declarations at compile time, which causes
// function_exists to return true before the autoloader is loaded).
if (defined('PDF_EXPORT_LOADED')) {
    return;
}
define('PDF_EXPORT_LOADED', true);

// Load DomPDF autoloader (use realpath to avoid Windows path issues with require_once)
$_dompdfAutoload = realpath(__DIR__ . '/../libs/autoload.php');
if ($_dompdfAutoload && file_exists($_dompdfAutoload)) {
    require_once $_dompdfAutoload;
} else {
    // Fallback: try direct path
    $fallback = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($fallback)) {
        require_once $fallback;
    }
}

// Verify DomPDF loaded — show clear error if library is missing
if (!class_exists('Dompdf\\Options')) {
    function generatePdf(string $html, string $filename, string $orientation = 'landscape', string $paperSize = 'letter'): void {
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo '<div style="font-family:system-ui,sans-serif;max-width:500px;margin:60px auto;text-align:center;">';
        echo '<h2 style="color:#dc2626;">PDF Export Not Available</h2>';
        echo '<p>DomPDF library was not found. Install it by running these commands in the <code>libs/</code> folder:</p>';
        echo '<pre style="background:#f3f4f6;padding:12px;border-radius:6px;text-align:left;font-size:12px;">';
        echo "cd libs\n";
        echo "curl -L -o dompdf.zip https://github.com/dompdf/dompdf/archive/refs/tags/v2.0.8.zip\n";
        echo "unzip dompdf.zip && mv dompdf-2.0.8 dompdf && rm dompdf.zip\n\n";
        echo "curl -L -o fontlib.zip https://github.com/dompdf/php-font-lib/archive/refs/tags/0.5.6.zip\n";
        echo "unzip fontlib.zip && mv php-font-lib-0.5.6 php-font-lib && rm fontlib.zip\n\n";
        echo "curl -L -o svglib.zip https://github.com/dompdf/php-svg-lib/archive/refs/tags/0.5.4.zip\n";
        echo "unzip svglib.zip && mv php-svg-lib-0.5.4 php-svg-lib && rm svglib.zip\n\n";
        echo "curl -L -o html5.zip https://github.com/Masterminds/html5-php/archive/refs/tags/2.9.0.zip\n";
        echo "unzip html5.zip && mv html5-php-2.9.0 html5-php && rm html5.zip</pre>";
        echo '<p>See <a href="INSTALL.md">INSTALL.md</a> for full instructions.</p>';
        echo '<p><a href="javascript:history.back()" style="color:#2563eb;">&larr; Go Back</a></p>';
        echo '</div>';
        exit;
    }
    function buildPdfHtml(string $title, string $schoolName, string $dateRange, string $bodyHtml): string { return ''; }
    return;
}

/**
 * Generate and stream a PDF from HTML content.
 *
 * @param string $html        Full HTML document to render
 * @param string $filename    Download filename (e.g., 'revenue_report_2026-02.pdf')
 * @param string $orientation 'portrait' or 'landscape'
 * @param string $paperSize   'letter', 'A4', etc.
 */
function generatePdf(string $html, string $filename, string $orientation = 'landscape', string $paperSize = 'letter'): void
{
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $options->setTempDir(sys_get_temp_dir());

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper($paperSize, $orientation);
    $dompdf->render();

    // Add page numbers
    // DomPDF page_script provides: $PAGE_NUM, $PAGE_COUNT, $pdf (canvas), $fontMetrics
    $dompdf->getCanvas()->page_script('
        $font = $fontMetrics->getFont("Helvetica", "normal");
        $size = 8;
        $pageText = "Page " . $PAGE_NUM . " of " . $PAGE_COUNT;
        $width = $fontMetrics->getTextWidth($pageText, $font, $size);
        $pdf->text($pdf->get_width() - $width - 36, $pdf->get_height() - 28, $pageText, $font, $size, array(0.5, 0.5, 0.5));
    ');

    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

/**
 * Build a standalone HTML document suitable for PDF rendering.
 * Provides inline CSS that mimics basic report styling for print.
 *
 * DomPDF cannot use Tailwind CSS (CDN-based, requires JS). Instead, this
 * function provides a self-contained HTML document with an embedded stylesheet
 * that approximates the report appearance.
 *
 * @param string $title      Report title (e.g., "Revenue Report")
 * @param string $schoolName School name for the header
 * @param string $dateRange  Date range label (e.g., "Feb 1 – Feb 28, 2026")
 * @param string $bodyHtml   The report body HTML (tables, sections, etc.)
 * @return string            Complete HTML document ready for DomPDF
 */
function buildPdfHtml(string $title, string $schoolName, string $dateRange, string $bodyHtml): string
{
    $timestamp = date('M j, Y g:i A');
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        /* === Base === */
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 30px 36px;
            line-height: 1.4;
        }

        /* === Header === */
        .report-header {
            border-bottom: 3px solid #1f2937;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .report-header h1 {
            font-size: 22px;
            margin: 0 0 4px;
            color: #111827;
        }
        .report-meta {
            font-size: 9px;
            color: #6b7280;
        }

        /* === Section headings === */
        h2 {
            font-size: 14px;
            color: #1f2937;
            margin: 20px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }
        h3 {
            font-size: 12px;
            color: #374151;
            margin: 14px 0 6px;
        }

        /* === Tables === */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 16px;
            font-size: 9px;
        }
        th {
            background-color: #f3f4f6;
            text-align: left;
            padding: 6px 8px;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            border-bottom: 2px solid #d1d5db;
            font-weight: 600;
        }
        td {
            padding: 5px 8px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        /* === KPI summary cards === */
        .kpi-row {
            margin: 10px 0 18px;
        }
        .kpi-row table {
            margin: 0;
        }
        .kpi-row td {
            text-align: center;
            border: 1px solid #e5e7eb;
            padding: 10px 12px;
            background-color: #f9fafb;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            display: block;
        }
        .kpi-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            display: block;
            margin-top: 2px;
        }

        /* === Utility classes === */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .font-bold { font-weight: 700; }
        .font-medium { font-weight: 500; }

        .text-green { color: #059669; }
        .text-red { color: #dc2626; }
        .text-yellow { color: #d97706; }
        .text-blue { color: #2563eb; }
        .text-gray { color: #6b7280; }

        .text-sm { font-size: 9px; }
        .text-xs { font-size: 8px; }
        .text-lg { font-size: 13px; }

        .mt-4 { margin-top: 16px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }

        /* === Progress bars (CSS-only) === */
        .progress-bar {
            display: inline-block;
            width: 80px;
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            vertical-align: middle;
        }
        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }
        .progress-green { background-color: #059669; }
        .progress-yellow { background-color: #d97706; }
        .progress-red { background-color: #dc2626; }
        .progress-blue { background-color: #2563eb; }

        /* === Page break === */
        .page-break { page-break-before: always; }

        /* === Badge === */
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 600;
        }
        .badge-green { background-color: #d1fae5; color: #065f46; }
        .badge-red { background-color: #fee2e2; color: #991b1b; }
        .badge-yellow { background-color: #fef3c7; color: #92400e; }
        .badge-blue { background-color: #dbeafe; color: #1e40af; }
        .badge-gray { background-color: #f3f4f6; color: #374151; }

        /* === No data message === */
        .no-data {
            text-align: center;
            color: #9ca3af;
            font-style: italic;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>{$title}</h1>
        <div class="report-meta">{$schoolName} &mdash; {$dateRange} &mdash; Generated: {$timestamp}</div>
    </div>
    {$bodyHtml}
</body>
</html>
HTML;
}
