-- Add password field to students table for self-registration
ALTER TABLE students ADD COLUMN IF NOT EXISTS password VARCHAR(255) AFTER notes;

-- Create role permissions table
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

-- Insert default permissions for admin role (full access)
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
('admin', 'settings.php', 1, 0, 1, 0);

-- Insert default permissions for instructor role
INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
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
('instructor', 'settings.php', 1, 0, 1, 0);

-- Insert default permissions for staff role
INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
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
('staff', 'settings.php', 0, 0, 0, 0);

-- Insert default permissions for student role
INSERT IGNORE INTO role_permissions (role, page, can_view, can_create, can_edit, can_delete) VALUES
('student', 'student_portal.php', 1, 0, 0, 0),
('student', 'student_upgrade.php', 1, 1, 0, 0);

-- Settings table for payment gateway configuration
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default setting: only one payment gateway can be active at a time
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('active_payment_gateway', 'none'),
('stripe_enabled', '0'),
('square_enabled', '0'),
('stripe_publishable_key', ''),
('stripe_secret_key', ''),
('square_application_id', ''),
('square_access_token', '');
