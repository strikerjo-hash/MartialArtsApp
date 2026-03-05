<?php
/**
 * student_training.php — Training Resources for Students
 *
 * Displays documents and videos assigned to the student's belt ranks
 * AND all lower belt ranks within each style they train in.
 * Students use a belt-level selector to filter which rank's content
 * they want to view, keeping the page focused and uncluttered.
 */

require_once 'config.php';

// Require student login
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}
require_student_payment_clear();

$studentId = $_SESSION['student_id'];

// ---------------------------------------------------------------
// 1. Fetch student's AWARDED belt ranks (grouped by style)
// ---------------------------------------------------------------
$studentBelts = [];
try {
    $bStmt = $pdo->prepare(
        "SELECT DISTINCT sb.belt_id, sb.style_id, sb.black_belt_number,
                b.name as belt_name, b.color, b.rank_order, mas.name as style_name
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         JOIN martial_arts_styles mas ON mas.id = sb.style_id
         WHERE sb.student_id = ?" . school_where('sb') . "
         ORDER BY mas.name, b.rank_order"
    );
    $params = [$studentId];
    school_param($params);
    $bStmt->execute($params);
    $studentBelts = $bStmt->fetchAll();
} catch (\PDOException $e) {}

// ---------------------------------------------------------------
// 2. Determine the HIGHEST rank per style the student has achieved
// ---------------------------------------------------------------
$highestPerStyle = []; // style_id => rank_order
foreach ($studentBelts as $sb) {
    $sid = (int) $sb['style_id'];
    $ro  = (int) $sb['rank_order'];
    if (!isset($highestPerStyle[$sid]) || $ro > $highestPerStyle[$sid]) {
        $highestPerStyle[$sid] = $ro;
    }
}

// ---------------------------------------------------------------
// 3. Fetch ALL belts at or below the student's highest rank per
//    style — these are the belts whose content they can access
// ---------------------------------------------------------------
$accessibleBelts = [];
try {
    if (!empty($highestPerStyle)) {
        // Build a condition for each style: (style_id = X AND rank_order <= Y)
        $conditions = [];
        $params     = [];
        $i = 0;
        foreach ($highestPerStyle as $styleId => $maxRank) {
            $conditions[] = "(b.style_id = ? AND b.rank_order <= ?)";
            $params[] = $styleId;
            $params[] = $maxRank;
            $i++;
        }
        $where = implode(' OR ', $conditions);

        $abStmt = $pdo->prepare(
            "SELECT b.id as belt_id, b.style_id, b.name as belt_name,
                    b.color, b.rank_order, mas.name as style_name
             FROM belts b
             JOIN martial_arts_styles mas ON mas.id = b.style_id
             WHERE ({$where})
             ORDER BY mas.name, b.rank_order"
        );
        $abStmt->execute($params);
        $accessibleBelts = $abStmt->fetchAll();
    }
} catch (\PDOException $e) {}

// ---------------------------------------------------------------
// 4. Determine the selected belt filter (via ?belt= query param)
//    Default = the student's CURRENT (highest) belt in first style
// ---------------------------------------------------------------
$selectedBeltId = null;
if (isset($_GET['belt']) && $_GET['belt'] === 'all') {
    $selectedBeltId = 'all';
} elseif (!empty($_GET['belt'])) {
    // Validate it's an accessible belt
    $requestedId = (int) $_GET['belt'];
    foreach ($accessibleBelts as $ab) {
        if ((int) $ab['belt_id'] === $requestedId) {
            $selectedBeltId = $requestedId;
            break;
        }
    }
}

// If no valid selection, default to showing ALL content at or below the
// student's highest belt so they can review everything they've learned.
if ($selectedBeltId === null && !empty($accessibleBelts)) {
    $selectedBeltId = 'all';
}

// ---------------------------------------------------------------
// 5. Fetch resources based on selected filter
// ---------------------------------------------------------------
$resourcesByBelt = [];
try {
    if (!empty($accessibleBelts)) {
        if ($selectedBeltId === 'all') {
            // Show all accessible belt resources
            $beltIds = array_column($accessibleBelts, 'belt_id');
        } elseif ($selectedBeltId) {
            // Show only the selected belt's resources
            $beltIds = [$selectedBeltId];
        } else {
            $beltIds = [];
        }

        if (!empty($beltIds)) {
            $placeholders = implode(',', array_fill(0, count($beltIds), '?'));
            $rStmt = $pdo->prepare(
                "SELECT br.*, b.name as belt_name, b.color as belt_color,
                        b.rank_order, mas.name as style_name, brb.belt_id as junction_belt_id
                 FROM belt_resources br
                 JOIN belt_resource_belts brb ON br.id = brb.resource_id
                 JOIN belts b ON b.id = brb.belt_id
                 JOIN martial_arts_styles mas ON mas.id = brb.style_id
                 WHERE brb.belt_id IN ($placeholders)
                 ORDER BY mas.name, b.rank_order, br.sort_order ASC, br.created_at DESC"
            );
            $params = $beltIds;
            $rStmt->execute($params);
            $seenResourceBelt = [];
            foreach ($rStmt->fetchAll() as $res) {
                // Deduplicate: a resource assigned to multiple accessible belts should appear once per belt group
                $dedupKey = $res['id'] . '-' . $res['junction_belt_id'];
                if (isset($seenResourceBelt[$dedupKey])) continue;
                $seenResourceBelt[$dedupKey] = true;

                $key = $res['style_name'] . ' — ' . $res['belt_name'];
                $resourcesByBelt[$key][] = $res;
            }
        }
    }
} catch (\PDOException $e) {}

// Count total resources across all accessible belts (for "View All" badge)
$totalAccessibleResources = 0;
try {
    if (!empty($accessibleBelts)) {
        $allBeltIds = array_column($accessibleBelts, 'belt_id');
        $ph = implode(',', array_fill(0, count($allBeltIds), '?'));
        $cStmt = $pdo->prepare("SELECT COUNT(DISTINCT brb.resource_id) FROM belt_resource_belts brb WHERE brb.belt_id IN ($ph)");
        $params = $allBeltIds;
        $cStmt->execute($params);
        $totalAccessibleResources = (int) $cStmt->fetchColumn();
    }
} catch (\PDOException $e) {}

/**
 * Extract YouTube video ID from various URL formats.
 */
function getYouTubeId(string $url): ?string {
    $patterns = [
        '/youtu\.be\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]+)/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

/**
 * Extract Vimeo video ID from URL.
 */
function getVimeoId(string $url): ?string {
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Build an embeddable iframe URL from a video URL.
 * Returns the embed URL or null if not a supported platform.
 */
function getEmbedUrl(string $url): ?string {
    $ytId = getYouTubeId($url);
    if ($ytId) return "https://www.youtube.com/embed/{$ytId}";

    $vimeoId = getVimeoId($url);
    if ($vimeoId) return "https://player.vimeo.com/video/{$vimeoId}";

    return null;
}

// Belt color map for badges
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

/** Return CSS background style for a belt color (gradient for compound colors). */
if (!function_exists('beltBackground')) {
function beltBackground(string $color, array $colorMap): string
{
    $stripeColors = [
        'Yellow' => '#FFD700', 'Orange' => '#FF8C00', 'Green' => '#228B22',
        'Blue' => '#0000CD', 'Purple' => '#800080', 'Brown' => '#8B4513',
        'Red' => '#DC143C', 'White' => '#FFFFFF',
    ];
    $camoBg = 'linear-gradient(135deg, #4B5320 0%, #6B8E23 25%, #556B2F 50%, #4B5320 75%, #6B8E23 100%)';

    $splits = [
        'Brown-Red' => 'linear-gradient(135deg, #8B4513 50%, #DC143C 50%)',
        'Black-Red' => 'linear-gradient(135deg, #000000 50%, #DC143C 50%)',
    ];
    if (isset($splits[$color])) {
        return 'background: ' . $splits[$color] . ';';
    }

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

    if ($color === 'Camouflage') {
        return 'background: ' . $camoBg . ';';
    }

    return 'background-color: ' . ($colorMap[$color] ?? '#6B7280') . ';';
}
}

// Group accessible belts by style for the selector
$beltsByStyle = [];
foreach ($accessibleBelts as $ab) {
    $beltsByStyle[$ab['style_name']][] = $ab;
}

// Build a set of the student's directly-awarded belt IDs for highlighting
$awardedBeltIds = [];
foreach ($studentBelts as $sb) {
    $awardedBeltIds[(int) $sb['belt_id']] = true;
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Training Resources</h1>
        <p class="text-gray-600 mt-1">Documents and videos for your belt ranks. Select a belt level to view its materials.</p>
    </div>

    <?php if (empty($studentBelts)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <span class="text-4xl mb-4 block">&#127941;</span>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">No Belt Ranks Yet</h3>
            <p class="text-gray-500">You haven't been awarded any belt ranks yet. Once you receive a belt, training resources will appear here.</p>
        </div>

    <?php else: ?>

        <!-- ===================== BELT SELECTOR ===================== -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Select Belt Level</h2>
            </div>
            <div class="p-4">
                <?php foreach ($beltsByStyle as $styleName => $belts): ?>
                    <?php if (count($beltsByStyle) > 1): ?>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2 mt-2 first:mt-0"><?= htmlspecialchars($styleName) ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach ($belts as $belt):
                            $bId        = (int) $belt['belt_id'];
                            $isSelected = ($selectedBeltId !== 'all' && $selectedBeltId === $bId);
                            $isAwarded  = isset($awardedBeltIds[$bId]);
                            $bgStyle    = beltBackground($belt['color'], $colorMap);
                            $textColor  = (in_array($belt['color'], ['White', 'Yellow', 'Orange']) || str_starts_with($belt['color'], 'White-')) ? '#1f2937' : '#ffffff';
                            $borderHex  = ($colorMap[$belt['color']] ?? '#6B7280');
                        ?>
                            <a href="?belt=<?= $bId ?>"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all
                                      <?= $isSelected
                                          ? 'ring-2 ring-offset-2 ring-blue-500 shadow-md scale-105'
                                          : 'hover:shadow-md hover:scale-102 opacity-80 hover:opacity-100' ?>"
                               style="<?= $bgStyle ?> color: <?= $textColor ?>; border: 2px solid <?= $borderHex === '#FFFFFF' ? '#d1d5db' : $borderHex ?>;">
                                <span class="text-xs"><?= htmlspecialchars($belt['belt_name']) ?></span>
                                <?php if ($isAwarded): ?>
                                    <span title="You've earned this belt" style="font-size: 11px;">&#9733;</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <!-- "View All" button -->
                <div class="mt-2 pt-3 border-t border-gray-100">
                    <a href="?belt=all"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all
                              <?= $selectedBeltId === 'all'
                                  ? 'bg-blue-600 text-white ring-2 ring-offset-2 ring-blue-500 shadow-md'
                                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200 hover:shadow-md' ?>">
                        <span>&#128218;</span>
                        View All Levels
                        <span class="text-xs opacity-75">(<?= $totalAccessibleResources ?>)</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- ===================== RESOURCES ===================== -->
        <?php if (empty($resourcesByBelt)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <span class="text-4xl mb-4 block">&#128218;</span>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">No Resources Available</h3>
                <p class="text-gray-500">No training resources have been uploaded for this belt level yet. Try selecting a different belt or check back later!</p>
            </div>
        <?php else: ?>
            <?php foreach ($resourcesByBelt as $groupLabel => $resources): ?>
                <div class="bg-white rounded-lg shadow mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($groupLabel) ?></h2>
                        <p class="text-sm text-gray-500"><?= count($resources) ?> resource<?= count($resources) !== 1 ? 's' : '' ?></p>
                    </div>
                    <div class="p-6 space-y-6">
                        <?php
                        // Separate documents and videos
                        $docs = array_filter($resources, fn($r) => $r['resource_type'] === 'document');
                        $vids = array_filter($resources, fn($r) => $r['resource_type'] === 'video');
                        ?>

                        <?php if (!empty($docs)): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">&#128196; Documents</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($docs as $doc): ?>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank"
                                           class="flex items-center gap-3 p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition group">
                                            <div class="flex-shrink-0 w-10 h-10 bg-purple-200 rounded-lg flex items-center justify-center">
                                                <span class="text-purple-700 text-lg">&#128196;</span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="font-medium text-gray-800 group-hover:text-purple-700 truncate"><?= htmlspecialchars($doc['title']) ?></p>
                                                <?php if (!empty($doc['description'])): ?>
                                                    <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($doc['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-purple-600 text-sm flex-shrink-0">Download &rarr;</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($vids)): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">&#127909; Videos</h3>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <?php foreach ($vids as $vid): ?>
                                        <div class="bg-gray-50 rounded-lg overflow-hidden">
                                            <?php
                                            $embedUrl = getEmbedUrl($vid['video_url']);
                                            if ($embedUrl):
                                            ?>
                                                <div class="relative" style="padding-top: 56.25%;">
                                                    <iframe src="<?= htmlspecialchars($embedUrl) ?>"
                                                            class="absolute inset-0 w-full h-full rounded-t-lg"
                                                            frameborder="0"
                                                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                                            allowfullscreen></iframe>
                                                </div>
                                            <?php else: ?>
                                                <div class="h-40 flex items-center justify-center bg-gray-100">
                                                    <a href="<?= htmlspecialchars($vid['video_url']) ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                                        Open Video &rarr;
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <div class="p-3">
                                                <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($vid['title']) ?></p>
                                                <?php if (!empty($vid['description'])): ?>
                                                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($vid['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ===================== YOUR BELT RANKS ===================== -->
        <div class="bg-white rounded-lg shadow mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Your Belt Ranks</h2>
            </div>
            <div class="p-6">
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($studentBelts as $sb): ?>
                        <div class="flex items-center gap-2 px-4 py-2 rounded-full border border-gray-200">
                            <div class="w-5 h-5 rounded-full border border-gray-300"
                                 style="<?= beltBackground($sb['color'], $colorMap) ?>"></div>
                            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($sb['belt_name']) ?></span>
                            <span class="text-xs text-gray-500">(<?= htmlspecialchars($sb['style_name']) ?>)</span>
                            <?php if (!empty($sb['black_belt_number'])): ?>
                                <span class="text-xs font-bold bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full border border-yellow-300">BB #<?= htmlspecialchars($sb['black_belt_number']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-400 mt-3">&#9733; = belts you've earned. Lower ranks are accessible for review.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/student_footer.php'; ?>
