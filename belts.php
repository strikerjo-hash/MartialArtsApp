<?php
require_once 'config.php';
requireLogin();

$message = '';

// Handle belt promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'promote') {
        $stmt = $pdo->prepare("
            INSERT INTO student_belts (student_id, belt_id, style_id, awarded_date, instructor_id, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['student_id'],
            $_POST['belt_id'],
            $_POST['style_id'],
            $_POST['awarded_date'],
            $_SESSION['user_id'],
            sanitizeInput($_POST['notes'])
        ]);
        $message = showAlert('Student promoted successfully!', 'success');
    }
}

// Get all styles and their belts
$styles = $pdo->query("
    SELECT mas.*, COUNT(b.id) as belt_count
    FROM martial_arts_styles mas
    LEFT JOIN belts b ON mas.id = b.style_id
    GROUP BY mas.id
    ORDER BY mas.name
")->fetchAll();

// Get recent belt promotions
$recent_promotions = $pdo->query("
    SELECT sb.*, 
           s.first_name, s.last_name,
           b.name as belt_name, b.color,
           mas.name as style_name,
           u.full_name as instructor_name
    FROM student_belts sb
    JOIN students s ON sb.student_id = s.id
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    LEFT JOIN users u ON sb.instructor_id = u.id
    ORDER BY sb.awarded_date DESC
    LIMIT 20
")->fetchAll();

// Get students for dropdown
$students = $pdo->query("SELECT id, first_name, last_name FROM students WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Belt System</h1>
        <button onclick="document.getElementById('promoteModal').classList.remove('hidden')" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
            + Promote Student
        </button>
    </div>
    
    <!-- Belt Systems by Style -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <?php foreach ($styles as $style): ?>
            <?php
            $belts = $pdo->prepare("SELECT * FROM belts WHERE style_id = ? ORDER BY rank_order ASC");
            $belts->execute([$style['id']]);
            $belts = $belts->fetchAll();
            ?>
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 to-blue-600">
                    <h2 class="text-xl font-semibold text-white"><?php echo $style['name']; ?></h2>
                    <p class="text-sm text-blue-100 mt-1"><?php echo count($belts); ?> belts</p>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        <?php foreach ($belts as $belt): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-sm"
                                         style="background-color: <?php 
                                         $colors = [
                                             'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
                                             'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
                                             'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000'
                                         ];
                                         echo $colors[$belt['color']] ?? '#6B7280';
                                         ?>; <?php echo $belt['color'] === 'White' || $belt['color'] === 'Yellow' ? 'color: #000;' : ''; ?>">
                                        <?php echo $belt['rank_order']; ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900"><?php echo $belt['name']; ?></p>
                                        <?php if ($belt['requirements']): ?>
                                            <p class="text-xs text-gray-600"><?php echo substr($belt['requirements'], 0, 50); ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Recent Promotions -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Recent Promotions</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Belt</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Style</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recent_promotions as $promo): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo formatDate($promo['awarded_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $promo['first_name'] . ' ' . $promo['last_name']; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 text-sm font-semibold rounded-full" 
                                      style="background-color: <?php 
                                      $colors = [
                                          'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
                                          'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
                                          'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000'
                                      ];
                                      echo $colors[$promo['color']] ?? '#6B7280';
                                      ?>; <?php echo in_array($promo['color'], ['White', 'Yellow']) ? 'color: #000;' : 'color: #FFF;'; ?>">
                                    <?php echo $promo['belt_name']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['style_name']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['instructor_name'] ?? 'N/A'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <?php echo $promo['notes'] ?: '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($recent_promotions)): ?>
                <div class="text-center py-12 text-gray-500">
                    <p class="text-lg">No promotions recorded yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Promote Student Modal -->
<div id="promoteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Promote Student</h3>
            <button onclick="document.getElementById('promoteModal').classList.add('hidden')" 
                    class="text-gray-600 hover:text-gray-800">✕</button>
        </div>
        
        <form method="POST" class="space-y-4" id="promoteForm">
            <input type="hidden" name="action" value="promote">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <select name="student_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select student...</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>">
                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="style_id" required onchange="loadBelts(this.value)"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?php echo $style['id']; ?>"><?php echo $style['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt/Rank *</label>
                <select name="belt_id" id="belt_select" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style first...</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Award Date *</label>
                <input type="date" name="awarded_date" value="<?php echo date('Y-m-d'); ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Test results, comments, etc."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('promoteModal').classList.add('hidden')"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Promote Student
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function loadBelts(styleId) {
    const beltSelect = document.getElementById('belt_select');
    beltSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!styleId) {
        beltSelect.innerHTML = '<option value="">Select style first...</option>';
        return;
    }
    
    // In a real implementation, this would be an AJAX call
    // For now, reload the page to get the belts
    const belts = <?php echo json_encode(array_reduce($styles, function($carry, $style) use ($pdo) {
        $belts = $pdo->prepare("SELECT * FROM belts WHERE style_id = ? ORDER BY rank_order ASC");
        $belts->execute([$style['id']]);
        $carry[$style['id']] = $belts->fetchAll();
        return $carry;
    }, [])); ?>;
    
    beltSelect.innerHTML = '<option value="">Select belt...</option>';
    if (belts[styleId]) {
        belts[styleId].forEach(belt => {
            const option = document.createElement('option');
            option.value = belt.id;
            option.textContent = belt.name + ' (' + belt.color + ')';
            beltSelect.appendChild(option);
        });
    }
}
</script>

<?php include 'includes/footer.php'; ?>
