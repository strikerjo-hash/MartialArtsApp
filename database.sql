-- Martial Arts Studio Management System Database Schema

CREATE DATABASE IF NOT EXISTS martial_arts_studio;
USE martial_arts_studio;

-- Users/Admin table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'instructor', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Students/Members table
CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20),
    date_of_birth DATE,
    address TEXT,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    join_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    photo VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Martial Arts Styles
CREATE TABLE martial_arts_styles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Belt/Rank System
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

-- Student Belt Progress
CREATE TABLE student_belts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    belt_id INT NOT NULL,
    style_id INT NOT NULL,
    awarded_date DATE NOT NULL,
    instructor_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (belt_id) REFERENCES belts(id) ON DELETE CASCADE,
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Membership Plans
CREATE TABLE membership_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    duration_months INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    classes_per_week INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Student Memberships
CREATE TABLE memberships (
    id INT PRIMARY KEY AUTO_INCREMENT,
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
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE
);

-- Classes/Training Sessions
CREATE TABLE classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    style_id INT NOT NULL,
    instructor_id INT,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_students INT DEFAULT 20,
    skill_level ENUM('beginner', 'intermediate', 'advanced', 'all') DEFAULT 'all',
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (style_id) REFERENCES martial_arts_styles(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Student Class Enrollments
CREATE TABLE class_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    status ENUM('active', 'dropped') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, class_id)
);

-- Attendance Tracking
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time TIME,
    check_out_time TIME,
    status ENUM('present', 'absent', 'late', 'excused') DEFAULT 'present',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Events (Belt Tests, Tournaments, Seminars, etc.)
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
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
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Event Registrations
CREATE TABLE event_registrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
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
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_registration (event_id, student_id)
);

-- Payment Records
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    payment_type ENUM('membership', 'event', 'merchandise', 'other') NOT NULL,
    reference_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'credit_card', 'debit_card', 'bank_transfer', 'other') NOT NULL,
    payment_date DATE NOT NULL,
    receipt_number VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Training Logs
CREATE TABLE training_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    log_date DATE NOT NULL,
    duration_minutes INT,
    techniques_practiced TEXT,
    instructor_notes TEXT,
    student_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password, email, full_name, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@martialarts.com', 'System Administrator', 'admin');

-- Insert sample martial arts styles
INSERT INTO martial_arts_styles (name, description) VALUES
('Karate', 'Traditional Japanese martial art focusing on striking techniques'),
('Taekwondo', 'Korean martial art known for dynamic kicking techniques'),
('Jiu-Jitsu', 'Japanese martial art focusing on grappling and ground fighting'),
('Muay Thai', 'Thai martial art known as the "Art of Eight Limbs"'),
('Kung Fu', 'Chinese martial arts with various styles and forms');

-- Insert sample belts for Karate
INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES
(1, 'White Belt', 'White', 1, 'Basic stances, blocks, and punches'),
(1, 'Yellow Belt', 'Yellow', 2, 'Basic kata, front kick, roundhouse kick'),
(1, 'Orange Belt', 'Orange', 3, 'Intermediate kata, combination techniques'),
(1, 'Green Belt', 'Green', 4, 'Advanced kata, sparring basics'),
(1, 'Blue Belt', 'Blue', 5, 'Complex combinations, kata mastery'),
(1, 'Purple Belt', 'Purple', 6, 'Advanced sparring, teaching assistance'),
(1, 'Brown Belt', 'Brown', 7, 'Weapons kata, advanced combinations'),
(1, 'Black Belt 1st Dan', 'Black', 8, 'Complete mastery of fundamentals, teaching capability');

-- Insert sample belts for Taekwondo
INSERT INTO belts (style_id, name, color, rank_order, requirements) VALUES
(2, 'White Belt', 'White', 1, 'Basic kicks and stances'),
(2, 'Yellow Belt', 'Yellow', 2, 'Front kick, axe kick, forms'),
(2, 'Green Belt', 'Green', 3, 'Side kick, back kick, poomsae'),
(2, 'Blue Belt', 'Blue', 4, 'Spinning kicks, sparring'),
(2, 'Red Belt', 'Red', 5, 'Advanced kicks, board breaking'),
(2, 'Black Belt 1st Dan', 'Black', 6, 'Master level techniques, teaching');

-- Insert sample membership plans
INSERT INTO membership_plans (name, description, duration_months, price, classes_per_week, status) VALUES
('Basic Monthly', 'Up to 2 classes per week', 1, 99.00, 2, 'active'),
('Standard Monthly', 'Up to 3 classes per week', 1, 149.00, 3, 'active'),
('Unlimited Monthly', 'Unlimited classes', 1, 199.00, 99, 'active'),
('Basic Quarterly', 'Up to 2 classes per week - 3 months', 3, 270.00, 2, 'active'),
('Standard Quarterly', 'Up to 3 classes per week - 3 months', 3, 399.00, 3, 'active'),
('Unlimited Annual', 'Unlimited classes for 12 months', 12, 1999.00, 99, 'active');
