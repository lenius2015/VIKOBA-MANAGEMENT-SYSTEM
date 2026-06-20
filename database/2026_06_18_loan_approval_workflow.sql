-- ============================================================
-- VIKOBA - Loan Approval Workflow Enhancement
-- Multi-level approval chain, conditional approvals, bulk ops
-- ============================================================

-- 1. Add approval level tracking to loans table
ALTER TABLE loans ADD COLUMN IF NOT EXISTS current_approval_level ENUM('officer','treasurer','admin','none') DEFAULT 'none' AFTER status;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS disbursement_authorized_by INT DEFAULT NULL AFTER approved_by;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS disbursement_authorized_date DATE DEFAULT NULL AFTER disbursement_date;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL AFTER review_notes;
ALTER TABLE loans ADD COLUMN IF NOT EXISTS approval_notes TEXT DEFAULT NULL AFTER rejection_reason;

-- 2. Loan Approvals Chain Table
CREATE TABLE IF NOT EXISTS loan_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    approver_id INT NOT NULL,
    approval_level ENUM('officer','treasurer','admin') NOT NULL,
    action ENUM('pending','approved','rejected','requested_changes','conditionally_approved') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_loan_approvals_loan (loan_id),
    INDEX idx_loan_approvals_approver (approver_id),
    INDEX idx_loan_approvals_level (approval_level, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Loan Conditions Table
CREATE TABLE IF NOT EXISTS loan_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    condition_text TEXT NOT NULL,
    condition_type ENUM('document','guarantor','payment','other') NOT NULL DEFAULT 'other',
    is_met TINYINT(1) NOT NULL DEFAULT 0,
    met_date DATE DEFAULT NULL,
    met_by INT DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (met_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_loan_conditions_loan (loan_id),
    INDEX idx_loan_conditions_status (is_met)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. System settings for approval thresholds
INSERT INTO system_settings (setting_key, setting_value) VALUES
('approval_officer_max_amount', '500000'),
('approval_treasurer_max_amount', '2000000'),
('approval_admin_max_amount', '999999999'),
('approval_high_risk_requires_admin', '1'),
('approval_auto_approve_enabled', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);