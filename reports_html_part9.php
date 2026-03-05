
    <!-- ============================================= -->
    <!-- MEMBERSHIP LIFECYCLE TAB -->
    <!-- ============================================= -->
    <div id="tab-membership-lifecycle" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('membership_lifecycle', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <?php
        $renewalColor = ($membership_renewal_rate >= 80) ? 'bg-green-500' : (($membership_renewal_rate >= 60) ? 'bg-yellow-500' : 'bg-red-500');
        $renewalText  = ($membership_renewal_rate >= 80) ? 'text-green-50' : (($membership_renewal_rate >= 60) ? 'text-yellow-50' : 'text-red-50');
        $collectionColor = ($payment_collection_rate >= 80) ? 'bg-green-500' : (($payment_collection_rate >= 60) ? 'bg-yellow-500' : 'bg-red-500');
        $collectionText  = ($payment_collection_rate >= 80) ? 'text-green-50' : (($payment_collection_rate >= 60) ? 'text-yellow-50' : 'text-red-50');
        ?>
        <!-- MRR -->
        <div class="bg-green-500 rounded-lg shadow p-6">
            <h3 class="text-green-50 text-sm font-medium mb-2">Monthly Recurring Revenue</h3>
            <p class="text-3xl font-bold text-white"><?php echo formatMoney($mrr); ?></p>
            <p class="text-sm text-green-100 mt-1">MRR</p>
        </div>
        <!-- Avg Duration -->
        <div class="bg-blue-500 rounded-lg shadow p-6">
            <h3 class="text-blue-50 text-sm font-medium mb-2">Avg Membership Duration</h3>
            <p class="text-3xl font-bold text-white"><?php echo number_format($avg_membership_duration, 1); ?> <span class="text-lg font-medium">months</span></p>
            <p class="text-sm text-blue-100 mt-1">Average active tenure</p>
        </div>
        <!-- Renewal Rate -->
        <div class="<?php echo $renewalColor; ?> rounded-lg shadow p-6">
            <h3 class="<?php echo $renewalText; ?> text-sm font-medium mb-2">Renewal Rate</h3>
            <p class="text-3xl font-bold text-white"><?php echo number_format($membership_renewal_rate, 1); ?>%</p>
            <p class="text-sm <?php echo $renewalText; ?> mt-1"><?php echo $membership_renewal_rate >= 80 ? 'Healthy' : ($membership_renewal_rate >= 60 ? 'Needs attention' : 'Critical'); ?></p>
        </div>
        <!-- Collection Rate -->
        <div class="<?php echo $collectionColor; ?> rounded-lg shadow p-6">
            <h3 class="<?php echo $collectionText; ?> text-sm font-medium mb-2">Payment Collection Rate</h3>
            <p class="text-3xl font-bold text-white"><?php echo number_format($payment_collection_rate, 1); ?>%</p>
            <p class="text-sm <?php echo $collectionText; ?> mt-1"><?php echo $payment_collection_rate >= 80 ? 'Healthy' : ($payment_collection_rate >= 60 ? 'Needs attention' : 'Critical'); ?></p>
        </div>
    </div>

    <!-- New Memberships Trend (Line Chart) -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">New Memberships Trend (Last 12 Months)</h2>
        <p class="text-sm text-gray-500 mb-4">Number of new memberships started each month.</p>
        <?php if (!empty($new_memberships_trend)): ?>
        <?php
        $totalNewMemberships = array_sum(array_column($new_memberships_trend, 'count'));
        $avgNewPerMonth = count($new_memberships_trend) > 0 ? $totalNewMemberships / count($new_memberships_trend) : 0;
        ?>
        <p class="text-sm text-gray-500 mb-4">
            Total: <strong class="text-gray-800"><?php echo number_format($totalNewMemberships); ?></strong>
            &middot;
            Average: <strong class="text-gray-800"><?php echo number_format($avgNewPerMonth, 1); ?>/mo</strong>
        </p>
        <div style="position: relative; height: 300px;">
            <canvas id="newMembershipChart"></canvas>
        </div>
        <script>
        (function(){
            const ctx = document.getElementById('newMembershipChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($d){ return date('M \'y', strtotime($d['month'].'-01')); }, $new_memberships_trend)); ?>;
            const data = <?php echo json_encode(array_map(function($d){ return (int)$d['count']; }, $new_memberships_trend)); ?>;
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'New Memberships',
                        data: data,
                        borderColor: 'rgba(59, 130, 246, 1)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                plugins: [ChartDataLabels],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            anchor: 'end',
                            align: 'end',
                            formatter: function(v) { return v; },
                            font: { weight: 'bold', size: 11 },
                            color: '#374151'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) { return c.raw + ' new membership' + (c.raw !== 1 ? 's' : ''); }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, callback: function(v) { return Number.isInteger(v) ? v : ''; } } },
                        x: { grid: { display: false } }
                    },
                    layout: { padding: { top: 20 } }
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No new membership data for the last 12 months.</p>
        <?php endif; ?>
    </div>

    <!-- Plan Popularity (Doughnut Chart + Table) -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Plan Popularity</h2>
        <p class="text-sm text-gray-500 mb-4">Active membership distribution by plan.</p>
        <?php if (!empty($plan_popularity)): ?>
        <?php
        $totalActivePlans = array_sum(array_column($plan_popularity, 'active_count'));
        $totalMonthlyRevEst = array_sum(array_column($plan_popularity, 'monthly_revenue_estimate'));
        $planChartColors = [
            'rgba(59, 130, 246, 0.8)',   // blue
            'rgba(16, 185, 129, 0.8)',   // green
            'rgba(245, 158, 11, 0.8)',   // amber
            'rgba(139, 92, 246, 0.8)',   // purple
            'rgba(239, 68, 68, 0.8)',    // red
            'rgba(236, 72, 153, 0.8)',   // pink
            'rgba(20, 184, 166, 0.8)',   // teal
            'rgba(249, 115, 22, 0.8)',   // orange
            'rgba(99, 102, 241, 0.8)',   // indigo
            'rgba(107, 114, 128, 0.8)',  // gray
        ];
        $planChartBorders = [
            'rgba(59, 130, 246, 1)',
            'rgba(16, 185, 129, 1)',
            'rgba(245, 158, 11, 1)',
            'rgba(139, 92, 246, 1)',
            'rgba(239, 68, 68, 1)',
            'rgba(236, 72, 153, 1)',
            'rgba(20, 184, 166, 1)',
            'rgba(249, 115, 22, 1)',
            'rgba(99, 102, 241, 1)',
            'rgba(107, 114, 128, 1)',
        ];
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Doughnut Chart -->
            <div class="flex items-center justify-center">
                <div style="position: relative; width: 300px; height: 300px;">
                    <canvas id="planPopularityChart"></canvas>
                </div>
            </div>
            <!-- Plan Table -->
            <div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Plan Name</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Active Members</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Monthly Rev Est</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($plan_popularity as $pp):
                                $planPct = $totalActivePlans > 0 ? round(($pp['active_count'] / $totalActivePlans) * 100, 1) : 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($pp['plan_name']); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($pp['active_count']); ?></td>
                                <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($pp['monthly_revenue_estimate']); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $planPct; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-gray-50 font-bold">
                                <td class="px-4 py-2 text-sm text-gray-800">Total</td>
                                <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo number_format($totalActivePlans); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-green-700"><?php echo formatMoney($totalMonthlyRevEst); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600">100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function(){
            const ctx = document.getElementById('planPopularityChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($d){ return $d['plan_name']; }, $plan_popularity)); ?>;
            const data = <?php echo json_encode(array_map(function($d){ return (int)$d['active_count']; }, $plan_popularity)); ?>;
            const colors = <?php echo json_encode(array_slice($planChartColors, 0, count($plan_popularity))); ?>;
            const borders = <?php echo json_encode(array_slice($planChartBorders, 0, count($plan_popularity))); ?>;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderColor: borders,
                        borderWidth: 2
                    }]
                },
                plugins: [ChartDataLabels],
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 15, usePointStyle: true, pointStyle: 'circle' }
                        },
                        datalabels: {
                            formatter: function(value, ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return pct + '%';
                            },
                            color: '#fff',
                            font: { weight: 'bold', size: 12 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    var total = c.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? ((c.raw / total) * 100).toFixed(1) : 0;
                                    return c.label + ': ' + c.raw + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No plan data available.</p>
        <?php endif; ?>
    </div>

    <!-- Key Metrics Summary Table -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Key Metrics Summary</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Metric</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <!-- Renewal Rate -->
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-800">Renewal Rate</td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800"><?php echo number_format($membership_renewal_rate, 1); ?>%</td>
                        <td class="px-6 py-4">
                            <?php
                            $renewalStatus = $membership_renewal_rate >= 80 ? 'Healthy' : ($membership_renewal_rate >= 60 ? 'Needs Attention' : 'Critical');
                            $renewalBadge  = $membership_renewal_rate >= 80 ? 'bg-green-100 text-green-800' : ($membership_renewal_rate >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $renewalBadge; ?>"><?php echo $renewalStatus; ?></span>
                        </td>
                    </tr>
                    <!-- Cancellation Rate -->
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-800">Cancellation Rate</td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800"><?php echo number_format($membership_cancel_rate, 1); ?>%</td>
                        <td class="px-6 py-4">
                            <?php
                            $cancelStatus = $membership_cancel_rate <= 10 ? 'Healthy' : ($membership_cancel_rate <= 20 ? 'Needs Attention' : 'Critical');
                            $cancelBadge  = $membership_cancel_rate <= 10 ? 'bg-green-100 text-green-800' : ($membership_cancel_rate <= 20 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $cancelBadge; ?>"><?php echo $cancelStatus; ?></span>
                        </td>
                    </tr>
                    <!-- Avg Membership Duration -->
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-800">Avg Membership Duration</td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800"><?php echo number_format($avg_membership_duration, 1); ?> months</td>
                        <td class="px-6 py-4">
                            <?php
                            $durationStatus = $avg_membership_duration >= 12 ? 'Excellent' : ($avg_membership_duration >= 6 ? 'Good' : 'Low');
                            $durationBadge  = $avg_membership_duration >= 12 ? 'bg-green-100 text-green-800' : ($avg_membership_duration >= 6 ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800');
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $durationBadge; ?>"><?php echo $durationStatus; ?></span>
                        </td>
                    </tr>
                    <!-- Payment Collection Rate -->
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-800">Payment Collection Rate</td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-gray-800"><?php echo number_format($payment_collection_rate, 1); ?>%</td>
                        <td class="px-6 py-4">
                            <?php
                            $collStatus = $payment_collection_rate >= 80 ? 'Healthy' : ($payment_collection_rate >= 60 ? 'Needs Attention' : 'Critical');
                            $collBadge  = $payment_collection_rate >= 80 ? 'bg-green-100 text-green-800' : ($payment_collection_rate >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $collBadge; ?>"><?php echo $collStatus; ?></span>
                        </td>
                    </tr>
                    <!-- MRR -->
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-800">Monthly Recurring Revenue</td>
                        <td class="px-6 py-4 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($mrr); ?></td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    </div><!-- /tab-membership-lifecycle -->
