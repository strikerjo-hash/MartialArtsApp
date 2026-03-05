
    <!-- ============================================= -->
    <!-- BELT PROGRESSION TAB -->
    <!-- ============================================= -->
    <div id="tab-belt-progression" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('belt_progression', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Average Time Between Promotions -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Average Time Between Promotions</h2>
        <p class="text-sm text-gray-500 mb-4">Average number of days students take to reach each belt rank.</p>
        <?php if (!empty($avg_promotion_time)): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Chart.js Horizontal Bar -->
            <div>
                <div style="position: relative; height: <?php echo max(250, count($avg_promotion_time) * 45); ?>px;">
                    <canvas id="promotionTimeChart"></canvas>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const ptCtx = document.getElementById('promotionTimeChart');
                    if (!ptCtx) return;

                    const beltColorMap = {
                        'white': 'rgba(156, 163, 175, 0.8)',
                        'yellow': 'rgba(250, 204, 21, 0.8)',
                        'orange': 'rgba(251, 146, 60, 0.8)',
                        'green': 'rgba(34, 197, 94, 0.8)',
                        'blue': 'rgba(59, 130, 246, 0.8)',
                        'purple': 'rgba(168, 85, 247, 0.8)',
                        'brown': 'rgba(217, 119, 6, 0.8)',
                        'red': 'rgba(239, 68, 68, 0.8)',
                        'black': 'rgba(55, 65, 81, 0.8)'
                    };

                    const beltBorderMap = {
                        'white': 'rgba(156, 163, 175, 1)',
                        'yellow': 'rgba(250, 204, 21, 1)',
                        'orange': 'rgba(251, 146, 60, 1)',
                        'green': 'rgba(34, 197, 94, 1)',
                        'blue': 'rgba(59, 130, 246, 1)',
                        'purple': 'rgba(168, 85, 247, 1)',
                        'brown': 'rgba(217, 119, 6, 1)',
                        'red': 'rgba(239, 68, 68, 1)',
                        'black': 'rgba(55, 65, 81, 1)'
                    };

                    const ptLabels = <?php echo json_encode(array_map(function($r) { return ucfirst($r['belt_rank']); }, $avg_promotion_time)); ?>;
                    const ptData = <?php echo json_encode(array_map(function($r) { return (float) $r['avg_days']; }, $avg_promotion_time)); ?>;
                    const ptBeltKeys = <?php echo json_encode(array_map(function($r) { return strtolower(trim($r['belt_rank'])); }, $avg_promotion_time)); ?>;

                    const ptColors = ptBeltKeys.map(k => beltColorMap[k] || 'rgba(156, 163, 175, 0.8)');
                    const ptBorders = ptBeltKeys.map(k => beltBorderMap[k] || 'rgba(156, 163, 175, 1)');

                    new Chart(ptCtx, {
                        type: 'bar',
                        data: {
                            labels: ptLabels,
                            datasets: [{
                                label: 'Avg Days',
                                data: ptData,
                                backgroundColor: ptColors,
                                borderColor: ptBorders,
                                borderWidth: 1
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                datalabels: {
                                    anchor: 'end',
                                    align: 'right',
                                    color: '#374151',
                                    font: { weight: 'bold', size: 12 },
                                    formatter: function(value) {
                                        return value + ' days';
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    title: { display: true, text: 'Days', color: '#6B7280' },
                                    grid: { color: 'rgba(0,0,0,0.05)' }
                                },
                                y: {
                                    grid: { display: false }
                                }
                            }
                        },
                        plugins: [ChartDataLabels]
                    });
                });
                </script>
            </div>
            <!-- Summary Table -->
            <div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Belt Rank</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Days</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Months</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($avg_promotion_time as $apt):
                                $avgDays = (float) $apt['avg_days'];
                                $avgMonths = $avgDays / 30.44;
                                $beltKey = strtolower(trim($apt['belt_rank']));
                                $beltDotColors = [
                                    'white' => 'bg-gray-300', 'yellow' => 'bg-yellow-400', 'orange' => 'bg-orange-400',
                                    'green' => 'bg-green-500', 'blue' => 'bg-blue-500', 'purple' => 'bg-purple-500',
                                    'brown' => 'bg-amber-700', 'red' => 'bg-red-500', 'black' => 'bg-gray-800',
                                ];
                                $dotColor = $beltDotColors[$beltKey] ?? 'bg-gray-400';
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-sm font-medium text-gray-800">
                                    <span class="inline-block w-3 h-3 rounded-full <?php echo $dotColor; ?> mr-2 align-middle"></span>
                                    <?php echo htmlspecialchars(ucfirst($apt['belt_rank'])); ?>
                                </td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($avgDays, 0); ?></td>
                                <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($avgMonths, 1); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No promotion time data available.</p>
        <?php endif; ?>
    </div>

    <!-- Promotion Velocity by Cohort -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Promotion Velocity by Cohort</h2>
        <p class="text-sm text-gray-500 mb-4">Average promotions per student grouped by the year they joined.</p>
        <?php if (!empty($promotion_velocity)): ?>
        <?php
            $maxVelocity = max(array_column($promotion_velocity, 'promotions_per_student'));
        ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Join Year</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Students</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Promotions</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Promotions/Student</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($promotion_velocity as $pv):
                        $velocity = (float) $pv['promotions_per_student'];
                        $isHighest = ($velocity == $maxVelocity);
                    ?>
                    <tr class="hover:bg-gray-50 <?php echo $isHighest ? 'bg-green-50' : ''; ?>">
                        <td class="px-4 py-2 text-sm font-bold text-gray-800"><?php echo htmlspecialchars($pv['cohort_year']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($pv['student_count']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($pv['total_promotions']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold <?php echo $isHighest ? 'text-green-700' : 'text-gray-800'; ?>">
                            <?php echo number_format($velocity, 2); ?>
                            <?php if ($isHighest): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700">Highest</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No promotion velocity data available.</p>
        <?php endif; ?>
    </div>

    <!-- Instructor Promotion Awards -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Instructor Promotion Awards (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Number of belt promotions awarded by each instructor during the selected period.</p>
        <?php if (!empty($instructor_promotions_detail)): ?>
        <?php
            $maxInstructorPromo = max(1, max(array_column($instructor_promotions_detail, 'promotion_count')));
        ?>
        <div class="space-y-3">
            <?php foreach ($instructor_promotions_detail as $ipd):
                $ipPct = ($ipd['promotion_count'] / $maxInstructorPromo) * 100;
            ?>
            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($ipd['full_name']); ?></span>
                    <span class="text-sm font-bold text-gray-800"><?php echo number_format($ipd['promotion_count']); ?> promotion<?php echo $ipd['promotion_count'] != 1 ? 's' : ''; ?></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-5">
                    <div class="bg-indigo-500 rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                         style="width: <?php echo max($ipPct, 5); ?>%;">
                        <?php echo number_format($ipd['promotion_count']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Instructor Summary Table -->
        <div class="overflow-x-auto mt-6">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Promotions Awarded</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php $totalInstructorPromos = 0; ?>
                    <?php foreach ($instructor_promotions_detail as $ipd):
                        $totalInstructorPromos += (int) $ipd['promotion_count'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($ipd['full_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo number_format($ipd['promotion_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-gray-50 font-bold">
                        <td class="px-4 py-2 text-sm text-gray-800">Total</td>
                        <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo number_format($totalInstructorPromos); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No instructor promotion data for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Belt Test Pass Rates -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Belt Test Pass Rates</h2>
        <p class="text-sm text-gray-500 mb-4">Pass rates for belt testing events.</p>
        <?php if (!empty($belt_test_pass_rates)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event Name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Tested</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Passed</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pass Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($belt_test_pass_rates as $btp):
                        $passRate = (float) ($btp['pass_rate'] ?? 0);
                        if ($passRate >= 80) {
                            $prBarColor = 'bg-green-500';
                            $prTextColor = 'text-green-700';
                        } elseif ($passRate >= 60) {
                            $prBarColor = 'bg-yellow-500';
                            $prTextColor = 'text-yellow-700';
                        } else {
                            $prBarColor = 'bg-red-500';
                            $prTextColor = 'text-red-700';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($btp['event_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-600"><?php echo formatDate($btp['event_date']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($btp['total_tested']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo number_format($btp['total_passed']); ?></td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[120px]">
                                    <div class="h-3 rounded-full <?php echo $prBarColor; ?>"
                                         style="width: <?php echo min($passRate, 100); ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $prTextColor; ?>">
                                    <?php echo number_format($passRate, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No belt test pass rate data available.</p>
        <?php endif; ?>
    </div>

    </div><!-- /tab-belt-progression -->
