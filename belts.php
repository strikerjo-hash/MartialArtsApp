<?php
require_once 'config.php';
requireLogin();

$message = '';

// Migrations have been moved to migrate.php

// Ensure uploads directory exists
$beltDocDir = 'uploads/belt_documents/';
if (!file_exists($beltDocDir)) {
    mkdir($beltDocDir, 0750, true);
}

// Handle all POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    switch ($_POST['action']) {

        // ======== STYLES ========
        case 'add_style':
            $stmt = $pdo->prepare("INSERT INTO martial_arts_styles (name, description) VALUES (?, ?)");
            $stmt->execute([sanitizeInput($_POST['style_name']), sanitizeInput($_POST['style_description'])]);
            $message = showAlert('Martial art style added successfully!', 'success');
            break;

        case 'edit_style':
            $stmt = $pdo->prepare("UPDATE martial_arts_styles SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([sanitizeInput($_POST['style_name']), sanitizeInput($_POST['style_description']), $_POST['style_id']]);
            $message = showAlert('Style updated successfully!', 'success');
            break;

        case 'delete_style':
            // Check for belts or student_belts referencing this style
            $beltCount = $pdo->prepare("SELECT COUNT(*) as c FROM belts WHERE style_id = ?");
            $beltCount->execute([$_POST['style_id']]);
            if ($beltCount->fetch()['c'] > 0) {
                $message = showAlert('Cannot delete style that has belts assigned. Remove all belts first.', 'error');
            } else {
                $pdo->prepare("DELETE FROM martial_arts_styles WHERE id = ?")->execute([$_POST['style_id']]);
                $message = showAlert('Style deleted successfully!', 'success');
            }
            break;

        // ======== BELTS ========
        case 'add_belt':
            $stmt = $pdo->prepare("INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['belt_style_id'],
                sanitizeInput($_POST['belt_name']),
                sanitizeInput($_POST['belt_color']),
                (int)$_POST['belt_rank_order'],
                sanitizeInput($_POST['belt_requirements'])
            ]);
            $message = showAlert('Belt added successfully!', 'success');
            break;

        case 'edit_belt':
            $stmt = $pdo->prepare("UPDATE belts SET name = ?, color = ?, rank_order = ?, requirements = ? WHERE id = ?");
            $stmt->execute([
                sanitizeInput($_POST['belt_name']),
                sanitizeInput($_POST['belt_color']),
                (int)$_POST['belt_rank_order'],
                sanitizeInput($_POST['belt_requirements']),
                $_POST['belt_id']
            ]);
            $message = showAlert('Belt updated successfully!', 'success');
            break;

        case 'delete_belt':
            // Check if any students hold this belt
            $promoCount = $pdo->prepare("SELECT COUNT(*) as c FROM student_belts WHERE belt_id = ?");
            $promoCount->execute([$_POST['belt_id']]);
            if ($promoCount->fetch()['c'] > 0) {
                $message = showAlert('Cannot delete belt that has been awarded to students. Remove those promotions first.', 'error');
            } else {
                $pdo->prepare("DELETE FROM belts WHERE id = ?")->execute([$_POST['belt_id']]);
                $message = showAlert('Belt deleted successfully!', 'success');
            }
            break;

        // ======== PROMOTIONS ========
        case 'promote':
            $blackBeltNumber = null;
            // Check if the belt being awarded is a Black belt
            $beltCheck = $pdo->prepare("SELECT color FROM belts WHERE id = ?");
            $beltCheck->execute([$_POST['belt_id']]);
            $beltRow = $beltCheck->fetch();
            if ($beltRow && str_starts_with(strtolower($beltRow['color']), 'black') && !empty($_POST['black_belt_number'])) {
                // Verify uniqueness of the black belt number within this style
                $dupCheck = $pdo->prepare("SELECT COUNT(*) as c FROM student_belts WHERE black_belt_number = ? AND style_id = ?");
                $dupCheck->execute([trim($_POST['black_belt_number']), $_POST['style_id']]);
                if ($dupCheck->fetch()['c'] > 0) {
                    $message = showAlert('Black belt number "' . htmlspecialchars($_POST['black_belt_number']) . '" is already assigned in this style. Each number must be unique.', 'error');
                    break;
                }
                $blackBeltNumber = trim($_POST['black_belt_number']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO student_belts (school_id, student_id, belt_id, style_id, awarded_date, instructor_id, notes, black_belt_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                current_school_id(),
                $_POST['student_id'],
                $_POST['belt_id'],
                $_POST['style_id'],
                $_POST['awarded_date'],
                $_SESSION['user_id'],
                sanitizeInput($_POST['notes']),
                $blackBeltNumber
            ]);
            $message = showAlert('Student promoted successfully!' . ($blackBeltNumber ? ' Black Belt #' . $blackBeltNumber : ''), 'success');
            break;

        case 'edit_promotion':
            // Note: black_belt_number is NOT updatable once set — it's permanent
            $editPromoParams = [
                $_POST['belt_id'],
                $_POST['style_id'],
                $_POST['awarded_date'],
                sanitizeInput($_POST['notes']),
                $_POST['promotion_id']
            ];
            $stmt = $pdo->prepare("UPDATE student_belts SET belt_id = ?, style_id = ?, awarded_date = ?, notes = ? WHERE id = ?" . school_where());
            school_param($editPromoParams);
            $stmt->execute($editPromoParams);
            $message = showAlert('Promotion updated successfully!', 'success');
            break;

        case 'delete_promotion':
            $delPromoParams = [$_POST['promotion_id']];
            $delPromoStmt = $pdo->prepare("DELETE FROM student_belts WHERE id = ?" . school_where());
            school_param($delPromoParams);
            $delPromoStmt->execute($delPromoParams);
            $message = showAlert('Promotion deleted successfully!', 'success');
            break;

        // ======== QUICK BULK BELT ASSIGNMENT ========
        case 'bulk_promote':
            $bulkStudents = $_POST['bulk_student_id'] ?? [];
            $bulkBelts    = $_POST['bulk_belt_id'] ?? [];
            $bulkStyles   = $_POST['bulk_style_id'] ?? [];
            $bulkDates    = $_POST['bulk_date'] ?? [];
            $bulkBBNums   = $_POST['bulk_bb_number'] ?? [];

            $assigned = 0;
            $skipped  = 0;
            $errors   = [];

            for ($i = 0; $i < count($bulkStudents); $i++) {
                $sid  = (int) ($bulkStudents[$i] ?? 0);
                $bid  = (int) ($bulkBelts[$i] ?? 0);
                $stid = (int) ($bulkStyles[$i] ?? 0);
                $dt   = $bulkDates[$i] ?? date('Y-m-d');
                $bbNum = trim($bulkBBNums[$i] ?? '');

                if (!$sid || !$bid || !$stid) {
                    $skipped++;
                    continue;
                }

                // Check if student already has this exact belt+style
                $dupParams = [$sid, $bid, $stid];
                $dupCheck = $pdo->prepare("SELECT id FROM student_belts WHERE student_id = ? AND belt_id = ? AND style_id = ?" . school_where());
                school_param($dupParams);
                $dupCheck->execute($dupParams);
                if ($dupCheck->fetch()) {
                    $skipped++;
                    continue;
                }

                // Check if belt is black; handle BB number
                $beltCheck = $pdo->prepare("SELECT color FROM belts WHERE id = ?");
                $beltCheck->execute([$bid]);
                $beltRow = $beltCheck->fetch();
                $finalBBNum = null;

                if ($beltRow && str_starts_with(strtolower($beltRow['color']), 'black') && $bbNum !== '') {
                    $bbDupCheck = $pdo->prepare("SELECT COUNT(*) as c FROM student_belts WHERE black_belt_number = ? AND style_id = ?");
                    $bbDupCheck->execute([$bbNum, $stid]);
                    if ($bbDupCheck->fetch()['c'] > 0) {
                        $errors[] = 'BB#' . htmlspecialchars($bbNum) . ' already exists — skipped';
                        $skipped++;
                        continue;
                    }
                    $finalBBNum = $bbNum;
                }

                try {
                    $pdo->prepare("
                        INSERT INTO student_belts (school_id, student_id, belt_id, style_id, awarded_date, instructor_id, notes, black_belt_number)
                        VALUES (?, ?, ?, ?, ?, ?, 'Bulk assignment — historical record', ?)
                    ")->execute([current_school_id(), $sid, $bid, $stid, $dt, $_SESSION['user_id'], $finalBBNum]);
                    $assigned++;
                } catch (PDOException $e) {
                    $skipped++;
                }
            }

            $msgParts = [];
            if ($assigned > 0) $msgParts[] = $assigned . ' belt(s) assigned';
            if ($skipped > 0) $msgParts[] = $skipped . ' skipped (already held or incomplete)';
            if (!empty($errors)) $msgParts[] = implode('; ', $errors);
            $message = showAlert(implode('. ', $msgParts) . '.', $assigned > 0 ? 'success' : 'error');
            break;

        // ======== BELT RESOURCES ========
        case 'add_document':
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $fileExt = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];

                if (!in_array($fileExt, $allowed)) {
                    $message = showAlert('Invalid file type. Allowed: ' . implode(', ', $allowed), 'error');
                } elseif ($_FILES['document_file']['size'] > 10485760) {
                    $message = showAlert('File too large. Max 10MB.', 'error');
                } else {
                    $newFilename = 'belt_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $fileExt;
                    if (move_uploaded_file($_FILES['document_file']['tmp_name'], $beltDocDir . $newFilename)) {
                        $firstBeltId = null;
                        $beltIds = isset($_POST['resource_belt_ids']) ? (array)$_POST['resource_belt_ids'] : [];
                        if (empty($beltIds) && !empty($_POST['resource_belt_id'])) {
                            $beltIds = [$_POST['resource_belt_id']];
                        }
                        $firstBeltId = $beltIds[0] ?? null;
                        $styleId = $_POST['resource_style_id'];

                        $stmt = $pdo->prepare("INSERT INTO belt_resources (belt_id, style_id, resource_type, title, description, file_path, sort_order)
                                               VALUES (?, ?, 'document', ?, ?, ?, ?)");
                        $stmt->execute([
                            $firstBeltId,
                            $styleId,
                            sanitizeInput($_POST['resource_title']),
                            sanitizeInput($_POST['resource_description'] ?? ''),
                            $beltDocDir . $newFilename,
                            (int)($_POST['resource_sort_order'] ?? 0)
                        ]);
                        $resourceId = $pdo->lastInsertId();

                        // Insert into junction table for each selected belt
                        $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
                        foreach ($beltIds as $bid) {
                            $jStmt->execute([$resourceId, (int)$bid, (int)$styleId]);
                        }

                        $beltCount = count($beltIds);
                        $message = showAlert("Document uploaded and assigned to {$beltCount} belt(s)!", 'success');
                    } else {
                        $message = showAlert('File upload failed.', 'error');
                    }
                }
            } else {
                $message = showAlert('Please select a file to upload.', 'error');
            }
            break;

        case 'add_video':
            $videoUrl = trim($_POST['video_url'] ?? '');
            if (empty($videoUrl)) {
                $message = showAlert('Please provide a video URL.', 'error');
            } else {
                $beltIds = isset($_POST['resource_belt_ids']) ? (array)$_POST['resource_belt_ids'] : [];
                if (empty($beltIds) && !empty($_POST['resource_belt_id'])) {
                    $beltIds = [$_POST['resource_belt_id']];
                }
                $firstBeltId = $beltIds[0] ?? null;
                $styleId = $_POST['resource_style_id'];

                $stmt = $pdo->prepare("INSERT INTO belt_resources (belt_id, style_id, resource_type, title, description, video_url, sort_order)
                                       VALUES (?, ?, 'video', ?, ?, ?, ?)");
                $stmt->execute([
                    $firstBeltId,
                    $styleId,
                    sanitizeInput($_POST['resource_title']),
                    sanitizeInput($_POST['resource_description'] ?? ''),
                    $videoUrl,
                    (int)($_POST['resource_sort_order'] ?? 0)
                ]);
                $resourceId = $pdo->lastInsertId();

                // Insert into junction table for each selected belt
                $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
                foreach ($beltIds as $bid) {
                    $jStmt->execute([$resourceId, (int)$bid, (int)$styleId]);
                }

                $beltCount = count($beltIds);
                $message = showAlert("Video link added and assigned to {$beltCount} belt(s)!", 'success');
            }
            break;

        case 'delete_resource':
            // Get the resource to delete its file if it's a document
            $resStmt = $pdo->prepare("SELECT * FROM belt_resources WHERE id = ?");
            $resStmt->execute([$_POST['resource_id']]);
            $resource = $resStmt->fetch();
            if ($resource) {
                if ($resource['resource_type'] === 'document' && $resource['file_path'] && file_exists($resource['file_path'])) {
                    @unlink($resource['file_path']);
                }
                $pdo->prepare("DELETE FROM belt_resource_belts WHERE resource_id = ?")->execute([$_POST['resource_id']]);
                $pdo->prepare("DELETE FROM belt_resources WHERE id = ?")->execute([$_POST['resource_id']]);
                $message = showAlert('Resource deleted successfully!', 'success');
            }
            break;
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

// Pre-load all belts grouped by style for JS
$allBeltsByStyle = [];
foreach ($styles as $style) {
    $belts = $pdo->prepare("SELECT * FROM belts WHERE style_id = ? ORDER BY rank_order ASC");
    $belts->execute([$style['id']]);
    $allBeltsByStyle[$style['id']] = $belts->fetchAll();
}

// Pre-load resource counts per belt (via junction table)
$resourceCounts = [];
try {
    $rcStmt = $pdo->query("SELECT brb.belt_id, COUNT(DISTINCT brb.resource_id) as cnt FROM belt_resource_belts brb GROUP BY brb.belt_id");
    foreach ($rcStmt->fetchAll() as $rc) {
        $resourceCounts[$rc['belt_id']] = (int)$rc['cnt'];
    }
} catch (\PDOException $e) {}

// Pre-load all resources grouped by belt for JS (via junction table)
$allResourcesByBelt = [];
try {
    $resStmt = $pdo->query("
        SELECT br.*, GROUP_CONCAT(DISTINCT brb.belt_id ORDER BY brb.belt_id) as belt_ids
        FROM belt_resources br
        LEFT JOIN belt_resource_belts brb ON br.id = brb.resource_id
        GROUP BY br.id
        ORDER BY br.sort_order ASC, br.created_at DESC
        LIMIT 1000
    ");
    foreach ($resStmt->fetchAll() as $res) {
        // Each resource may belong to multiple belts — add it to each belt's list
        $beltIdList = $res['belt_ids'] ? explode(',', $res['belt_ids']) : ($res['belt_id'] ? [$res['belt_id']] : []);
        foreach ($beltIdList as $bid) {
            $allResourcesByBelt[(int)$bid][] = $res;
        }
    }
} catch (\PDOException $e) {}

// Get recent belt promotions
$recentPromoParams = [];
$recentPromoSql = "
    SELECT sb.*, sb.black_belt_number,
           s.first_name, s.last_name,
           b.name as belt_name, b.color,
           mas.name as style_name,
           u.full_name as instructor_name
    FROM student_belts sb
    JOIN students s ON sb.student_id = s.id
    JOIN belts b ON sb.belt_id = b.id
    JOIN martial_arts_styles mas ON sb.style_id = mas.id
    LEFT JOIN users u ON sb.instructor_id = u.id
    WHERE 1=1" . school_where('s') . school_where('sb') . "
    ORDER BY sb.awarded_date DESC
    LIMIT 20
";
school_param($recentPromoParams);
school_param($recentPromoParams);
$recentPromoStmt = $pdo->prepare($recentPromoSql);
$recentPromoStmt->execute($recentPromoParams);
$recent_promotions = $recentPromoStmt->fetchAll();

// Get students for dropdown
$studentDropParams = [];
$studentDropStmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE status = 'active'" . school_where() . " ORDER BY first_name, last_name");
school_param($studentDropParams);
$studentDropStmt->execute($studentDropParams);
$students = $studentDropStmt->fetchAll();

// Pre-load each student's highest belt per style (for Quick Assign display)
$studentHighestBelts = [];
try {
    $shbParams = [];
    $shbSql = "
        SELECT sb.student_id, sb.style_id, b.name as belt_name, b.color, b.rank_order,
               sb.black_belt_number
        FROM student_belts sb
        JOIN belts b ON sb.belt_id = b.id
        WHERE b.rank_order = (
            SELECT MAX(b2.rank_order) FROM student_belts sb2
            JOIN belts b2 ON sb2.belt_id = b2.id
            WHERE sb2.student_id = sb.student_id AND sb2.style_id = sb.style_id" . school_where('sb2') . "
        )" . school_where('sb') . "
        ORDER BY sb.student_id, sb.style_id
    ";
    school_param($shbParams);
    school_param($shbParams);
    $shbStmt = $pdo->prepare($shbSql);
    $shbStmt->execute($shbParams);
    $shbRows = $shbStmt->fetchAll();
    foreach ($shbRows as $row) {
        $studentHighestBelts[$row['student_id']][$row['style_id']] = $row;
    }
} catch (PDOException $e) {}

// Belt color map — primary hex for each belt color
$colorMap = [
    'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
    'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
    'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000',
    'Brown-Red' => '#8B4513', 'Black-Red' => '#000000',
    'Black-White Stripe' => '#000000', 'Black-Blue Stripe' => '#000000',
    'Black-Red Stripe' => '#000000',
    'White-Yellow Stripe' => '#FFFFFF', 'White-Orange Stripe' => '#FFFFFF',
    'White-Green Stripe' => '#FFFFFF', 'White-Blue Stripe' => '#FFFFFF',
    'White-Purple Stripe' => '#FFFFFF', 'White-Brown Stripe' => '#FFFFFF',
    'White-Red Stripe' => '#FFFFFF',
    'Camouflage-Yellow Stripe' => '#4B5320', 'Camouflage-Orange Stripe' => '#4B5320',
    'Camouflage-Green Stripe' => '#4B5320', 'Camouflage-Blue Stripe' => '#4B5320',
    'Camouflage-Purple Stripe' => '#4B5320', 'Camouflage-Brown Stripe' => '#4B5320',
    'Camouflage-Red Stripe' => '#4B5320',
    'Camouflage' => '#4B5320'
];

/**
 * Return CSS background style for a belt color.
 * Compound colors get a gradient; simple colors get a solid hex.
 * Stripe belts show a single stripe through the center.
 */
if (!function_exists('beltBackground')) {
function beltBackground(string $color, array $colorMap): string
{
    $stripeColors = [
        'Yellow' => '#FFD700', 'Orange' => '#FF8C00', 'Green' => '#228B22',
        'Blue' => '#0000CD', 'Purple' => '#800080', 'Brown' => '#8B4513',
        'Red' => '#DC143C', 'White' => '#FFFFFF',
    ];
    $camoBg = 'linear-gradient(135deg, #4B5320 0%, #6B8E23 25%, #556B2F 50%, #4B5320 75%, #6B8E23 100%)';

    // Split-color belts (half/half diagonal)
    $splits = [
        'Brown-Red' => 'linear-gradient(135deg, #8B4513 50%, #DC143C 50%)',
        'Black-Red' => 'linear-gradient(135deg, #000000 50%, #DC143C 50%)',
    ];
    if (isset($splits[$color])) {
        return 'background: ' . $splits[$color] . ';';
    }

    // Single-stripe belts: base color with one stripe through the middle
    if (preg_match('/^(Black|White|Camouflage)-(\w+) Stripe$/', $color, $m)) {
        $baseName = $m[1];
        $stripeName = $m[2];
        $stripeHex = $stripeColors[$stripeName] ?? '#999';

        if ($baseName === 'Camouflage') {
            return 'background: linear-gradient(180deg, transparent 40%, ' . $stripeHex . ' 40%, ' . $stripeHex . ' 60%, transparent 60%), ' . $camoBg . ';';
        }
        $baseHex = ($baseName === 'Black') ? '#000' : '#fff';
        return 'background: linear-gradient(180deg, ' . $baseHex . ' 40%, ' . $stripeHex . ' 40%, ' . $stripeHex . ' 60%, ' . $baseHex . ' 60%);';
    }

    // Plain camouflage
    if ($color === 'Camouflage') {
        return 'background: ' . $camoBg . ';';
    }

    return 'background-color: ' . ($colorMap[$color] ?? '#6B7280') . ';';
}
}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Belt System</h1>
        <div class="space-x-2">
            <button onclick="document.getElementById('addStyleModal').classList.remove('hidden')"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                + Add Style
            </button>
            <button onclick="document.getElementById('addBeltModal').classList.remove('hidden')"
                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                + Add Belt
            </button>
            <button onclick="document.getElementById('promoteModal').classList.remove('hidden')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                + Promote Student
            </button>
            <button onclick="document.getElementById('bulkAssignModal').classList.remove('hidden')"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg font-medium">
                &#9889; Quick Assign
            </button>
            <a href="belt_resources_upload.php"
               class="inline-block bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                &#128206; Bulk Upload Resources
            </a>
        </div>
    </div>

    <!-- Belt Systems by Style -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <?php foreach ($styles as $style): ?>
            <?php $belts = $allBeltsByStyle[$style['id']] ?? []; ?>
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 to-blue-600 rounded-t-lg">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-xl font-semibold text-white"><?php echo htmlspecialchars($style['name']); ?></h2>
                            <p class="text-sm text-blue-100 mt-1"><?php echo count($belts); ?> belts<?php if ($style['description']): ?> &mdash; <?php echo htmlspecialchars(substr($style['description'], 0, 60)); ?><?php endif; ?></p>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="editStyle(<?php echo htmlspecialchars(json_encode($style)); ?>)"
                                    class="text-blue-100 hover:text-white text-sm" title="Edit Style">&#9998;</button>
                            <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this style? All belts must be removed first.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_style">
                                <input type="hidden" name="style_id" value="<?php echo $style['id']; ?>">
                                <button type="submit" class="text-blue-100 hover:text-white text-sm" title="Delete Style">&#10005;</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <?php if (empty($belts)): ?>
                        <p class="text-gray-400 text-center py-4">No belts defined for this style yet.</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($belts as $belt): ?>
                            <?php $resCount = $resourceCounts[$belt['id']] ?? 0; ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm border border-gray-300"
                                         style="<?php echo beltBackground($belt['color'], $colorMap); ?> <?php echo (in_array($belt['color'], ['White', 'Yellow']) || str_starts_with($belt['color'], 'White-')) ? 'color: #000;' : 'color: #fff;'; ?>">
                                        <?php echo $belt['rank_order']; ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($belt['name']); ?></p>
                                        <?php if ($belt['requirements']): ?>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars(substr($belt['requirements'], 0, 60)); ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button onclick="openResources(<?php echo $belt['id']; ?>, <?php echo $style['id']; ?>, '<?php echo htmlspecialchars(addslashes($belt['name'])); ?>', '<?php echo htmlspecialchars(addslashes($style['name'])); ?>')"
                                            class="text-purple-600 hover:text-purple-800 text-sm flex items-center gap-1" title="Manage Resources">
                                        &#128206;
                                        <?php if ($resCount > 0): ?>
                                            <span class="bg-purple-100 text-purple-700 text-xs px-1.5 py-0.5 rounded-full"><?php echo $resCount; ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <button onclick="editBelt(<?php echo htmlspecialchars(json_encode($belt)); ?>)"
                                            class="text-blue-600 hover:text-blue-800 text-sm" title="Edit Belt">&#9998;</button>
                                    <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this belt?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_belt">
                                        <input type="hidden" name="belt_id" value="<?php echo $belt['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="Delete Belt">&#10005;</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($styles)): ?>
            <div class="col-span-2 bg-white rounded-lg shadow p-12 text-center text-gray-500">
                <p class="text-lg mb-2">No martial art styles defined yet.</p>
                <p class="text-sm">Click "Add Style" to create your first martial art style.</p>
            </div>
        <?php endif; ?>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">BB #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Style</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Instructor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
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
                                      style="<?php echo beltBackground($promo['color'], $colorMap); ?> <?php echo (in_array($promo['color'], ['White', 'Yellow']) || str_starts_with($promo['color'], 'White-')) ? 'color: #000;' : 'color: #FFF;'; ?>">
                                    <?php echo $promo['belt_name']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if (!empty($promo['black_belt_number'])): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800 border border-yellow-300">
                                        #<?php echo htmlspecialchars($promo['black_belt_number']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-300">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['style_name']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo $promo['instructor_name'] ?? 'N/A'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                <?php echo $promo['notes'] ?: '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <button onclick="editPromotion(<?php echo htmlspecialchars(json_encode($promo)); ?>)"
                                        class="text-blue-600 hover:text-blue-900 mr-2">Edit</button>
                                <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this promotion record?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_promotion">
                                    <input type="hidden" name="promotion_id" value="<?php echo $promo['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
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

<!-- ==================== MODALS ==================== -->

<!-- Add Style Modal -->
<div id="addStyleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Martial Art Style</h3>
            <button onclick="document.getElementById('addStyleModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_style">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Style Name *</label>
                <input type="text" name="style_name" required placeholder="e.g., Karate, Taekwondo, BJJ" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="style_description" rows="3" placeholder="Brief description of this martial art..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addStyleModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">Add Style</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Style Modal -->
<div id="editStyleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Martial Art Style</h3>
            <button onclick="document.getElementById('editStyleModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_style">
            <input type="hidden" name="style_id" id="edit_style_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Style Name *</label>
                <input type="text" name="style_name" id="edit_style_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="style_description" id="edit_style_description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editStyleModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Style</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Belt Modal -->
<div id="addBeltModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Add Belt</h3>
            <button onclick="document.getElementById('addBeltModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_belt">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="belt_style_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt Name *</label>
                <input type="text" name="belt_name" required placeholder="e.g., White Belt, 1st Dan" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Color *</label>
                    <select name="belt_color" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">Select color...</option>
                        <optgroup label="Solid">
                            <option value="White">White</option>
                            <option value="Yellow">Yellow</option>
                            <option value="Orange">Orange</option>
                            <option value="Green">Green</option>
                            <option value="Blue">Blue</option>
                            <option value="Purple">Purple</option>
                            <option value="Brown">Brown</option>
                            <option value="Red">Red</option>
                            <option value="Black">Black</option>
                            <option value="Camouflage">Camouflage</option>
                        </optgroup>
                        <optgroup label="Split">
                            <option value="Brown-Red">Brown-Red</option>
                            <option value="Black-Red">Black-Red</option>
                        </optgroup>
                        <optgroup label="White Stripe">
                            <option value="White-Yellow Stripe">White-Yellow Stripe</option>
                            <option value="White-Orange Stripe">White-Orange Stripe</option>
                            <option value="White-Green Stripe">White-Green Stripe</option>
                            <option value="White-Blue Stripe">White-Blue Stripe</option>
                            <option value="White-Purple Stripe">White-Purple Stripe</option>
                            <option value="White-Brown Stripe">White-Brown Stripe</option>
                            <option value="White-Red Stripe">White-Red Stripe</option>
                        </optgroup>
                        <optgroup label="Camouflage Stripe">
                            <option value="Camouflage-Yellow Stripe">Camouflage-Yellow Stripe</option>
                            <option value="Camouflage-Orange Stripe">Camouflage-Orange Stripe</option>
                            <option value="Camouflage-Green Stripe">Camouflage-Green Stripe</option>
                            <option value="Camouflage-Blue Stripe">Camouflage-Blue Stripe</option>
                            <option value="Camouflage-Purple Stripe">Camouflage-Purple Stripe</option>
                            <option value="Camouflage-Brown Stripe">Camouflage-Brown Stripe</option>
                            <option value="Camouflage-Red Stripe">Camouflage-Red Stripe</option>
                        </optgroup>
                        <optgroup label="Black Stripe">
                            <option value="Black-White Stripe">Black-White Stripe</option>
                            <option value="Black-Blue Stripe">Black-Blue Stripe</option>
                            <option value="Black-Red Stripe">Black-Red Stripe</option>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rank Order *</label>
                    <input type="number" name="belt_rank_order" min="1" required placeholder="1 = lowest" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                <textarea name="belt_requirements" rows="3" placeholder="Requirements to achieve this belt..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('addBeltModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">Add Belt</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Belt Modal -->
<div id="editBeltModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Belt</h3>
            <button onclick="document.getElementById('editBeltModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_belt">
            <input type="hidden" name="belt_id" id="edit_belt_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt Name *</label>
                <input type="text" name="belt_name" id="edit_belt_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Color *</label>
                    <select name="belt_color" id="edit_belt_color" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <optgroup label="Solid">
                            <option value="White">White</option>
                            <option value="Yellow">Yellow</option>
                            <option value="Orange">Orange</option>
                            <option value="Green">Green</option>
                            <option value="Blue">Blue</option>
                            <option value="Purple">Purple</option>
                            <option value="Brown">Brown</option>
                            <option value="Red">Red</option>
                            <option value="Black">Black</option>
                            <option value="Camouflage">Camouflage</option>
                        </optgroup>
                        <optgroup label="Split">
                            <option value="Brown-Red">Brown-Red</option>
                            <option value="Black-Red">Black-Red</option>
                        </optgroup>
                        <optgroup label="White Stripe">
                            <option value="White-Yellow Stripe">White-Yellow Stripe</option>
                            <option value="White-Orange Stripe">White-Orange Stripe</option>
                            <option value="White-Green Stripe">White-Green Stripe</option>
                            <option value="White-Blue Stripe">White-Blue Stripe</option>
                            <option value="White-Purple Stripe">White-Purple Stripe</option>
                            <option value="White-Brown Stripe">White-Brown Stripe</option>
                            <option value="White-Red Stripe">White-Red Stripe</option>
                        </optgroup>
                        <optgroup label="Camouflage Stripe">
                            <option value="Camouflage-Yellow Stripe">Camouflage-Yellow Stripe</option>
                            <option value="Camouflage-Orange Stripe">Camouflage-Orange Stripe</option>
                            <option value="Camouflage-Green Stripe">Camouflage-Green Stripe</option>
                            <option value="Camouflage-Blue Stripe">Camouflage-Blue Stripe</option>
                            <option value="Camouflage-Purple Stripe">Camouflage-Purple Stripe</option>
                            <option value="Camouflage-Brown Stripe">Camouflage-Brown Stripe</option>
                            <option value="Camouflage-Red Stripe">Camouflage-Red Stripe</option>
                        </optgroup>
                        <optgroup label="Black Stripe">
                            <option value="Black-White Stripe">Black-White Stripe</option>
                            <option value="Black-Blue Stripe">Black-Blue Stripe</option>
                            <option value="Black-Red Stripe">Black-Red Stripe</option>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rank Order *</label>
                    <input type="number" name="belt_rank_order" id="edit_belt_rank_order" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                <textarea name="belt_requirements" id="edit_belt_requirements" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editBeltModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Belt</button>
            </div>
        </form>
    </div>
</div>

<!-- Promote Student Modal -->
<div id="promoteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Promote Student</h3>
            <button onclick="document.getElementById('promoteModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4" id="promoteForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="promote">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student *</label>
                <div id="belt-student-picker"></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="style_id" required onchange="loadBelts(this.value, 'belt_select')" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt/Rank *</label>
                <select name="belt_id" id="belt_select" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style first...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Award Date *</label>
                <input type="date" name="awarded_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <!-- Black Belt Number (shown conditionally via JS) -->
            <div id="blackBeltNumberField" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Black Belt Number <span class="text-red-500">*</span>
                    <span class="text-xs text-gray-500 font-normal ml-1">(Permanent &mdash; cannot be changed once assigned)</span>
                </label>
                <input type="text" name="black_belt_number" id="black_belt_number_input"
                       placeholder="e.g., 1001, BB-2024-001"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-yellow-500">
                <p class="text-xs text-yellow-600 mt-1 font-medium">This number is permanent and unique per style. It will never change once assigned.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Test results, comments, etc." class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('promoteModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Promote Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Assign (Bulk Belt Assignment) Modal -->
<div id="bulkAssignModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-6xl shadow-lg rounded-md bg-white" style="max-height: 90vh; overflow-y: auto;">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-xl font-bold text-gray-800">&#9889; Quick Belt Assignment</h3>
                <p class="text-sm text-gray-500">Quickly assign belt ranks to students who already passed content but weren't recorded in the system.</p>
            </div>
            <button onclick="document.getElementById('bulkAssignModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800 text-xl">&#10005;</button>
        </div>

        <!-- Style filter -->
        <div class="flex items-center gap-4 mb-4">
            <label class="text-sm font-medium text-gray-700">Style:</label>
            <select id="bulk_style_filter" onchange="filterBulkStyle()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <?php foreach ($styles as $style): ?>
                    <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="text-sm font-medium text-gray-700 ml-4">Award Date:</label>
            <input type="date" id="bulk_global_date" value="<?php echo date('Y-m-d'); ?>"
                   onchange="applyGlobalDate()"
                   class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
            <span class="text-xs text-gray-400">(applies to all rows)</span>
        </div>

        <form method="POST" id="bulkAssignForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_promote">

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" id="bulkAssignTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Current Rank</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assign Belt</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">BB #</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="bulkAssignBody">
                        <?php foreach ($students as $si => $st):
                            $stName = htmlspecialchars($st['first_name'] . ' ' . $st['last_name']);
                        ?>
                            <tr class="hover:bg-gray-50 bulk-row" data-student-id="<?php echo $st['id']; ?>">
                                <td class="px-3 py-2 font-medium text-gray-800">
                                    <?php echo $stName; ?>
                                    <input type="hidden" data-name="bulk_student_id[]" value="<?php echo $st['id']; ?>">
                                </td>
                                <td class="px-3 py-2 bulk-current-rank" data-student-id="<?php echo $st['id']; ?>">
                                    <?php
                                    // This gets dynamically updated by JS based on selected style
                                    // Pre-render for all styles as data attributes
                                    foreach ($styles as $sty) {
                                        $hb = $studentHighestBelts[$st['id']][$sty['id']] ?? null;
                                        if ($hb) {
                                            $bgStyle = beltBackground($hb['color'], $colorMap);
                                            $txtColor = (in_array($hb['color'], ['White', 'Yellow']) || str_starts_with($hb['color'], 'White-')) ? '#333' : '#fff';
                                            echo '<span class="bulk-rank-badge hidden" data-style="' . $sty['id'] . '">';
                                            echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold" style="' . $bgStyle . 'color:' . $txtColor . ';border:1px solid #00000020;">';
                                            echo htmlspecialchars($hb['belt_name']);
                                            if (!empty($hb['black_belt_number'])) echo ' #' . htmlspecialchars($hb['black_belt_number']);
                                            echo '</span></span>';
                                        } else {
                                            echo '<span class="bulk-rank-badge hidden" data-style="' . $sty['id'] . '"><span class="text-xs text-gray-400 italic">None</span></span>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="px-3 py-2">
                                    <select data-name="bulk_belt_id[]" class="bulk-belt-select w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                        <option value="">— skip —</option>
                                    </select>
                                    <input type="hidden" data-name="bulk_style_id[]" class="bulk-style-hidden" value="">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="date" data-name="bulk_date[]" value="<?php echo date('Y-m-d'); ?>"
                                           class="bulk-date-input w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" data-name="bulk_bb_number[]" placeholder="—"
                                           class="bulk-bb-input w-24 px-2 py-1 border border-gray-300 rounded text-sm hidden">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-between items-center pt-4 border-t border-gray-200 mt-4">
                <p class="text-xs text-gray-500">Only rows with a belt selected will be assigned. Duplicates are automatically skipped.</p>
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('bulkAssignModal').classList.add('hidden')"
                            class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium">
                        Assign Selected Belts
                    </button>
                </div>
            </div>

            <script>
            document.getElementById('bulkAssignForm').addEventListener('submit', function(e) {
                // Only activate name attributes on rows where a belt was actually selected
                var hasAny = false;
                document.querySelectorAll('#bulkAssignBody tr.bulk-row').forEach(function(row) {
                    var beltSelect = row.querySelector('.bulk-belt-select');
                    var inputs = row.querySelectorAll('[data-name]');
                    if (beltSelect && beltSelect.value) {
                        // Belt was selected — promote data-name to name so it POSTs
                        inputs.forEach(function(inp) {
                            inp.setAttribute('name', inp.getAttribute('data-name'));
                        });
                        hasAny = true;
                    }
                    // If no belt selected, inputs have no name → not included in POST
                });
                if (!hasAny) {
                    e.preventDefault();
                    alert('Please select at least one belt to assign.');
                }
            });
            </script>
        </form>
    </div>
</div>

<!-- Edit Promotion Modal -->
<div id="editPromotionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Edit Promotion</h3>
            <button onclick="document.getElementById('editPromotionModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800">&#10005;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_promotion">
            <input type="hidden" name="promotion_id" id="edit_promo_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Martial Art Style *</label>
                <select name="style_id" id="edit_promo_style" required onchange="loadBelts(this.value, 'edit_promo_belt')" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style...</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?php echo $style['id']; ?>"><?php echo htmlspecialchars($style['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Belt/Rank *</label>
                <select name="belt_id" id="edit_promo_belt" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Select style first...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Award Date *</label>
                <input type="date" name="awarded_date" id="edit_promo_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
            </div>
            <!-- Black Belt Number (read-only if already set) -->
            <div id="editBlackBeltField" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">Black Belt Number</label>
                <input type="text" id="edit_promo_bb_number" disabled
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-700 font-bold">
                <p class="text-xs text-gray-500 mt-1">Black belt numbers are permanent and cannot be changed.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="edit_promo_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="document.getElementById('editPromotionModal').classList.add('hidden')" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Update Promotion</button>
            </div>
        </form>
    </div>
</div>

<!-- Manage Resources Modal -->
<div id="resourcesModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-800">Manage Resources — <span id="res_belt_label"></span></h3>
            <button onclick="document.getElementById('resourcesModal').classList.add('hidden')" class="text-gray-600 hover:text-gray-800 text-xl">&#10005;</button>
        </div>

        <!-- Existing Resources List -->
        <div id="resources_list" class="mb-6">
            <p class="text-gray-400 text-center py-4">Loading resources...</p>
        </div>

        <!-- Add Document Form -->
        <div class="border-t border-gray-200 pt-4 mb-4">
            <h4 class="font-semibold text-gray-700 mb-3">Upload Document</h4>
            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_document">
                <input type="hidden" name="resource_style_id" id="doc_style_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                        <input type="text" name="resource_title" required placeholder="e.g., White Belt Curriculum" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">File *</label>
                        <input type="file" name="document_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"
                               class="w-full text-sm text-gray-600 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-purple-50 file:text-purple-700">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assign to Belts *</label>
                    <div id="doc_belt_checkboxes" class="flex flex-wrap gap-2 p-2 border border-gray-200 rounded-lg bg-gray-50 max-h-40 overflow-y-auto">
                        <span class="text-gray-400 text-sm">Loading belts...</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Select one or more belts to assign this document to.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="resource_description" placeholder="Optional description..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                    <input type="number" name="resource_sort_order" value="0" min="0" class="w-24 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm">Upload Document</button>
                <p class="text-xs text-gray-500">Allowed: PDF, DOC, DOCX, JPG, PNG, GIF. Max 10MB.</p>
            </form>
        </div>

        <!-- Add Video Form -->
        <div class="border-t border-gray-200 pt-4">
            <h4 class="font-semibold text-gray-700 mb-3">Add Video Link</h4>
            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_video">
                <input type="hidden" name="resource_style_id" id="vid_style_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                        <input type="text" name="resource_title" required placeholder="e.g., Form 1 Tutorial" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Video URL *</label>
                        <input type="url" name="video_url" required placeholder="https://youtube.com/watch?v=..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assign to Belts *</label>
                    <div id="vid_belt_checkboxes" class="flex flex-wrap gap-2 p-2 border border-gray-200 rounded-lg bg-gray-50 max-h-40 overflow-y-auto">
                        <span class="text-gray-400 text-sm">Loading belts...</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Select one or more belts to assign this video to.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="resource_description" placeholder="Optional description..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                    <input type="number" name="resource_sort_order" value="0" min="0" class="w-24 px-3 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">Add Video</button>
                <p class="text-xs text-gray-500">Supports YouTube and Vimeo links.</p>
            </form>
        </div>
    </div>
</div>

<script>
// Pre-loaded belt data
const beltsByStyle = <?php echo json_encode($allBeltsByStyle); ?>;
const resourcesByBelt = <?php echo json_encode($allResourcesByBelt); ?>;

function loadBelts(styleId, targetSelectId) {
    const beltSelect = document.getElementById(targetSelectId);
    beltSelect.innerHTML = '<option value="">Loading...</option>';

    if (!styleId) {
        beltSelect.innerHTML = '<option value="">Select style first...</option>';
        return;
    }

    beltSelect.innerHTML = '<option value="">Select belt...</option>';
    if (beltsByStyle[styleId]) {
        beltsByStyle[styleId].forEach(belt => {
            const option = document.createElement('option');
            option.value = belt.id;
            option.textContent = belt.name + ' (' + belt.color + ')';
            option.dataset.color = belt.color;
            beltSelect.appendChild(option);
        });
    }

    // Reset black belt field when style changes
    if (targetSelectId === 'belt_select') {
        toggleBlackBeltField();
    }
}

// Show/hide black belt number field based on belt color
function toggleBlackBeltField() {
    const beltSelect = document.getElementById('belt_select');
    const bbField = document.getElementById('blackBeltNumberField');
    const bbInput = document.getElementById('black_belt_number_input');
    if (!beltSelect || !bbField) return;

    const selected = beltSelect.options[beltSelect.selectedIndex];
    const isBlack = selected && selected.dataset && selected.dataset.color && selected.dataset.color.toLowerCase().startsWith('black');

    if (isBlack) {
        bbField.classList.remove('hidden');
        bbInput.required = true;
    } else {
        bbField.classList.add('hidden');
        bbInput.required = false;
        bbInput.value = '';
    }
}

// Attach change listener to belt_select
document.addEventListener('DOMContentLoaded', function() {
    const beltSelect = document.getElementById('belt_select');
    if (beltSelect) {
        beltSelect.addEventListener('change', toggleBlackBeltField);
    }
});

function editStyle(style) {
    document.getElementById('edit_style_id').value = style.id;
    document.getElementById('edit_style_name').value = style.name;
    document.getElementById('edit_style_description').value = style.description || '';
    document.getElementById('editStyleModal').classList.remove('hidden');
}

function editBelt(belt) {
    document.getElementById('edit_belt_id').value = belt.id;
    document.getElementById('edit_belt_name').value = belt.name;
    document.getElementById('edit_belt_color').value = belt.color;
    document.getElementById('edit_belt_rank_order').value = belt.rank_order;
    document.getElementById('edit_belt_requirements').value = belt.requirements || '';
    document.getElementById('editBeltModal').classList.remove('hidden');
}

function editPromotion(promo) {
    document.getElementById('edit_promo_id').value = promo.id;
    document.getElementById('edit_promo_date').value = promo.awarded_date;
    document.getElementById('edit_promo_notes').value = promo.notes || '';

    // Show black belt number if present (read-only)
    const bbField = document.getElementById('editBlackBeltField');
    const bbInput = document.getElementById('edit_promo_bb_number');
    if (promo.black_belt_number) {
        bbField.classList.remove('hidden');
        bbInput.value = '#' + promo.black_belt_number;
    } else {
        bbField.classList.add('hidden');
        bbInput.value = '';
    }

    // Set style and load belts
    document.getElementById('edit_promo_style').value = promo.style_id;
    loadBelts(promo.style_id, 'edit_promo_belt');

    // After loading belts, select the current belt (small delay for DOM update)
    setTimeout(() => {
        document.getElementById('edit_promo_belt').value = promo.belt_id;
    }, 100);

    document.getElementById('editPromotionModal').classList.remove('hidden');
}

function confirmDelete(msg) {
    return confirm(msg);
}

function openResources(beltId, styleId, beltName, styleName) {
    document.getElementById('res_belt_label').textContent = beltName + ' (' + styleName + ')';
    document.getElementById('doc_style_id').value = styleId;
    document.getElementById('vid_style_id').value = styleId;

    // Build belt checkboxes for document and video forms
    const styleBelts = beltsByStyle[styleId] || [];
    ['doc_belt_checkboxes', 'vid_belt_checkboxes'].forEach(containerId => {
        const container = document.getElementById(containerId);
        const prefix = containerId.startsWith('doc') ? 'doc' : 'vid';
        if (styleBelts.length === 0) {
            container.innerHTML = '<span class="text-gray-400 text-sm">No belts in this style.</span>';
            return;
        }
        let cbHtml = '';
        styleBelts.forEach(belt => {
            const checked = (parseInt(belt.id) === parseInt(beltId)) ? 'checked' : '';
            cbHtml += `<label class="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-200 bg-white text-sm cursor-pointer hover:bg-purple-50">
                <input type="checkbox" name="resource_belt_ids[]" value="${belt.id}" ${checked} class="rounded text-purple-600">
                <span>${escapeHtml(belt.name)}</span>
            </label>`;
        });
        container.innerHTML = cbHtml;
    });

    // Build a belt lookup for showing belt names on resources
    const beltLookup = {};
    styleBelts.forEach(b => { beltLookup[b.id] = b.name; });

    // Build resources list
    const list = document.getElementById('resources_list');
    const resources = resourcesByBelt[beltId] || [];

    if (resources.length === 0) {
        list.innerHTML = '<p class="text-gray-400 text-center py-4">No resources uploaded yet for this belt.</p>';
    } else {
        let html = '<div class="space-y-2">';
        resources.forEach(res => {
            const icon = res.resource_type === 'document' ? '&#128196;' : '&#127909;';
            const typeBadge = res.resource_type === 'document'
                ? '<span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">Document</span>'
                : '<span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Video</span>';

            // Show all assigned belt badges
            let beltBadges = '';
            if (res.belt_ids) {
                const ids = String(res.belt_ids).split(',');
                if (ids.length > 1) {
                    beltBadges = ids.map(id => {
                        const name = beltLookup[id] || ('Belt #' + id);
                        return '<span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">' + escapeHtml(name) + '</span>';
                    }).join(' ');
                }
            }

            html += `<div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <span class="text-xl">${icon}</span>
                    <div>
                        <p class="font-medium text-gray-800 text-sm">${escapeHtml(res.title)}</p>
                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                            ${typeBadge}
                            ${beltBadges}
                            ${res.description ? '<span class="text-xs text-gray-500">' + escapeHtml(res.description) + '</span>' : ''}
                        </div>
                    </div>
                </div>
                <form method="POST" class="inline" onsubmit="return confirm('Delete this resource?')">
                    <input type="hidden" name="csrf_token" value="${document.querySelector('meta[name=csrf-token]')?.content || document.querySelector('input[name=csrf_token]')?.value || ''}">
                    <input type="hidden" name="action" value="delete_resource">
                    <input type="hidden" name="resource_id" value="${res.id}">
                    <button type="submit" class="text-red-500 hover:text-red-700 text-sm">&#10005; Delete</button>
                </form>
            </div>`;
        });
        html += '</div>';
        list.innerHTML = html;
    }

    document.getElementById('resourcesModal').classList.remove('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============= Quick Assign (Bulk) Functions =============

function filterBulkStyle() {
    const styleId = document.getElementById('bulk_style_filter').value;
    const belts = beltsByStyle[styleId] || [];

    // Update all current rank badges — show only the one matching the selected style
    document.querySelectorAll('.bulk-rank-badge').forEach(el => {
        el.classList.toggle('hidden', el.dataset.style !== styleId);
    });

    // Update all belt dropdowns
    document.querySelectorAll('.bulk-belt-select').forEach(sel => {
        sel.innerHTML = '<option value="">— skip —</option>';
        belts.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.rank_order + '. ' + b.name + ' (' + b.color + ')';
            opt.dataset.color = b.color;
            sel.appendChild(opt);
        });
    });

    // Update hidden style fields
    document.querySelectorAll('.bulk-style-hidden').forEach(inp => {
        inp.value = styleId;
    });

    // Reset BB number fields
    document.querySelectorAll('.bulk-bb-input').forEach(inp => {
        inp.classList.add('hidden');
        inp.value = '';
    });
}

function applyGlobalDate() {
    const globalDate = document.getElementById('bulk_global_date').value;
    document.querySelectorAll('.bulk-date-input').forEach(inp => {
        inp.value = globalDate;
    });
}

// Show/hide BB number field based on belt selection in bulk assign
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the bulk style filter on first open
    const bulkModal = document.getElementById('bulkAssignModal');
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (!bulkModal.classList.contains('hidden')) {
                filterBulkStyle();
            }
        });
    });
    observer.observe(bulkModal, { attributes: true, attributeFilter: ['class'] });

    // Delegate change events on bulk belt selects to show/hide BB# field
    document.getElementById('bulkAssignTable')?.addEventListener('change', function(e) {
        if (e.target.classList.contains('bulk-belt-select')) {
            const row = e.target.closest('tr');
            const bbInput = row.querySelector('.bulk-bb-input');
            const selected = e.target.options[e.target.selectedIndex];
            if (selected && selected.dataset.color && selected.dataset.color.toLowerCase().startsWith('black')) {
                bbInput.classList.remove('hidden');
            } else {
                bbInput.classList.add('hidden');
                bbInput.value = '';
            }
        }
    });
});
</script>

<script src="assets/js/student-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    StudentPicker.init({
        container: '#belt-student-picker',
        inputName: 'student_id',
        placeholder: 'Type student name to search\u2026',
        data: <?= json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name'])]; }, $students)) ?>
    });
});
</script>

<?php include 'includes/footer.php'; ?>
