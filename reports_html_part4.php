
    <!-- ============================================= -->
    <!-- STUDENTS TAB -->
    <!-- ============================================= -->
    <div id="tab-students" class="report-panel hidden">

    <!-- Student Retention Cohorts -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Student Retention Cohorts</h2>
        <p class="text-sm text-gray-500 mb-4">Active students grouped by how long they have been members.</p>
        <?php if (!empty($retention_cohorts)): ?>
        <div class="space-y-3">
            <?php
            $cohortColors = [
                'Under 3 months' => 'bg-blue-400',
                '3-6 months'     => 'bg-blue-500',
                '6-12 months'    => 'bg-indigo-500',
                '1-2 years'      => 'bg-purple-500',
                '2+ years'       => 'bg-green-500',
            ];
            foreach ($retention_cohorts as $rc):
                $pct = round(($rc['count'] / $totalActiveForRetention) * 100, 1);
                $barColor = $cohortColors[$rc['cohort']] ?? 'bg-gray-400';
            ?>
            <div class="flex items-center gap-4">
                <div class="w-36 text-sm font-medium text-gray-700 text-right"><?php echo $rc['cohort']; ?></div>
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-100 rounded-full h-6">
                            <div class="<?php echo $barColor; ?> rounded-full h-6 flex items-center justify-end pr-2 text-white text-xs font-bold" style="width: <?php echo max($pct, 8); ?>%;">
                                <?php echo $rc['count']; ?>
                            </div>
                        </div>
                        <span class="text-sm text-gray-500 w-14 text-right"><?php echo $pct; ?>%</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p class="text-center text-gray-500 py-4">No active students to display.</p>
        <?php endif; ?>
    </div>

    <!-- Student Lifetime Value -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Student Lifetime Value</h2>
        <?php if (!empty($ltv_cohorts)): ?>
        <h3 class="text-lg font-semibold text-gray-700 mb-3">LTV by Join Year</h3>
        <div class="overflow-x-auto mb-6">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Join Year</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Students</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg LTV</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($ltv_cohorts as $lc): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-bold text-gray-800"><?php echo $lc['join_year']; ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($lc['student_count']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($lc['avg_ltv']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($top_ltv_students)): ?>
        <h3 class="text-lg font-semibold text-gray-700 mb-3">Top 20 Students by Lifetime Revenue</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Join Date</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Lifetime Revenue</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payments</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($top_ltv_students as $i => $ts): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-bold text-gray-500"><?php echo $i + 1; ?></td>
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($ts['first_name'] . ' ' . $ts['last_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-600"><?php echo $ts['join_date'] ? formatDate($ts['join_date']) : 'N/A'; ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $ts['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo ucfirst($ts['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ts['lifetime_revenue']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ts['total_payments']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (empty($ltv_cohorts) && empty($top_ltv_students)): ?>
        <p class="text-center text-gray-500 py-4">No lifetime value data available.</p>
        <?php endif; ?>
    </div>

    <!-- Churn Risk / At-Risk Students -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Churn Risk / At-Risk Students</h2>
        <p class="text-sm text-gray-500 mb-4">Students with expiring memberships (no auto-renew) or payment failures.</p>
        <?php if (!empty($churn_risk_students)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Days Left</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Auto-Renew</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Recent Attendance</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Payment Failures</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Risk Level</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($churn_risk_students as $cr):
                        $daysLeft = (int) $cr['days_left'];
                        $recentAtt = (int) $cr['recent_attendance'];
                        $payFails = (int) $cr['payment_failures'];
                        // Determine risk level
                        if ($daysLeft <= 7 || $payFails >= 2 || $recentAtt === 0) {
                            $riskLevel = 'HIGH';
                            $riskColor = 'bg-red-100 text-red-800';
                        } elseif ($daysLeft <= 14 || $payFails >= 1 || $recentAtt <= 2) {
                            $riskLevel = 'MEDIUM';
                            $riskColor = 'bg-yellow-100 text-yellow-800';
                        } else {
                            $riskLevel = 'LOW';
                            $riskColor = 'bg-blue-100 text-blue-800';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cr['first_name'] . ' ' . $cr['last_name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($cr['email'] ?? ''); ?></div>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600"><?php echo htmlspecialchars($cr['plan_name']); ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="text-sm font-semibold <?php echo $daysLeft <= 7 ? 'text-red-600' : ($daysLeft <= 14 ? 'text-yellow-600' : 'text-gray-600'); ?>">
                                <?php echo $daysLeft; ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center text-sm text-gray-600"><?php echo $cr['auto_renew'] ? 'Yes' : 'No'; ?></td>
                        <td class="px-4 py-2 text-center text-sm <?php echo $recentAtt === 0 ? 'text-red-600 font-semibold' : 'text-gray-600'; ?>">
                            <?php echo $recentAtt; ?> (30d)
                        </td>
                        <td class="px-4 py-2 text-center text-sm <?php echo $payFails > 0 ? 'text-red-600 font-semibold' : 'text-gray-600'; ?>">
                            <?php echo $payFails; ?>
                        </td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $riskColor; ?>"><?php echo $riskLevel; ?></span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="student_detail.php?id=<?php echo $cr['id']; ?>" class="text-sm text-blue-600 hover:text-blue-900">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
            <p class="text-green-700 font-medium">No at-risk students detected.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Belt Progression Pipeline -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Belt Progression Pipeline</h2>
        <?php if (!empty($belt_pipeline)):
            $maxBeltCount = max(1, max(array_column($belt_pipeline, 'student_count')));
            $beltColors = [
                'white' => 'bg-gray-300', 'yellow' => 'bg-yellow-400', 'orange' => 'bg-orange-400',
                'green' => 'bg-green-500', 'blue' => 'bg-blue-500', 'purple' => 'bg-purple-500',
                'brown' => 'bg-amber-700', 'red' => 'bg-red-500', 'black' => 'bg-gray-800',
            ];
        ?>
        <div class="space-y-3">
            <?php foreach ($belt_pipeline as $bp):
                $bpPct = round(($bp['student_count'] / $maxBeltCount) * 100, 1);
                $beltKey = strtolower(trim($bp['belt_rank']));
                $bColor = $beltColors[$beltKey] ?? 'bg-gray-400';
            ?>
            <div class="flex items-center gap-4">
                <div class="w-28 text-sm font-medium text-gray-700 text-right"><?php echo htmlspecialchars(ucfirst($bp['belt_rank'])); ?></div>
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-100 rounded-full h-6">
                            <div class="<?php echo $bColor; ?> rounded-full h-6 flex items-center justify-end pr-2 text-white text-xs font-bold" style="width: <?php echo max($bpPct, 8); ?>%;">
                                <?php echo $bp['student_count']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No belt rank data available.</p>
        <?php endif; ?>
    </div>

    </div><!-- /tab-students -->
