<?php
/**
 * includes/report_helpers.php — Shared helper functions for reports.
 *
 * Provides date-range parsing and common formatting used by both
 * reports.php and the report export endpoints (PDF/Excel).
 *
 * Depends on: config.php (formatDate, formatMoney, etc.)
 */

/**
 * Parse the date range from GET parameters.
 *
 * @return array [$report_range, $date_from, $date_to, $range_label]
 */
function parseReportDateRange(): array
{
    $report_range = $_GET['range'] ?? 'this_month';
    $date_from    = $_GET['date_from'] ?? '';
    $date_to      = $_GET['date_to'] ?? '';

    switch ($report_range) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to   = date('Y-m-d');
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to   = date('Y-m-t');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('-1 month'));
            $date_to   = date('Y-m-t', strtotime('-1 month'));
            break;
        case 'this_quarter':
            $quarter   = ceil(date('n') / 3);
            $date_from = date('Y-' . str_pad(($quarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01');
            $date_to   = date('Y-m-t', strtotime($date_from . ' +2 months'));
            break;
        case 'this_year':
            $date_from = date('Y-01-01');
            $date_to   = date('Y-12-31');
            break;
        case 'last_year':
            $date_from = date('Y-01-01', strtotime('-1 year'));
            $date_to   = date('Y-12-31', strtotime('-1 year'));
            break;
        case 'custom':
            if (empty($date_from)) $date_from = date('Y-m-01');
            if (empty($date_to))   $date_to   = date('Y-m-d');
            break;
        default:
            $report_range = 'this_month';
            $date_from    = date('Y-m-01');
            $date_to      = date('Y-m-t');
            break;
    }

    // Build human-readable label
    $range_label = match ($report_range) {
        'today'        => 'Today',
        'this_week'    => 'This Week',
        'this_month'   => 'This Month',
        'last_month'   => 'Last Month',
        'this_quarter' => 'This Quarter',
        'this_year'    => 'This Year',
        'last_year'    => 'Last Year',
        'custom'       => formatDate($date_from) . ' – ' . formatDate($date_to),
        default        => 'This Month',
    };

    return [$report_range, $date_from, $date_to, $range_label];
}

/**
 * Build the URL query string for export links that preserves the current
 * date range parameters.
 *
 * @param string $tab           The report tab name (e.g., 'revenue')
 * @param string $report_range  The range key
 * @param string $date_from     Start date
 * @param string $date_to       End date
 * @return string               URL query string (already encoded)
 */
function buildExportQuery(string $tab, string $report_range, string $date_from, string $date_to): string
{
    return http_build_query([
        'tab'       => $tab,
        'range'     => $report_range,
        'date_from' => $date_from,
        'date_to'   => $date_to,
    ]);
}

/**
 * Render a set of export buttons (PDF + Excel) for a report tab.
 * Returns HTML string for inclusion in tab headers.
 *
 * @param string $tab           Tab name
 * @param string $report_range  Range key
 * @param string $date_from     Start date
 * @param string $date_to       End date
 * @return string               HTML for PDF and Excel download buttons
 */
function renderExportButtons(string $tab, string $report_range, string $date_from, string $date_to): string
{
    $query = buildExportQuery($tab, $report_range, $date_from, $date_to);

    return <<<HTML
<div class="flex gap-2">
    <a href="report_export_pdf.php?{$query}"
       class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-lg transition-colors"
       title="Download PDF report">
        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        PDF
    </a>
    <a href="report_export_excel.php?{$query}"
       class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-lg transition-colors"
       title="Download Excel report">
        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Excel
    </a>
</div>
HTML;
}

/**
 * Format a number as a percentage string for display.
 *
 * @param float|int|null $value  The percentage value
 * @param int            $decimals Number of decimal places
 * @return string                 Formatted string (e.g., "85.5%")
 */
function formatPercent($value, int $decimals = 1): string
{
    if ($value === null || $value === '') return '–';
    return number_format((float) $value, $decimals) . '%';
}

/**
 * Return a CSS color class based on a rate/percentage threshold.
 *
 * @param float $rate  The rate (0-100)
 * @param float $good  Threshold for "good" (default 80)
 * @param float $warn  Threshold for "warning" (default 60)
 * @return string      'text-green' | 'text-yellow' | 'text-red'
 */
function rateColorClass(float $rate, float $good = 80.0, float $warn = 60.0): string
{
    if ($rate >= $good) return 'text-green';
    if ($rate >= $warn) return 'text-yellow';
    return 'text-red';
}
