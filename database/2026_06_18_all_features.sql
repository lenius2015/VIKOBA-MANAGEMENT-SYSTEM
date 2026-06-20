-- ============================================================
-- VIKOBA - All Features Enhancement
-- Phase 2: Restructuring, SMS/Email, M-Pesa, PWA
-- ============================================================

-- 1. GUARANTOR REQUESTS - Additional tracking columns
ALTER TABLE guarantors ADD COLUMN IF NOT EXISTS requested_by INT DEFAULT NULL AFTER loan_id;
ALTER TABLE guarantors ADD COLUMN IF NOT EXISTS responded_by INT DEFAULT NULL AFTER request_date;
ALTER TABLE guarantors ADD COLUMN IF NOT EXISTS request_date DATE DEFAULT NULL AFTER notes;
ALTER TABLE guarantors ADD COLUMN IF NOT EXISTS notified TINYINT(1) DEFAULT 0 AFTER responded_by;

-- 2. LOAN RESTRUCTURING ENHANCEMENTS
-- Add additional restructure types to existing loan_restructures
ALTER TABLE loan_restructures MODIFY COLUMN restructure_type ENUM('extension','rate_change','payment_holiday','top_up','consolidation') NOT NULL;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS requested_by INT DEFAULT NULL AFTER reason;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS requested_date DATE DEFAULT NULL AFTER requested_by;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS reviewed_by INT DEFAULT NULL AFTER approved_by;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS review_notes TEXT AFTER reason;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS loan_balance_before DECIMAL(15,2) DEFAULT NULL AFTER previous_value;
ALTER TABLE loan_restructures ADD COLUMN IF NOT EXISTS new_loan_id INT DEFAULT NULL AFTER new_value;

-- 3. SMS/EMAIL - Scheduled reminders
CREATE TABLE IF NOT EXISTS scheduled_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_type ENUM('sms','email') NOT NULL DEFAULT 'sms',
    recipient_type ENUM('member','all_members','specific') NOT NULL DEFAULT 'all_members',
    recipient_id INT DEFAULT NULL,
    loan_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    scheduled_date DATETIME NOT NULL,
    sent_date DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. SMS/EMAIL - Sent log
CREATE TABLE IF NOT EXISTS sent_communications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    communication_type ENUM('sms','email') NOT NULL,
    recipient VARCHAR(100) NOT NULL,
    recipient_name VARCHAR(100) DEFAULT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    message TEXT NOT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    provider_response TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. AFRICA'S TALKING SETTINGS
CREATE TABLE IF NOT EXISTS africas_talking_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(100) NOT NULL DEFAULT '',
    sender_id VARCHAR(50) NOT NULL DEFAULT 'VIKOBA',
    active TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default Africa's Talking settings
INSERT INTO africas_talking_settings (api_key, username, sender_id, active) VALUES ('', '', 'VIKOBA', 0);

-- 6. M-PESA SETTINGS
CREATE TABLE IF NOT EXISTS mpesa_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consumer_key VARCHAR(255) NOT NULL DEFAULT '',
    consumer_secret VARCHAR(255) NOT NULL DEFAULT '',
    passkey VARCHAR(255) NOT NULL DEFAULT '',
    shortcode VARCHAR(20) NOT NULL DEFAULT '',
    environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    callback_url VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default M-Pesa settings
INSERT INTO mpesa_settings (consumer_key, consumer_secret, passkey, shortcode, environment) VALUES ('', '', '', '174379', 'sandbox');

-- 7. M-PESA TRANSACTIONS LOG
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_type VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(100) NOT NULL UNIQUE,
    loan_id INT DEFAULT NULL,
    member_id INT DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    merchant_request_id VARCHAR(100) DEFAULT NULL,
    checkout_request_id VARCHAR(100) DEFAULT NULL,
    result_code VARCHAR(10) DEFAULT NULL,
    result_desc TEXT DEFAULT NULL,
    status ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    raw_response TEXT DEFAULT NULL,
    callback_data TEXT DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE SET NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. SYSTEM SETTINGS TABLE (for generic key-value storage)
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('pwa_enabled', '1'),
('pwa_app_name', 'Vikoba Management'),
('pwa_app_short_name', 'Vikoba'),
('pwa_app_icon', 'public/img/icon-192x192.png'),
('reminder_enabled', '1'),
('reminder_days_before_due', '3'),
('mpesa_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_from_email', 'noreply@vikoba.co.tz'),
('smtp_from_name', 'Vikoba System')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 9. Add member phone_normalized for M-Pesa
ALTER TABLE members ADD COLUMN IF NOT EXISTS phone_normalized VARCHAR(20) DEFAULT NULL AFTER phone;

-- 10. PWA - Push subscription table
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    auth_key VARCHAR(255) DEFAULT NULL,
    p256dh_key VARCHAR(255) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Add index for performance
CREATE INDEX IF NOT EXISTS idx_scheduled_reminders_status ON scheduled_reminders(status, scheduled_date);
CREATE INDEX IF NOT EXISTS idx_mpesa_transactions_loan ON mpesa_transactions(loan_id);
CREATE INDEX IF NOT EXISTS idx_mpesa_transactions_member ON mpesa_transactions(member_id);
CREATE INDEX IF NOT EXISTS idx_mpesa_transactions_status ON mpesa_transactions(status);
CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_sent_communications_type ON sent_communications(communication_type, created_at);