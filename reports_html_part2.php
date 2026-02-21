
    <!-- ============================================= -->
    <!-- REVENUE TAB -->
    <!-- ============================================= -->
    <div id="tab-revenue" class="report-panel">

    <!-- Projected vs Actual Income -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Projected vs Actual Income</h2>
        <p class="text-sm text-gray-500 mb-4">Projected income is based on currently active memberships and their plan prices.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Monthly -->
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Monthly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div>
                        <p class="text-xs text-gray-500">Projected</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_monthly); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Actual</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['monthly_revenue']); ?></p>
                    </div>
                </div>
                <?php
                $monthlyVariance = $projected_monthly > 0 ? (($stats['monthly_revenue'] - $projected_monthly) / $projected_monthly) * 100 : 0;
                $mvColor = $monthlyVariance >= 0 ? 'text-green-600' : 'text-red-600';
                $mvSign = $monthlyVariance >= 0 ? '+' : '';
                ?>
                <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Variance</span>
                    <span class="text-sm font-semibold <?php echo $mvColor; ?>"><?php echo $mvSign . number_format($monthlyVariance, 1); ?>%</span>
                </div>
                <?php $monthlyPct = $projected_monthly > 0 ? min(100, ($stats['monthly_revenue'] / $projected_monthly) * 100) : 0; ?>
                <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $monthlyPct >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo $monthlyPct; ?>%;"></div>
                </div>
            </div>

            <!-- Yearly -->
            <div class="border rounded-lg p-5">
                <h3 class="text-sm font-medium text-gray-500 uppercase mb-3">Yearly</h3>
                <div class="flex items-end justify-between mb-2">
                    <div>
                        <p class="text-xs text-gray-500">Projected</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo formatMoney($projected_yearly); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Actual YTD</p>
                        <p class="text-2xl font-bold text-green-600"><?php echo formatMoney($stats['yearly_revenue']); ?></p>
                    </div>
                </div>
                <?php
                $yearlyVariance = $projected_yearly > 0 ? (($stats['yearly_revenue'] - $projected_yearly) / $projected_yearly) * 100 : 0;
                $yvColor = $yearlyVariance >= 0 ? 'text-green-600' : 'text-red-600';
                $yvSign = $yearlyVariance >= 0 ? '+' : '';
                $monthOfYear = (int) date('n');
                $expectedYtd = $projected_yearly * ($monthOfYear / 12);
                ?>
                <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Expected YTD: <?php echo formatMoney($expectedYtd); ?></span>
                    <span class="text-sm font-semibold <?php echo $yvColor; ?>"><?php echo $yvSign . number_format($yearlyVariance, 1); ?>%</span>
                </div>
                <?php $yearlyPct = $projected_yearly > 0 ? min(100, ($stats['yearly_revenue'] / $projected_yearly) * 100) : 0; ?>
                <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                    <div class="h-2 rounded-full <?php echo $yearlyPct >= 80 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo $yearlyPct; ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Trend Chart.js -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue Trend (Last 12 Months)</h2>
        <?php
        $maxMonthlyRevenue = 0;
        $totalChartRevenue = 0;
        $monthCount = count($monthly_revenue);
        foreach ($monthly_revenue as $d) {
            $val = (float) $d['total'];
            if ($val > $maxMonthlyRevenue) $maxMonthlyRevenue = $val;
            $totalChartRevenue += $val;
        }
        $avgMonthlyRevenue = $monthCount > 0 ? $totalChartRevenue / $monthCount : 0;
        ?>
        <p class="text-sm text-gray-500 mb-4">
            Total: <strong class="text-gray-800"><?php echo formatMoney($totalChartRevenue); ?></strong>
            &nbsp;&middot;&nbsp;
            Average: <strong class="text-gray-800"><?php echo formatMoney($avgMonthlyRevenue); ?>/mo</strong>
        </p>
        <?php if (!empty($monthly_revenue)): ?>
        <div style="position: relative; height: 350px;">
            <canvas id="revenueTrendChart"></canvas>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No revenue data for the last 12 months.</p>
        <?php endif; ?>
    </div>

    <!-- Revenue by Category -->
    <?php if (!empty($revenue_by_category)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue by Category (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Breakdown of revenue by payment type for the selected period.</p>
        <?php
        $catBarColors = ['membership' => 'bg-blue-500', 'event' => 'bg-purple-500', 'merchandise' => 'bg-orange-500', 'other' => 'bg-gray-400'];
        $catTextColors = ['membership' => 'text-blue-700', 'event' => 'text-purple-700', 'merchandise' => 'text-orange-700', 'other' => 'text-gray-600'];
        $catLabels = ['membership' => 'Memberships', 'event' => 'Events', 'merchandise' => 'Merchandise', 'other' => 'Other'];
        $maxCatRevenue = max(1, max(array_column($revenue_by_category, 'total')));
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Chart -->
            <div class="space-y-4">
                <?php foreach ($revenue_by_category as $rc):
                    $catPct = ($rc['total'] / $maxCatRevenue) * 100;
                    $barColor = $catBarColors[$rc['payment_type']] ?? 'bg-gray-400';
                    $textColor = $catTextColors[$rc['payment_type']] ?? 'text-gray-600';
                    $label = $catLabels[$rc['payment_type']] ?? ucfirst($rc['payment_type']);
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium <?php echo $textColor; ?>"><?php echo $label; ?></span>
                        <span class="text-sm font-bold text-gray-800"><?php echo formatMoney($rc['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-5">
                        <div class="<?php echo $barColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                             style="width: <?php echo max($catPct, 5); ?>%;">
                            <?php echo $rc['cnt']; ?> txn<?php echo $rc['cnt'] != 1 ? 's' : ''; ?>
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
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of Total</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($revenue_by_category as $rc):
                            $pct = $total_category_revenue > 0 ? round(($rc['total'] / $total_category_revenue) * 100, 1) : 0;
                            $label = $catLabels[$rc['payment_type']] ?? ucfirst($rc['payment_type']);
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo $label; ?></td>
                            <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($rc['total']); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $pct; ?>%</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($rc['cnt']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold">
                            <td class="px-4 py-2 text-sm text-gray-800">Total</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($total_category_revenue); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600">100%</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format(array_sum(array_column($revenue_by_category, 'cnt'))); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Revenue by Payment Method -->
    <?php if (!empty($revenue_by_method)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-1">Revenue by Payment Method (<?php echo $range_label; ?>)</h2>
        <p class="text-sm text-gray-500 mb-4">Breakdown of revenue by how payments were collected.</p>
        <?php
        $methodBarColors = ['credit_card' => 'bg-blue-500', 'bank_transfer' => 'bg-green-500', 'cash' => 'bg-yellow-500', 'debit_card' => 'bg-indigo-500', 'other' => 'bg-gray-400'];
        $methodTextColors = ['credit_card' => 'text-blue-700', 'bank_transfer' => 'text-green-700', 'cash' => 'text-yellow-700', 'debit_card' => 'text-indigo-700', 'other' => 'text-gray-600'];
        $methodLabels = ['credit_card' => 'Credit Card', 'bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'debit_card' => 'Debit Card', 'other' => 'Other'];
        $maxMethodRevenue = max(1, max(array_column($revenue_by_method, 'total')));
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Chart -->
            <div class="space-y-4">
                <?php foreach ($revenue_by_method as $rm):
                    $methPct = ($rm['total'] / $maxMethodRevenue) * 100;
                    $barColor = $methodBarColors[$rm['payment_method']] ?? 'bg-gray-400';
                    $textColor = $methodTextColors[$rm['payment_method']] ?? 'text-gray-600';
                    $label = $methodLabels[$rm['payment_method']] ?? ucfirst(str_replace('_', ' ', $rm['payment_method']));
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium <?php echo $textColor; ?>"><?php echo $label; ?></span>
                        <span class="text-sm font-bold text-gray-800"><?php echo formatMoney($rm['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-5">
                        <div class="<?php echo $barColor; ?> rounded-full h-5 flex items-center justify-end pr-2 text-white text-xs font-semibold transition-all"
                             style="width: <?php echo max($methPct, 5); ?>%;">
                            <?php echo $rm['cnt']; ?> txn<?php echo $rm['cnt'] != 1 ? 's' : ''; ?>
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
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">% of Total</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($revenue_by_method as $rm):
                            $pct = $total_method_revenue > 0 ? round(($rm['total'] / $total_method_revenue) * 100, 1) : 0;
                            $label = $methodLabels[$rm['payment_method']] ?? ucfirst(str_replace('_', ' ', $rm['payment_method']));
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-sm font-medium text-gray-800"><?php echo $label; ?></td>
                            <td class="px-4 py-2 text-sm text-right font-semibold text-gray-800"><?php echo formatMoney($rm['total']); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo $pct; ?>%</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format($rm['cnt']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold">
                            <td class="px-4 py-2 text-sm text-gray-800">Total</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-800"><?php echo formatMoney($total_method_revenue); ?></td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600">100%</td>
                            <td class="px-4 py-2 text-sm text-right text-gray-600"><?php echo number_format(array_sum(array_column($revenue_by_method, 'cnt'))); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
