
    <!-- ============================================= -->
    <!-- EVENTS TAB -->
    <!-- ============================================= -->
    <div id="tab-events" class="report-panel hidden">

    <!-- Event Revenue by Type -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Event Revenue by Type (<?php echo $range_label; ?>)</h2>
        <?php if (!empty($event_revenue_summary)):
            $maxEventRev = max(1, max(array_column($event_revenue_summary, 'total_revenue')));
            $eventTypeColors = ['tournament' => 'bg-red-500', 'seminar' => 'bg-blue-500', 'workshop' => 'bg-purple-500', 'belt_test' => 'bg-yellow-500', 'other' => 'bg-gray-400'];
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- CSS Bars -->
            <div class="space-y-4">
                <?php foreach ($event_revenue_summary as $ers):
                    $erPct = ($ers['total_revenue'] / $maxEventRev) * 100;
                    $erColor = $eventTypeColors[$ers['event_type']] ?? 'bg-gray-400';
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-gray-700"><?php echo ucfirst(str_replace('_', ' ', $ers['event_type'])); ?></span>
                        <span class="text-sm font-bold text-gray-800"><?php echo formatMoney($ers['total_revenue']); ?></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-5">
                        <div class="<?php echo $erColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                             style="width: <?php echo max($erPct, 5); ?>%;">
                            <?php echo $ers['registrations']; ?> reg
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Summary Table -->
            <div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Events</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Registrations</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($event_revenue_summary as $ers): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo ucfirst(str_replace('_', ' ', $ers['event_type'])); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ers['event_count']); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ers['registrations']); ?></td>
                            <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ers['total_revenue']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No event data for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Event ROI / Attendance Analysis -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Event ROI & Attendance Analysis (<?php echo $range_label; ?>)</h2>
        <?php if (!empty($event_roi)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Fee</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Registered</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Attended</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">No-Shows</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fill Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($event_roi as $er):
                        $fillRate = (float) ($er['fill_rate'] ?? 0);
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($er['name']); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-600"><?php echo ucfirst(str_replace('_', ' ', $er['event_type'])); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-600"><?php echo formatDate($er['event_date']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo formatMoney($er['registration_fee']); ?></td>
                        <td class="px-4 py-2 text-sm text-center text-gray-800 font-semibold"><?php echo $er['registrations']; ?></td>
                        <td class="px-4 py-2 text-sm text-center text-green-700 font-semibold"><?php echo $er['attended']; ?></td>
                        <td class="px-4 py-2 text-sm text-center <?php echo $er['no_shows'] > 0 ? 'text-red-600 font-semibold' : 'text-gray-600'; ?>">
                            <?php echo $er['no_shows']; ?>
                        </td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($er['actual_revenue']); ?></td>
                        <td class="px-4 py-2">
                            <?php if ($er['max_participants']): ?>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 rounded-full h-3 max-w-[100px]">
                                    <div class="h-3 rounded-full <?php echo $fillRate > 90 ? 'bg-green-500' : ($fillRate >= 50 ? 'bg-yellow-500' : 'bg-red-500'); ?>"
                                         style="width: <?php echo min($fillRate, 100); ?>%;"></div>
                                </div>
                                <span class="text-xs font-semibold text-gray-600"><?php echo number_format($fillRate, 1); ?>%</span>
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
        <p class="text-center text-gray-500 py-4">No event data for this period.</p>
        <?php endif; ?>
    </div>

    </div><!-- /tab-events -->
