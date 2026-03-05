<?php
/**
 * parent_training_wizard.php — Parent/Family Training Guide
 *
 * Interactive step-by-step wizard that walks parents through every
 * feature of the parent/family portal in logical discovery order.
 * Progress is persisted in localStorage.
 */

require_once 'config.php';
require_once __DIR__ . '/includes/parent_auth.php';

require_parent();

$theme = getActiveTheme();

// Check if this parent also has a student account (student-as-parent)
$isStudentParent = (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'student' && !empty($_SESSION['is_parent']));

include 'includes/parent_header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">

    <!-- Role Banner -->
    <div class="wizard-role-banner wizard-role-parent mb-6">
        <div class="flex items-center gap-3">
            <span class="wizard-role-icon wizard-role-icon-parent">&#128106;</span>
            <div>
                <h2 class="text-lg font-bold text-purple-900">Family Portal Guide</h2>
                <p class="text-sm text-purple-700">This guide covers features available to you as a <strong>parent/guardian</strong> &mdash; managing children, family payments, and events.</p>
            </div>
        </div>
        <?php if ($isStudentParent): ?>
        <div class="mt-3 pt-3 border-t border-purple-200">
            <p class="text-sm text-purple-700">
                &#128161; You also have a <strong>Student Portal</strong> with its own features and guide.
                <a href="student_training_wizard.php" class="inline-flex items-center gap-1 font-semibold text-purple-900 hover:text-purple-700 underline ml-1">
                    Open Student Guide &rarr;
                </a>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Page Title -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Parent Portal Guide</h1>
            <p class="text-sm text-gray-500 mt-1">Learn how to manage your family account and your children's activities &mdash; step by step.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="wizard-reset-btn" onclick="if(confirm('Reset all training progress? This cannot be undone.')) wizard.resetProgress();">Reset Progress</span>
        </div>
    </div>

    <!-- Progress Bar -->
    <div id="wizard-progress" class="wizard-progress-bar"></div>

    <!-- Wizard Layout -->
    <div class="wizard-layout">
        <div id="wizard-sidebar" class="wizard-sidebar wizard-sidebar-parent"></div>
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
var parentSteps = [

    // ── GROUP 1: Getting Started ────────────────────────────
    {
        title: 'Getting Started',
        icon: '\uD83C\uDFE0',
        substeps: [
            {
                title: 'Family Dashboard',
                description: 'Your Family Dashboard is the home page after logging in. It shows summary cards for each of your linked children, upcoming events, and quick action buttons for common tasks.',
                highlights: [
                    'Overview cards for each linked child showing name, belt rank, and membership status',
                    'Quick links on each child card: Overview, Training resources, and Plan details',
                    'Upcoming events across all your children',
                    'Quick action buttons for adding children and managing payments',
                    'At-a-glance view of your entire family\'s enrollment'
                ],
                tips: ['If any child has a payment issue, you\'ll see a warning badge on their card. Visit the Payment page to resolve it.'],
                link: 'parent_portal.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 2: Managing Children ──────────────────────────
    {
        title: 'Managing Children',
        icon: '\uD83D\uDC65',
        substeps: [
            {
                title: 'Link & Manage Children',
                description: 'Add children to your family account by linking their existing student accounts. You can manage multiple children from your single parent login.',
                highlights: [
                    'Link an existing student account using their username or email',
                    'Specify your relationship (parent, guardian, or other)',
                    'Unlink children if they are no longer under your care',
                    'Your payment methods are automatically shared with all linked children',
                    'Each linked child appears as a card on your Family Dashboard'
                ],
                tips: [
                    'Your child must already have a student account at the school before you can link them.',
                    'Ask the school administrator if you need a student account created for a new child.'
                ],
                link: 'parent_portal.php',
                linkTarget: '_self'
            },
            {
                title: 'Child Overview Page',
                description: 'View detailed information about each child including their profile, class attendance, current schedule, belt rank progression, and planned curriculum.',
                highlights: [
                    'Full student profile for the child (name, belt rank, membership)',
                    'Attendance history showing classes attended, missed, and late arrivals',
                    'Absence counts and make-up class credits within the current belt testing cycle',
                    'Current class enrollment and weekly schedule',
                    'View planned curriculum (Focus, Rotation, Technique) for each class day',
                    'Belt rank progression and history',
                    'Use the Children dropdown in the top navigation to quickly switch between children'
                ],
                tips: [
                    'Click a child\'s name on the Family Dashboard, or use the "Children" dropdown in the top navigation bar to jump to any child.',
                    'If your child has too many absences in a testing cycle, contact the school to schedule make-up classes.'
                ],
                link: 'parent_portal.php',
                linkTarget: '_self'
            },
            {
                title: 'Child Training & Belt Resources',
                description: 'Access training materials assigned to each child\'s belt rank. Documents and instructional videos uploaded by instructors are available for your child\'s current rank and all lower ranks.',
                highlights: [
                    'View documents (PDFs, images) for the child\'s current belt level',
                    'Watch video links for technique demonstrations',
                    'Filter content by martial arts style',
                    'Access all content from previous belt ranks as well',
                    'New content appears automatically when instructors upload it'
                ],
                tips: ['Reviewing training materials with your child before belt tests can help them prepare and feel more confident.'],
                link: 'parent_portal.php',
                linkTarget: '_self'
            },
            {
                title: 'Child Membership Management',
                description: 'View and manage each child\'s membership plan, including billing details, renewal dates, requesting plan changes, and enrolling in special programs.',
                highlights: [
                    'See current plan name, price, and renewal dates for each child',
                    'View billing frequency (monthly installments or upfront)',
                    'Request plan upgrades or downgrades on behalf of your child',
                    'Enroll children in afterschool programs, camps, and tax-deductible programs at any time — these bypass the 30-day plan change lockout',
                    'See auto-renewal status for each child\'s membership',
                    'Track pending plan change requests'
                ],
                tips: [
                    'Plan changes may require administrator approval. Check back to see if your request has been processed.',
                    'Afterschool, camp, and tax-deductible programs can be enrolled anytime, even during a lockout period.'
                ],
                link: 'parent_portal.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 3: Events ─────────────────────────────────────
    {
        title: 'Events',
        icon: '\uD83C\uDFAF',
        substeps: [
            {
                title: 'Browse & Register Children for Events',
                description: 'View upcoming school events and register your children. You can register multiple children for the same event and pay registration fees from one account.',
                highlights: [
                    'Browse all upcoming events with dates, times, and descriptions',
                    'See event types: belt tests, tournaments, seminars, workshops, camps',
                    'Select which children to register for each event',
                    'Pay event registration fees online',
                    'View your children\'s existing event registrations',
                    'Check registration deadlines and available spots'
                ],
                tips: ['You can register multiple children for the same event in one session — just select each child during registration.'],
                link: 'parent_events.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 4: Payments ───────────────────────────────────
    {
        title: 'Payment Management',
        icon: '\uD83D\uDCB3',
        substeps: [
            {
                title: 'Payment Methods',
                description: 'Manage payment methods for your family account. Cards saved here are shared across all your linked children for membership renewals and event fees.',
                highlights: [
                    'Add or update your credit/debit card on file',
                    'Your payment methods are shared with all linked children',
                    'If any child has a payment failure, update your card here to fix it',
                    'Secure payment processing through Stripe or Square',
                    'One payment method covers all children\'s memberships and events'
                ],
                tips: ['Keeping a valid payment method on file ensures uninterrupted access for all your children.'],
                link: 'parent_payment.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 5: Transactions ───────────────────────────────
    {
        title: 'Transaction History',
        icon: '\uD83D\uDCC4',
        substeps: [
            {
                title: 'View All Transactions',
                description: 'See a consolidated view of all payment transactions across all your children — membership fees, event payments, refunds, and credits in one place.',
                highlights: [
                    'Transactions for all linked children in one unified list',
                    'Filter by child, date range, or payment type',
                    'See payment amounts, dates, and descriptions',
                    'Track membership renewal payments for each child',
                    'View event registration payment confirmations',
                    'Useful for tax records if plans/events are tax-deductible'
                ],
                link: 'parent_transactions.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 6: Tax Statements ─────────────────────────────
    {
        title: 'Tax Statements',
        icon: '\uD83D\uDCC4',
        substeps: [
            {
                title: 'Annual Tax Statements',
                description: 'Generate a Federal tax-compliant Dependent Care Statement for all your qualifying children. The statement includes all payments for tax-deductible programs (afterschool, camps, eligible memberships and events) and is formatted for use with IRS Form 2441.',
                highlights: [
                    'Provider information with business name, address, and Federal EIN',
                    'Per-child breakdown with dates of service and itemized payments',
                    'Grand total of all tax-eligible expenses across all qualifying children',
                    'IRS Form 2441 and W-10 reference information',
                    'Print-friendly format suitable for tax filing records',
                    'Year selector to view statements for any previous year',
                    'Provider certification section for official records'
                ],
                tips: [
                    'Only plans and events marked as "tax-deductible" by the school appear on tax statements. Contact your school if you believe a program should qualify.',
                    'You can also access a quick summary of tax-eligible payments from the Family Transaction History page.'
                ],
                link: 'parent_tax_statement.php',
                linkTarget: '_self'
            }
        ]
    },

    // ── GROUP 7: Profile ────────────────────────────────────
    {
        title: 'Your Profile',
        icon: '\uD83D\uDC64',
        substeps: [
            {
                title: 'Edit Your Profile',
                description: 'Update your own contact information and change your login password. Your profile is separate from your children\'s profiles.',
                highlights: [
                    'Update your name, email address, and phone number',
                    'Change your login password',
                    'View your account creation date',
                    'Your contact info is used for school communications and billing notifications'
                ],
                tips: ['Keep your email address current — important notifications about payments, events, and schedule changes are sent to this address.'],
                link: 'parent_profile.php',
                linkTarget: '_self'
            }
        ]
    }
];

// Initialize wizard
var wizard = new TrainingWizard({
    storageKey: 'parent_training_progress',
    steps:      parentSteps,
    sidebarEl:  document.getElementById('wizard-sidebar'),
    contentEl:  document.getElementById('wizard-content'),
    progressEl: document.getElementById('wizard-progress')
});
wizard.init();
</script>

<?php include 'includes/student_footer.php'; ?>
