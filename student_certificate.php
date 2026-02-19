<?php
/**
 * student_certificate.php — Printable Certificate of Achievement
 *
 * Shows a styled certificate for the student's highest belt rank.
 * Includes black belt number display for black belts.
 * Print-optimized with @media print styles.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/payment_gateway.php';

// Determine access mode: admin/staff viewing a student's cert, or student viewing their own
$isAdminView = false;
$backLink = 'student_portal.php';
$backLabel = 'Back to Dashboard';

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_GET['student_id'])) {
    // Admin/staff accessing via ?student_id=X
    requireLogin();
    $studentId = (int) $_GET['student_id'];
    $isAdminView = true;
    $backLink = 'student_detail.php?id=' . $studentId;
    $backLabel = 'Back to Student Detail';
} elseif ((isset($_SESSION['is_student']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) && isset($_SESSION['student_id'])) {
    // Student portal access — enforce payment lockout
    require_student_payment_clear();
    $studentId = $_SESSION['student_id'];
} else {
    header('Location: login.php');
    exit;
}

// Fetch student profile
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: logout.php');
    exit;
}

$theme = getActiveTheme();
$logoPath = getLogoPath();
$siteName = getSiteName();

// Get all belt achievements ordered by rank (highest first)
$beltAchievements = [];
try {
    $beltStmt = $pdo->prepare(
        "SELECT b.name as belt_name, b.color, b.rank_order, mas.name as style_name,
                sb.awarded_date, sb.black_belt_number, sb.notes,
                u.full_name as instructor_name
         FROM student_belts sb
         JOIN belts b ON b.id = sb.belt_id
         JOIN martial_arts_styles mas ON mas.id = sb.style_id
         LEFT JOIN users u ON sb.instructor_id = u.id
         WHERE sb.student_id = :sid
         ORDER BY b.rank_order DESC, sb.awarded_date DESC"
    );
    $beltStmt->execute([':sid' => $studentId]);
    $beltAchievements = $beltStmt->fetchAll();
} catch (\PDOException $e) {}

// If a specific belt is requested via query param, show that; otherwise show the highest
$selectedIndex = 0;
if (isset($_GET['belt_index']) && is_numeric($_GET['belt_index'])) {
    $idx = (int) $_GET['belt_index'];
    if ($idx >= 0 && $idx < count($beltAchievements)) {
        $selectedIndex = $idx;
    }
}

$selectedBelt = $beltAchievements[$selectedIndex] ?? null;

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

$studentName = trim($student['first_name'] . ' ' . $student['last_name']);

// Determine cert type and load type-specific settings — any Black-* variant counts as black belt
$isBlackBeltRank = $selectedBelt ? str_starts_with(strtolower($selectedBelt['color'] ?? ''), 'black') : false;
$certTypePrefix = $isBlackBeltRank ? 'black' : 'color';

// Template image
$customTemplate = getSetting('cert_' . $certTypePrefix . '_belt_template', '');
$hasCustomTemplate = !empty($customTemplate) && file_exists(__DIR__ . '/' . $customTemplate);

// Font: type-specific first, fall back to legacy single-font
$customFontPath = getSetting('cert_' . $certTypePrefix . '_font', '');
if (empty($customFontPath) || !file_exists(__DIR__ . '/' . $customFontPath)) {
    $customFontPath = getSetting('cert_custom_font', ''); // legacy fallback
}
$hasCustomFont = !empty($customFontPath) && file_exists(__DIR__ . '/' . $customFontPath);
$certFontFamily = $hasCustomFont ? "'CertCustomFont', Georgia, 'Times New Roman', serif" : "Georgia, 'Times New Roman', serif";

// Helper: load a setting with type-prefix, falling back to legacy single-prefix
function certSetting($certTypePrefix, $field, $prop, $default) {
    $newKey = 'cert_' . $certTypePrefix . '_' . $field . '_' . $prop;
    $val = getSetting($newKey, '');
    if ($val !== '') return $val;
    // Fallback to legacy key
    $legacyField = ($field === 'instructor') ? 'instructor' : $field;
    $legacyKey = 'cert_' . $legacyField . '_' . $prop;
    return getSetting($legacyKey, $default);
}

// Helper: build text-shadow CSS for stroke effect
function certStrokeCSS($stroke, $strokeColor) {
    $s = (float) $stroke;
    if ($s <= 0) return 'none';
    $c = $strokeColor ?: '#000000';
    return "{$s}px {$s}px 0 {$c}, -{$s}px {$s}px 0 {$c}, {$s}px -{$s}px 0 {$c}, -{$s}px -{$s}px 0 {$c}";
}

// Map of short keys to field names used in settings
$fieldMap = [
    'name' => ['field' => 'name', 'settingsKey' => 'name', 'defaults' => ['visible'=>'1','top'=>'48','left'=>'50','size'=>'42','color'=>'#1a1a1a','stroke'=>'0','stroke_color'=>'#000000']],
    'rank' => ['field' => 'rank', 'settingsKey' => 'rank', 'defaults' => ['visible'=>'1','top'=>'60','left'=>'50','size'=>'36','color'=>'#1a1a1a','stroke'=>'0','stroke_color'=>'#000000']],
    'style' => ['field' => 'style', 'settingsKey' => 'style', 'defaults' => ['visible'=>'1','top'=>'67','left'=>'50','size'=>'22','color'=>'#555555','stroke'=>'0','stroke_color'=>'#000000']],
    'date' => ['field' => 'date', 'settingsKey' => 'date', 'defaults' => ['visible'=>'1','top'=>'78','left'=>'25','size'=>'18','color'=>'#333333','stroke'=>'0','stroke_color'=>'#000000']],
    'instr' => ['field' => 'instructor', 'settingsKey' => 'instructor', 'defaults' => ['visible'=>'1','top'=>'78','left'=>'75','size'=>'18','color'=>'#333333','stroke'=>'0','stroke_color'=>'#000000']],
    'bb_num' => ['field' => 'bb_number', 'settingsKey' => 'bb_number', 'defaults' => ['visible'=>'1','top'=>'72','left'=>'50','size'=>'18','color'=>'#b8860b','stroke'=>'0','stroke_color'=>'#000000']],
];

$certSettings = [];
foreach ($fieldMap as $short => $info) {
    $f = $info['settingsKey'];
    $d = $info['defaults'];
    $certSettings[$short . '_visible']      = (int) certSetting($certTypePrefix, $f, 'visible', $d['visible']);
    $certSettings[$short . '_top']           = (float) certSetting($certTypePrefix, $f, 'top_pct', $d['top']);
    $certSettings[$short . '_left']          = (float) certSetting($certTypePrefix, $f, 'left_pct', $d['left']);
    $certSettings[$short . '_size']          = (int) certSetting($certTypePrefix, $f, 'font_size', $d['size']);
    $certSettings[$short . '_color']         = certSetting($certTypePrefix, $f, 'color', $d['color']);
    $certSettings[$short . '_stroke']        = (float) certSetting($certTypePrefix, $f, 'stroke', $d['stroke']);
    $certSettings[$short . '_stroke_color']  = certSetting($certTypePrefix, $f, 'stroke_color', $d['stroke_color']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Achievement - <?php echo htmlspecialchars($siteName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        <?php if ($hasCustomFont): ?>
        @font-face {
            font-family: 'CertCustomFont';
            src: url('<?php echo htmlspecialchars($customFontPath); ?>');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'CertCustomFont';
            src: url('<?php echo htmlspecialchars($customFontPath); ?>');
            font-weight: bold;
            font-style: normal;
            font-display: swap;
        }
        <?php endif; ?>

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .certificate-page { box-shadow: none !important; margin: 0 !important; border-radius: 0 !important; }
        }

        .certificate-page {
            width: 10in;
            min-height: 7.5in;
            margin: 2rem auto;
            position: relative;
            overflow: hidden;
        }

        .cert-border {
            position: absolute;
            inset: 12px;
            border: 3px double #b8860b;
            border-radius: 4px;
            pointer-events: none;
        }

        .cert-inner-border {
            position: absolute;
            inset: 20px;
            border: 1px solid #d4a84040;
            border-radius: 2px;
            pointer-events: none;
        }

        .cert-corner {
            position: absolute;
            width: 60px;
            height: 60px;
            opacity: 0.3;
        }
        .cert-corner svg { width: 100%; height: 100%; }
        .cert-corner-tl { top: 24px; left: 24px; }
        .cert-corner-tr { top: 24px; right: 24px; transform: scaleX(-1); }
        .cert-corner-bl { bottom: 24px; left: 24px; transform: scaleY(-1); }
        .cert-corner-br { bottom: 24px; right: 24px; transform: scale(-1); }

        .gold-text { color: #b8860b; }
        .cert-name { font-family: <?php echo $certFontFamily; ?>; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Controls (hidden on print) -->
    <div class="no-print container mx-auto px-4 py-4">
        <div class="flex items-center justify-between mb-4">
            <a href="<?php echo $backLink; ?>" class="text-blue-600 hover:underline text-sm">&larr; <?php echo $backLabel; ?></a>
            <div class="flex gap-3">
                <?php if (count($beltAchievements) > 1): ?>
                    <select onchange="window.location.href='student_certificate.php?<?php echo $isAdminView ? 'student_id=' . $studentId . '&' : ''; ?>belt_index='+this.value"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <?php foreach ($beltAchievements as $i => $ba): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i === $selectedIndex ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ba['belt_name']); ?> — <?php echo htmlspecialchars($ba['style_name']); ?>
                                (<?php echo formatDate($ba['awarded_date']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-medium">
                    &#128424; Print Certificate
                </button>
            </div>
        </div>
    </div>

    <?php if (!$selectedBelt): ?>
        <div class="container mx-auto px-4">
            <div class="bg-white rounded-lg shadow p-12 text-center">
                <p class="text-gray-500 text-lg">No belt achievements found.</p>
                <p class="text-gray-400 text-sm mt-2">Once you receive a belt promotion, your certificate will appear here.</p>
                <a href="<?php echo $backLink; ?>" class="inline-block mt-4 text-blue-600 hover:underline">Return to <?php echo $isAdminView ? 'Student Detail' : 'Dashboard'; ?></a>
            </div>
        </div>
    <?php else: ?>
        <?php
        $beltColor = $colorMap[$selectedBelt['color']] ?? '#666666';
        $beltBgStyle = beltBackground($selectedBelt['color'], $colorMap);
        $isBlackBelt = str_starts_with(strtolower($selectedBelt['color'] ?? ''), 'black');
        $textOnBelt = (in_array($selectedBelt['color'], ['White', 'Yellow', 'Orange']) || str_starts_with($selectedBelt['color'], 'White-')) ? '#1a1a1a' : '#FFFFFF';
        ?>

        <?php if ($hasCustomTemplate): ?>
        <!-- ============ CUSTOM TEMPLATE CERTIFICATE ============ -->
        <div class="certificate-page shadow-2xl rounded-lg" style="position: relative; overflow: hidden;">
            <!-- Background template image -->
            <img src="<?php echo htmlspecialchars($customTemplate); ?>" alt="Certificate"
                 style="width: 100%; height: 100%; object-fit: contain; display: block;">

            <!-- Text overlays (absolutely positioned, per-type config with stroke support) -->
            <div style="position: absolute; inset: 0; width: 100%; height: 100%;">

                <!-- Student Name -->
                <?php if ($certSettings['name_visible']): ?>
                <div style="position: absolute; top: <?php echo $certSettings['name_top']; ?>%; left: <?php echo $certSettings['name_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['name_size']; ?>px; font-weight: bold; color: <?php echo $certSettings['name_color']; ?>; font-style: italic; text-shadow: <?php echo certStrokeCSS($certSettings['name_stroke'], $certSettings['name_stroke_color']); ?>;">
                        <?php echo htmlspecialchars($studentName); ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Belt Rank -->
                <?php if ($certSettings['rank_visible']): ?>
                <div style="position: absolute; top: <?php echo $certSettings['rank_top']; ?>%; left: <?php echo $certSettings['rank_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['rank_size']; ?>px; font-weight: bold; color: <?php echo $certSettings['rank_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['rank_stroke'], $certSettings['rank_stroke_color']); ?>;">
                        <?php echo htmlspecialchars($selectedBelt['belt_name']); ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Style -->
                <?php if ($certSettings['style_visible']): ?>
                <div style="position: absolute; top: <?php echo $certSettings['style_top']; ?>%; left: <?php echo $certSettings['style_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['style_size']; ?>px; color: <?php echo $certSettings['style_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['style_stroke'], $certSettings['style_stroke_color']); ?>;">
                        <?php echo htmlspecialchars($selectedBelt['style_name']); ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Date Awarded -->
                <?php if ($certSettings['date_visible']): ?>
                <div style="position: absolute; top: <?php echo $certSettings['date_top']; ?>%; left: <?php echo $certSettings['date_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['date_size']; ?>px; font-weight: 600; color: <?php echo $certSettings['date_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['date_stroke'], $certSettings['date_stroke_color']); ?>;">
                        <?php echo date('F j, Y', strtotime($selectedBelt['awarded_date'])); ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Instructor -->
                <?php if ($certSettings['instr_visible'] && !empty($selectedBelt['instructor_name'])): ?>
                <div style="position: absolute; top: <?php echo $certSettings['instr_top']; ?>%; left: <?php echo $certSettings['instr_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['instr_size']; ?>px; font-weight: 600; color: <?php echo $certSettings['instr_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['instr_stroke'], $certSettings['instr_stroke_color']); ?>;">
                        <?php echo htmlspecialchars($selectedBelt['instructor_name']); ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Black Belt Number (only shown for black belts) -->
                <?php if ($certSettings['bb_num_visible'] && $isBlackBelt && !empty($selectedBelt['black_belt_number'])): ?>
                <div style="position: absolute; top: <?php echo $certSettings['bb_num_top']; ?>%; left: <?php echo $certSettings['bb_num_left']; ?>%; transform: translateX(-50%); text-align: center; white-space: nowrap;">
                    <span class="cert-name" style="font-size: <?php echo $certSettings['bb_num_size']; ?>px; font-weight: bold; color: <?php echo $certSettings['bb_num_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['bb_num_stroke'], $certSettings['bb_num_stroke_color']); ?>;">
                        Black Belt #<?php echo htmlspecialchars($selectedBelt['black_belt_number']); ?>
                    </span>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php else: ?>
        <!-- ============ SYSTEM DEFAULT CERTIFICATE ============ -->
        <div class="certificate-page bg-white shadow-2xl rounded-lg p-0">
            <!-- Decorative borders -->
            <div class="cert-border"></div>
            <div class="cert-inner-border"></div>

            <!-- Corner ornaments -->
            <div class="cert-corner cert-corner-tl">
                <svg viewBox="0 0 60 60" fill="none"><path d="M5 5 Q30 5 30 30 Q5 30 5 5Z" fill="#b8860b" opacity="0.25"/><path d="M8 8 Q25 8 25 25" stroke="#b8860b" fill="none" stroke-width="1"/></svg>
            </div>
            <div class="cert-corner cert-corner-tr">
                <svg viewBox="0 0 60 60" fill="none"><path d="M5 5 Q30 5 30 30 Q5 30 5 5Z" fill="#b8860b" opacity="0.25"/><path d="M8 8 Q25 8 25 25" stroke="#b8860b" fill="none" stroke-width="1"/></svg>
            </div>
            <div class="cert-corner cert-corner-bl">
                <svg viewBox="0 0 60 60" fill="none"><path d="M5 5 Q30 5 30 30 Q5 30 5 5Z" fill="#b8860b" opacity="0.25"/><path d="M8 8 Q25 8 25 25" stroke="#b8860b" fill="none" stroke-width="1"/></svg>
            </div>
            <div class="cert-corner cert-corner-br">
                <svg viewBox="0 0 60 60" fill="none"><path d="M5 5 Q30 5 30 30 Q5 30 5 5Z" fill="#b8860b" opacity="0.25"/><path d="M8 8 Q25 8 25 25" stroke="#b8860b" fill="none" stroke-width="1"/></svg>
            </div>

            <!-- Certificate Content -->
            <div class="relative z-10 flex flex-col items-center justify-center text-center px-16 py-10 min-h-full">

                <!-- Studio Logo / Name -->
                <div class="mb-2">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo" class="max-h-14 mx-auto object-contain mb-1">
                    <?php else: ?>
                        <span class="text-4xl">&#129352;</span>
                    <?php endif; ?>
                    <p class="text-sm tracking-widest uppercase gold-text font-semibold"><?php echo htmlspecialchars($siteName); ?></p>
                </div>

                <!-- Title -->
                <h1 class="text-3xl font-bold tracking-wider uppercase gold-text mb-1" style="letter-spacing: 0.2em;">Certificate of Achievement</h1>
                <div class="w-48 h-px bg-gradient-to-r from-transparent via-yellow-700 to-transparent mb-4"></div>

                <p class="text-gray-500 text-sm mb-3">This is to certify that</p>

                <!-- Student Name -->
                <?php if ($certSettings['name_visible']): ?>
                <h2 class="cert-name font-bold mb-1" style="font-size: <?php echo $certSettings['name_size']; ?>px; color: <?php echo $certSettings['name_color']; ?>; font-style: italic; text-shadow: <?php echo certStrokeCSS($certSettings['name_stroke'], $certSettings['name_stroke_color']); ?>;"><?php echo htmlspecialchars($studentName); ?></h2>
                <div class="w-64 h-px bg-gray-300 mb-4"></div>
                <?php else: ?>
                <div class="mb-4"></div>
                <?php endif; ?>

                <p class="text-gray-600 text-sm mb-4">has been awarded the rank of</p>

                <!-- Belt Rank Display -->
                <?php if ($certSettings['rank_visible']): ?>
                <div class="flex items-center gap-4 mb-2">
                    <!-- Belt color swatch -->
                    <div class="w-16 h-16 rounded-full shadow-lg flex items-center justify-center text-lg font-bold"
                         style="<?php echo $beltBgStyle; ?> color: <?php echo $textOnBelt; ?>; border: 2px solid <?php echo $isBlackBelt ? '#b8860b' : '#00000030'; ?>;">
                        <?php echo $isBlackBelt ? '&#9733;' : '&#129352;'; ?>
                    </div>
                    <div>
                        <h3 class="font-bold cert-name" style="font-size: <?php echo $certSettings['rank_size']; ?>px; color: <?php echo $certSettings['rank_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['rank_stroke'], $certSettings['rank_stroke_color']); ?>;"><?php echo htmlspecialchars($selectedBelt['belt_name']); ?></h3>
                        <?php if ($certSettings['style_visible']): ?>
                        <p class="cert-name" style="font-size: <?php echo $certSettings['style_size']; ?>px; color: <?php echo $certSettings['style_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['style_stroke'], $certSettings['style_stroke_color']); ?>;"><?php echo htmlspecialchars($selectedBelt['style_name']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="mb-2"></div>
                <?php endif; ?>

                <!-- Black Belt Number -->
                <?php if ($certSettings['bb_num_visible'] && $isBlackBelt && !empty($selectedBelt['black_belt_number'])): ?>
                    <div class="mt-1 mb-3 inline-flex items-center gap-2 bg-gray-900 font-bold px-5 py-2 rounded-full shadow" style="font-size: <?php echo $certSettings['bb_num_size']; ?>px; color: <?php echo $certSettings['bb_num_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['bb_num_stroke'], $certSettings['bb_num_stroke_color']); ?>;">
                        <span>&#127941;</span>
                        <span>Black Belt #<?php echo htmlspecialchars($selectedBelt['black_belt_number']); ?></span>
                        <span>&#127941;</span>
                    </div>
                <?php else: ?>
                    <div class="mb-3"></div>
                <?php endif; ?>

                <!-- Date & Instructor -->
                <div class="flex items-center gap-12 mt-2 mb-6">
                    <?php if ($certSettings['date_visible']): ?>
                    <div class="text-center">
                        <p class="font-semibold cert-name" style="font-size: <?php echo $certSettings['date_size']; ?>px; color: <?php echo $certSettings['date_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['date_stroke'], $certSettings['date_stroke_color']); ?>;"><?php echo date('F j, Y', strtotime($selectedBelt['awarded_date'])); ?></p>
                        <div class="w-32 h-px bg-gray-400 mt-1 mb-1"></div>
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Date Awarded</p>
                    </div>
                    <?php endif; ?>
                    <?php if ($certSettings['instr_visible'] && !empty($selectedBelt['instructor_name'])): ?>
                        <div class="text-center">
                            <p class="font-semibold cert-name" style="font-size: <?php echo $certSettings['instr_size']; ?>px; color: <?php echo $certSettings['instr_color']; ?>; text-shadow: <?php echo certStrokeCSS($certSettings['instr_stroke'], $certSettings['instr_stroke_color']); ?>;"><?php echo htmlspecialchars($selectedBelt['instructor_name']); ?></p>
                            <div class="w-32 h-px bg-gray-400 mt-1 mb-1"></div>
                            <p class="text-xs text-gray-500 uppercase tracking-wider">Instructor</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Seal / Stamp -->
                <div class="mt-2 w-20 h-20 rounded-full border-2 border-dashed gold-text flex items-center justify-center opacity-40">
                    <div class="text-center">
                        <p class="text-xs font-bold gold-text leading-tight">OFFICIAL<br>SEAL</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Belt Achievement History (hidden on print) -->
        <?php if (count($beltAchievements) > 1): ?>
        <div class="no-print container mx-auto px-4 py-8 max-w-4xl">
            <h2 class="text-xl font-bold text-gray-800 mb-4">All Belt Achievements</h2>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Belt</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Style</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">BB #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($beltAchievements as $i => $ba):
                            $baBgStyle = beltBackground($ba['color'], $colorMap);
                            $baIsBlack = str_starts_with(strtolower($ba['color'] ?? ''), 'black');
                        ?>
                            <tr class="hover:bg-gray-50 <?php echo $i === $selectedIndex ? 'bg-blue-50' : ''; ?>">
                                <td class="px-6 py-3 text-sm text-gray-600">#<?php echo $ba['rank_order']; ?></td>
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block w-4 h-4 rounded-full border border-gray-300" style="<?php echo $baBgStyle; ?>"></span>
                                        <span class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($ba['belt_name']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ba['style_name']); ?></td>
                                <td class="px-6 py-3 text-sm text-gray-600"><?php echo formatDate($ba['awarded_date']); ?></td>
                                <td class="px-6 py-3 text-sm">
                                    <?php if ($baIsBlack && !empty($ba['black_belt_number'])): ?>
                                        <span class="inline-flex items-center gap-1 bg-gray-900 text-yellow-400 text-xs font-bold px-2 py-1 rounded-full">
                                            #<?php echo htmlspecialchars($ba['black_belt_number']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <a href="student_certificate.php?<?php echo $isAdminView ? 'student_id=' . $studentId . '&' : ''; ?>belt_index=<?php echo $i; ?>"
                                       class="text-xs text-blue-600 hover:underline">View Certificate</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</body>
</html>
