

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
        <h1 class="text-3xl font-bold text-gray-800">Reports & Analytics</h1>
        <span class="text-sm text-gray-500 bg-blue-50 px-3 py-1 rounded-full font-medium"><?php echo $range_label; ?></span>
    </div>

    <!-- Date Range Filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Report Period</label>
                <select name="range" id="reportRange"
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                        onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php echo $report_range === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="this_week" <?php echo $report_range === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="this_month" <?php echo $report_range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="last_month" <?php echo $report_range === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                    <option value="this_quarter" <?php echo $report_range === 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                    <option value="this_year" <?php echo $report_range === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="last_year" <?php echo $report_range === 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                    <option value="custom" <?php echo $report_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>

            <div id="customDateFields" class="<?php echo $report_range === 'custom' ? 'flex' : 'hidden'; ?> gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                Apply
            </button>
            <a href="reports.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg">
                Reset
            </a>
        </form>
    </div>

    <script>
    function toggleCustomDates(val) {
        document.getElementById('customDateFields').className = val === 'custom' ? 'flex gap-3 items-end' : 'hidden';
    }
    </script>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Total Students</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_students']; ?></p>
            <p class="text-sm text-green-600 mt-1"><?php echo $stats['active_students']; ?> active</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Active Memberships</h3>
            <p class="text-3xl font-bold text-gray-800"><?php echo $stats['active_memberships']; ?></p>
            <p class="text-sm text-orange-600 mt-1"><?php echo $stats['expiring_soon']; ?> expiring soon</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Revenue (<?php echo $range_label; ?>)</h3>
            <p class="text-3xl font-bold text-green-600"><?php echo formatMoney($stats['range_revenue']); ?></p>
            <p class="text-sm text-gray-600 mt-1"><?php echo $stats['range_payment_count']; ?> payment(s)</p>
            <?php if ($stats['range_refund_count'] > 0): ?>
                <p class="text-sm text-red-500 mt-1"><?php echo $stats['range_refund_count']; ?> refund(s): -<?php echo formatMoney($stats['range_refund_total']); ?></p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-gray-600 text-sm font-medium mb-2">Compliance Issues</h3>
            <p class="text-3xl font-bold <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                <?php echo $total_compliance_issues; ?>
            </p>
            <p class="text-sm <?php echo $total_compliance_issues > 0 ? 'text-red-600' : 'text-green-600'; ?> mt-1">
                <?php echo $total_compliance_issues > 0 ? 'Needs attention' : 'All clear'; ?>
            </p>
        </div>
    </div>

    <!-- Revenue Category Breakdown Badges -->
    <?php if (!empty($revenue_by_category)): ?>
    <?php
    $categoryLabels = ['membership' => 'Memberships', 'event' => 'Events', 'merchandise' => 'Merchandise', 'other' => 'Other'];
    $categoryColors = ['membership' => 'bg-blue-100 text-blue-800 border-blue-200', 'event' => 'bg-purple-100 text-purple-800 border-purple-200', 'merchandise' => 'bg-orange-100 text-orange-800 border-orange-200', 'other' => 'bg-gray-100 text-gray-700 border-gray-200'];
    ?>
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium text-gray-500">Revenue Breakdown:</span>
            <?php foreach ($revenue_by_category as $rc):
                $label = $categoryLabels[$rc['payment_type']] ?? ucfirst($rc['payment_type']);
                $color = $categoryColors[$rc['payment_type']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                $pct = $total_category_revenue > 0 ? round(($rc['total'] / $total_category_revenue) * 100, 1) : 0;
            ?>
                <span class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium <?php echo $color; ?>">
                    <?php echo $label; ?>:
                    <strong><?php echo formatMoney($rc['total']); ?></strong>
                    <span class="text-xs opacity-75">(<?php echo $pct; ?>%)</span>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================= -->
    <!-- TAB NAVIGATION -->
    <!-- ============================================= -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="flex overflow-x-auto border-b border-gray-200" id="reportTabs">
            <button onclick="switchTab('revenue')" data-tab="revenue" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-blue-600 text-blue-600">
                Revenue
                <?php if ($tab_badges['revenue'] > 0): ?>
                    <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700"><?php echo $tab_badges['revenue']; ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('students')" data-tab="students" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Students
                <?php if ($tab_badges['students'] > 0): ?>
                    <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-700"><?php echo $tab_badges['students']; ?> at-risk</span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('attendance')" data-tab="attendance" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Attendance
                <?php if ($tab_badges['attendance'] > 0): ?>
                    <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-600"><?php echo $tab_badges['attendance']; ?> classes</span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('events')" data-tab="events" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Events
                <?php if ($tab_badges['events'] > 0): ?>
                    <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-700"><?php echo $tab_badges['events']; ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('compliance')" data-tab="compliance" class="report-tab px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap border-transparent text-gray-500 hover:text-gray-700">
                Compliance
                <?php if ($tab_badges['compliance'] > 0): ?>
                    <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700"><?php echo $tab_badges['compliance']; ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>
