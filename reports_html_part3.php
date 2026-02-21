
    <!-- Year-over-Year Revenue Chart.js -->
    <?php if (!empty($yearly_comparison) && count($yearly_comparison) > 1): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Year-over-Year Revenue</h2>
        <p class="text-sm text-gray-500 mb-4">Annual revenue and refund comparison across all years with payment data.</p>
        <div style="position: relative; height: 350px;">
            <canvas id="yoyRevenueChart"></canvas>
        </div>
        <!-- Legend -->
        <div class="flex items-center justify-center gap-6 mt-4 mb-4">
            <div class="flex items-center gap-2"><div class="w-4 h-4 bg-emerald-500 rounded"></div><span class="text-sm text-gray-600">Revenue</span></div>
            <div class="flex items-center gap-2"><div class="w-4 h-4 bg-red-400 rounded"></div><span class="text-sm text-gray-600">Refunds</span></div>
        </div>
        <!-- Summary Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Year</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Refunds</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Net Revenue</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Transaction</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $grandRevenue = 0; $grandRefunds = 0; $grandTxns = 0;
                    foreach ($yearly_comparison as $yc):
                        $net = $yc['revenue'] - $yc['refunds'];
                        $avg = $yc['txn_count'] > 0 ? $yc['revenue'] / $yc['txn_count'] : 0;
                        $grandRevenue += $yc['revenue'];
                        $grandRefunds += $yc['refunds'];
                        $grandTxns += $yc['txn_count'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-bold text-gray-800"><?php echo $yc['yr']; ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($yc['revenue']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-red-600"><?php echo $yc['refunds'] > 0 ? '-' . formatMoney($yc['refunds']) : '$0.00'; ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($net); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($yc['txn_count']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo formatMoney($avg); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-gray-50 font-bold">
                        <td class="px-4 py-2 text-sm text-gray-800">All Time</td>
                        <td class="px-4 py-2 text-sm text-right text-green-700"><?php echo formatMoney($grandRevenue); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-red-600"><?php echo $grandRefunds > 0 ? '-' . formatMoney($grandRefunds) : '$0.00'; ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($grandRevenue - $grandRefunds); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($grandTxns); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $grandTxns > 0 ? formatMoney($grandRevenue / $grandTxns) : '$0.00'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Revenue Per Student -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Revenue Per Student (<?php echo $range_label; ?>)</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="border rounded-lg p-5 text-center">
                <p class="text-xs text-gray-500 uppercase mb-1">Avg Revenue / Student</p>
                <p class="text-3xl font-bold text-blue-600"><?php echo formatMoney($avg_revenue_per_student); ?></p>
            </div>
            <div class="border rounded-lg p-5 text-center">
                <p class="text-xs text-gray-500 uppercase mb-1">Paying Students</p>
                <p class="text-3xl font-bold text-gray-800"><?php echo number_format($paying_student_count); ?></p>
            </div>
        </div>
        <?php if (!empty($top_spenders)): ?>
        <h3 class="text-lg font-semibold text-gray-700 mb-3">Top 10 Spenders</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Spent</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payments</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($top_spenders as $i => $ts): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-sm font-bold text-gray-500"><?php echo $i + 1; ?></td>
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($ts['first_name'] . ' ' . $ts['last_name']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold text-green-700"><?php echo formatMoney($ts['total_spent']); ?></td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($ts['payment_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-4">No payment data for this period.</p>
        <?php endif; ?>
    </div>

    <!-- Best/Worst Performing Months -->
    <?php if (!empty($best_worst_months)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Best & Worst Performing Months (Last 24 Months)</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Month</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $totalBW = count($best_worst_months);
                    foreach ($best_worst_months as $i => $bw):
                        $rank = $i + 1;
                        $rowColor = '';
                        if ($rank <= 3) $rowColor = 'bg-green-50';
                        elseif ($rank > $totalBW - 3) $rowColor = 'bg-red-50';
                    ?>
                    <tr class="hover:bg-gray-50 <?php echo $rowColor; ?>">
                        <td class="px-4 py-2 text-sm font-bold <?php echo $rank <= 3 ? 'text-green-700' : ($rank > $totalBW - 3 ? 'text-red-700' : 'text-gray-500'); ?>">
                            #<?php echo $rank; ?>
                        </td>
                        <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($bw['label']); ?></td>
                        <td class="px-4 py-2 text-sm text-right font-semibold <?php echo $rank <= 3 ? 'text-green-700' : ($rank > $totalBW - 3 ? 'text-red-700' : 'text-gray-800'); ?>">
                            <?php echo formatMoney($bw['revenue']); ?>
                        </td>
                        <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($bw['txn_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /tab-revenue -->
