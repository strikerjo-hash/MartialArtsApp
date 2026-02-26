<?php
require_once 'config.php';
require_once __DIR__ . '/includes/security.php';
requireLogin();

// Admin / Super Admin access
if (!in_array(getCurrentUser()['role'], ['admin', 'super_admin'])) {
    accessDenied('Data export requires Admin or Super Admin privileges.');
}

$pdo = get_db();

// =====================================================================
//  Shared Query Functions (return headers + rows for both CSV and Excel)
// =====================================================================

function fetchStudentsData(PDO $pdo, string $dateFrom, string $dateTo, string $status): array
{
    $headers = [
        'ID', 'Username', 'First Name', 'Last Name', 'Email', 'Phone',
        'Belt Rank', 'Join Date', 'Date of Birth', 'Status', 'Address',
        'Emergency Contact', 'Emergency Phone', 'Notes', 'Is Parent', 'Created At'
    ];

    $query = "SELECT * FROM students WHERE 1=1";
    $params = [];

    if ($dateFrom) {
        $query .= " AND join_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $query .= " AND join_date <= ?";
        $params[] = $dateTo;
    }
    if ($status) {
        $query .= " AND status = ?";
        $params[] = $status;
    }

    $query .= school_where();
    school_param($params);
    $query .= " ORDER BY last_name, first_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch()) {
        $rows[] = [
            $row['id'],
            $row['username'] ?? '',
            $row['first_name'],
            $row['last_name'],
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['belt_rank'] ?? 'White',
            $row['join_date'] ?? '',
            $row['date_of_birth'] ?? '',
            $row['status'] ?? 'active',
            $row['address'] ?? '',
            $row['emergency_contact_name'] ?? '',
            $row['emergency_contact_phone'] ?? '',
            $row['notes'] ?? '',
            $row['is_parent'] ?? 0,
            $row['created_at'] ?? '',
        ];
    }

    return ['headers' => $headers, 'rows' => $rows];
}

// =====================================================================
//  CSV Export Functions
// =====================================================================

function exportStudents(PDO $pdo, string $dateFrom, string $dateTo, string $status): void
{
    $data = fetchStudentsData($pdo, $dateFrom, $dateTo, $status);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $data['headers']);
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function fetchParentsData(PDO $pdo): array
{
    $headers = [
        'Parent ID', 'Username', 'First Name', 'Last Name', 'Email', 'Phone',
        'Status', 'Children', 'Child Count', 'Join Date'
    ];

    $parentSql = "
        SELECT s.*,
               GROUP_CONCAT(DISTINCT CONCAT(cs.first_name, ' ', cs.last_name) ORDER BY cs.first_name SEPARATOR '; ') as children,
               COUNT(DISTINCT ps.student_id) as child_count
        FROM students s
        LEFT JOIN parent_students ps ON ps.parent_id = s.id
        LEFT JOIN students cs ON cs.id = ps.student_id
        WHERE s.is_parent = 1" . school_where('s') . "
        GROUP BY s.id
        ORDER BY s.last_name, s.first_name
    ";
    $parentParams = [];
    school_param($parentParams);
    $parentStmt = $pdo->prepare($parentSql);
    $parentStmt->execute($parentParams);
    $parents = $parentStmt->fetchAll();

    $rows = [];
    foreach ($parents as $p) {
        $rows[] = [
            $p['id'],
            $p['username'] ?? '',
            $p['first_name'],
            $p['last_name'],
            $p['email'] ?? '',
            $p['phone'] ?? '',
            $p['status'] ?? 'active',
            $p['children'] ?? '',
            $p['child_count'],
            $p['join_date'] ?? '',
        ];
    }

    return ['headers' => $headers, 'rows' => $rows];
}

function exportParents(PDO $pdo): void
{
    $data = fetchParentsData($pdo);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parents_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $data['headers']);
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function fetchMembershipsData(PDO $pdo, string $dateFrom, string $dateTo, string $status): array
{
    $headers = [
        'Membership ID', 'Student First Name', 'Student Last Name', 'Student Email',
        'Plan Name', 'Plan Price', 'Billing Frequency', 'Start Date', 'End Date',
        'Status', 'Payment Status', 'Amount Paid', 'Auto Renew', 'Created At'
    ];

    $query = "
        SELECT m.*, s.first_name, s.last_name, s.email,
               mp.name as plan_name, mp.price as plan_price, mp.billing_frequency
        FROM memberships m
        JOIN students s ON m.student_id = s.id
        JOIN membership_plans mp ON m.plan_id = mp.id
        WHERE 1=1
    ";
    $params = [];

    if ($dateFrom) {
        $query .= " AND m.start_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $query .= " AND m.start_date <= ?";
        $params[] = $dateTo;
    }
    if ($status) {
        $query .= " AND m.status = ?";
        $params[] = $status;
    }

    $query .= school_where('m');
    school_param($params);
    $query .= " ORDER BY m.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch()) {
        $rows[] = [
            $row['id'],
            $row['first_name'],
            $row['last_name'],
            $row['email'] ?? '',
            $row['plan_name'],
            $row['plan_price'],
            $row['billing_frequency'] ?? 'upfront',
            $row['start_date'],
            $row['end_date'],
            $row['status'],
            $row['payment_status'],
            $row['amount_paid'],
            isset($row['auto_renew']) ? ($row['auto_renew'] ? 'Yes' : 'No') : 'Yes',
            $row['created_at'] ?? '',
        ];
    }

    return ['headers' => $headers, 'rows' => $rows, 'formats' => [5 => 'currency', 11 => 'currency']];
}

function exportMemberships(PDO $pdo, string $dateFrom, string $dateTo, string $status): void
{
    $data = fetchMembershipsData($pdo, $dateFrom, $dateTo, $status);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="memberships_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $data['headers']);
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function fetchPaymentsData(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $headers = [
        'Payment ID', 'Receipt Number', 'Payment Date', 'Student First Name', 'Student Last Name',
        'Payment Type', 'Payment Method', 'Amount', 'Notes', 'Created At'
    ];

    $query = "
        SELECT p.*, s.first_name, s.last_name
        FROM payments p
        JOIN students s ON p.student_id = s.id
        WHERE 1=1
    ";
    $params = [];

    if ($dateFrom) {
        $query .= " AND p.payment_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $query .= " AND p.payment_date <= ?";
        $params[] = $dateTo;
    }

    $query .= school_where('p');
    school_param($params);
    $query .= " ORDER BY p.payment_date DESC, p.id DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch()) {
        $rows[] = [
            $row['id'],
            $row['receipt_number'] ?? '',
            $row['payment_date'],
            $row['first_name'],
            $row['last_name'],
            $row['payment_type'],
            $row['payment_method'],
            $row['amount'],
            $row['notes'] ?? '',
            $row['created_at'] ?? '',
        ];
    }

    return ['headers' => $headers, 'rows' => $rows, 'formats' => [7 => 'currency']];
}

function exportPayments(PDO $pdo, string $dateFrom, string $dateTo): void
{
    $data = fetchPaymentsData($pdo, $dateFrom, $dateTo);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $data['headers']);
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function fetchFullData(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $headers = [
        'Student ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Belt Rank',
        'Join Date', 'Date of Birth', 'Status', 'Address',
        'Emergency Contact', 'Emergency Phone',
        'Parent Name', 'Active Plan', 'Membership Status',
        'Total Payments', 'Last Payment Date', 'Notes'
    ];

    $query = "
        SELECT s.*,
               parent_info.parent_name,
               active_plan.plan_name,
               active_plan.membership_status,
               payment_info.total_payments,
               payment_info.last_payment_date
        FROM students s

        LEFT JOIN (
            SELECT ps.student_id,
                   GROUP_CONCAT(DISTINCT CONCAT(p.first_name, ' ', p.last_name) SEPARATOR '; ') as parent_name
            FROM parent_students ps
            JOIN students p ON p.id = ps.parent_id
            GROUP BY ps.student_id
        ) parent_info ON parent_info.student_id = s.id

        LEFT JOIN (
            SELECT m.student_id,
                   mp.name as plan_name,
                   m.status as membership_status
            FROM memberships m
            JOIN membership_plans mp ON m.plan_id = mp.id
            WHERE m.status = 'active'
              AND m.end_date >= CURDATE()
        ) active_plan ON active_plan.student_id = s.id

        LEFT JOIN (
            SELECT student_id,
                   SUM(amount) as total_payments,
                   MAX(payment_date) as last_payment_date
            FROM payments
            GROUP BY student_id
        ) payment_info ON payment_info.student_id = s.id

        WHERE s.is_parent = 0
    ";
    $params = [];

    if ($dateFrom) {
        $query .= " AND s.join_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $query .= " AND s.join_date <= ?";
        $params[] = $dateTo;
    }

    $query .= school_where('s');
    school_param($params);
    $query .= " ORDER BY s.last_name, s.first_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch()) {
        $rows[] = [
            $row['id'],
            $row['first_name'],
            $row['last_name'],
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['belt_rank'] ?? 'White',
            $row['join_date'] ?? '',
            $row['date_of_birth'] ?? '',
            $row['status'] ?? 'active',
            $row['address'] ?? '',
            $row['emergency_contact_name'] ?? '',
            $row['emergency_contact_phone'] ?? '',
            $row['parent_name'] ?? '',
            $row['plan_name'] ?? 'None',
            $row['membership_status'] ?? 'N/A',
            $row['total_payments'] ? number_format((float) $row['total_payments'], 2) : '0.00',
            $row['last_payment_date'] ?? '',
            $row['notes'] ?? '',
        ];
    }

    return ['headers' => $headers, 'rows' => $rows, 'formats' => [15 => 'currency']];
}

function exportFull(PDO $pdo, string $dateFrom, string $dateTo): void
{
    $data = fetchFullData($pdo, $dateFrom, $dateTo);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="full_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $data['headers']);
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

// =====================================================================
//  Excel Export Functions
// =====================================================================

function exportStudentsExcel(PDO $pdo, string $dateFrom, string $dateTo, string $status): void
{
    require_once __DIR__ . '/includes/export_excel.php';
    $data = fetchStudentsData($pdo, $dateFrom, $dateTo, $status);
    generateExcel('students_export_' . date('Y-m-d'), [[
        'title'   => 'Students',
        'headers' => $data['headers'],
        'rows'    => $data['rows'],
        'formats' => $data['formats'] ?? [],
    ]]);
}

function exportParentsExcel(PDO $pdo): void
{
    require_once __DIR__ . '/includes/export_excel.php';
    $data = fetchParentsData($pdo);
    generateExcel('parents_export_' . date('Y-m-d'), [[
        'title'   => 'Parents',
        'headers' => $data['headers'],
        'rows'    => $data['rows'],
        'formats' => $data['formats'] ?? [],
    ]]);
}

function exportMembershipsExcel(PDO $pdo, string $dateFrom, string $dateTo, string $status): void
{
    require_once __DIR__ . '/includes/export_excel.php';
    $data = fetchMembershipsData($pdo, $dateFrom, $dateTo, $status);
    generateExcel('memberships_export_' . date('Y-m-d'), [[
        'title'   => 'Memberships',
        'headers' => $data['headers'],
        'rows'    => $data['rows'],
        'formats' => $data['formats'] ?? [],
    ]]);
}

function exportPaymentsExcel(PDO $pdo, string $dateFrom, string $dateTo): void
{
    require_once __DIR__ . '/includes/export_excel.php';
    $data = fetchPaymentsData($pdo, $dateFrom, $dateTo);
    generateExcel('payments_export_' . date('Y-m-d'), [[
        'title'   => 'Payments',
        'headers' => $data['headers'],
        'rows'    => $data['rows'],
        'formats' => $data['formats'] ?? [],
    ]]);
}

function exportFullExcel(PDO $pdo, string $dateFrom, string $dateTo): void
{
    require_once __DIR__ . '/includes/export_excel.php';
    $data = fetchFullData($pdo, $dateFrom, $dateTo);
    generateExcel('full_export_' . date('Y-m-d'), [[
        'title'   => 'Full Export',
        'headers' => $data['headers'],
        'rows'    => $data['rows'],
        'formats' => $data['formats'] ?? [],
    ]]);
}

// =====================================================================
//  Handle Export POST Requests
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    $dateFrom = trim($_POST['date_from'] ?? '');
    $dateTo   = trim($_POST['date_to'] ?? '');
    $status   = trim($_POST['status'] ?? '');

    switch ($_POST['action']) {
        case 'export_students':
            exportStudents($pdo, $dateFrom, $dateTo, $status);
            exit;
        case 'export_students_excel':
            exportStudentsExcel($pdo, $dateFrom, $dateTo, $status);
            exit;
        case 'export_parents':
            exportParents($pdo);
            exit;
        case 'export_parents_excel':
            exportParentsExcel($pdo);
            exit;
        case 'export_memberships':
            exportMemberships($pdo, $dateFrom, $dateTo, $status);
            exit;
        case 'export_memberships_excel':
            exportMembershipsExcel($pdo, $dateFrom, $dateTo, $status);
            exit;
        case 'export_payments':
            exportPayments($pdo, $dateFrom, $dateTo);
            exit;
        case 'export_payments_excel':
            exportPaymentsExcel($pdo, $dateFrom, $dateTo);
            exit;
        case 'export_full':
            exportFull($pdo, $dateFrom, $dateTo);
            exit;
        case 'export_full_excel':
            exportFullExcel($pdo, $dateFrom, $dateTo);
            exit;
    }
}

// =====================================================================
//  Get counts for display
// =====================================================================
$stmtSC = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_parent = 0" . school_where());
$scParams = [];
school_param($scParams);
$stmtSC->execute($scParams);
$studentCount    = (int) $stmtSC->fetchColumn();
$stmtPC = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_parent = 1" . school_where());
$pcParams = [];
school_param($pcParams);
$stmtPC->execute($pcParams);
$parentCount     = (int) $stmtPC->fetchColumn();
$membershipCount = 0;
$paymentCount    = 0;
try {
    $stmtMC = $pdo->prepare("SELECT COUNT(*) FROM memberships " . school_where_clause());
    $mcParams = [];
    school_param($mcParams);
    $stmtMC->execute($mcParams);
    $membershipCount = (int) $stmtMC->fetchColumn();
} catch (\PDOException $e) {}
try {
    $stmtPyC = $pdo->prepare("SELECT COUNT(*) FROM payments " . school_where_clause());
    $pycParams = [];
    school_param($pycParams);
    $stmtPyC->execute($pycParams);
    $paymentCount = (int) $stmtPyC->fetchColumn();
} catch (\PDOException $e) {}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Import / Export Data</h1>
        <div class="space-x-3">
            <a href="import_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Students</a>
            <a href="import_payments.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-gray-200 text-gray-700 hover:bg-gray-300">Import Payments</a>
            <a href="export_data.php" class="px-4 py-2 rounded-lg font-medium text-sm bg-blue-600 text-white">Export</a>
        </div>
    </div>

    <!-- Data Summary -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-5 text-center">
            <div class="text-3xl font-bold text-blue-600"><?php echo $studentCount; ?></div>
            <div class="text-sm text-gray-600 mt-1">Students</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5 text-center">
            <div class="text-3xl font-bold text-purple-600"><?php echo $parentCount; ?></div>
            <div class="text-sm text-gray-600 mt-1">Parents</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5 text-center">
            <div class="text-3xl font-bold text-green-600"><?php echo $membershipCount; ?></div>
            <div class="text-sm text-gray-600 mt-1">Memberships</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5 text-center">
            <div class="text-3xl font-bold text-teal-600"><?php echo $paymentCount; ?></div>
            <div class="text-sm text-gray-600 mt-1">Payments</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Export Filters</h2>
        <p class="text-sm text-gray-500 mb-4">These filters apply to the export buttons below. Leave blank to export all data.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="export-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" id="filter_date_from" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" id="filter_date_to" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="filter_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Export Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <!-- Students Export -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-5">
                <h3 class="text-lg font-bold">Students</h3>
                <p class="text-sm opacity-90 mt-1"><?php echo $studentCount; ?> records</p>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 mb-4">Export all student demographic data including names, contact info, belt rank, join date, and notes.</p>
                <div class="flex gap-2">
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_students">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <input type="hidden" name="status" class="filter-status">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#11015; CSV
                        </button>
                    </form>
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_students_excel">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <input type="hidden" name="status" class="filter-status">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#128202; Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Parents Export -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white p-5">
                <h3 class="text-lg font-bold">Parents</h3>
                <p class="text-sm opacity-90 mt-1"><?php echo $parentCount; ?> records</p>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 mb-4">Export parent accounts with their linked children names and child count.</p>
                <div class="flex gap-2">
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_parents">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#11015; CSV
                        </button>
                    </form>
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_parents_excel">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#128202; Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Memberships Export -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-5">
                <h3 class="text-lg font-bold">Memberships</h3>
                <p class="text-sm opacity-90 mt-1"><?php echo $membershipCount; ?> records</p>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 mb-4">Export membership records with plan details, dates, payment status, and billing info.</p>
                <div class="flex gap-2">
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_memberships">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <input type="hidden" name="status" class="filter-status">
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#11015; CSV
                        </button>
                    </form>
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_memberships_excel">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <input type="hidden" name="status" class="filter-status">
                        <button type="submit" class="w-full bg-green-700 hover:bg-green-800 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#128202; Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Payments Export -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-teal-500 to-teal-600 text-white p-5">
                <h3 class="text-lg font-bold">Payments</h3>
                <p class="text-sm opacity-90 mt-1"><?php echo $paymentCount; ?> records</p>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 mb-4">Export complete payment history with receipt numbers, amounts, and methods.</p>
                <div class="flex gap-2">
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_payments">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#11015; CSV
                        </button>
                    </form>
                    <form method="POST" class="export-form flex-1">
                        <input type="hidden" name="action" value="export_payments_excel">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
                            &#128202; Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Full Export -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden md:col-span-2 lg:col-span-3">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 text-white p-5">
                <h3 class="text-lg font-bold">Full Export (Students + Parents + Plans + Payments)</h3>
                <p class="text-sm opacity-90 mt-1">Comprehensive one-row-per-student export</p>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 mb-4">Export a combined view with each student's demographic data, parent name, active membership plan, total payments, and last payment date. Ideal for a complete snapshot of all student data.</p>
                <div class="flex gap-3">
                    <form method="POST" class="export-form">
                        <input type="hidden" name="action" value="export_full">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-medium py-3 px-8 rounded-lg">
                            &#11015; Export Full CSV
                        </button>
                    </form>
                    <form method="POST" class="export-form">
                        <input type="hidden" name="action" value="export_full_excel">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="date_from" class="filter-date-from">
                        <input type="hidden" name="date_to" class="filter-date-to">
                        <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-medium py-3 px-8 rounded-lg">
                            &#128202; Export Full Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Sync filter values to hidden form fields before submission
document.querySelectorAll('.export-form').forEach(function(form) {
    form.addEventListener('submit', function() {
        var dateFrom = document.getElementById('filter_date_from').value;
        var dateTo = document.getElementById('filter_date_to').value;
        var status = document.getElementById('filter_status').value;

        form.querySelectorAll('.filter-date-from').forEach(function(el) { el.value = dateFrom; });
        form.querySelectorAll('.filter-date-to').forEach(function(el) { el.value = dateTo; });
        form.querySelectorAll('.filter-status').forEach(function(el) { el.value = status; });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
