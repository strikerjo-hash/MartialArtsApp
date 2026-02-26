<?php
/**
 * audit_log.php — Admin Audit & Application Log Viewer
 *
 * Two-tab interface:
 *   Tab 1: Audit Log  — all user actions (login, logout, POST actions)
 *   Tab 2: App Log    — application errors, warnings, info events
 *
 * Admin-only page with filters, pagination, and expandable row details.
 */

require_once 'config.php';
requireLogin();
require_super_admin();

// Migrations have been moved to migrate.php

$theme = getActiveTheme();
$perPage = 50;

// ── Tab selection ──────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'audit';
if (!in_array($activeTab, ['audit', 'app'], true)) {
    $activeTab = 'audit';
}

// ── Audit Log Filters & Query ──────────────────────────────────────
$auditRows   = [];
$auditTotal  = 0;
$auditPage   = max(1, (int)($_GET['audit_page'] ?? 1));
$auditOffset = ($auditPage - 1) * $perPage;

// Filters
$af_date_from  = $_GET['af_date_from'] ?? '';
$af_date_to    = $_GET['af_date_to'] ?? '';
$af_user_type  = $_GET['af_user_type'] ?? '';
$af_username   = trim($_GET['af_username'] ?? '');
$af_action     = trim($_GET['af_action'] ?? '');

$auditWhere  = ' WHERE 1=1';
$auditParams = [];

if (function_exists('school_where')) {
    $auditWhere .= school_where('a');
    school_param($auditParams);
}

if ($af_date_from) {
    $auditWhere .= ' AND a.created_at >= :af_from';
    $auditParams[':af_from'] = $af_date_from . ' 00:00:00';
}
if ($af_date_to) {
    $auditWhere .= ' AND a.created_at <= :af_to';
    $auditParams[':af_to'] = $af_date_to . ' 23:59:59';
}
if ($af_user_type) {
    $auditWhere .= ' AND a.user_type = :af_utype';
    $auditParams[':af_utype'] = $af_user_type;
}
if ($af_username) {
    $auditWhere .= ' AND a.username LIKE :af_uname';
    $auditParams[':af_uname'] = '%' . $af_username . '%';
}
if ($af_action) {
    $auditWhere .= ' AND a.action LIKE :af_action';
    $auditParams[':af_action'] = '%' . $af_action . '%';
}

try {
    // Count
    $countSql = "SELECT COUNT(*) FROM audit_log a" . $auditWhere;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($auditParams);
    $auditTotal = (int) $countStmt->fetchColumn();

    // Fetch
    $sql = "SELECT * FROM audit_log a" . $auditWhere . " ORDER BY a.created_at DESC LIMIT {$perPage} OFFSET {$auditOffset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($auditParams);
    $auditRows = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Table may not exist yet
}

$auditPages = max(1, ceil($auditTotal / $perPage));

// ── App Log Filters & Query ────────────────────────────────────────
$appRows   = [];
$appTotal  = 0;
$appPage   = max(1, (int)($_GET['app_page'] ?? 1));
$appOffset = ($appPage - 1) * $perPage;

// Filters
$al_date_from = $_GET['al_date_from'] ?? '';
$al_date_to   = $_GET['al_date_to'] ?? '';
$al_level     = $_GET['al_level'] ?? '';
$al_category  = trim($_GET['al_category'] ?? '');
$al_search    = trim($_GET['al_search'] ?? '');

$appWhere  = ' WHERE 1=1';
$appParams = [];

if (function_exists('school_where')) {
    $appWhere .= school_where('l');
    school_param($appParams);
}

if ($al_date_from) {
    $appWhere .= ' AND l.created_at >= :al_from';
    $appParams[':al_from'] = $al_date_from . ' 00:00:00';
}
if ($al_date_to) {
    $appWhere .= ' AND l.created_at <= :al_to';
    $appParams[':al_to'] = $al_date_to . ' 23:59:59';
}
if ($al_level) {
    $appWhere .= ' AND l.level = :al_level';
    $appParams[':al_level'] = $al_level;
}
if ($al_category) {
    $appWhere .= ' AND l.category LIKE :al_cat';
    $appParams[':al_cat'] = '%' . $al_category . '%';
}
if ($al_search) {
    $appWhere .= ' AND (l.message LIKE :al_search OR l.reference_id LIKE :al_search2)';
    $appParams[':al_search']  = '%' . $al_search . '%';
    $appParams[':al_search2'] = '%' . $al_search . '%';
}

try {
    // Count
    $countSql = "SELECT COUNT(*) FROM app_log l" . $appWhere;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($appParams);
    $appTotal = (int) $countStmt->fetchColumn();

    // Fetch
    $sql = "SELECT * FROM app_log l" . $appWhere . " ORDER BY l.created_at DESC LIMIT {$perPage} OFFSET {$appOffset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($appParams);
    $appRows = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Table may not exist yet
}

$appPages = max(1, ceil($appTotal / $perPage));

// ── Monitoring Metrics (summary cards) ────────────────────────────
$metrics = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM app_log WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $metrics['errors_24h'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM app_log WHERE level = 'warning' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $metrics['warnings_24h'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $metrics['logins_24h'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $metrics['failed_logins_24h'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_log");
    $metrics['total_audit'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM app_log");
    $metrics['total_app'] = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    $metrics = ['errors_24h' => 0, 'warnings_24h' => 0, 'logins_24h' => 0, 'failed_logins_24h' => 0, 'total_audit' => 0, 'total_app' => 0];
}

include 'includes/header.php';
?>

<style>
.tab-btn {
    padding: 0.5rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 0.5rem 0.5rem 0 0;
    border: 1px solid #e5e7eb;
    border-bottom: none;
    background: #f9fafb;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.15s;
}
.tab-btn.active {
    background: white;
    color: <?php echo htmlspecialchars($theme['primary']); ?>;
    border-color: #e5e7eb;
    font-weight: 600;
    position: relative;
}
.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: white;
}
.tab-btn:hover:not(.active) {
    background: #f3f4f6;
    color: #374151;
}
.tab-content { display: none; }
.tab-content.active { display: block; }
.log-row { cursor: pointer; transition: background 0.1s; }
.log-row:hover { background: #f9fafb; }
.log-detail { display: none; }
.log-detail.open { display: table-row; }
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-admin   { background: #dbeafe; color: #1d4ed8; }
.badge-student { background: #d1fae5; color: #065f46; }
.badge-parent  { background: #fef3c7; color: #92400e; }
.badge-error   { background: #fee2e2; color: #991b1b; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-info    { background: #dbeafe; color: #1d4ed8; }
.badge-debug   { background: #f3f4f6; color: #6b7280; }
.json-display {
    font-family: ui-monospace, monospace;
    font-size: 0.75rem;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 0.375rem;
    padding: 0.5rem;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 200px;
    overflow-y: auto;
}
</style>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Audit & Application Logs</h1>
        <p class="text-sm text-gray-500 mt-1">Review all user actions and system events.</p>
    </div>

    <!-- Monitoring Dashboard Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 <?php echo $metrics['errors_24h'] > 0 ? 'border-red-500' : 'border-green-500'; ?>">
            <p class="text-xs font-semibold text-gray-500 uppercase">Errors (24h)</p>
            <p class="text-2xl font-bold <?php echo $metrics['errors_24h'] > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($metrics['errors_24h']); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 <?php echo $metrics['warnings_24h'] > 0 ? 'border-amber-500' : 'border-green-500'; ?>">
            <p class="text-xs font-semibold text-gray-500 uppercase">Warnings (24h)</p>
            <p class="text-2xl font-bold <?php echo $metrics['warnings_24h'] > 0 ? 'text-amber-600' : 'text-green-600'; ?>"><?php echo number_format($metrics['warnings_24h']); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
            <p class="text-xs font-semibold text-gray-500 uppercase">Logins (24h)</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($metrics['logins_24h']); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 <?php echo $metrics['failed_logins_24h'] > 5 ? 'border-red-500' : 'border-gray-300'; ?>">
            <p class="text-xs font-semibold text-gray-500 uppercase">Failed Logins (24h)</p>
            <p class="text-2xl font-bold <?php echo $metrics['failed_logins_24h'] > 5 ? 'text-red-600' : 'text-gray-700'; ?>"><?php echo number_format($metrics['failed_logins_24h']); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-300">
            <p class="text-xs font-semibold text-gray-500 uppercase">Audit Entries</p>
            <p class="text-2xl font-bold text-gray-700"><?php echo number_format($metrics['total_audit']); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-300">
            <p class="text-xs font-semibold text-gray-500 uppercase">App Log Entries</p>
            <p class="text-2xl font-bold text-gray-700"><?php echo number_format($metrics['total_app']); ?></p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-0">
        <button onclick="switchTab('audit')" class="tab-btn <?php echo $activeTab === 'audit' ? 'active' : ''; ?>" data-tab="audit">
            &#128203; Audit Log
            <span class="ml-1 text-xs text-gray-400">(<?php echo number_format($auditTotal); ?>)</span>
        </button>
        <button onclick="switchTab('app')" class="tab-btn <?php echo $activeTab === 'app' ? 'active' : ''; ?>" data-tab="app">
            &#128187; Application Log
            <span class="ml-1 text-xs text-gray-400">(<?php echo number_format($appTotal); ?>)</span>
        </button>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 1: AUDIT LOG
         ═══════════════════════════════════════════════════════════════ -->
    <div id="tab-audit" class="tab-content <?php echo $activeTab === 'audit' ? 'active' : ''; ?> bg-white border border-gray-200 rounded-b-xl rounded-tr-xl p-6">

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-6">
            <input type="hidden" name="tab" value="audit">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">From Date</label>
                <input type="date" name="af_date_from" value="<?php echo htmlspecialchars($af_date_from); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">To Date</label>
                <input type="date" name="af_date_to" value="<?php echo htmlspecialchars($af_date_to); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">User Type</label>
                <select name="af_user_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
                    <option value="">All Types</option>
                    <option value="admin" <?php echo $af_user_type === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="student" <?php echo $af_user_type === 'student' ? 'selected' : ''; ?>>Student</option>
                    <option value="parent" <?php echo $af_user_type === 'parent' ? 'selected' : ''; ?>>Parent</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                <input type="text" name="af_username" value="<?php echo htmlspecialchars($af_username); ?>" placeholder="Search user..."
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Action</label>
                <div class="flex gap-2">
                    <input type="text" name="af_action" value="<?php echo htmlspecialchars($af_action); ?>" placeholder="login, post_action..."
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white rounded-lg" style="background-color: <?php echo htmlspecialchars($theme['primary']); ?>;">Filter</button>
                </div>
            </div>
        </form>

        <?php if ($af_date_from || $af_date_to || $af_user_type || $af_username || $af_action): ?>
        <div class="mb-4">
            <a href="?tab=audit" class="text-sm text-blue-600 hover:underline">&times; Clear all filters</a>
        </div>
        <?php endif; ?>

        <!-- Audit Table -->
        <?php if (empty($auditRows)): ?>
            <div class="text-center py-12 text-gray-400">
                <div class="text-4xl mb-3">&#128203;</div>
                <p class="text-lg font-medium">No audit log entries found</p>
                <p class="text-sm">Actions will appear here as users interact with the system.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left">
                        <th class="px-4 py-3 font-semibold text-gray-600">Date / Time</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">User</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Type</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Action</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Entity</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Description</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($auditRows as $row): ?>
                    <tr class="log-row" onclick="toggleAuditDetail(<?php echo (int)$row['id']; ?>)">
                        <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(formatDateTime($row['created_at'])); ?></td>
                        <td class="px-4 py-2.5 font-medium text-gray-800"><?php echo htmlspecialchars($row['username'] ?? '—'); ?></td>
                        <td class="px-4 py-2.5">
                            <?php
                            $utBadge = 'badge-' . ($row['user_type'] ?? 'debug');
                            echo '<span class="badge ' . $utBadge . '">' . htmlspecialchars(ucfirst($row['user_type'] ?? '—')) . '</span>';
                            ?>
                        </td>
                        <td class="px-4 py-2.5 text-gray-700 font-mono text-xs"><?php echo htmlspecialchars($row['action']); ?></td>
                        <td class="px-4 py-2.5 text-gray-500">
                            <?php
                            if ($row['entity_type']) {
                                echo htmlspecialchars($row['entity_type']);
                                if ($row['entity_id']) echo ' #' . (int)$row['entity_id'];
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($row['description'] ?? '—'); ?></td>
                        <td class="px-4 py-2.5 text-gray-400 font-mono text-xs"><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></td>
                    </tr>
                    <tr class="log-detail" id="audit-detail-<?php echo (int)$row['id']; ?>">
                        <td colspan="7" class="px-6 py-4 bg-gray-50">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Request URI</h4>
                                    <p class="text-sm text-gray-700 font-mono break-all"><?php echo htmlspecialchars($row['request_uri'] ?? '—'); ?></p>
                                </div>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">User ID</h4>
                                    <p class="text-sm text-gray-700"><?php echo (int)($row['user_id'] ?? 0); ?></p>
                                </div>
                                <?php if ($row['old_values']): ?>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Old Values</h4>
                                    <div class="json-display"><?php echo htmlspecialchars(json_encode(json_decode($row['old_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['new_values']): ?>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">New Values</h4>
                                    <div class="json-display"><?php echo htmlspecialchars(json_encode(json_decode($row['new_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Audit Pagination -->
        <?php if ($auditPages > 1): ?>
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-500">
                Showing <?php echo number_format($auditOffset + 1); ?>–<?php echo number_format(min($auditOffset + $perPage, $auditTotal)); ?> of <?php echo number_format($auditTotal); ?>
            </p>
            <div class="flex gap-1">
                <?php
                $auditQueryBase = array_filter([
                    'tab' => 'audit',
                    'af_date_from' => $af_date_from,
                    'af_date_to' => $af_date_to,
                    'af_user_type' => $af_user_type,
                    'af_username' => $af_username,
                    'af_action' => $af_action,
                ]);
                if ($auditPage > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($auditQueryBase, ['audit_page' => $auditPage - 1])); ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">&laquo; Prev</a>
                <?php endif;
                // Show page numbers (max 7 around current)
                $start = max(1, $auditPage - 3);
                $end = min($auditPages, $auditPage + 3);
                for ($p = $start; $p <= $end; $p++):
                    $isCurrentPage = $p === $auditPage;
                ?>
                <a href="?<?php echo http_build_query(array_merge($auditQueryBase, ['audit_page' => $p])); ?>"
                   class="px-3 py-1.5 text-sm border rounded-lg <?php echo $isCurrentPage ? 'text-white border-blue-500' : 'border-gray-300 hover:bg-gray-50'; ?>"
                   <?php if ($isCurrentPage): ?>style="background-color: <?php echo htmlspecialchars($theme['primary']); ?>;"<?php endif; ?>>
                    <?php echo $p; ?>
                </a>
                <?php endfor;
                if ($auditPage < $auditPages): ?>
                <a href="?<?php echo http_build_query(array_merge($auditQueryBase, ['audit_page' => $auditPage + 1])); ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 2: APPLICATION LOG
         ═══════════════════════════════════════════════════════════════ -->
    <div id="tab-app" class="tab-content <?php echo $activeTab === 'app' ? 'active' : ''; ?> bg-white border border-gray-200 rounded-b-xl rounded-tr-xl p-6">

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-6">
            <input type="hidden" name="tab" value="app">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">From Date</label>
                <input type="date" name="al_date_from" value="<?php echo htmlspecialchars($al_date_from); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">To Date</label>
                <input type="date" name="al_date_to" value="<?php echo htmlspecialchars($al_date_to); ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Level</label>
                <select name="al_level" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
                    <option value="">All Levels</option>
                    <option value="error" <?php echo $al_level === 'error' ? 'selected' : ''; ?>>Error</option>
                    <option value="warning" <?php echo $al_level === 'warning' ? 'selected' : ''; ?>>Warning</option>
                    <option value="info" <?php echo $al_level === 'info' ? 'selected' : ''; ?>>Info</option>
                    <option value="debug" <?php echo $al_level === 'debug' ? 'selected' : ''; ?>>Debug</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                <input type="text" name="al_category" value="<?php echo htmlspecialchars($al_category); ?>" placeholder="auth, payment..."
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
                <div class="flex gap-2">
                    <input type="text" name="al_search" value="<?php echo htmlspecialchars($al_search); ?>" placeholder="Message or ref ID..."
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400">
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white rounded-lg" style="background-color: <?php echo htmlspecialchars($theme['primary']); ?>;">Filter</button>
                </div>
            </div>
        </form>

        <?php if ($al_date_from || $al_date_to || $al_level || $al_category || $al_search): ?>
        <div class="mb-4">
            <a href="?tab=app" class="text-sm text-blue-600 hover:underline">&times; Clear all filters</a>
        </div>
        <?php endif; ?>

        <!-- App Log Table -->
        <?php if (empty($appRows)): ?>
            <div class="text-center py-12 text-gray-400">
                <div class="text-4xl mb-3">&#128187;</div>
                <p class="text-lg font-medium">No application log entries found</p>
                <p class="text-sm">Errors, warnings, and system events will appear here.</p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left">
                        <th class="px-4 py-3 font-semibold text-gray-600">Date / Time</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Level</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Category</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Message</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">File : Line</th>
                        <th class="px-4 py-3 font-semibold text-gray-600">Ref</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($appRows as $row): ?>
                    <tr class="log-row" onclick="toggleAppDetail(<?php echo (int)$row['id']; ?>)">
                        <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(formatDateTime($row['created_at'])); ?></td>
                        <td class="px-4 py-2.5">
                            <span class="badge badge-<?php echo htmlspecialchars($row['level']); ?>">
                                <?php echo htmlspecialchars(ucfirst($row['level'])); ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars($row['category'] ?? '—'); ?></td>
                        <td class="px-4 py-2.5 text-gray-700 max-w-md truncate"><?php echo htmlspecialchars(mb_substr($row['message'], 0, 120)); ?></td>
                        <td class="px-4 py-2.5 text-gray-400 font-mono text-xs whitespace-nowrap">
                            <?php
                            if ($row['file']) {
                                echo htmlspecialchars(basename($row['file']));
                                if ($row['line']) echo ':' . (int)$row['line'];
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-2.5 text-gray-400 font-mono text-xs"><?php echo htmlspecialchars($row['reference_id'] ?? ''); ?></td>
                    </tr>
                    <tr class="log-detail" id="app-detail-<?php echo (int)$row['id']; ?>">
                        <td colspan="6" class="px-6 py-4 bg-gray-50">
                            <div class="space-y-4">
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Full Message</h4>
                                    <p class="text-sm text-gray-700 break-all"><?php echo htmlspecialchars($row['message']); ?></p>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Full Path</h4>
                                        <p class="text-sm text-gray-700 font-mono break-all"><?php echo htmlspecialchars(($row['file'] ?? '—') . ':' . ($row['line'] ?? '?')); ?></p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">User</h4>
                                        <p class="text-sm text-gray-700"><?php echo htmlspecialchars(($row['user_type'] ?? '—') . ' #' . ($row['user_id'] ?? '0')); ?></p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">IP / URI</h4>
                                        <p class="text-sm text-gray-700 font-mono break-all"><?php echo htmlspecialchars(($row['ip_address'] ?? '') . ' ' . ($row['request_uri'] ?? '')); ?></p>
                                    </div>
                                </div>
                                <?php if ($row['context']): ?>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Context</h4>
                                    <div class="json-display"><?php echo htmlspecialchars(json_encode(json_decode($row['context'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['trace']): ?>
                                <div>
                                    <h4 class="text-xs font-semibold text-gray-500 uppercase mb-1">Stack Trace</h4>
                                    <pre class="json-display"><?php echo htmlspecialchars($row['trace']); ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- App Log Pagination -->
        <?php if ($appPages > 1): ?>
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-500">
                Showing <?php echo number_format($appOffset + 1); ?>–<?php echo number_format(min($appOffset + $perPage, $appTotal)); ?> of <?php echo number_format($appTotal); ?>
            </p>
            <div class="flex gap-1">
                <?php
                $appQueryBase = array_filter([
                    'tab' => 'app',
                    'al_date_from' => $al_date_from,
                    'al_date_to' => $al_date_to,
                    'al_level' => $al_level,
                    'al_category' => $al_category,
                    'al_search' => $al_search,
                ]);
                if ($appPage > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($appQueryBase, ['app_page' => $appPage - 1])); ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">&laquo; Prev</a>
                <?php endif;
                $start = max(1, $appPage - 3);
                $end = min($appPages, $appPage + 3);
                for ($p = $start; $p <= $end; $p++):
                    $isCurrentPage = $p === $appPage;
                ?>
                <a href="?<?php echo http_build_query(array_merge($appQueryBase, ['app_page' => $p])); ?>"
                   class="px-3 py-1.5 text-sm border rounded-lg <?php echo $isCurrentPage ? 'text-white border-blue-500' : 'border-gray-300 hover:bg-gray-50'; ?>"
                   <?php if ($isCurrentPage): ?>style="background-color: <?php echo htmlspecialchars($theme['primary']); ?>;"<?php endif; ?>>
                    <?php echo $p; ?>
                </a>
                <?php endfor;
                if ($appPage < $appPages): ?>
                <a href="?<?php echo http_build_query(array_merge($appQueryBase, ['app_page' => $appPage + 1])); ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(function(content) {
        content.classList.toggle('active', content.id === 'tab-' + tab);
    });
    // Update URL without reload
    var url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function toggleAuditDetail(id) {
    var row = document.getElementById('audit-detail-' + id);
    if (row) row.classList.toggle('open');
}

function toggleAppDetail(id) {
    var row = document.getElementById('app-detail-' + id);
    if (row) row.classList.toggle('open');
}
</script>

<?php include 'includes/footer.php'; ?>
