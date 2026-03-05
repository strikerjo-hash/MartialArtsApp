<?php
/**
 * belt_resources_upload.php — Bulk Document Upload for Belt Training Resources
 *
 * Two workflows:
 *   1. Upload multiple files to a specific belt (select style + belt, then upload)
 *   2. Upload multiple files to an "unassigned" staging area, then assign to belts later
 *
 * Preserves existing single-file upload in belts.php (Manage Resources modal).
 */

require_once 'config.php';
requireLogin();

// Permission check: reuse belts.php permission
if (function_exists('canView') && !canView('belts.php')) {
    accessDenied('Bulk document upload requires Belt System access.', 'belts.php');
}

// Ensure uploads directory exists
$beltDocDir = 'uploads/belt_documents/';
if (!file_exists($beltDocDir)) {
    mkdir($beltDocDir, 0750, true);
}

$message = '';
$allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
$allowedMimes = [
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg', 'image/png', 'image/gif',
];
$maxFileSize = 10485760; // 10MB

/**
 * Generate a human-readable title from a filename.
 * e.g. "white_belt-curriculum_2024.pdf" → "White Belt Curriculum 2024"
 */
function titleFromFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    return ucwords(trim($name));
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ── Bulk Upload ──────────────────────────────────────────
        case 'bulk_upload':
            $beltIds = isset($_POST['belt_ids']) ? array_map('intval', (array)$_POST['belt_ids']) : [];
            $beltIds = array_filter($beltIds, fn($id) => $id > 0);
            $styleId = !empty($_POST['style_id']) ? (int)$_POST['style_id'] : null;
            $firstBeltId = $beltIds[0] ?? null;
            $desc    = sanitizeInput($_POST['description'] ?? '');

            // If belts selected but style isn't, look it up from first belt
            if ($firstBeltId && !$styleId) {
                $sStmt = $pdo->prepare("SELECT style_id FROM belts WHERE id = ?");
                $sStmt->execute([$firstBeltId]);
                $styleId = (int)$sStmt->fetchColumn() ?: null;
            }

            if (!isset($_FILES['documents']) || !is_array($_FILES['documents']['name'])) {
                $message = showAlert('Please select at least one file to upload.', 'error');
                break;
            }

            $uploaded = 0;
            $failed = 0;
            $errors = [];

            $fileCount = count($_FILES['documents']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $fileName  = $_FILES['documents']['name'][$i];
                $fileError = $_FILES['documents']['error'][$i];
                $fileSize  = $_FILES['documents']['size'][$i];
                $fileTmp   = $_FILES['documents']['tmp_name'][$i];

                // Skip empty slots
                if ($fileError === UPLOAD_ERR_NO_FILE) continue;

                if ($fileError !== UPLOAD_ERR_OK) {
                    $errors[] = htmlspecialchars($fileName) . ': Upload error (code ' . $fileError . ')';
                    $failed++;
                    continue;
                }

                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExt, $allowed)) {
                    $errors[] = htmlspecialchars($fileName) . ': Invalid file type (' . $fileExt . ')';
                    $failed++;
                    continue;
                }

                // Validate MIME type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($fileTmp);
                if (!in_array($detectedMime, $allowedMimes)) {
                    $errors[] = htmlspecialchars($fileName) . ': MIME type mismatch (' . $detectedMime . ')';
                    $failed++;
                    continue;
                }

                if ($fileSize > $maxFileSize) {
                    $errors[] = htmlspecialchars($fileName) . ': File too large (' . round($fileSize / 1048576, 1) . 'MB)';
                    $failed++;
                    continue;
                }

                $newFilename = 'belt_doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $fileExt;
                if (move_uploaded_file($fileTmp, $beltDocDir . $newFilename)) {
                    $title = titleFromFilename($fileName);
                    $stmt = $pdo->prepare("
                        INSERT INTO belt_resources (belt_id, style_id, resource_type, title, description, file_path, sort_order)
                        VALUES (?, ?, 'document', ?, ?, ?, 0)
                    ");
                    $stmt->execute([$firstBeltId, $styleId, $title, $desc, $beltDocDir . $newFilename]);
                    $resourceId = $pdo->lastInsertId();

                    // Insert into junction table for each selected belt
                    if (!empty($beltIds) && $styleId) {
                        $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
                        foreach ($beltIds as $bid) {
                            $jStmt->execute([$resourceId, $bid, $styleId]);
                        }
                    }
                    $uploaded++;
                } else {
                    $errors[] = htmlspecialchars($fileName) . ': Failed to save file';
                    $failed++;
                }
            }

            $beltCount = count($beltIds);
            $dest = $beltCount > 0 ? "assigned to {$beltCount} belt(s)" : 'added to unassigned staging';
            $msg = "{$uploaded} file(s) uploaded successfully and {$dest}.";
            if ($failed > 0) {
                $msg .= " {$failed} file(s) failed.";
            }
            $alertType = ($failed > 0 && $uploaded === 0) ? 'error' : ($failed > 0 ? 'warning' : 'success');
            if (!empty($errors)) {
                $msg .= '<br><small>' . implode('<br>', $errors) . '</small>';
            }
            $message = showAlert($msg, $alertType);
            break;

        // ── Assign a single resource to belt(s) ────────────────────
        case 'assign_resource':
            $resId   = (int)($_POST['resource_id'] ?? 0);
            $beltIds = isset($_POST['belt_ids']) ? array_map('intval', (array)$_POST['belt_ids']) : [];
            $beltIds = array_filter($beltIds, fn($id) => $id > 0);
            $styleId = (int)($_POST['style_id'] ?? 0);

            if (!$resId || empty($beltIds) || !$styleId) {
                $message = showAlert('Please select a style and at least one belt to assign.', 'error');
                break;
            }

            $firstBeltId = $beltIds[0];
            $pdo->prepare("UPDATE belt_resources SET belt_id = ?, style_id = ? WHERE id = ?")
                ->execute([$firstBeltId, $styleId, $resId]);
            // Insert into junction table for each selected belt
            $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
            foreach ($beltIds as $bid) {
                $jStmt->execute([$resId, $bid, $styleId]);
            }
            $beltCount = count($beltIds);
            $message = showAlert("Resource assigned to {$beltCount} belt(s) successfully!", 'success');
            break;

        // ── Bulk assign multiple resources ────────────────────────
        case 'bulk_assign':
            $resourceIds = $_POST['resource_ids'] ?? [];
            $beltIds     = isset($_POST['belt_ids']) ? array_map('intval', (array)$_POST['belt_ids']) : [];
            $beltIds     = array_filter($beltIds, fn($id) => $id > 0);
            $styleId     = (int)($_POST['style_id'] ?? 0);

            if (empty($resourceIds) || empty($beltIds) || !$styleId) {
                $message = showAlert('Please select resources, a style, and at least one belt to assign.', 'error');
                break;
            }

            $firstBeltId = $beltIds[0];
            $assigned = 0;
            $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
            foreach ($resourceIds as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $pdo->prepare("UPDATE belt_resources SET belt_id = ?, style_id = ? WHERE id = ? AND belt_id IS NULL")
                        ->execute([$firstBeltId, $styleId, $rid]);
                    // Insert into junction table for each selected belt
                    foreach ($beltIds as $bid) {
                        $jStmt->execute([$rid, $bid, $styleId]);
                    }
                    $assigned++;
                }
            }
            $beltCount = count($beltIds);
            $message = showAlert("{$assigned} resource(s) assigned to {$beltCount} belt(s) successfully!", 'success');
            break;

        // ── Update belt assignments for an existing resource ────────
        case 'update_assignments':
            $resId   = (int)($_POST['resource_id'] ?? 0);
            $beltIds = isset($_POST['belt_ids']) ? array_map('intval', (array)$_POST['belt_ids']) : [];
            $beltIds = array_filter($beltIds, fn($id) => $id > 0);
            $styleId = (int)($_POST['style_id'] ?? 0);

            if (!$resId || !$styleId) {
                $message = showAlert('Invalid resource or style.', 'error');
                break;
            }

            // Remove all existing assignments for this resource
            $pdo->prepare("DELETE FROM belt_resource_belts WHERE resource_id = ?")->execute([$resId]);

            if (!empty($beltIds)) {
                // Insert new assignments
                $jStmt = $pdo->prepare("INSERT IGNORE INTO belt_resource_belts (resource_id, belt_id, style_id) VALUES (?, ?, ?)");
                foreach ($beltIds as $bid) {
                    $jStmt->execute([$resId, $bid, $styleId]);
                }
                // Keep belt_resources.belt_id in sync (backward compat)
                $pdo->prepare("UPDATE belt_resources SET belt_id = ?, style_id = ? WHERE id = ?")
                    ->execute([$beltIds[0], $styleId, $resId]);

                $beltCount = count($beltIds);
                $message = showAlert("Resource updated — now assigned to {$beltCount} belt(s).", 'success');
            } else {
                // All belts unchecked — resource becomes unassigned
                $pdo->prepare("UPDATE belt_resources SET belt_id = NULL WHERE id = ?")
                    ->execute([$resId]);
                $message = showAlert('Resource unassigned from all belts.', 'warning');
            }
            break;

        // ── Delete an unassigned resource ─────────────────────────
        case 'delete_unassigned':
            $resId = (int)($_POST['resource_id'] ?? 0);
            if ($resId) {
                // Only allow deleting unassigned resources from this page
                $resStmt = $pdo->prepare("SELECT * FROM belt_resources WHERE id = ? AND id NOT IN (SELECT resource_id FROM belt_resource_belts)");
                $resStmt->execute([$resId]);
                $resource = $resStmt->fetch();
                if ($resource) {
                    if (!empty($resource['file_path']) && file_exists($resource['file_path'])) {
                        @unlink($resource['file_path']);
                    }
                    $pdo->prepare("DELETE FROM belt_resource_belts WHERE resource_id = ?")->execute([$resId]);
                    $pdo->prepare("DELETE FROM belt_resources WHERE id = ?")->execute([$resId]);
                    $message = showAlert('Resource deleted successfully!', 'success');
                } else {
                    $message = showAlert('Resource not found or already assigned.', 'error');
                }
            }
            break;
    }
}

// ---------- Load data for dropdowns ----------

// Get all styles
$styles = $pdo->query("SELECT * FROM martial_arts_styles ORDER BY name")->fetchAll();

// Pre-load belts grouped by style (for JS cascading dropdowns)
$allBeltsByStyle = [];
foreach ($styles as $style) {
    $bStmt = $pdo->prepare("SELECT id, name, color, rank_order FROM belts WHERE style_id = ? ORDER BY rank_order ASC");
    $bStmt->execute([$style['id']]);
    $allBeltsByStyle[$style['id']] = $bStmt->fetchAll();
}

// Get unassigned resources (not in junction table)
$unassigned = [];
try {
    $uStmt = $pdo->query("SELECT * FROM belt_resources WHERE id NOT IN (SELECT resource_id FROM belt_resource_belts) ORDER BY created_at DESC");
    $unassigned = $uStmt->fetchAll();
} catch (\PDOException $e) {}

// Get assigned resources with their belt assignments (for "Manage Assignments" section)
$assignedResources = [];
try {
    $aStmt = $pdo->query("
        SELECT br.id, br.title, br.description, br.resource_type, br.file_path, br.video_url, br.created_at,
               GROUP_CONCAT(DISTINCT brb.belt_id ORDER BY brb.belt_id) as belt_ids,
               GROUP_CONCAT(DISTINCT b.name ORDER BY b.rank_order) as belt_names,
               MIN(brb.style_id) as style_id,
               MIN(mas.name) as style_name
        FROM belt_resources br
        JOIN belt_resource_belts brb ON br.id = brb.resource_id
        JOIN belts b ON b.id = brb.belt_id
        JOIN martial_arts_styles mas ON mas.id = brb.style_id
        GROUP BY br.id
        ORDER BY mas.name, br.title ASC
        LIMIT 500
    ");
    $assignedResources = $aStmt->fetchAll();
} catch (\PDOException $e) {}

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php echo $message; ?>

    <div class="flex justify-between items-center mb-6">
        <div>
            <a href="belts.php" class="text-blue-600 hover:text-blue-800">&larr; Back to Belt System</a>
            <h1 class="text-3xl font-bold text-gray-800 mt-2">Bulk Upload Resources</h1>
            <p class="text-gray-500 text-sm mt-1">Upload multiple documents at once. Optionally assign them to a belt now, or upload first and assign later.</p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- Section 1: Bulk Upload Form                                -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Upload Documents</h2>

        <form method="POST" enctype="multipart/form-data" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_upload">

            <!-- Optional Belt Assignment -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 class="text-sm font-semibold text-blue-800 mb-3">Assign to Belts (Optional)</h3>
                <p class="text-xs text-blue-600 mb-3">Select a style, then check one or more belts to assign all uploaded files to. Leave blank to upload to the unassigned staging area.</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Style</label>
                    <select name="style_id" id="upload_style"
                            onchange="showBeltCheckboxes(this.value, 'upload_belt_checkboxes')"
                            class="w-full md:w-1/2 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">-- No style (unassigned) --</option>
                        <?php foreach ($styles as $style): ?>
                            <option value="<?= $style['id'] ?>"><?= htmlspecialchars($style['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Belts</label>
                    <div id="upload_belt_checkboxes" class="flex flex-wrap gap-2 p-2 border border-gray-200 rounded-lg bg-white min-h-[40px] max-h-48 overflow-y-auto">
                        <span class="text-gray-400 text-sm">Select a style first...</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Check multiple belts to assign the same documents to all of them (e.g., Yellow and Orange).</p>
                </div>
            </div>

            <!-- File Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Files *</label>
                <input type="file" name="documents[]" multiple required
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"
                       class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                <p class="text-xs text-gray-500 mt-1">Allowed: PDF, DOC, DOCX, JPG, PNG, GIF. Max 10MB per file. Titles are auto-generated from filenames.</p>
            </div>

            <!-- Optional Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description (applied to all uploads)</label>
                <input type="text" name="description" placeholder="Optional description for all uploaded files..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>

            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-medium">
                Upload Files
            </button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- Section 2: Unassigned Resources                            -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Unassigned Resources</h2>
                <p class="text-sm text-gray-500">These documents have been uploaded but not yet assigned to a belt. Assign them below.</p>
            </div>
            <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-semibold">
                <?= count($unassigned) ?> unassigned
            </span>
        </div>

        <?php if (empty($unassigned)): ?>
            <div class="text-center py-8 text-gray-400">
                <p class="text-lg">No unassigned resources</p>
                <p class="text-sm mt-1">All uploaded documents have been assigned to belts, or you haven't uploaded any yet.</p>
            </div>
        <?php else: ?>

            <!-- Bulk Assign Bar -->
            <div class="bg-gray-50 rounded-lg p-4 mb-4 border border-gray-200">
                <form method="POST" id="bulkAssignForm" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_assign">

                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded mr-2">
                            <label for="selectAll" class="text-sm font-medium text-gray-700">Select All</label>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Style</label>
                            <select name="style_id" id="bulk_assign_style"
                                    onchange="showBeltCheckboxes(this.value, 'bulk_assign_belt_checkboxes')"
                                    class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500">
                                <option value="">-- Style --</option>
                                <?php foreach ($styles as $style): ?>
                                    <option value="<?= $style['id'] ?>"><?= htmlspecialchars($style['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" onclick="return confirm('Assign all selected resources to the checked belt(s)?')"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            Assign Selected
                        </button>
                    </div>

                    <div id="bulk_assign_belt_checkboxes" class="flex flex-wrap gap-2 p-2 border border-gray-200 rounded-lg bg-white min-h-[36px] max-h-40 overflow-y-auto">
                        <span class="text-gray-400 text-sm">Select a style to see available belts...</span>
                    </div>
                </form>
            </div>

            <!-- Unassigned Resources Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10"></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uploaded</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assign To</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($unassigned as $idx => $res): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <input type="checkbox" name="resource_ids[]" value="<?= $res['id'] ?>"
                                           form="bulkAssignForm"
                                           class="resource-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded">
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <?php
                                        $ext = strtolower(pathinfo($res['file_path'] ?? '', PATHINFO_EXTENSION));
                                        $icon = match($ext) {
                                            'pdf' => '&#128196;',
                                            'doc', 'docx' => '&#128209;',
                                            'jpg', 'jpeg', 'png', 'gif' => '&#128247;',
                                            default => '&#128196;'
                                        };
                                        ?>
                                        <span class="text-xl"><?= $icon ?></span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($res['title']) ?></p>
                                            <?php if (!empty($res['description'])): ?>
                                                <p class="text-xs text-gray-500"><?= htmlspecialchars($res['description']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full uppercase"><?= htmlspecialchars($ext) ?></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    <?= date('M j, Y', strtotime($res['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="space-y-1">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="assign_resource">
                                        <input type="hidden" name="resource_id" value="<?= $res['id'] ?>">
                                        <div class="flex items-center gap-2">
                                            <select name="style_id" id="row_style_<?= $idx ?>"
                                                    onchange="showRowBeltCheckboxes(this.value, 'row_belts_<?= $idx ?>')"
                                                    class="px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:border-blue-500">
                                                <option value="">Style...</option>
                                                <?php foreach ($styles as $style): ?>
                                                    <option value="<?= $style['id'] ?>"><?= htmlspecialchars($style['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="text-blue-600 hover:text-blue-800 text-xs font-semibold whitespace-nowrap">Assign</button>
                                        </div>
                                        <div id="row_belts_<?= $idx ?>" class="flex flex-wrap gap-1 max-h-24 overflow-y-auto"></div>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($res['file_path']) && file_exists($res['file_path'])): ?>
                                            <a href="<?= htmlspecialchars($res['file_path']) ?>" target="_blank"
                                               class="text-green-600 hover:text-green-800 text-xs font-semibold">View</a>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this resource and its file?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_unassigned">
                                            <input type="hidden" name="resource_id" value="<?= $res['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-semibold">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- Section 3: Manage Belt Assignments for Existing Resources   -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-lg shadow p-6 mt-8">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Manage Belt Assignments</h2>
                <p class="text-sm text-gray-500">Update which belts each existing resource is assigned to. Check or uncheck belts to reassign without re-uploading.</p>
            </div>
            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-semibold">
                <?= count($assignedResources) ?> assigned
            </span>
        </div>

        <?php if (empty($assignedResources)): ?>
            <div class="text-center py-8 text-gray-400">
                <p class="text-lg">No assigned resources yet</p>
                <p class="text-sm mt-1">Upload documents and assign them to belts above.</p>
            </div>
        <?php else: ?>
            <!-- Style filter -->
            <div class="mb-4">
                <label class="text-sm font-medium text-gray-700 mr-2">Filter by style:</label>
                <select id="manage_style_filter" onchange="filterAssignedResources(this.value)"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500">
                    <option value="">All Styles</option>
                    <?php foreach ($styles as $style): ?>
                        <option value="<?= $style['id'] ?>"><?= htmlspecialchars($style['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="space-y-3" id="assigned_resources_list">
                <?php foreach ($assignedResources as $aidx => $ares):
                    $ext = strtolower(pathinfo($ares['file_path'] ?? '', PATHINFO_EXTENSION));
                    $icon = match($ares['resource_type']) {
                        'video' => '&#127909;',
                        default => match($ext) {
                            'pdf' => '&#128196;',
                            'doc', 'docx' => '&#128209;',
                            'jpg', 'jpeg', 'png', 'gif' => '&#128247;',
                            default => '&#128196;'
                        }
                    };
                    $currentBeltIds = $ares['belt_ids'] ? explode(',', $ares['belt_ids']) : [];
                    $beltNamesList = $ares['belt_names'] ?: '';
                ?>
                    <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 assigned-resource-row" data-style-id="<?= $ares['style_id'] ?>">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_assignments">
                            <input type="hidden" name="resource_id" value="<?= $ares['id'] ?>">
                            <input type="hidden" name="style_id" value="<?= $ares['style_id'] ?>">

                            <div class="flex items-start justify-between gap-4">
                                <!-- Resource info -->
                                <div class="flex items-start gap-3 flex-1 min-w-0">
                                    <span class="text-2xl mt-0.5"><?= $icon ?></span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800"><?= htmlspecialchars($ares['title']) ?></p>
                                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                                            <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full"><?= htmlspecialchars($ares['style_name']) ?></span>
                                            <?php if ($ares['resource_type'] === 'video'): ?>
                                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Video</span>
                                            <?php else: ?>
                                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full uppercase"><?= htmlspecialchars($ext) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($ares['description'])): ?>
                                                <span class="text-xs text-gray-500"><?= htmlspecialchars($ares['description']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Save button -->
                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium whitespace-nowrap">
                                    Save
                                </button>
                            </div>

                            <!-- Belt checkboxes -->
                            <div class="mt-3 ml-9">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Assigned Belts:</label>
                                <div class="flex flex-wrap gap-2">
                                    <?php
                                    $styleBelts = $allBeltsByStyle[$ares['style_id']] ?? [];
                                    foreach ($styleBelts as $belt):
                                        $isChecked = in_array($belt['id'], $currentBeltIds) ? 'checked' : '';
                                    ?>
                                        <label class="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-200 bg-white text-sm cursor-pointer hover:bg-purple-50">
                                            <input type="checkbox" name="belt_ids[]" value="<?= $belt['id'] ?>" <?= $isChecked ?>
                                                   class="rounded text-purple-600">
                                            <span><?= htmlspecialchars($belt['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Pre-loaded belt data (same pattern as belts.php)
const beltsByStyle = <?= json_encode($allBeltsByStyle) ?>;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Build belt checkboxes for upload form and bulk assign bar
function showBeltCheckboxes(styleId, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!styleId || !beltsByStyle[styleId]) {
        container.innerHTML = '<span class="text-gray-400 text-sm">Select a style first...</span>';
        return;
    }

    const belts = beltsByStyle[styleId];
    let html = '';
    belts.forEach(belt => {
        html += `<label class="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-200 bg-white text-sm cursor-pointer hover:bg-purple-50">
            <input type="checkbox" name="belt_ids[]" value="${belt.id}" class="rounded text-purple-600">
            <span>${escapeHtml(belt.name)}</span>
        </label>`;
    });
    container.innerHTML = html;
}

// Build belt checkboxes for per-row assign (compact)
function showRowBeltCheckboxes(styleId, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!styleId || !beltsByStyle[styleId]) {
        container.innerHTML = '';
        return;
    }

    const belts = beltsByStyle[styleId];
    let html = '';
    belts.forEach(belt => {
        html += `<label class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-gray-200 bg-white text-xs cursor-pointer hover:bg-purple-50">
            <input type="checkbox" name="belt_ids[]" value="${belt.id}" class="rounded text-purple-600 w-3 h-3">
            <span>${escapeHtml(belt.name)}</span>
        </label>`;
    });
    container.innerHTML = html;
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.resource-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

// Filter assigned resources by style
function filterAssignedResources(styleId) {
    document.querySelectorAll('.assigned-resource-row').forEach(row => {
        if (!styleId || row.dataset.styleId === styleId) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
