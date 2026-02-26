-- Martial Arts Studio Management System Database Schema
-- Multi-Tenancy Edition — every data table is scoped by school_id.

CREATE DATABASE IF NOT EXISTS martial_arts_studio;
USE martial_arts_studio;

-- ────────────────────────────────────────────────────────────
-- Schools (multi-tenancy root)
-- ────────────────────────────────────────────────────────────
CREATE TABLE schools (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    address TEXT DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    timezone VARCHAR(50) DEFAULT 'America/New_York',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO schools (id, name, slug) VALUES (1, 'Default School', 'default');

-- ────────────────────────────────────────────────────────────
-- Users / Admin table
-- ────────────────────────────────────────────────────────────
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('super_admin', 'admin', 'instructor', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_school (school_id),
    UNIQUE KEY idx_users_username_school (username, school_id),
    UNIQUE KEY idx_users_email_school (email, school_id)
);

-- ────────────────────────────────────────────────────────────
-- Students / Members table
-- ────────────────────────────────────────────────────────────
CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100),
    username VARCHAR(50) DEFAULT NULL,
    password VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20),
    date_of_birth DATE,
    address TEXT,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    join_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    activity_status ENUM('active', 'inactive') DEFAULT 'active',
    inactive_since DATE DEFAULT NULL,
    photo VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_students_school (school_id),
    UNIQUE KEY idx_students_email_school (email, school_id),
    UNIQUE KEY idx_students_username_school (username, school_id)
);

-- ────────────────────────────────────────────────────────────
-- Parents table
-- ────────────────────────────────────────────────────────────
CREATE TABLE parents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parents_school (school_id),
    UNIQUE KEY idx_parents_username_school (username, school_id)
);

-- Parent-Student linkage
CREATE TABLE parent_students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ps_school (school_id),
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Parent payment methods
CREATE TABLE parent_payment_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    parent_id INT NOT NULL,
    stripe_payment_method_id VARCHAR(255),
    card_brand VARCHAR(20),
    card_last4 VARCHAR(4),
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ppm_school (school_id),
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Martial Arts Styles (GLOBAL — no school_id)
-- ────────────────────────────────────────────────────────────
CREATE TABLE martial_arts_styles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Belt / Rank System (GLOBAL — no school_id)
CREATE TABLE belts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    style_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(30),
    rank_order INT NOT NULL,
    requirements TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE
);

-- Belt resources (GLOBAL — no school_id)
-- belt_id/style_id are nullable to support unassigned (bulk-uploaded) resources
CREATE TABLE belt_resources (
    id INT PRIMARY KEY AUTO_INCREMENT,
    belt_id INT DEFAULT NULL,
    style_id INT DEFAULT NULL,
    resource_type ENUM('document', 'video') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(500) DEFAULT NULL,
    video_url VARCHAR(500) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (belt_id) REFERENCES belts(id) ON DELETE CASCADE,
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Student Belt Progress
-- ────────────────────────────────────────────────────────────
CREATE TABLE student_belts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    belt_id INT NOT NULL,
    style_id INT NOT NULL,
    awarded_date DATE NOT NULL,
    instructor_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sb_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (belt_id) REFERENCES belts(id) ON DELETE CASCADE,
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ────────────────────────────────────────────────────────────
-- Membership Plans
-- ────────────────────────────────────────────────────────────
CREATE TABLE membership_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    duration_months INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    classes_per_week INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mp_school (school_id)
);

-- Student Memberships
CREATE TABLE memberships (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    plan_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
    payment_status ENUM('paid', 'pending', 'partial') DEFAULT 'pending',
    amount_paid DECIMAL(10, 2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_m_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Rooms
-- ────────────────────────────────────────────────────────────
CREATE TABLE rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    capacity INT DEFAULT 20,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_school (school_id)
);

-- ────────────────────────────────────────────────────────────
-- Classes / Training Sessions
-- ────────────────────────────────────────────────────────────
CREATE TABLE classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    style_id INT NOT NULL,
    instructor_id INT,
    room_id INT DEFAULT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_students INT DEFAULT 20,
    skill_level ENUM('beginner', 'intermediate', 'advanced', 'all', 'black_belt', 'ninja', 'beginner_warrior', 'intermediate_warrior', 'advanced_warrior') DEFAULT 'all',
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_classes_school (school_id),
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Student Class Enrollments
CREATE TABLE class_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('active', 'dropped') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ce_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment_school (student_id, class_id, school_id)
);

-- ────────────────────────────────────────────────────────────
-- Attendance Tracking
-- ────────────────────────────────────────────────────────────
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time TIME,
    check_out_time TIME,
    status ENUM('present', 'absent', 'late', 'excused') DEFAULT 'present',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_att_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Events (Belt Tests, Tournaments, Seminars, etc.)
-- ────────────────────────────────────────────────────────────
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    event_type ENUM('belt_test', 'tournament', 'seminar', 'workshop', 'demonstration', 'other') NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    location VARCHAR(255),
    max_participants INT,
    registration_fee DECIMAL(10, 2) DEFAULT 0,
    registration_deadline DATE,
    instructor_id INT,
    status ENUM('upcoming', 'ongoing', 'completed', 'cancelled') DEFAULT 'upcoming',
    requirements TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_events_school (school_id),
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Event Registrations
CREATE TABLE event_registrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    event_id INT NOT NULL,
    student_id INT NOT NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_status ENUM('paid', 'pending', 'waived') DEFAULT 'pending',
    amount_paid DECIMAL(10, 2) DEFAULT 0,
    attendance_status ENUM('registered', 'attended', 'no_show', 'cancelled') DEFAULT 'registered',
    notes TEXT,
    result TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_er_school (school_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_registration_school (event_id, student_id, school_id)
);

-- ────────────────────────────────────────────────────────────
-- Payment Records
-- ────────────────────────────────────────────────────────────
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    payment_type ENUM('membership', 'event', 'merchandise', 'other') NOT NULL,
    reference_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'credit_card', 'debit_card', 'bank_transfer', 'other') NOT NULL,
    payment_date DATE NOT NULL,
    receipt_number VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Training Logs
-- ────────────────────────────────────────────────────────────
CREATE TABLE training_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    log_date DATE NOT NULL,
    duration_minutes INT,
    techniques_practiced TEXT,
    instructor_notes TEXT,
    student_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tl_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Messages
-- ────────────────────────────────────────────────────────────
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    sender_id INT NOT NULL,
    sender_type ENUM('admin', 'instructor', 'staff', 'student', 'parent') NOT NULL,
    subject VARCHAR(200),
    body TEXT NOT NULL,
    priority ENUM('normal', 'high', 'urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msg_school (school_id)
);

CREATE TABLE message_recipients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    message_id INT NOT NULL,
    recipient_id INT NOT NULL,
    recipient_type ENUM('admin', 'instructor', 'staff', 'student', 'parent') NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mr_school (school_id),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Discount Codes
-- ────────────────────────────────────────────────────────────
CREATE TABLE discount_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    code VARCHAR(50) NOT NULL,
    description TEXT,
    discount_type ENUM('percentage', 'fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    applies_to ENUM('membership', 'event', 'all') DEFAULT 'all',
    plan_id INT DEFAULT NULL,
    event_id INT DEFAULT NULL,
    max_uses INT DEFAULT NULL,
    times_used INT DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dc_school (school_id)
);

-- ────────────────────────────────────────────────────────────
-- Makeup Classes
-- ────────────────────────────────────────────────────────────
CREATE TABLE makeup_classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    original_class_id INT NOT NULL,
    makeup_class_id INT DEFAULT NULL,
    absence_date DATE NOT NULL,
    makeup_date DATE DEFAULT NULL,
    status ENUM('pending', 'scheduled', 'completed', 'expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mc_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (original_class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Absence Warnings
-- ────────────────────────────────────────────────────────────
CREATE TABLE absence_warnings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    student_id INT NOT NULL,
    warning_type ENUM('consecutive', 'monthly', 'custom') DEFAULT 'consecutive',
    absence_count INT NOT NULL,
    warning_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aw_school (school_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Renewal Log
-- ────────────────────────────────────────────────────────────
CREATE TABLE renewal_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    membership_id INT NOT NULL,
    student_id INT NOT NULL,
    old_end_date DATE,
    new_end_date DATE,
    amount DECIMAL(10,2),
    renewal_type ENUM('auto', 'manual') DEFAULT 'auto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_school (school_id),
    FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- Settings (per-school)
-- ────────────────────────────────────────────────────────────
CREATE TABLE settings (
    school_id INT NOT NULL DEFAULT 1,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    PRIMARY KEY (school_id, setting_key)
);

-- ────────────────────────────────────────────────────────────
-- Studio Config (per-school branding/theme)
-- ────────────────────────────────────────────────────────────
CREATE TABLE studio_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL DEFAULT 1,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT NOT NULL,
    UNIQUE KEY idx_sc_school_key (school_id, config_key)
);

-- ────────────────────────────────────────────────────────────
-- Role Permissions (GLOBAL — no school_id)
-- ────────────────────────────────────────────────────────────
CREATE TABLE role_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role VARCHAR(20) NOT NULL,
    page VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_role_page (role, page)
);

-- ────────────────────────────────────────────────────────────
-- Login Attempts (GLOBAL — rate limiting, no school_id)
-- ────────────────────────────────────────────────────────────
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_user (username, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
);

-- ════════════════════════════════════════════════════════════
-- Seed Data
-- ════════════════════════════════════════════════════════════

-- Default super-admin user (password: admin123)
INSERT INTO users (school_id, username, password, email, full_name, role)
VALUES (1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@martialarts.com', 'System Administrator', 'super_admin');

-- Sample martial arts styles
INSERT INTO martial_arts_styles (name, description) VALUES
('Karate', 'Traditional Japanese martial art focusing on striking techniques'),
('Taekwondo', 'Korean martial art known for dynamic kicking techniques'),
('Jiu-Jitsu', 'Japanese martial art focusing on grappling and ground fighting'),
('Muay Thai', 'Thai martial art known as the "Art of Eight Limbs"'),
('Kung Fu', 'Chinese martial arts with various styles and forms');

-- Sample belts for Karate
INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES
(1, 'White Belt', 'White', 1, 'Basic stances, blocks, and punches'),
(1, 'Yellow Belt', 'Yellow', 2, 'Basic kata, front kick, roundhouse kick'),
(1, 'Orange Belt', 'Orange', 3, 'Intermediate kata, combination techniques'),
(1, 'Green Belt', 'Green', 4, 'Advanced kata, sparring basics'),
(1, 'Blue Belt', 'Blue', 5, 'Complex combinations, kata mastery'),
(1, 'Purple Belt', 'Purple', 6, 'Advanced sparring, teaching assistance'),
(1, 'Brown Belt', 'Brown', 7, 'Weapons kata, advanced combinations'),
(1, 'Black Belt 1st Dan', 'Black', 8, 'Complete mastery of fundamentals, teaching capability');

-- Sample belts for Taekwondo
INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES
(2, 'White Belt', 'White', 1, 'Basic kicks and stances'),
(2, 'Yellow Belt', 'Yellow', 2, 'Front kick, axe kick, forms'),
(2, 'Green Belt', 'Green', 3, 'Side kick, back kick, poomsae'),
(2, 'Blue Belt', 'Blue', 4, 'Spinning kicks, sparring'),
(2, 'Red Belt', 'Red', 5, 'Advanced kicks, board breaking'),
(2, 'Black Belt 1st Dan', 'Black', 6, 'Master level techniques, teaching');

-- Sample membership plans (school 1)
INSERT INTO membership_plans (school_id, name, description, duration_months, price, classes_per_week, status) VALUES
(1, 'Basic Monthly', 'Up to 2 classes per week', 1, 99.00, 2, 'active'),
(1, 'Standard Monthly', 'Up to 3 classes per week', 1, 149.00, 3, 'active'),
(1, 'Unlimited Monthly', 'Unlimited classes', 1, 199.00, 99, 'active'),
(1, 'Basic Quarterly', 'Up to 2 classes per week - 3 months', 3, 270.00, 2, 'active'),
(1, 'Standard Quarterly', 'Up to 3 classes per week - 3 months', 3, 399.00, 3, 'active'),
(1, 'Unlimited Annual', 'Unlimited classes for 12 months', 12, 1999.00, 99, 'active');
