
    <!-- ============================================= -->
    <!-- ATTENDANCE TAB -->
    <!-- ============================================= -->
    <div id="tab-attendance" class="report-panel hidden">

    <!-- Attendance Trends Chart.js -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Attendance Trends (Last 12 Months)</h2>
        <p class="text-sm text-gray-500 mb-4">Monthly attendance rate showing the percentage of present records over total.</p>
        <?php if (!empty($attendance_trends)): ?>
        <div style="position: relative; height: 300px;">
            <canvas id="attendanceTrendChart"></canvas>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No attendance data for the last 12 months.</p>
        <?php endif; ?>
    </div>

    <!-- Class Attendance Reports -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Class Attendance (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Attendance statistics per active class for the selected period.</p>
        <?php if (!empty($class_attendance)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Day</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Sessions</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attendance Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($class_attendance as $ca): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ca['name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo $ca['start_time'] ? date('g:i A', strtotime($ca['start_time'])) : ''; ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-center text-gray-600"><?php echo $ca['day_of_week']; ?></td>
                        <td class="px-6 py-4 text-sm text-center text-gray-800 font-semibold"><?php echo $ca['total_sessions']; ?></td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-green-700"><?php echo $ca['total_present']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-red-600"><?php echo $ca['total_absent']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-yellow-600"><?php echo $ca['total_late']; ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <?php $rate = (float) ($ca['avg_rate'] ?? 0); ?>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[120px]">
                                    <div class="h-3 rounded-full <?php echo $rate >= 80 ? 'bg-green-500' : ($rate >= 60 ? 'bg-yellow-500' : 'bg-red-500'); ?>"
                                         style="width: <?php echo $rate; ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $rate >= 80 ? 'text-green-700' : ($rate >= 60 ? 'text-yellow-700' : 'text-red-700'); ?>">
                                    <?php echo number_format($rate, 1); ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No attendance data for active classes.</p>
        <?php endif; ?>
    </div>

    <!-- Class Capacity Utilization -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-2">Class Capacity Utilization</h2>
        <p class="text-sm text-gray-500 mb-4">Current enrollment vs maximum capacity for each active class.</p>
        <?php if (!empty($class_capacity)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Day</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Enrolled</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Max</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Utilization</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($class_capacity as $cc):
                        $util = (float) ($cc['utilization_pct'] ?? 0);
                        if ($util > 90) { $utilColor = 'bg-red-500'; $utilTextColor = 'text-red-700'; }
                        elseif ($util >= 60) { $utilColor = 'bg-yellow-500'; $utilTextColor = 'text-yellow-700'; }
                        else { $utilColor = 'bg-gray-400'; $utilTextColor = 'text-gray-600'; }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cc['name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo $cc['start_time'] ? date('g:i A', strtotime($cc['start_time'])) : ''; ?></div>
                        </td>
                        <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo $cc['day_of_week']; ?></td>
                        <td class="px-4 py-2 text-sm text-center font-semibold text-gray-800"><?php echo $cc['enrolled']; ?></td>
                        <td class="px-4 py-2 text-sm text-center text-gray-600"><?php echo $cc['max_students'] ?: 'N/A'; ?></td>
                        <td class="px-4 py-2">
                            <?php if ($cc['max_students']): ?>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[150px]">
                                    <div class="h-3 rounded-full <?php echo $utilColor; ?>" style="width: <?php echo min($util, 100); ?>%;"></div>
                                </div>
                                <span class="text-sm font-semibold <?php echo $utilTextColor; ?>"><?php echo number_format($util, 1); ?>%</span>
                            </div>
                            <?php else: ?>
                            <span class="text-sm text-gray-400">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No class capacity data available.</p>
        <?php endif; ?>
    </div>

    <!-- Top Attendance -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Students by Attendance (<?php echo $range_label; ?>)</h2>
        <div class="space-y-3">
            <?php foreach ($top_attendance as $index => $student): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo $index + 1; ?>
                        </div>
                        <span class="font-medium text-gray-900">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </span>
                    </div>
                    <span class="text-blue-600 font-semibold">
                        <?php echo $student['attendance_count']; ?> classes
                    </span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($top_attendance)): ?>
                <p class="text-center text-gray-500 py-8">No attendance data for <?php echo htmlspecialchars($range_label); ?></p>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- /tab-attendance -->
