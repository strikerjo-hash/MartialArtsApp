
    <!-- ============================================= -->
    <!-- ATTENDANCE PATTERNS TAB -->
    <!-- ============================================= -->
    <div id="tab-attendance-patterns" class="report-panel hidden">

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4">
        <?php echo renderExportButtons('attendance_patterns', $report_range, $date_from, $date_to); ?>
    </div>

    <!-- Attendance Heatmap -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Attendance Heatmap</h2>
        <p class="text-sm text-gray-500 mb-4">Attendance rate by day of week and hour. Darker blue indicates higher attendance.</p>
        <?php if (!empty($attendance_heatmap)):
            // Build structured data: rows keyed by day, columns by hour
            $days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $heatmap_data = [];
            $hours_in_data = [];
            foreach ($attendance_heatmap as $hm) {
                $day = $hm['day_of_week'];
                $hour = (int) $hm['hour_slot'];
                $heatmap_data[$day][$hour] = (float) $hm['rate'];
                $hours_in_data[$hour] = true;
            }
            ksort($hours_in_data);
            $hour_slots = array_keys($hours_in_data);
        ?>
        <div class="overflow-x-auto">
            <table class="border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-xs font-medium text-gray-500 text-left"></th>
                        <?php foreach ($hour_slots as $h): ?>
                        <th class="px-1 py-2 text-xs font-medium text-gray-500 text-center" style="min-width: 44px;">
                            <?php
                                if ($h == 0) { echo '12am'; }
                                elseif ($h < 12) { echo $h . 'am'; }
                                elseif ($h == 12) { echo '12pm'; }
                                else { echo ($h - 12) . 'pm'; }
                            ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($days_order as $day):
                        if (!isset($heatmap_data[$day])) continue;
                    ?>
                    <tr>
                        <td class="px-3 py-1 text-sm font-medium text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($day); ?></td>
                        <?php foreach ($hour_slots as $h):
                            $rate = isset($heatmap_data[$day][$h]) ? (float) $heatmap_data[$day][$h] : 0;
                            $opacity = $rate / 100;
                        ?>
                        <td class="px-1 py-1">
                            <div class="w-10 h-10 rounded flex items-center justify-center text-xs font-semibold cursor-default border border-gray-100"
                                 style="background-color: rgba(59, 130, 246, <?php echo number_format($opacity, 2); ?>); color: <?php echo $opacity > 0.5 ? '#ffffff' : '#374151'; ?>;"
                                 title="<?php echo htmlspecialchars($day) . ' ' . ($h < 12 ? ($h == 0 ? '12am' : $h . 'am') : ($h == 12 ? '12pm' : ($h - 12) . 'pm')); ?>: <?php echo number_format($rate, 1); ?>%">
                                <?php echo number_format($rate, 0); ?>
                            </div>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Legend -->
        <div class="flex items-center gap-2 mt-4">
            <span class="text-xs text-gray-500">0%</span>
            <div class="flex">
                <?php for ($i = 0; $i <= 10; $i++): ?>
                <div class="w-6 h-4 border border-gray-100"
                     style="background-color: rgba(59, 130, 246, <?php echo number_format($i / 10, 1); ?>);"></div>
                <?php endfor; ?>
            </div>
            <span class="text-xs text-gray-500">100%</span>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No attendance heatmap data available.</p>
        <?php endif; ?>
    </div>

    <!-- Student Consistency Scores -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Student Consistency Scores</h2>
        <p class="text-sm text-gray-500 mb-4">Top 50 students ranked by average sessions per week.</p>
        <?php if (!empty($student_consistency)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sessions/Week</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Present</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($student_consistency as $rank => $sc):
                        $position = $rank + 1;
                        if ($position === 1) {
                            $badgeClass = 'bg-yellow-400 text-yellow-900';
                            $badgeIcon = '1st';
                        } elseif ($position === 2) {
                            $badgeClass = 'bg-gray-300 text-gray-800';
                            $badgeIcon = '2nd';
                        } elseif ($position === 3) {
                            $badgeClass = 'bg-orange-400 text-orange-900';
                            $badgeIcon = '3rd';
                        } else {
                            $badgeClass = '';
                            $badgeIcon = '';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm">
                            <?php if ($position <= 3): ?>
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-bold <?php echo $badgeClass; ?>">
                                <?php echo $badgeIcon; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-600 font-medium"><?php echo $position; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($sc['first_name'] . ' ' . $sc['last_name']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-semibold text-blue-700">
                            <?php echo number_format((float) $sc['sessions_per_week'], 1); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800">
                            <?php echo number_format((int) $sc['total_present']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No student consistency data available.</p>
        <?php endif; ?>
    </div>

    <!-- Dropout Risk (Declining Attendance) -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Dropout Risk &mdash; Declining Attendance</h2>
        <p class="text-sm text-gray-500 mb-4">Students whose attendance has dropped compared to the previous 30-day period. Sorted by steepest decline.</p>
        <?php if (!empty($dropout_risk)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Last 30 Days</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Previous 30 Days</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Change %</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($dropout_risk as $dr):
                        $changePct = (float) $dr['change_pct'];
                        $changeColor = $changePct < 0 ? 'text-red-600' : 'text-green-600';
                        $changePrefix = $changePct > 0 ? '+' : '';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($dr['first_name'] . ' ' . $dr['last_name']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800">
                            <?php echo number_format((int) $dr['last_30d']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800">
                            <?php echo number_format((int) $dr['prev_30d']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-bold <?php echo $changeColor; ?>">
                            <?php echo $changePrefix . number_format($changePct, 1); ?>%
                        </td>
                        <td class="px-4 py-3 text-sm text-center">
                            <a href="student_detail.php?id=<?php echo (int) $dr['id']; ?>"
                               class="inline-flex items-center px-3 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-medium rounded-lg transition-colors">
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No dropout risk data available. This requires at least 60 days of attendance history.</p>
        <?php endif; ?>
    </div>

    <!-- Seasonal Patterns Chart -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Seasonal Attendance Patterns</h2>
        <p class="text-sm text-gray-500 mb-4">Average attendance rate by month across all available data.</p>
        <?php if (!empty($seasonal_patterns)): ?>
        <div style="position: relative; height: 350px;">
            <canvas id="seasonalPatternChart"></canvas>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No seasonal pattern data available.</p>
        <?php endif; ?>

        <?php if (!empty($seasonal_patterns)): ?>
        <script>
        (function(){
            var seasonalCtx = document.getElementById('seasonalPatternChart');
            if (!seasonalCtx) return;
            var labels = <?php echo json_encode(array_map(function($sp) { return $sp['month_name']; }, $seasonal_patterns)); ?>;
            var data = <?php echo json_encode(array_map(function($sp) { return round((float) $sp['attendance_rate'], 1); }, $seasonal_patterns)); ?>;
            var colors = data.map(function(r){ return r>=80?'rgba(34,197,94,0.8)':r>=60?'rgba(234,179,8,0.8)':'rgba(239,68,68,0.8)'; });
            var borders = data.map(function(r){ return r>=80?'rgba(34,197,94,1)':r>=60?'rgba(234,179,8,1)':'rgba(239,68,68,1)'; });
            new Chart(seasonalCtx, {
                type:'bar', data:{labels:labels, datasets:[{label:'Attendance Rate %',data:data,backgroundColor:colors,borderColor:borders,borderWidth:1,borderRadius:4}]},
                plugins:[ChartDataLabels],
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false},datalabels:{anchor:'end',align:'top',color:'#374151',font:{weight:'bold',size:11},formatter:function(v){return v.toFixed(1)+'%';}},
                        tooltip:{callbacks:{label:function(c){return 'Attendance: '+c.parsed.y.toFixed(1)+'%';}}}},
                    scales:{y:{beginAtZero:true,max:100,ticks:{callback:function(v){return v+'%';}},grid:{color:'rgba(0,0,0,0.05)'}},x:{grid:{display:false}}},
                    layout:{padding:{top:20}}}
            });
        })();
        </script>
        <?php endif; ?>
    </div>

    </div><!-- /tab-attendance-patterns -->
