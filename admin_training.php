<?php
/**
 * admin_training.php — Admin Training Guide
 *
 * Interactive step-by-step wizard that walks administrators through
 * every feature and configuration option in logical setup order.
 * Progress is persisted in localStorage so admins can resume any time.
 */

require_once 'config.php';
requireLogin();

$theme = getActiveTheme();

include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-7xl">
    <!-- Page Title -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Admin Training Guide</h1>
            <p class="text-sm text-gray-500 mt-1">Learn how to configure and manage every feature of your martial arts school — step by step.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="wizard-reset-btn" onclick="if(confirm('Reset all training progress? This cannot be undone.')) wizard.resetProgress();">Reset Progress</span>
        </div>
    </div>

    <!-- Progress Bar -->
    <div id="wizard-progress" class="wizard-progress-bar"></div>

    <!-- Wizard Layout -->
    <div class="wizard-layout">
        <div id="wizard-sidebar" class="wizard-sidebar"></div>
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
var adminSteps = [

    // ── GROUP 1: Initial Setup ──────────────────────────────
    {
        title: 'Initial Setup',
        icon: '\u2699\uFE0F',
        substeps: [
            {
                title: 'General Settings',
                description: 'Configure your school\'s basic information including the site name, timezone, and password policies. These settings form the foundation that all other features build upon.',
                highlights: [
                    'Set the school/studio name displayed throughout the application',
                    'Configure timezone for accurate scheduling and billing',
                    'Set password policies for student and admin accounts',
                    'Change your admin password'
                ],
                tips: ['Configure General settings first — the school name appears on every page, in emails, and on student portals.'],
                link: 'settings.php#general'
            },
            {
                title: 'School Schedule & Hours',
                description: 'Define your school\'s hours of operation and schedule slot intervals. These settings control how the calendar and class scheduling work.',
                highlights: [
                    'Set daily operating hours (supports different hours per day)',
                    'Configure schedule slot intervals (15, 30, or 60 minutes)',
                    'Define which days of the week your school is open',
                    'These hours appear on the calendar and constrain class scheduling'
                ],
                tips: ['Set your hours of operation before creating classes, as class times must fall within these hours.'],
                link: 'settings.php#school-schedule'
            },
            {
                title: 'Studio Branding',
                description: 'Customize your school\'s visual identity including logo, colors, and theme. This branding appears throughout the admin panel, student portal, and parent portal.',
                highlights: [
                    'Upload your studio logo (shown in headers and certificates)',
                    'Choose from preset color themes: Ocean Blue, Crimson Dojo, Forest, Purple, Sunset, Slate',
                    'Set custom primary, secondary, and accent colors',
                    'Preview changes in real-time before saving'
                ],
                tips: ['Your branding colors are inherited by the student and parent portals, giving a consistent look across the entire system.'],
                link: 'admin_dashboard.php'
            }
        ]
    },

    // ── GROUP 2: Belt System ────────────────────────────────
    {
        title: 'Belt System',
        icon: '\uD83E\uDD4B',
        substeps: [
            {
                title: 'Configure Belt Ranks',
                description: 'Set up your martial arts styles and belt ranking hierarchy. The belt system is central to student progression tracking, attendance eligibility warnings, and training resources.',
                highlights: [
                    'View pre-loaded martial arts styles (Karate, Jiu-Jitsu, Taekwondo, Kung Fu, MMA)',
                    'Add custom styles if your school teaches a different art',
                    'Define belt ranks with colors and display order within each style',
                    'Upload training resources (documents, videos) organized by belt rank',
                    'Resources uploaded here appear automatically in the student portal'
                ],
                tips: [
                    'Set up belt ranks before adding students, so you can assign the correct initial belt level during student creation.',
                    'Training resources can be uploaded in bulk from the Belt Resources Upload page.'
                ],
                link: 'belts.php'
            },
            {
                title: 'Belt Testing Settings',
                description: 'Configure belt testing cycle windows, absence thresholds for eligibility warnings, and payment failure notification preferences.',
                highlights: [
                    'Set belt testing cycle start and end dates for the current testing period',
                    'Configure the absence warning threshold — students who miss too many classes get flagged',
                    'Enable or disable automatic email notifications for payment failures',
                    'Testing cycle dates help generate eligibility reports'
                ],
                tips: ['The absence threshold works with attendance tracking to automatically warn students who may not be eligible for belt testing.'],
                link: 'settings.php#belt-testing'
            },
            {
                title: 'Certificates',
                description: 'Design and configure belt promotion certificates that can be generated when students advance in rank.',
                highlights: [
                    'Customize certificate layout, text, and formatting',
                    'Include school logo and instructor signatures',
                    'Preview certificate designs before issuing',
                    'Certificates can be generated from the Belt System page after awarding a new rank'
                ],
                link: 'settings.php#certificates'
            }
        ]
    },

    // ── GROUP 3: Membership Plans ───────────────────────────
    {
        title: 'Membership Plans',
        icon: '\uD83D\uDCCB',
        substeps: [
            {
                title: 'Create Membership Plans',
                description: 'Define the membership packages your school offers. Plans control billing amounts, durations, and auto-renewal behavior. Every student needs an active membership plan.',
                highlights: [
                    'Create plans with a name, monthly or total price, and duration',
                    'Choose billing frequency: pay upfront (full amount) or monthly installments',
                    'Enable auto-renewal so memberships renew automatically when they expire',
                    'Mark plans as tax-deductible (useful for camps and afterschool programs)',
                    'Create Afterschool Programs with fixed program start/end dates and no auto-renewal',
                    'Create Camp Programs (fixed-term, like afterschool) — afterschool and camp are mutually exclusive',
                    'Hold, cancel, resume, or reactivate memberships directly from plan cards',
                    'Six default plans are pre-loaded — you can edit or add more'
                ],
                tips: [
                    'Create at least one membership plan before enrolling students — students need a plan assignment for billing to work.',
                    'Auto-renewal requires a payment gateway (Stripe or Square) to be configured.',
                    'Afterschool and camp plans bypass the 30-day plan change lockout — students can enroll anytime.'
                ],
                link: 'memberships.php'
            },
            {
                title: 'Discount Codes',
                description: 'Create promotional discount codes that can be applied during online registration or when administrators process payments.',
                highlights: [
                    'Set percentage-based or fixed-amount discounts',
                    'Control usage limits (e.g., first 50 uses only)',
                    'Set expiration dates for time-limited promotions',
                    'Track how many times each code has been used'
                ],
                tips: ['Discount codes can be shared on social media or in marketing emails to drive registrations.'],
                link: 'discount_codes.php'
            },
            {
                title: 'Billing & Plan Change Settings',
                description: 'Configure advanced membership behavior like registration fees, service fees, plan change policies, and payment lockout behavior.',
                highlights: [
                    'Set a one-time registration fee for new students',
                    'Configure service fee percentage added to payments',
                    'Allow or restrict student-initiated plan changes (upgrades/downgrades)',
                    'Configure payment lockout — lock student portal access when payments are overdue',
                    'Test lockout mode lets you preview what a locked-out student sees'
                ],
                tips: ['Payment lockout is a powerful tool for collecting overdue payments — students can only access the payment page until they update their card.'],
                link: 'settings.php#billing'
            }
        ]
    },

    // ── GROUP 4: Class Setup ────────────────────────────────
    {
        title: 'Class Setup',
        icon: '\uD83E\uDD4B',
        substeps: [
            {
                title: 'Create Rooms',
                description: 'Define the physical training spaces in your facility. Rooms help organize classes and prevent scheduling conflicts.',
                highlights: [
                    'Add rooms with names (e.g., Main Dojo, Training Room B)',
                    'Set sort order to control how rooms appear in views',
                    'Toggle rooms active/inactive as your facility changes',
                    'Rooms are used in the kanban board view to visually organize classes'
                ],
                tips: ['If your school has only one training area, you can skip room setup — a default room is used automatically.'],
                link: 'classes.php'
            },
            {
                title: 'Create Classes',
                description: 'Set up your recurring class schedule. Classes are the core building block — students enroll in them, attendance is tracked per class, and the schedule appears in calendars and portals.',
                highlights: [
                    'Define class name, day of week, start time, and duration',
                    'Assign an instructor and room to each class',
                    'Set skill level requirements (e.g., beginner, advanced, all levels)',
                    'Set maximum capacity per class',
                    'Toggle between list view and kanban board view',
                    'Manage which students are enrolled in each class'
                ],
                tips: [
                    'The kanban board view (toggle button in the top-right) groups classes by room — great for spotting schedule gaps.',
                    'Your view preference (list vs. kanban) is saved automatically.'
                ],
                link: 'classes.php'
            },
            {
                title: 'Calendar View',
                description: 'Review your complete weekly schedule on the calendar. This view combines all classes and events in a visual weekly layout.',
                highlights: [
                    'Visual weekly calendar showing all classes and events',
                    'Color-coded blocks by class type or room',
                    'Respects your configured hours of operation',
                    'Events also appear on the calendar alongside classes'
                ],
                link: 'calendar.php'
            }
        ]
    },

    // ── GROUP 5: Student Management ─────────────────────────
    {
        title: 'Student Management',
        icon: '\uD83D\uDC65',
        substeps: [
            {
                title: 'Add & Manage Students',
                description: 'Create student accounts, manage their profiles, assign memberships and belt ranks, and track their progress. Students are the heart of the system.',
                highlights: [
                    'Add students with full profile: name, email, date of birth, phone, address, medical info, school district',
                    'Assign a membership plan and set billing start date',
                    'Assign current belt rank from your configured belt system',
                    'Each student gets login credentials (username + password) for the Student Portal',
                    'View individual student detail pages with attendance, membership history, and belt timeline',
                    'Filter and search student lists by name, status, or plan',
                    'Mark students as active, inactive, or suspended',
                    'Deactivate students manually or automatically for payment issues — deactivation cascades to cancel memberships, remove class enrollments, and restrict portal access',
                    'Manage account credits (positive balance) or past due amounts (negative balance)',
                    'Hold, cancel, resume, or reactivate individual memberships from the student detail page'
                ],
                tips: [
                    'Students can also self-register through the online registration form (configured in Settings > Registration).',
                    'You can bulk import students from a CSV file using the Import/Export page.',
                    'Deactivating a student cascades — their memberships are cancelled and class enrollments are removed automatically.'
                ],
                link: 'students.php'
            },
            {
                title: 'Registration Settings',
                description: 'Configure the self-service online registration form that prospective students use to sign up.',
                highlights: [
                    'Choose which fields are required (email, phone, DOB, etc.)',
                    'Configure liability waiver text and version tracking',
                    'Enable or disable online registration',
                    'Registration fee is charged during signup (configured in Billing settings)',
                    'Imported students can be flagged for forced password change on first login'
                ],
                tips: ['Share the registration link on your website and social media to accept new student signups 24/7.'],
                link: 'settings.php#registration'
            },
            {
                title: 'Pending Registrations',
                description: 'Review and approve students who have completed the online registration form. A badge count in the sidebar shows when registrations are waiting.',
                highlights: [
                    'See all pending student registrations with their submitted details',
                    'View payment status (paid/pending) for each registration',
                    'Approve registrations to create full student accounts',
                    'Reject registrations with an optional reason',
                    'Badge count appears in the sidebar navigation when registrations are pending'
                ],
                link: 'pending_registrations.php'
            }
        ]
    },

    // ── GROUP 6: Parent Accounts ────────────────────────────
    {
        title: 'Parent Accounts',
        icon: '\uD83D\uDC68\u200D\uD83D\uDC69\u200D\uD83D\uDC67\u200D\uD83D\uDC66',
        substeps: [
            {
                title: 'Manage Parent Accounts',
                description: 'View and manage family/parent accounts that link to one or more student accounts. Parents can manage multiple children from a single login.',
                highlights: [
                    'View all parent accounts and their linked children',
                    'Parents share payment methods across all linked children',
                    'Parents can register children for events and manage memberships',
                    'Two account models: student promoted to parent (is_parent flag) or standalone parent accounts',
                    'Parents log in through the same login page as students'
                ],
                tips: [
                    'A student account can be "promoted" to a parent account — useful for adult students who also have children enrolled.',
                    'Parents see a Family Dashboard with cards for each child.'
                ],
                link: 'parent_accounts.php'
            }
        ]
    },

    // ── GROUP 7: Events ─────────────────────────────────────
    {
        title: 'Events',
        icon: '\uD83C\uDFAF',
        substeps: [
            {
                title: 'Create & Manage Events',
                description: 'Create special events like belt tests, tournaments, seminars, workshops, camps, and demonstrations. Events can have registration fees, capacity limits, and deadlines.',
                highlights: [
                    'Multiple event types: belt test, tournament, seminar, workshop, demonstration, camp',
                    'Set registration fees (or make events free)',
                    'Set registration deadlines and maximum capacity',
                    'Mark events as tax-deductible for camps and educational programs',
                    'Choose whether events require registration or are calendar-only',
                    'Events appear on the calendar alongside classes'
                ],
                tips: ['Events with registration fees can be paid online through the student/parent portal if a payment gateway is configured.'],
                link: 'events.php'
            },
            {
                title: 'Event Participants & Registration',
                description: 'View individual event pages with full registration lists, payment status for each participant, and manual registration tools.',
                highlights: [
                    'See everyone registered for an event with their payment status',
                    'Manually register students who signed up in person',
                    'Track paid vs. pending payments per event',
                    'Click any event name on the events list to open its detail page'
                ],
                link: 'events.php'
            }
        ]
    },

    // ── GROUP 8: Attendance & Tracking ──────────────────────
    {
        title: 'Attendance & Tracking',
        icon: '\u2705',
        substeps: [
            {
                title: 'Take Attendance',
                description: 'Record daily attendance for each class. The attendance page displays the planned curriculum for each class/date and powers belt testing eligibility warnings, student stats, and reporting.',
                highlights: [
                    'Select a class and date to view the enrolled student roster',
                    'Mark each student as present, absent, late, or excused',
                    'See the planned curriculum (Focus, Rotation, Technique) for the selected class and date',
                    'Dates past the belt testing cycle end automatically show "Test Prep" curriculum',
                    'Admin override: enroll students directly from attendance without a membership (temporary until they register)',
                    'View attendance history filtered by date range',
                    'Absence counts are automatically checked against belt testing thresholds',
                    'Students can see their own attendance history in the portal'
                ],
                tips: [
                    'Take attendance regularly — it feeds into belt testing eligibility reports and helps track student engagement.',
                    'If no curriculum is planned for a date, you\'ll see a link to set it up in the Curriculum Management page.'
                ],
                link: 'attendance.php'
            },
            {
                title: 'Make-Up Classes',
                description: 'Manage make-up class sessions for students who missed their regular class. Track which students have make-up credits available.',
                highlights: [
                    'Schedule make-up class opportunities at alternate times',
                    'Track which students have earned make-up credits',
                    'Link make-up attendance back to the original missed class',
                    'Keep accurate records for belt testing eligibility'
                ],
                link: 'makeup_classes.php'
            }
        ]
    },

    // ── GROUP 9: Curriculum Management ─────────────────────
    {
        title: 'Curriculum Management',
        icon: '\uD83D\uDCD6',
        substeps: [
            {
                title: 'Manage Curriculum',
                description: 'Create, edit, and manage the curriculum plan for each class within the current belt testing cycle. The Curriculum Management page provides a full calendar grid view with inline editing for quick updates.',
                highlights: [
                    'Summary view shows all classes with curriculum coverage percentages',
                    'Select a class to see a date-by-date calendar grid within the current cycle',
                    'Three content fields per entry: Focus (line1), Rotation (line2), and Technique (line3)',
                    'Click any cell to edit inline — changes save automatically via AJAX',
                    'Add new entries using the Add Entry modal',
                    'Edit or delete individual entries from the Actions column',
                    'Past dates are dimmed, dates past the cycle end show "Test Prep" in orange',
                    'Week numbers are displayed for easy reference'
                ],
                tips: [
                    'You can also import curriculum in bulk from an XLSX spreadsheet using the Import from XLSX tab.',
                    'Set up your belt testing cycle dates in Settings > Belt Testing before managing curriculum — the grid uses those dates.'
                ],
                link: 'curriculum.php'
            },
            {
                title: 'Import Curriculum from XLSX',
                description: 'Bulk import curriculum content from an Excel spreadsheet. The import wizard walks you through uploading, mapping columns, previewing data, and executing the import.',
                highlights: [
                    'Four-step import wizard: Upload, Map Columns, Preview, Execute',
                    'Supports Pattern A/B sheet detection for alternating curriculum weeks',
                    'Map spreadsheet columns to Focus, Rotation, and Technique fields',
                    'Preview all entries before committing the import',
                    'Existing entries for the same class/date are updated (upsert), not duplicated',
                    'Accessible from Curriculum Management page or Import/Export navigation'
                ],
                tips: [
                    'Download the template spreadsheet first to see the expected format.',
                    'If your curriculum alternates weekly, use separate sheets named Pattern A and Pattern B.'
                ],
                link: 'import_curriculum.php'
            },
            {
                title: 'Copy Week & Batch Operations',
                description: 'Use batch operations to efficiently manage curriculum across multiple dates. Copy an entire week of curriculum to another week, clear a date range, or clear all entries for a class.',
                highlights: [
                    'Copy Week: duplicate all entries from a source week to a target week within the same class',
                    'Bulk Delete: remove all entries in a specific date range for a class',
                    'Clear Class: remove all curriculum entries for a class within the current cycle',
                    'All operations are scoped to the selected class and current school'
                ],
                tips: ['Copy Week is great for repeating curriculum patterns — set up one week perfectly, then copy it to the following weeks.'],
                link: 'curriculum.php'
            },
            {
                title: 'Apply to New Cycle (Cycle Rollover)',
                description: 'When a new belt testing cycle begins, carry your existing curriculum forward automatically. This maps your old curriculum onto the new cycle dates by matching week number and day of week.',
                highlights: [
                    'Select old cycle dates and new cycle dates',
                    'The system maps entries by week offset + day of week (e.g., Week 1 Monday stays Week 1 Monday)',
                    'Optionally update the belt testing cycle settings to the new dates',
                    'Preview count of entries that will be applied before executing',
                    'If the new cycle is shorter, extra entries are silently skipped',
                    'Wraps in a database transaction for safety'
                ],
                tips: [
                    'Run Apply to New Cycle at the START of each new testing period to carry your curriculum forward.',
                    'You can then fine-tune individual entries using inline editing after the rollover.'
                ],
                link: 'curriculum.php'
            }
        ]
    },

    // ── GROUP 10: Payments & Billing ─────────────────────────
    {
        title: 'Payments & Billing',
        icon: '\uD83D\uDCB0',
        substeps: [
            {
                title: 'Payment Gateway Setup',
                description: 'Configure your payment processor (Stripe or Square) to accept online payments for memberships, events, and registration fees.',
                highlights: [
                    'Stripe integration: enter your publishable key and secret key',
                    'Square integration: enter your access token, location ID, and application ID',
                    'Only one gateway can be active at a time',
                    'Test/sandbox mode available — verify everything works before going live',
                    'Gateway powers auto-renewals, event payments, and registration fees'
                ],
                tips: [
                    'Configure the payment gateway before enabling auto-renewal on membership plans.',
                    'Start with test mode (Stripe test keys or Square sandbox) to verify everything works safely.'
                ],
                link: 'settings.php#billing'
            },
            {
                title: 'Payment Records',
                description: 'View all payment transactions across your school. Filter, search, and review the complete financial history.',
                highlights: [
                    'Complete payment history with date, student name, amount, method, and status',
                    'Filter by date range, student, payment type, or status',
                    'View both membership and event-related payments',
                    'Cash, credit card, and bank transfer payments are all tracked'
                ],
                link: 'payments.php'
            },
            {
                title: 'Pending Payments',
                description: 'Review and process event registration payments that have not yet been collected. A badge count shows in the sidebar.',
                highlights: [
                    'Badge count in sidebar shows pending payment count',
                    'Review event registrations where payment is still outstanding',
                    'Mark payments as collected when received in person',
                    'Send payment reminders to students or parents'
                ],
                link: 'pending_payments.php'
            },
            {
                title: 'Tax Statements',
                description: 'Generate annual tax statements for families with tax-deductible membership plans or events. Useful for afterschool programs and summer camps.',
                highlights: [
                    'Generate yearly tax summaries per student or family',
                    'Includes all payments for plans and events marked as tax-deductible',
                    'Export-friendly format suitable for printing and record-keeping',
                    'Covers the full calendar year of transactions'
                ],
                tips: ['Only plans and events marked as "tax-deductible" appear on tax statements — configure this when creating plans and events.'],
                link: 'tax_statement.php'
            },
            {
                title: 'Fee & Lockout Settings',
                description: 'Configure registration fees, service fees, and the payment lockout system that restricts portal access for overdue accounts.',
                highlights: [
                    'Set a one-time registration fee charged to new students',
                    'Configure a service fee percentage added to online payments',
                    'Enable payment lockout to restrict student portal access when payment fails',
                    'Test lockout mode lets you preview the locked-out experience without affecting real students',
                    'Locked-out students can only access the Payment page to update their card'
                ],
                link: 'settings.php#billing'
            }
        ]
    },

    // ── GROUP 11: Communications & Advanced ──────────────────
    {
        title: 'Communications & Advanced',
        icon: '\uD83D\uDCAC',
        substeps: [
            {
                title: 'Messaging System',
                description: 'Send messages to students and families via email, SMS, or in-app notifications. The messaging system supports rich audience filtering.',
                highlights: [
                    'Compose messages with a subject, body, and delivery channel',
                    'Three channels: Email (SMTP), SMS (Twilio), and In-App notifications',
                    'Filter recipients by status, membership plan, event registration, or user role',
                    'Preview recipient count before sending',
                    'In-app messages appear in each student\'s portal inbox'
                ],
                tips: ['In-app messaging works without any external service — email and SMS require SMTP and Twilio configuration respectively.'],
                link: 'messages.php'
            },
            {
                title: 'Communication Settings',
                description: 'Configure SMTP email integration and Twilio SMS integration for the messaging system and automated notifications.',
                highlights: [
                    'SMTP server configuration: host, port, username, password, encryption',
                    'Twilio SMS setup: Account SID, Auth Token, and phone number',
                    'Payment failure notification toggle — automatically email students when billing fails',
                    'Test your configuration with a test message'
                ],
                tips: ['You can use Gmail SMTP (smtp.gmail.com, port 587) for small schools, or services like SendGrid/Mailgun for larger volumes.'],
                link: 'settings.php#communications'
            },
            {
                title: 'Reports',
                description: 'Generate comprehensive reports on attendance, revenue, memberships, students, and more. Export to Excel or PDF.',
                highlights: [
                    'Multiple report types across 11 categories',
                    'Filter by date range: today, this week, this month, or custom dates',
                    'Export reports to Excel (XLSX) or PDF format',
                    'Revenue reports, attendance reports, membership analytics, and student activity',
                    'Visual charts and data tables'
                ],
                link: 'reports.php'
            },
            {
                title: 'User Management & Permissions',
                description: 'Create admin, instructor, and staff accounts. Assign users to one or more schools so they appear in the right instructor dropdowns and user lists. Control what each role can access using per-page permission settings.',
                highlights: [
                    'Four user roles: Super Admin, Admin, Instructor, Staff',
                    'Assign users to multiple schools — click the "Schools" button on any user to manage their school assignments',
                    'Users assigned to a school appear in that school\'s instructor dropdowns and user lists',
                    'Per-page access control — choose which pages each role can see',
                    'Super Admins can manage multiple schools and assign users across locations',
                    'Instructors can be assigned to classes at each of their assigned schools',
                    'Staff accounts have limited access for front-desk operations',
                    'School assignment checkboxes are also available when adding a new user'
                ],
                tips: [
                    'Create instructor user accounts so they can be assigned to classes in the scheduling system.',
                    'Use the Permissions page to fine-tune exactly what each role can access.',
                    'If an instructor teaches at multiple locations, assign them to all relevant schools so they appear in each school\'s class scheduling.'
                ],
                link: 'users.php'
            },
            {
                title: 'Import / Export Data',
                description: 'Bulk import students from CSV files with intelligent plan matching, or export your entire database for backup or migration. The import tool handles full student profiles, parent accounts, and billing data.',
                highlights: [
                    'Import students from CSV with full profiles: name, email, phone, DOB, address, belt rank, medical info, school district',
                    'Automatic plan matching — the import tool sums each student\'s monthly program costs and matches them to your closest existing membership plan',
                    'Addon programs (like Warriors Weapons or Competitive Elite) are detected and handled separately from base plans',
                    'Past due balances are imported as negative account credits, and those students are automatically deactivated',
                    'Parent accounts are auto-created from CSV customer name fields and linked to their children',
                    'Preview step shows plan matching results before importing — review matched plans, rate differences, and unmatched students',
                    'Duplicate detection by email or name — merge into existing records without overwriting data',
                    'Export all data (students, payments, attendance, etc.) for backup',
                    'Download CSV templates for the correct import format'
                ],
                tips: [
                    'Migrating from another system? Export your old data as CSV, then use the import tool to load everything in bulk.',
                    'Create your membership plans FIRST, then run the import — the tool matches students to existing plans by monthly cost.',
                    'Review the plan matching preview carefully before executing the import.'
                ],
                link: 'import_data.php'
            },
            {
                title: 'Multi-School Management',
                description: 'For organizations with multiple locations, manage all schools from a single super admin account. Copy programs between schools, assign staff to multiple locations, and maintain per-school data isolation.',
                highlights: [
                    'Create and manage multiple school locations',
                    'Switch between schools using the dropdown in the top header',
                    'View "All Schools" aggregate data across all locations',
                    'Copy Programs Between Schools — duplicate membership plans, classes, events, and rooms from one school to another using the dedicated copy tool',
                    'Assign users (instructors, staff, admins) to multiple schools so they appear at each location',
                    'Each school has its own students, classes, memberships, and settings',
                    'When creating new classes or plans, optionally check "Also create in other schools" to copy them simultaneously',
                    'Per-school data isolation ensures privacy between locations'
                ],
                tips: [
                    'Multi-school features require the Super Admin role. Regular admins see only their assigned school.',
                    'Use the Copy Programs page to quickly set up a new school location with the same plans and classes as an existing one.',
                    'Assign instructors to all the schools they teach at so they appear in each school\'s class scheduling.'
                ],
                link: 'schools.php'
            },
            {
                title: 'System Settings',
                description: 'Advanced system configuration including cron job management for auto-renewals, debug settings, and data maintenance tools.',
                highlights: [
                    'Configure the cron job URL for automated membership renewals',
                    'View system health checks and PHP configuration',
                    'Debug mode toggle for troubleshooting',
                    'Database maintenance utilities',
                    'View regression test results and seed test data'
                ],
                tips: ['Set up a cron job (or scheduled task) to run the renewal URL daily — this processes auto-renewals and expires overdue memberships.'],
                link: 'settings.php#system'
            }
        ]
    }
];

// Initialize wizard
var wizard = new TrainingWizard({
    storageKey: 'admin_training_progress',
    steps:      adminSteps,
    sidebarEl:  document.getElementById('wizard-sidebar'),
    contentEl:  document.getElementById('wizard-content'),
    progressEl: document.getElementById('wizard-progress')
});
wizard.init();
</script>

<?php include 'includes/footer.php'; ?>
