<?php
/**
 * parent_child_training.php — Parent view of a child's training resources
 *
 * Mirrors student_training.php but with parent auth and parent header.
 * Shows documents and videos assigned to the child's belt ranks.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$parentId = get_effective_parent_id();
$childId  = (int)($_GET['id'] ?? 0);

// Verify this child belongs to this parent
$child = parent_verify_child($parentId, $childId);

$children = get_parent_children($parentId);

// ---------------------------------------------------------------
// 1. Fetch child's AWARDED belt ranks
// ---------------------------------------------------------------
$studentBelts = [];
try {
    $bSql = "SELECT DISTINCT sb.belt_id, sb.style_id, sb.black_belt_number,
                b.name as belt_name, b.color, b.rank_order, mas.name as style_name
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         JOIN martial_arts_styles mas ON mas.id = sb.style_id
         WHERE sb.student_id = :sid";
    $bParams = [':sid' => $childId];
    if (!is_viewing_all_schools()) {
        $bSql .= " AND sb.school_id = :school_id";
        $bParams[':school_id'] = current_school_id();
    }
    $bSql .= " ORDER BY mas.name, b.rank_order";
    $bStmt = $pdo->prepare($bSql);
    $bStmt->execute($bParams);
    $studentBelts = $bStmt->fetchAll();
} catch (\PDOException $e) {}

// 2. Highest rank per style
$highestPerStyle = [];
foreach ($studentBelts as $sb) {
    $sid = (int) $sb['style_id'];
    $ro  = (int) $sb['rank_order'];
    if (!isset($highestPerStyle[$sid]) || $ro > $highestPerStyle[$sid]) {
        $highestPerStyle[$sid] = $ro;
    }
}

// 3. Accessible belts (at or below highest rank per style)
$accessibleBelts = [];
try {
    if (!empty($highestPerStyle)) {
        $conditions = [];
        $params     = [];
        $i = 0;
        foreach ($highestPerStyle as $styleId => $maxRank) {
            $conditions[] = "(b.style_id = :s{$i} AND b.rank_order <= :r{$i})";
            $params[":s{$i}"] = $styleId;
            $params[":r{$i}"] = $maxRank;
            $i++;
        }
        $where = implode(' OR ', $conditions);
        $abSql = "SELECT b.id as belt_id, b.style_id, b.name as belt_name,
                    b.color, b.rank_order, mas.name as style_name
             FROM belts b
             JOIN martial_arts_styles mas ON mas.id = b.style_id
             WHERE ({$where})
             ORDER BY mas.name, b.rank_order";
        $abStmt = $pdo->prepare($abSql);
        $abStmt->execute($params);
        $accessibleBelts = $abStmt->fetchAll();
    }
} catch (\PDOException $e) {}

// 4. Selected belt filter
$selectedBeltId = null;
if (isset($_GET['belt']) && $_GET['belt'] === 'all') {
    $selectedBeltId = 'all';
} elseif (!empty($_GET['belt'])) {
    $requestedId = (int) $_GET['belt'];
    foreach ($accessibleBelts as $ab) {
        if ((int) $ab['belt_id'] === $requestedId) {
            $selectedBeltId = $requestedId;
            break;
        }
    }
}
// Default to showing ALL content at or below the student's highest belt
if ($selectedBeltId === null && !empty($accessibleBelts)) {
    $selectedBeltId = 'all';
}

// 5. Fetch resources
$resourcesByBelt = [];
try {
    if (!empty($accessibleBelts)) {
        $beltIds = ($selectedBeltId === 'all')
            ? array_column($accessibleBelts, 'belt_id')
            : ($selectedBeltId ? [$selectedBeltId] : []);

        if (!empty($beltIds)) {
            $placeholders = implode(',', array_fill(0, count($beltIds), '?'));
            $params = $beltIds;
            $rStmt = $pdo->prepare(
                "SELECT br.*, b.name as belt_name, b.color as belt_color,
                        b.rank_order, mas.name as style_name
                 FROM belt_resources br
                 JOIN belts b ON b.id = br.belt_id
                 JOIN martial_arts_styles mas ON mas.id = br.style_id
                 WHERE br.belt_id IN ($placeholders)
                 ORDER BY mas.name, b.rank_order, br.sort_order ASC, br.created_at DESC"
            );
            $rStmt->execute($params);
            foreach ($rStmt->fetchAll() as $res) {
                $key = $res['style_name'] . ' — ' . $res['belt_name'];
                $resourcesByBelt[$key][] = $res;
            }
        }
    }
} catch (\PDOException $e) {}

$totalAccessibleResources = 0;
try {
    if (!empty($accessibleBelts)) {
        $allBeltIds = array_column($accessibleBelts, 'belt_id');
        $ph = implode(',', array_fill(0, count($allBeltIds), '?'));
        $params = $allBeltIds;
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM belt_resources WHERE belt_id IN ($ph)");
        $cStmt->execute($params);
        $totalAccessibleResources = (int) $cStmt->fetchColumn();
    }
} catch (\PDOException $e) {}

// Video embed helpers
if (!function_exists('getYouTubeId')) {
    function getYouTubeId(string $url): ?string {
        $patterns = ['/youtu\.be\/([a-zA-Z0-9_-]+)/', '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/'];
        foreach ($patterns as $p) { if (preg_match($p, $url, $m)) return $m[1]; }
        return null;
    }
}
if (!function_exists('getVimeoId')) {
    function getVimeoId(string $url): ?string {
        return preg_match('/vimeo\.com\/(\d+)/', $url, $m) ? $m[1] : null;
    }
}
if (!function_exists('getEmbedUrl')) {
    function getEmbedUrl(string $url): ?string {
        $yt = getYouTubeId($url);
        if ($yt) return "https://www.youtube.com/embed/{$yt}";
        $vi = getVimeoId($url);
        if ($vi) return "https://player.vimeo.com/video/{$vi}";
        return null;
    }
}

// Belt color helpers
$colorMap = [
    'White' => '#FFFFFF', 'Yellow' => '#FFD700', 'Orange' => '#FF8C00',
    'Green' => '#228B22', 'Blue' => '#0000CD', 'Purple' => '#800080',
    'Brown' => '#8B4513', 'Red' => '#DC143C', 'Black' => '#000000',
];
if (!function_exists('beltBackground')) {
    function beltBackground(string $color, array $colorMap): string {
        $stripeColors = ['Yellow'=>'#FFD700','Orange'=>'#FF8C00','Green'=>'#228B22','Blue'=>'#0000CD','Purple'=>'#800080','Brown'=>'#8B4513','Red'=>'#DC143C','White'=>'#FFFFFF'];
        $camoBg = 'linear-gradient(135deg, #4B5320 0%, #6B8E23 25%, #556B2F 50%, #4B5320 75%, #6B8E23 100%)';
        $splits = ['Brown-Red'=>'linear-gradient(135deg, #8B4513 50%, #DC143C 50%)','Black-Red'=>'linear-gradient(135deg, #000000 50%, #DC143C 50%)'];
        if (isset($splits[$color])) return 'background: '.$splits[$color].';';
        if (preg_match('/^(Black|White|Camouflage)-(\w+) Stripe$/', $color, $m)) {
            $stripeHex = $stripeColors[$m[2]] ?? '#999';
            if ($m[1]==='Camouflage') return 'background: linear-gradient(180deg, transparent 40%, '.$stripeHex.' 40%, '.$stripeHex.' 60%, transparent 60%), '.$camoBg.';';
            $baseHex = ($m[1]==='Black') ? '#000' : '#fff';
            return 'background: linear-gradient(180deg, '.$baseHex.' 40%, '.$stripeHex.' 40%, '.$stripeHex.' 60%, '.$baseHex.' 60%);';
        }
        if ($color === 'Camouflage') return 'background: '.$camoBg.';';
        return 'background-color: '.($colorMap[$color] ?? '#6B7280').';';
    }
}

$beltsByStyle = [];
foreach ($accessibleBelts as $ab) {
    $beltsByStyle[$ab['style_name']][] = $ab;
}
$awardedBeltIds = [];
foreach ($studentBelts as $sb) {
    $awardedBeltIds[(int) $sb['belt_id']] = true;
}

include 'includes/student_header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Breadcrumb + Child Switcher -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="parent_child.php?id=<?= $childId ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">&larr; <?= htmlspecialchars($child['first_name']) ?></a>
            <span class="text-gray-300">|</span>
            <h1 class="text-2xl font-bold text-gray-800">Training Resources</h1>
        </div>
        <?php if (count($children) > 1): ?>
            <select onchange="window.location.href='parent_child_training.php?id='+this.value"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                <?php foreach ($children as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $childId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <p class="text-gray-600 mb-6">Training documents and videos for <?= htmlspecialchars($child['first_name']) ?>'s belt levels.</p>

    <?php if (empty($studentBelts)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <span class="text-4xl mb-4 block">🥋</span>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">No Belt Ranks Yet</h3>
            <p class="text-gray-500"><?= htmlspecialchars($child['first_name']) ?> hasn't been awarded any belt ranks yet.</p>
        </div>
    <?php else: ?>
        <!-- Belt Selector -->
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
                            $bId = (int) $belt['belt_id'];
                            $isSelected = ($selectedBeltId !== 'all' && $selectedBeltId === $bId);
                            $isAwarded = isset($awardedBeltIds[$bId]);
                            $bgStyle = beltBackground($belt['color'], $colorMap);
                            $textColor = (in_array($belt['color'], ['White','Yellow','Orange']) || str_starts_with($belt['color'], 'White-')) ? '#1f2937' : '#ffffff';
                            $borderHex = ($colorMap[$belt['color']] ?? '#6B7280');
                        ?>
                            <a href="?id=<?= $childId ?>&belt=<?= $bId ?>"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all
                                      <?= $isSelected ? 'ring-2 ring-offset-2 ring-blue-500 shadow-md scale-105' : 'hover:shadow-md opacity-80 hover:opacity-100' ?>"
                               style="<?= $bgStyle ?> color: <?= $textColor ?>; border: 2px solid <?= $borderHex === '#FFFFFF' ? '#d1d5db' : $borderHex ?>;">
                                <span class="text-xs"><?= htmlspecialchars($belt['belt_name']) ?></span>
                                <?php if ($isAwarded): ?><span title="Earned" style="font-size: 11px;">★</span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="mt-2 pt-3 border-t border-gray-100">
                    <a href="?id=<?= $childId ?>&belt=all"
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all
                              <?= $selectedBeltId === 'all' ? 'bg-blue-600 text-white ring-2 ring-offset-2 ring-blue-500 shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        📚 View All Levels <span class="text-xs opacity-75">(<?= $totalAccessibleResources ?>)</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Resources -->
        <?php if (empty($resourcesByBelt)): ?>
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <span class="text-4xl mb-4 block">📚</span>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">No Resources Available</h3>
                <p class="text-gray-500">No training resources for this belt level yet.</p>
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
                        $docs = array_filter($resources, fn($r) => $r['resource_type'] === 'document');
                        $vids = array_filter($resources, fn($r) => $r['resource_type'] === 'video');
                        ?>
                        <?php if (!empty($docs)): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">📄 Documents</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($docs as $doc): ?>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank"
                                           class="flex items-center gap-3 p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition group">
                                            <div class="flex-shrink-0 w-10 h-10 bg-purple-200 rounded-lg flex items-center justify-center">
                                                <span class="text-purple-700 text-lg">📄</span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="font-medium text-gray-800 group-hover:text-purple-700 truncate"><?= htmlspecialchars($doc['title']) ?></p>
                                                <?php if (!empty($doc['description'])): ?>
                                                    <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($doc['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-purple-600 text-sm flex-shrink-0">Download →</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($vids)): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">🎥 Videos</h3>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <?php foreach ($vids as $vid): ?>
                                        <div class="bg-gray-50 rounded-lg overflow-hidden">
                                            <?php $embedUrl = getEmbedUrl($vid['video_url']); ?>
                                            <?php if ($embedUrl): ?>
                                                <div class="relative" style="padding-top: 56.25%;">
                                                    <iframe src="<?= htmlspecialchars($embedUrl) ?>"
                                                            class="absolute inset-0 w-full h-full"
                                                            frameborder="0" allowfullscreen allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                                </div>
                                            <?php else: ?>
                                                <div class="p-4">
                                                    <a href="<?= htmlspecialchars($vid['video_url']) ?>" target="_blank" class="text-blue-600 hover:underline">Watch Video →</a>
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
    <?php endif; ?>
</div>

<?php include 'includes/student_footer.php'; ?>
