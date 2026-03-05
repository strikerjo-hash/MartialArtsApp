
    <!-- ============================================= -->
    <!-- SCHOOL COMPARISON TAB (Super Admin Only) -->
    <!-- ============================================= -->
    <div id="tab-schools" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('schools', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Revenue Comparison Chart -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue Comparison by School (<?php echo htmlspecialchars($range_label); ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Horizontal bar chart comparing total revenue across all schools for the selected period.</p>
        <?php if (!empty($school_revenue)): ?>
        <div style="position: relative; height: <?php echo max(200, count($school_revenue) * 50); ?>px;">
            <canvas id="schoolRevenueChart"></canvas>
        </div>
        <script>
        (function(){
            const ctx = document.getElementById('schoolRevenueChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($r){ return $r['school_name']; }, $school_revenue)); ?>;
            const data = <?php echo json_encode(array_map(function($r){ return round((float)$r['total_revenue'], 2); }, $school_revenue)); ?>;
            const palette = [
                'rgba(59,130,246,0.8)',
                'rgba(37,99,235,0.8)',
                'rgba(29,78,216,0.8)',
                'rgba(96,165,250,0.8)',
                'rgba(147,197,253,0.8)',
                'rgba(30,64,175,0.8)',
                'rgba(67,56,202,0.8)',
                'rgba(79,70,229,0.8)'
            ];
            const borderPalette = [
                'rgba(59,130,246,1)',
                'rgba(37,99,235,1)',
                'rgba(29,78,216,1)',
                'rgba(96,165,250,1)',
                'rgba(147,197,253,1)',
                'rgba(30,64,175,1)',
                'rgba(67,56,202,1)',
                'rgba(79,70,229,1)'
            ];
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue',
                        data: data,
                        backgroundColor: data.map((v, i) => palette[i % palette.length]),
                        borderColor: data.map((v, i) => borderPalette[i % borderPalette.length]),
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                plugins: [ChartDataLabels],
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            anchor: 'end',
                            align: 'end',
                            formatter: v => '$' + v.toLocaleString('en-US', { maximumFractionDigits: 0 }),
                            font: { weight: 'bold', size: 11 },
                            color: '#374151'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    return '$' + c.raw.toLocaleString('en-US', { minimumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { callback: v => '$' + v.toLocaleString() }
                        },
                        y: {
                            grid: { display: false }
                        }
                    },
                    layout: { padding: { right: 60 } }
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No school revenue data available for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Revenue by School Table -->
    <?php if (!empty($school_revenue)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Revenue by School (<?php echo htmlspecialchars($range_label); ?>)</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payments</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg/Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $totalSchoolRevenue = 0;
                    $totalSchoolPayments = 0;
                    foreach ($school_revenue as $sr):
                        $totalSchoolRevenue += (float) $sr['total_revenue'];
                        $totalSchoolPayments += (int) $sr['payment_count'];
                        $avgPayment = (int) $sr['payment_count'] > 0 ? (float) $sr['total_revenue'] / (int) $sr['payment_count'] : 0;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($sr['school_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($sr['total_revenue']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($sr['payment_count']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo formatMoney($avgPayment); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-gray-50 font-bold">
                        <td class="px-4 py-2 text-sm text-gray-800">Total</td>
                        <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($totalSchoolRevenue); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($totalSchoolPayments); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $totalSchoolPayments > 0 ? formatMoney($totalSchoolRevenue / $totalSchoolPayments) : '$0.00'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Student Counts by School -->
    <?php if (!empty($school_students)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Student Counts by School</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Students</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Active Students</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Active Memberships</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Membership Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($school_students as $ss):
                        $activeStudents = (int) $ss['active_students'];
                        $activeMemberships = (int) $ss['active_memberships'];
                        $membershipRate = $activeStudents > 0 ? round(($activeMemberships / $activeStudents) * 100, 1) : 0;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($ss['school_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ss['total_students']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-800 font-semibold"><?php echo number_format($ss['active_students']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ss['active_memberships']); ?></td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[150px]">
                                    <div class="h-3 rounded-full <?php echo $membershipRate >= 80 ? 'bg-green-500' : ($membershipRate >= 50 ? 'bg-blue-500' : 'bg-yellow-500'); ?>"
                                         style="width: <?php echo min($membershipRate, 100); ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $membershipRate >= 80 ? 'text-green-700' : ($membershipRate >= 50 ? 'text-blue-700' : 'text-yellow-700'); ?>">
                                    <?php echo number_format($membershipRate, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Attendance Rate by School -->
    <?php if (!empty($school_attendance)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Attendance Rate by School (<?php echo htmlspecialchars($range_label); ?>)</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Present</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Records</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Attendance Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($school_attendance as $sa):
                        $attRate = (float) ($sa['attendance_rate'] ?? 0);
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($sa['school_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo number_format($sa['present_count']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($sa['total_records']); ?></td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[150px]">
                                    <div class="h-3 rounded-full <?php echo $attRate >= 80 ? 'bg-green-500' : ($attRate >= 60 ? 'bg-yellow-500' : 'bg-red-500'); ?>"
                                         style="width: <?php echo min($attRate, 100); ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $attRate >= 80 ? 'text-green-700' : ($attRate >= 60 ? 'text-yellow-700' : 'text-red-700'); ?>">
                                    <?php echo number_format($attRate, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Membership Plan Mix by School -->
    <?php if (!empty($school_plan_mix)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Membership Plan Mix by School</h2>
        <p class="text-sm text-gray-500 mb-4">Active membership plans grouped by school showing member counts for each plan.</p>
        <?php
        // Group plan mix data by school
        $plansBySchool = [];
        foreach ($school_plan_mix as $sp) {
            $schoolName = $sp['school_name'];
            if (!isset($plansBySchool[$schoolName])) {
                $plansBySchool[$schoolName] = [];
            }
            $plansBySchool[$schoolName][] = $sp;
        }
        ?>
        <div class="space-y-6">
            <?php foreach ($plansBySchool as $schoolName => $plans):
                $schoolTotal = array_sum(array_column($plans, 'member_count'));
                $maxPlanCount = max(1, max(array_column($plans, 'member_count')));
            ?>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($schoolName); ?></h3>
                    <span class="text-sm font-medium text-gray-500"><?php echo number_format($schoolTotal); ?> total member<?php echo $schoolTotal != 1 ? 's' : ''; ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Members</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of School</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Distribution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($plans as $plan):
                                $planPct = $schoolTotal > 0 ? round(($plan['member_count'] / $schoolTotal) * 100, 1) : 0;
                                $barPct = ($plan['member_count'] / $maxPlanCount) * 100;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($plan['plan_name']); ?></td>
                                <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo number_format($plan['member_count']); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $planPct; ?>%</td>
                                <td class="px-4 py-2">
                                    <div class="w-full bg-gray-100 rounded-full h-4 max-w-[200px]">
                                        <div class="bg-blue-500 rounded-full h-4 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                                             style="width: <?php echo max($barPct, 8); ?>%;">
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /tab-schools -->
