<?php
/**
 * student_training_wizard.php — Student Training Guide
 *
 * Interactive step-by-step wizard that walks students through every
 * feature of the student portal in logical discovery order.
 * Progress is persisted in localStorage.
 */

require_once 'config.php';

// Require student login (same pattern as student_portal.php)
if ((!isset($_SESSION['is_student']) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')) || !isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

// Enforce payment lockout and registration completion
require_student_payment_clear();

$theme = getActiveTheme();

// Check if this student also has parent capabilities
$hasParentRole = !empty($_SESSION['is_parent']);

include 'includes/student_header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">

    <!-- Role Banner -->
    <div class="wizard-role-banner wizard-role-student mb-6">
        <div class="flex items-center gap-3">
            <span class="wizard-role-icon wizard-role-icon-student">&#129351;</span>
            <div>
                <h2 class="text-lg font-bold text-blue-900">Student Portal Guide</h2>
                <p class="text-sm text-blue-700">This guide covers features available to you as a <strong>student</strong> &mdash; classes, training resources, events, and your account.</p>
            </div>
        </div>
        <?php if ($hasParentRole): ?>
        <div class="mt-3 pt-3 border-t border-blue-200">
            <p class="text-sm text-blue-700">
                &#128161; You also have a <strong>Family Portal</strong> for managing your children.
                <a href="parent_training_wizard.php" class="inline-flex items-center gap-1 font-semibold text-blue-900 hover:text-blue-700 underline ml-1">
                    Open Family Guide &rarr;
                </a>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Page Title -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Student Portal Guide</h1>
            <p class="text-sm text-gray-500 mt-1">Learn how to use every feature of your student portal &mdash; step by step.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="wizard-reset-btn" onclick="if(confirm('Reset all training progress? This cannot be undone.')) wizard.resetProgress();">Reset Progress</span>
        </div>
    </div>

    <!-- Progress Bar -->
    <div id="wizard-progress" class="wizard-progress-bar"></div>

    <!-- Wizard Layout -->
    <div class="wizard-layout">
        <div id="wizard-sidebar" class="wizard-sidebar wizard-sidebar-student"></div>
        <div id="wizard-content" class="wizard-main"></div>
    </div>
</div>

<link rel="stylesheet" href="assets/css/training-wizard.css">
<style>
:root {
    --wizard-primary: <?php echo htmlspecialchars($theme['primary']); ?>;
}
</style>

<script src="assets/js/training-wizard.js"></script>
<script>
var studentSteps = [

    // ── GROUP 1: Getting Started ────────────────────────────
    {
        title: 'Getting Started',
        icon: '\uD83C\uDFE0',
        substeps: [
            {
                title: 'Your Dashboard',
                description: 'The Dashboard is your home base after logging in. It shows a quick overview of everything about your account — your profile, membership, upcoming classes, events, and training resources.',
                highlights: [
                    'Profile summary with your name, belt rank, and membership status',
                    'Membership card showing your current plan and expiration date',
                    'Stats card with total classes attended and current belt rank',
                    'Your upcoming class schedule for the week',
                    'Upcoming events you can register for',
                    'Attendance history by class with present/absent/late counts',
                    'Training resources preview for your current belt rank',
                    'Account credit balance (if you have credits)',
                    'Family account info (if your account is linked to a parent or children)'
                ],
                tips: ['If you see a red "Payment Issue" badge anywhere, your account has a billing problem. Visit the Payment page to resolve it and restore full access.'],
                link: 'student_portal.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 2: Profile ────────────────────────────────────
    {
        title: 'Your Profile',
        icon: '\uD83D\uDC64',
        substeps: [
            {
                title: 'Edit Your Profile',
                description: 'Update your personal information including contact details and emergency contacts. You can also change your login password from this page.',
                highlights: [
                    'Update your name, email address, and phone number',
                    'Add or update your physical address',
                    'Set emergency contact information (name and phone number)',
                    'Change your login password',
                    'View your belt rank and join date (these are managed by your instructors)',
                    'See linked parent/family accounts if applicable'
                ],
                tips: ['Keep your email address up to date — it\'s used for important notifications about memberships, events, and school announcements.'],
                link: 'student_profile.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 3: Membership ─────────────────────────────────
    {
        title: 'Membership & Plans',
        icon: '\uD83D\uDCCB',
        substeps: [
            {
                title: 'View Your Membership',
                description: 'See your current membership plan details, billing schedule, and renewal dates. If your school allows it, you can also request plan changes (upgrades or downgrades) and enroll in special programs.',
                highlights: [
                    'Current plan name, price, and billing frequency (monthly or upfront)',
                    'Membership start date and expiration date',
                    'Auto-renewal status — whether your plan renews automatically',
                    'Request a plan upgrade or downgrade (if allowed by your school)',
                    'Enroll in afterschool programs, camps, and tax-deductible programs at any time — these bypass the 30-day plan change lockout',
                    'View history of any previous plan changes',
                    'See pending plan change requests awaiting admin approval'
                ],
                tips: [
                    'Plan changes may require administrator approval before taking effect.',
                    'Upgrades to more expensive plans are prorated based on your remaining time.',
                    'Afterschool, camp, and tax-deductible programs are always available for enrollment, even during a plan change lockout period.'
                ],
                link: 'student_upgrade.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 4: Classes ────────────────────────────────────
    {
        title: 'Class Schedule',
        icon: '\uD83D\uDCC5',
        substeps: [
            {
                title: 'Your Class Schedule',
                description: 'View all the classes you are enrolled in, including day, time, room, and instructor details. Your weekly schedule is also shown on the dashboard. Each class day has a planned curriculum so you know what to expect.',
                highlights: [
                    'See all your enrolled classes with day, time, room, and instructor',
                    'The dashboard shows your upcoming classes for the current week',
                    'View the planned curriculum for each class day — Focus, Rotation, and Technique topics',
                    'Dates past the belt testing cycle end automatically show "Test Prep" content',
                    'Your attendance history per class tracks participation over time',
                    'Absence counts and make-up class credits are tracked within each belt testing cycle',
                    'Attendance stats (present, absent, late, excused) appear on your dashboard'
                ],
                tips: [
                    'Need to change your class enrollment? Contact your school administrator — they manage class rosters.',
                    'If you have too many absences in a testing cycle, you may need to schedule make-up classes to maintain testing eligibility.'
                ],
                link: 'student_portal.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 5: Events ─────────────────────────────────────
    {
        title: 'Events',
        icon: '\uD83C\uDFAF',
        substeps: [
            {
                title: 'Browse & Register for Events',
                description: 'View all upcoming events your school is hosting — belt tests, tournaments, seminars, workshops, and camps. Register and pay for events directly from this page.',
                highlights: [
                    'Browse upcoming events with date, time, location, and description',
                    'See event types: belt tests, tournaments, seminars, workshops, demonstrations, camps',
                    'Register for events (some may have a registration fee)',
                    'View events you\'re already registered for',
                    'Check registration deadlines and remaining available spots',
                    'Pay event fees online (if a payment gateway is configured)'
                ],
                tips: ['Some events are calendar-only (no registration needed) — they\'ll show on the calendar but won\'t have a registration button.'],
                link: 'student_events.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 6: Training Resources ─────────────────────────
    {
        title: 'Training Resources',
        icon: '\uD83E\uDD4B',
        substeps: [
            {
                title: 'Belt Training Materials',
                description: 'Access documents and videos that your instructors have uploaded for each belt rank. You can see materials for your current rank and all ranks below it.',
                highlights: [
                    'Documents (PDFs, images) uploaded by instructors for each belt level',
                    'Video links for technique demonstrations and tutorials',
                    'Filter by martial arts style and specific belt rank',
                    'Content for your current rank AND all previous ranks is always available',
                    'New content added by instructors appears here automatically'
                ],
                tips: ['Check back regularly — instructors may upload new training materials at any time, especially before belt testing.'],
                link: 'student_training.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 7: Payments ───────────────────────────────────
    {
        title: 'Payments & Transactions',
        icon: '\uD83D\uDCB3',
        substeps: [
            {
                title: 'Payment Methods',
                description: 'Manage your payment methods on file. If your membership payment fails, you\'ll need to update your card here to restore full portal access.',
                highlights: [
                    'Add or update your credit/debit card on file',
                    'View your currently saved payment method',
                    'If your account is locked due to a failed payment, this is where you fix it',
                    'Payment methods may be shared with a linked parent account',
                    'Secure payment processing through Stripe or Square'
                ],
                tips: [
                    'If you see a red "Payment Issue" badge, your portal access is restricted until you update your payment method.',
                    'The Payment page is always accessible, even during a payment lockout.'
                ],
                link: 'student_payment.php',
                linkTarget: '_self'
            },
            {
                title: 'Transaction History',
                description: 'View a complete history of all your payments including membership fees, event registration payments, and any refunds or credits.',
                highlights: [
                    'See all past transactions with date, amount, type, and description',
                    'Filter transactions by date range',
                    'Track membership renewal payments over time',
                    'View event registration payment confirmations',
                    'See any credits or refunds applied to your account'
                ],
                link: 'student_transactions.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 8: Messages & Conversations ───────────────────
    {
        title: 'Messages & Conversations',
        icon: '\uD83D\uDCAC',
        substeps: [
            {
                title: 'Announcements Inbox',
                description: 'Read broadcast messages sent to you by your instructors and school administration. Messages appear as in-app notifications and the unread count shows in the navigation bar.',
                highlights: [
                    'View all broadcast messages from your school in your inbox',
                    'Unread message count appears as a red badge in the navigation',
                    'Mark messages as read after viewing',
                    'Messages cover announcements, schedule changes, belt testing info, and personal notes',
                    'Messages may also be sent to you via email or SMS (depending on school settings)'
                ],
                link: 'student_messages.php',
                linkTarget: '_self'
            },
            {
                title: 'Direct Conversations',
                description: 'Send and receive direct messages with your instructors and other students (if allowed by your school). Conversations are private threads between you and another person.',
                highlights: [
                    'Start a new conversation with instructors or fellow students',
                    'View your conversation history with each contact',
                    'Unread conversation count shows in the navigation bar',
                    'Messages are checked against a content moderation filter for safety',
                    'Block or unblock other students if needed',
                    'Hide conversations you no longer need — they can be restored later'
                ],
                tips: ['Content moderation is active — messages containing inappropriate language may be flagged for instructor review.'],
                link: 'student_conversations.php',
                linkTarget: '_self'
            }
        ]
    }
];

// Initialize wizard
var wizard = new TrainingWizard({
    storageKey: 'student_training_progress',
    steps:      studentSteps,
    sidebarEl:  document.getElementById('wizard-sidebar'),
    contentEl:  document.getElementById('wizard-content'),
    progressEl: document.getElementById('wizard-progress')
});
wizard.init();
</script>

<?php include 'includes/student_footer.php'; ?>
