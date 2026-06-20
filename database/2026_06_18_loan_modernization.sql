-- ============================================================
-- VIKOBA - Loan Modernization Features
-- Phase 1: Loan Products, Amortization, Guarantors, Documents
-- ============================================================

-- 1. LOAN PRODUCTS CATALOG
CREATE TABLE IF NOT EXISTS loan_products (
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
);

-- Seed default loan products
INSERT INTO loan_products (name, code, description, min_amount, max_amount, default_interest_rate, min_term_months, max_term_months, min_savings_pct, share_multiplier, late_fee_pct, late_fee_grace_days, requires_guarantor, min_guarantors, auto_approve_threshold, auto_approve_min_score) VALUES
('General Loan', 'GENERAL', 'Standard loan for any purpose', 10000, 5000000, 15.00, 1, 12, 20.00, 3.00, 1.00, 7, 0, 0, 500000, 80),
('Business Loan', 'BUSINESS', 'Capital for small business development', 50000, 10000000, 14.00, 3, 24, 25.00, 4.00, 1.50, 7, 1, 1, NULL, NULL),
('Emergency Loan', 'EMERGENCY', 'Quick loan for medical or urgent needs', 10000, 500000, 10.00, 1, 3, 10.00, 2.00, 0.50, 3, 0, 0, 200000, 75),
('Education Loan', 'EDUCATION', 'School fees and educational expenses', 50000, 3000000, 12.00, 3, 24, 20.00, 3.00, 1.00, 7, 0, 0, NULL, NULL),
('Agriculture Loan', 'AGRICULTURE', 'Farming inputs and equipment financing', 50000, 5000000, 13.00, 3, 18, 15.00, 3.00, 1.00, 14, 1, 1, NULL, NULL),
('Group Loan', 'GROUP_LOAN', 'Joint liability group financing', 100000, 10000000, 14.00, 3, 12, 20.00, 3.00, 1.50, 7, 1, 3, NULL, NULL);

-- 2. AMORTIZATION SCHEDULE
CREATE TABLE IF NOT EXISTS amortization_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    installment_no INT NOT NULL,
    due_date DATE NOT NULL,
    principal DECIMAL(15,2) NOT NULL DEFAULT 0,
    interest DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_date DATE DEFAULT NULL,
    status ENUM('pending','paid','overdue','partial') NOT NULL DEFAULT 'pending',
    late_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    UNIQUE KEY unique_installment (loan_id, installment_no)
);

-- 3. GUARANTORS
CREATE TABLE IF NOT EXISTS guarantors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    member_id INT NOT NULL,
    amount_guaranteed DECIMAL(15,2) NOT NULL,
    status ENUM('pending','approved','declined','released') NOT NULL DEFAULT 'pending',
    response_date DATE DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE KEY unique_guarantor (loan_id, member_id)
);

-- 4. LOAN DOCUMENTS
CREATE TABLE IF NOT EXISTS loan_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    member_id INT NOT NULL,
    document_type ENUM('id_card','business_plan','collateral_doc','guarantor_id','income_proof','other') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by INT DEFAULT NULL,
    verified_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 5. LOAN RESTRUCTURING LOG
CREATE TABLE IF NOT EXISTS loan_restructures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    restructure_type ENUM('extension','rate_change','payment_holiday','top_up','consolidation') NOT NULL,
    previous_value DECIMAL(15,2) DEFAULT NULL,
    new_value DECIMAL(15,2) DEFAULT NULL,
    reason TEXT,
    approved_by INT DEFAULT NULL,
    approved_date DATE DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 6. Add product_id to loans table
ALTER TABLE loans ADD COLUMN IF NOT EXISTS product_id INT DEFAULT NULL AFTER member_id;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS credit_score INT DEFAULT NULL AFTER risk_level;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS credit_score_breakdown TEXT DEFAULT NULL AFTER credit_score;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS auto_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_score_breakdown;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS disbursed_by INT DEFAULT NULL AFTER approved_by;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS term_months INT DEFAULT NULL AFTER due_date;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS late_fee_accrued DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total_repayable;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS payment_frequency ENUM('monthly','biweekly','weekly','lump_sum') NOT NULL DEFAULT 'monthly' AFTER term_months;

-- 7. Add guarantor_count to members for quick reference
ALTER TABLE members ADD COLUMN IF NOT EXISTS can_be_guarantor TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
ALTER TABLE members ADD COLUMN IF NOT EXISTS max_guarantee_amount DECIMAL(15,2) DEFAULT NULL AFTER can_be_guarantor;

-- 8. Indexes for performance
CREATE INDEX IF NOT EXISTS idx_amortization_loan_status ON amortization_schedule(loan_id, status);
CREATE INDEX IF NOT EXISTS idx_amortization_due_date ON amortization_schedule(due_date);
CREATE INDEX IF NOT EXISTS idx_guarantors_member ON guarantors(member_id);
CREATE INDEX IF NOT EXISTS idx_guarantors_loan ON guarantors(loan_id);
CREATE INDEX IF NOT EXISTS idx_loan_documents_loan ON loan_documents(loan_id);
CREATE INDEX IF NOT EXISTS idx_loan_restructures_loan ON loan_restructures(loan_id);