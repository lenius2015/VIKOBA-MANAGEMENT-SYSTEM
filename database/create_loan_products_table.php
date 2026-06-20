<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

echo "Creating loan_products table...\n";
$db->exec("CREATE TABLE IF NOT EXISTS loan_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT,
    min_amount DECIMAL(15,2) NOT NULL DEFAULT 10000,
    max_amount DECIMAL(15,2) NOT NULL DEFAULT 5000000,
    default_interest_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    min_term_months INT NOT NULL DEFAULT 1,
    max_term_months INT NOT NULL DEFAULT 12,
    min_savings_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    share_multiplier DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    late_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    late_fee_grace_days INT NOT NULL DEFAULT 7,
    requires_guarantor TINYINT(1) NOT NULL DEFAULT 0,
    min_guarantors INT NOT NULL DEFAULT 0,
    requires_collateral TINYINT(1) NOT NULL DEFAULT 0,
    auto_approve_threshold DECIMAL(15,2) DEFAULT NULL,
    auto_approve_min_score INT DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
echo "Table created.\n";

echo "Seeding default loan products...\n";
$db->exec("INSERT IGNORE INTO loan_products (name, code, description, min_amount, max_amount, default_interest_rate, min_term_months, max_term_months, min_savings_pct, share_multiplier, late_fee_pct, late_fee_grace_days, requires_guarantor, min_guarantors, auto_approve_threshold, auto_approve_min_score) VALUES
('General Loan', 'GENERAL', 'Standard loan for any purpose', 10000, 5000000, 15.00, 1, 12, 20.00, 3.00, 1.00, 7, 0, 0, 500000, 80),
('Business Loan', 'BUSINESS', 'Capital for small business development', 50000, 10000000, 14.00, 3, 24, 25.00, 4.00, 1.50, 7, 1, 1, NULL, NULL),
('Emergency Loan', 'EMERGENCY', 'Quick loan for medical or urgent needs', 10000, 500000, 10.00, 1, 3, 10.00, 2.00, 0.50, 3, 0, 0, 200000, 75),
('Education Loan', 'EDUCATION', 'School fees and educational expenses', 50000, 3000000, 12.00, 3, 24, 20.00, 3.00, 1.00, 7, 0, 0, NULL, NULL),
('Agriculture Loan', 'AGRICULTURE', 'Farming inputs and equipment financing', 50000, 5000000, 13.00, 3, 18, 15.00, 3.00, 1.00, 14, 1, 1, NULL, NULL),
('Group Loan', 'GROUP_LOAN', 'Joint liability group financing', 100000, 10000000, 14.00, 3, 12, 20.00, 3.00, 1.50, 7, 1, 3, NULL, NULL)");
echo "Seed data inserted.\n";

echo "Done.\n";