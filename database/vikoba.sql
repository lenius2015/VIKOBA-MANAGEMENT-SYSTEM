-- ============================================================
-- VIKOBA MANAGEMENT SYSTEM - DATABASE SCHEMA
-- ============================================================

CREATE DATABASE IF NOT EXISTS vikoba_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vikoba_db;

-- Users table (authentication)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','treasurer','member') NOT NULL DEFAULT 'member',
    member_id INT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Members table
CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_no VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    dob DATE,
    gender ENUM('male','female','other'),
    id_type VARCHAR(30),
    id_number VARCHAR(50),
    shares INT DEFAULT 1,
    join_date DATE NOT NULL,
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Contribution cycles
CREATE TABLE cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    amount_per_share DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('open','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Contributions table
CREATE TABLE contributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    cycle_id INT,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','mobile_money','bank_transfer') DEFAULT 'cash',
    reference VARCHAR(100),
    date DATE NOT NULL,
    recorded_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Loans table
CREATE TABLE loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_no VARCHAR(20) NOT NULL UNIQUE,
    member_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    total_repayable DECIMAL(15,2) NOT NULL,
    savings_at_application DECIMAL(15,2) NOT NULL DEFAULT 0,
    suggested_amount DECIMAL(15,2) DEFAULT NULL,
    review_notes TEXT,
    risk_level VARCHAR(10) DEFAULT NULL,
    purpose TEXT,
    application_date DATE NOT NULL,
    approved_date DATE,
    disbursement_date DATE,
    due_date DATE,
    status ENUM('draft','submitted','under_review','review_requested','resubmitted','approved','rejected','disbursed','completed','defaulted') DEFAULT 'submitted',
    approved_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Loan repayments table
CREATE TABLE repayments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    member_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','mobile_money','bank_transfer') DEFAULT 'cash',
    reference VARCHAR(100),
    date DATE NOT NULL,
    recorded_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Fines table
CREATE TABLE fines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    date DATE NOT NULL,
    paid TINYINT(1) DEFAULT 0,
    paid_date DATE,
    issued_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Activity log
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    module VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (name, email, password, role) VALUES
('System Admin', 'admin@vikoba.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Amina Treasurer', 'treasurer@vikoba.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'treasurer');
-- Default password: password

INSERT INTO members (member_no, name, phone, email, address, gender, shares, join_date, status) VALUES
('VK-001', 'Amina Hassan', '0712345678', 'amina@email.com', 'Kinondoni, Dar es Salaam', 'female', 20, '2023-01-15', 'active'),
('VK-002', 'John Mwanga', '0754123456', 'john@email.com', 'Ilala, Dar es Salaam', 'male', 15, '2023-02-01', 'active'),
('VK-003', 'Grace Kimaro', '0765987654', 'grace@email.com', 'Temeke, Dar es Salaam', 'female', 18, '2023-03-10', 'active'),
('VK-004', 'Peter Lyimo', '0744567890', 'peter@email.com', 'Arusha', 'male', 10, '2023-04-05', 'inactive'),
('VK-005', 'Fatuma Said', '0789234567', 'fatuma@email.com', 'Mbeya', 'female', 22, '2023-05-20', 'active'),
('VK-006', 'Daniel Mushi', '0721345678', 'daniel@email.com', 'Dodoma', 'male', 12, '2023-06-12', 'active');

INSERT INTO cycles (name, start_date, end_date, amount_per_share, status) VALUES
('Cycle January 2024', '2024-01-01', '2024-01-31', 2500, 'closed'),
('Cycle February 2024', '2024-02-01', '2024-02-29', 2500, 'closed'),
('Cycle March 2024', '2024-03-01', '2024-03-31', 2500, 'open');

INSERT INTO contributions (member_id, cycle_id, amount, payment_method, date, recorded_by) VALUES
(1, 1, 50000, 'cash', '2024-01-10', 1),
(2, 1, 50000, 'mobile_money', '2024-01-12', 1),
(3, 1, 50000, 'cash', '2024-01-15', 1),
(5, 2, 50000, 'bank_transfer', '2024-02-10', 1),
(1, 2, 50000, 'cash', '2024-02-12', 1),
(6, 2, 50000, 'mobile_money', '2024-02-14', 1);

INSERT INTO loans (loan_no, member_id, amount, interest_rate, total_repayable, purpose, application_date, approved_date, disbursement_date, due_date, status, approved_by) VALUES
('LN-001', 1, 300000, 15, 345000, 'Business capital', '2024-01-18', '2024-01-20', '2024-01-20', '2024-07-20', 'disbursed', 1),
('LN-002', 2, 200000, 15, 230000, 'School fees', '2024-02-03', '2024-02-05', '2024-02-05', '2024-08-05', 'disbursed', 1),
('LN-003', 3, 500000, 15, 575000, 'Business expansion', '2023-11-01', '2023-11-01', '2023-11-01', '2024-05-01', 'completed', 1),
('LN-004', 5, 150000, 15, 172500, 'Medical expenses', '2024-03-01', NULL, NULL, '2024-09-01', 'submitted', NULL),
('LN-005', 6, 400000, 15, 460000, 'Construction', '2024-03-15', NULL, NULL, '2024-09-15', 'submitted', NULL);

INSERT INTO repayments (loan_id, member_id, amount, payment_method, date, recorded_by) VALUES
(1, 1, 100000, 'cash', '2024-02-20', 1),
(1, 1, 50000, 'mobile_money', '2024-03-20', 1),
(2, 2, 80000, 'cash', '2024-03-05', 1),
(3, 3, 575000, 'bank_transfer', '2024-04-28', 1);

INSERT INTO fines (member_id, reason, amount, date, paid, issued_by) VALUES
(4, 'Late contribution', 10000, '2024-01-20', 0, 1),
(2, 'Missed meeting', 5000, '2024-02-15', 1, 1),
(6, 'Late contribution', 10000, '2024-02-20', 0, 1);
