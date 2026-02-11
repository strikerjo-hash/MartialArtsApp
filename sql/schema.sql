-- MartialArtsApp Database Schema

CREATE DATABASE IF NOT EXISTS martial_arts_app;
USE martial_arts_app;

-- Studio branding / theme configuration
CREATE TABLE IF NOT EXISTS studio_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL
);

-- Default studio branding values
INSERT INTO studio_config (config_key, config_value) VALUES
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

-- Students table
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    belt_rank VARCHAR(50) DEFAULT 'White',
    join_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    profile_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Admin users table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    role ENUM('owner', 'instructor', 'staff') DEFAULT 'staff',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Classes table
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(100) NOT NULL,
    description TEXT,
    instructor_id INT DEFAULT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_students INT DEFAULT 30,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (instructor_id) REFERENCES admins(id) ON DELETE SET NULL
);

-- Class enrollments
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    enrolled_date DATE NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, class_id)
);

-- Attendance tracking
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late') DEFAULT 'present',
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    posted_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- Login attempt rate-limiting
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_user (username, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
);

-- Payment methods (encrypted; card numbers are NEVER stored in plain text)
-- In production, prefer Stripe/Braintree tokens over storing card data yourself.
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,           -- e.g. "Visa ending 4242"
    card_brand VARCHAR(20) DEFAULT NULL,   -- visa, mastercard, amex, etc.
    last_four CHAR(4) NOT NULL,
    encrypted_token TEXT NOT NULL,          -- AES-256-GCM encrypted processor token / reference
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Insert a default admin (password: admin123 — change immediately)
INSERT INTO admins (username, password_hash, full_name, email, role) VALUES
    ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Studio Owner', 'admin@studio.com', 'owner');

-- Insert a sample student (password: student123)
INSERT INTO students (username, password_hash, first_name, last_name, email, belt_rank, join_date) VALUES
    ('jdoe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Doe', 'john@example.com', 'Blue', '2025-01-15');
