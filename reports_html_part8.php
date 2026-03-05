
    <!-- ============================================= -->
    <!-- INSTRUCTOR PERFORMANCE TAB -->
    <!-- ============================================= -->
    <div id="tab-instructors" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('instructors', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Instructor Performance Chart -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Students per Instructor (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Horizontal bar chart showing total students managed by each instructor.</p>
        <?php if (!empty($instructor_summary)): ?>
        <div style="position: relative; height: <?php echo max(250, count($instructor_summary) * 45); ?>px;">
            <canvas id="instructorChart"></canvas>
        </div>
        <script>
        (function(){
            const ctx = document.getElementById('instructorChart').getContext('2d');
            const labels = <?php echo json_encode(array_map(function($r){ return $r['full_name']; }, $instructor_summary)); ?>;
            const data = <?php echo json_encode(array_map(function($r){ return (int)$r['student_count']; }, $instructor_summary)); ?>;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Students',
                        data: data,
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(20, 184, 166, 0.8)',
                            'rgba(6, 182, 212, 0.8)',
                            'rgba(34, 197, 94, 0.8)',
                            'rgba(52, 211, 153, 0.8)',
                            'rgba(45, 212, 191, 0.8)',
                            'rgba(94, 234, 212, 0.8)',
                            'rgba(110, 231, 183, 0.8)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(20, 184, 166, 1)',
                            'rgba(6, 182, 212, 1)',
                            'rgba(34, 197, 94, 1)',
                            'rgba(52, 211, 153, 1)',
                            'rgba(45, 212, 191, 1)',
                            'rgba(94, 234, 212, 1)',
                            'rgba(110, 231, 183, 1)'
                        ],
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
                            formatter: function(v) { return v.toLocaleString(); },
                            font: { weight: 'bold', size: 11 },
                            color: '#374151'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) { return c.raw.toLocaleString() + ' students'; }
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { display: true }, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    },
                    layout: { padding: { right: 30 } }
                }
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No instructor data available for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Instructor Summary Table -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Instructor Summary (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Performance overview for each instructor including classes taught, students, and attendance rates.</p>
        <?php if (!empty($instructor_summary)):
            // Sort by student_count descending
            $sorted_summary = $instructor_summary;
            usort($sorted_summary, function($a, $b) { return (int)$b['student_count'] - (int)$a['student_count']; });
        ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Classes Taught</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Students</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Sessions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attendance Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($sorted_summary as $is):
                        $attRate = (float)($is['attendance_rate'] ?? 0);
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($is['full_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-center font-semibold text-gray-800">
                            <?php echo number_format((int)$is['class_count']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-center font-semibold text-teal-700">
                            <?php echo number_format((int)$is['student_count']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-center text-gray-600">
                            <?php echo number_format((int)$is['total_sessions']); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[120px]">
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
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No instructor summary data available for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Belt Promotions by Instructor -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Belt Promotions by Instructor (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Number of belt promotions awarded by each instructor during the selected period.</p>
        <?php if (!empty($instructor_promotions)):
            $maxPromo = max(1, max(array_column($instructor_promotions, 'promotion_count')));
        ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Promotions Awarded</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($instructor_promotions as $ip):
                        $promoPct = ((int)$ip['promotion_count'] / $maxPromo) * 100;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ip['full_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-center font-semibold text-gray-800">
                            <?php echo number_format((int)$ip['promotion_count']); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="w-full bg-gray-100 rounded-full h-5 max-w-[250px]">
                                <div class="bg-teal-500 rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                                     style="width: <?php echo max($promoPct, 8); ?>%;">
                                    <?php echo number_format((int)$ip['promotion_count']); ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No promotion data available for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Class Capacity by Instructor -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Class Capacity by Instructor</h2>
        <p class="text-sm text-gray-500 mb-4">Aggregate enrollment vs maximum capacity across all classes for each instructor.</p>
        <?php if (!empty($instructor_capacity)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Total Max Capacity</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Total Enrolled</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Utilization</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($instructor_capacity as $ic):
                        $util = (float)($ic['utilization_pct'] ?? 0);
                        if ($util >= 90) {
                            $utilColor = 'bg-red-500';
                            $utilTextColor = 'text-red-700';
                        } elseif ($util >= 60) {
                            $utilColor = 'bg-yellow-500';
                            $utilTextColor = 'text-yellow-700';
                        } else {
                            $utilColor = 'bg-gray-400';
                            $utilTextColor = 'text-gray-600';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ic['full_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-center text-gray-600">
                            <?php echo number_format((int)$ic['total_max']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-center font-semibold text-gray-800">
                            <?php echo number_format((int)$ic['total_enrolled']); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[150px]">
                                    <div class="h-3 rounded-full <?php echo $utilColor; ?>"
                                         style="width: <?php echo min($util, 100); ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $utilTextColor; ?>">
                                    <?php echo number_format($util, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No class capacity data available for instructors.</p>
        <?php endif; ?>
    </div>

    </div><!-- /tab-instructors -->
