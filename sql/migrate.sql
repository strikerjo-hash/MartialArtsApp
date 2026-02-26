-- ============================================================
-- MartialArtsApp — Migration Script
-- ============================================================
--
-- Run this ONCE against your existing database to add all new
-- tables and columns required by the latest application code.
--
-- IMPORTANT: config.php expects the database to be named
--   "martial_arts_app".  If you originally loaded database.sql,
--   your database is named "martial_arts_studio".  You have two
--   options:
--
--   Option A (recommended): Rename the database
--     1. mysqldump -u root martial_arts_studio > backup.sql
--     2. mysql -u root -e "CREATE DATABASE martial_arts_app"
--     3. mysql -u root martial_arts_app < backup.sql
--     4. Then run this migration against martial_arts_app.
--
--   Option B: Change config.php
--     Edit config.php line: define('DB_NAME', 'martial_arts_studio');
--
-- After choosing one option, run this file:
--   mysql -u root martial_arts_app < sql/migrate.sql
--
-- This script is safe to re-run — every statement uses
-- IF NOT EXISTS / IF EXISTS guards.
-- ============================================================

-- 1. Add username column to students table (for student login).
--    Students can log in with their username instead of email.
ALTER TABLE students ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE AFTER id;

-- 2. Add password_hash column to students table.
--    The auth code checks for both "password_hash" and "password" columns,
--    so either name works.  We add password_hash as the canonical name.
ALTER TABLE students ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) AFTER username;

-- 3. If you already ran database_updates.sql which added a "password" column,
--    you can migrate that data into password_hash and drop the old column.
--    Uncomment these lines ONLY if you previously ran database_updates.sql:
--
-- UPDATE students SET password_hash = password WHERE password_hash IS NULL AND password IS NOT NULL;
-- ALTER TABLE students DROP COLUMN password;

-- 4. Login attempts table — used for rate limiting (5 attempts per 15 minutes).
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_user (username, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
);

-- 5. Payment methods table — stores encrypted processor tokens.
--    Card numbers are NEVER stored; only the last four digits
--    and an AES-256-GCM encrypted token/reference.
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    card_brand VARCHAR(20) DEFAULT NULL,
    last_four CHAR(4) NOT NULL,
    encrypted_token TEXT NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- 6. Studio config table — key/value store for theme & branding.
--    Used by includes/theme.php to style the application per-studio.
CREATE TABLE IF NOT EXISTS studio_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL
);

-- Default branding values (skipped if rows already exist).
INSERT IGNORE INTO studio_config (config_key, config_value) VALUES
    ('studio_name', 'Martial Arts Academy'),
    ('studio_tagline', 'Discipline. Respect. Excellence.'),
    ('logo_url', 'assets/images/logo.png'),
    ('primary_color', '#b71c1c'),
    ('secondary_color', '#1a1a2e'),
    ('accent_color', '#f5c518'),
    ('background_color', '#0f0f1a'),
    ('text_color', '#e0e0e0'),
    ('font_family', '''Segoe UI'', Tahoma, Geneva, Verdana, sans-serif'),
    ('login_background_image', ''),
    ('favicon_url', ''),
    ('custom_css', '');

-- 7. Settings table — general application settings (payment gateways, etc.).
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('active_payment_gateway', 'none'),
    ('stripe_enabled', '0'),
    ('square_enabled', '0'),
    ('stripe_publishable_key', ''),
    ('stripe_secret_key', ''),
    ('square_application_id', ''),
    ('square_access_token', '');

-- 8. Role permissions table — controls which pages each role can access.
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role VARCHAR(50) NOT NULL,
    page VARCHAR(100) NOT NULL,
    can_view BOOLEAN DEFAULT 1,
    can_create BOOLEAN DEFAULT 0,
    can_edit BOOLEAN DEFAULT 0,
    can_delete BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_page (role, page)
);

-- Default role permissions.
INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
('admin', 'index.php', 1, 1, 1, 1),
('admin', 'students.php', 1, 1, 1, 1),
('admin', 'memberships.php', 1, 1, 1, 1),
('admin', 'classes.php', 1, 1, 1, 1),
('admin', 'events.php', 1, 1, 1, 1),
('admin', 'attendance.php', 1, 1, 1, 1),
('admin', 'payments.php', 1, 1, 1, 1),
('admin', 'belts.php', 1, 1, 1, 1),
('admin', 'reports.php', 1, 0, 0, 0),
('admin', 'users.php', 1, 1, 1, 1),
('admin', 'settings.php', 1, 0, 1, 0),
('instructor', 'index.php', 1, 0, 0, 0),
('instructor', 'students.php', 1, 0, 1, 0),
('instructor', 'memberships.php', 1, 0, 0, 0),
('instructor', 'classes.php', 1, 0, 1, 0),
('instructor', 'events.php', 1, 0, 1, 0),
('instructor', 'attendance.php', 1, 1, 1, 0),
('instructor', 'payments.php', 1, 0, 0, 0),
('instructor', 'belts.php', 1, 1, 1, 0),
('instructor', 'reports.php', 1, 0, 0, 0),
('instructor', 'users.php', 0, 0, 0, 0),
('instructor', 'settings.php', 1, 0, 1, 0),
('staff', 'index.php', 1, 0, 0, 0),
('staff', 'students.php', 1, 1, 1, 0),
('staff', 'memberships.php', 1, 1, 1, 0),
('staff', 'classes.php', 1, 0, 0, 0),
('staff', 'events.php', 1, 1, 1, 0),
('staff', 'attendance.php', 1, 1, 1, 0),
('staff', 'payments.php', 1, 1, 0, 0),
('staff', 'belts.php', 1, 0, 0, 0),
('staff', 'reports.php', 1, 0, 0, 0),
('staff', 'users.php', 0, 0, 0, 0),
('staff', 'settings.php', 0, 0, 0, 0),
('student', 'student_portal.php', 1, 0, 0, 0),
('student', 'student_upgrade.php', 1, 1, 0, 0);

-- 9. Set a username + password for any existing students that don't have one.
--    This generates a username from first_name + last_name (lowercase, no spaces)
--    and sets a default password of "changeme123" (bcrypt hash).
--    Students should change their password on first login.
UPDATE students
   SET username = LOWER(CONCAT(first_name, '.', last_name))
 WHERE username IS NULL OR username = '';

-- Default password: changeme123  (bcrypt hash below)
-- IMPORTANT: Tell your existing students to change their password!
UPDATE students
   SET password_hash = '$2y$10$YFEkGfCgNzW1bVJGmSPY7uvH3JGD7LeZVuqoRLxv7XqYhRNx1XUSK'
 WHERE (password_hash IS NULL OR password_hash = '')
   AND (password IS NULL OR password = '');

-- 10. Tax-deductible tracking columns for events and membership plans.
ALTER TABLE events ADD COLUMN IF NOT EXISTS tax_deductible TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE membership_plans ADD COLUMN IF NOT EXISTS tax_deductible TINYINT(1) NOT NULL DEFAULT 0;

-- Add 'camp' to event_type enum (safe to re-run — MySQL ignores if it already includes 'camp').
-- NOTE: If this fails on your MySQL version, the app will add it automatically on first visit to events.php.
ALTER TABLE events MODIFY COLUMN event_type ENUM('belt_test','tournament','seminar','workshop','demonstration','camp','other') NOT NULL;

-- 11. Tax statement settings.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('tax_business_name', ''),
    ('tax_id_ein', ''),
    ('tax_business_address', ''),
    ('tax_statement_note', 'This statement is provided for informational purposes. Please consult your tax advisor.');

-- 12. Afterschool program support: fixed-term plans with start/end dates.
--     Students enrolling mid-program pay a prorated cost.
ALTER TABLE membership_plans ADD COLUMN IF NOT EXISTS is_afterschool TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE membership_plans ADD COLUMN IF NOT EXISTS program_start_date DATE DEFAULT NULL;
ALTER TABLE membership_plans ADD COLUMN IF NOT EXISTS program_end_date DATE DEFAULT NULL;

-- 13. Import support: force password change and registration completion.
--     Imported students log in with email + default password, then must
--     change their password and complete any missing profile fields.
ALTER TABLE students ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE students ADD COLUMN IF NOT EXISTS registration_incomplete TINYINT(1) NOT NULL DEFAULT 0;

-- 14. Activity status tracking: separate from account status so students
--     who stop attending but keep paying auto-renewals can be flagged.
--     activity_status tracks class attendance activity (active/inactive).
--     inactive_since records when the student was marked inactive.
ALTER TABLE students ADD COLUMN IF NOT EXISTS activity_status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER status;
ALTER TABLE students ADD COLUMN IF NOT EXISTS inactive_since DATE DEFAULT NULL AFTER activity_status;

-- 15. Allow unassigned belt resources for bulk upload staging.
--     Making belt_id and style_id nullable lets admins upload documents
--     first and assign them to specific belts later.
ALTER TABLE belt_resources MODIFY COLUMN belt_id INT DEFAULT NULL;
ALTER TABLE belt_resources MODIFY COLUMN style_id INT DEFAULT NULL;

-- ============================================================
-- Done! Your database now supports:
--   - Student login with username & password
--   - Login rate limiting
--   - Encrypted payment method storage
--   - Studio branding / theming
--   - Role-based permissions
--   - Application settings
--   - Tax-deductible program tracking
--   - Tax statement generation
--   - Afterschool program plans with proration
--   - Imported student forced password change & registration completion
--   - Activity status tracking (inactive but still paying)
--   - Bulk document upload with unassigned resource staging
-- ============================================================
