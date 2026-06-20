-- ============================================================
-- VIKOBA MANAGEMENT SYSTEM - COMPREHENSIVE SECURITY FEATURES
-- ============================================================
-- This migration adds comprehensive security monitoring, logging,
-- and auditing features including login tracking, activity logs,
-- loan approval logs, suspicious activity detection, and more.

-- Loan Approval Logs (detailed tracking of loan approval process)
CREATE TABLE IF NOT EXISTS loan_approval_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  loan_no VARCHAR(50) NOT NULL,
  member_id INT NOT NULL,
  approving_officer_id INT NULL,
  approving_officer_name VARCHAR(191) NULL,
  approval_stage VARCHAR(100) NOT NULL,
  action VARCHAR(100) NOT NULL COMMENT 'submitted, reviewed, approved, rejected, etc',
  status_before VARCHAR(50) NULL,
  status_after VARCHAR(50) NOT NULL,
  comments TEXT NULL,
  approval_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  INDEX (loan_id),
  INDEX (member_id),
  INDEX (approving_officer_id),
  INDEX (approval_date),
  FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  FOREIGN KEY (approving_officer_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Suspicious Activity Detection Log
CREATE TABLE IF NOT EXISTS suspicious_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(191) NULL,
  activity_type VARCHAR(100) NOT NULL COMMENT 'brute_force, unusual_location, new_device, etc',
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  device_info VARCHAR(255) NULL,
  location_info VARCHAR(255) NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  acknowledged_by INT NULL,
  acknowledged_at DATETIME NULL,
  action_taken TEXT NULL,
  INDEX (user_id),
  INDEX (severity),
  INDEX (detected_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Enhanced Activity Logs for Specific Modules
CREATE TABLE IF NOT EXISTS module_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  username VARCHAR(191) NOT NULL,
  role VARCHAR(50) NOT NULL,
  module VARCHAR(100) NOT NULL COMMENT 'members, loans, contributions, fines, users, etc',
  action VARCHAR(100) NOT NULL COMMENT 'create, read, update, delete, approve, reject, export, etc',
  entity_type VARCHAR(100) NULL COMMENT 'member, loan, contribution, fine, etc',
  entity_id INT NULL,
  entity_name VARCHAR(255) NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (module),
  INDEX (action),
  INDEX (created_at),
  INDEX (entity_type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Data Modification Audit Trail (before/after values)
CREATE TABLE IF NOT EXISTS data_audit_trail (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  username VARCHAR(191) NOT NULL,
  table_name VARCHAR(100) NOT NULL,
  record_id VARCHAR(191) NOT NULL,
  field_name VARCHAR(191) NOT NULL,
  old_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  change_description TEXT NULL,
  ip_address VARCHAR(45) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (table_name),
  INDEX (record_id),
  INDEX (user_id),
  INDEX (changed_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Online Users Monitoring (active sessions in real-time)
CREATE TABLE IF NOT EXISTS online_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(191) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  username VARCHAR(191) NOT NULL,
  role VARCHAR(50) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  browser VARCHAR(100) NULL,
  device VARCHAR(100) NULL,
  os VARCHAR(100) NULL,
  login_time DATETIME NOT NULL,
  last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('active','idle','offline') NOT NULL DEFAULT 'active',
  location_info VARCHAR(255) NULL,
  INDEX (user_id),
  INDEX (last_activity),
  INDEX (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Admin Notification Center
CREATE TABLE IF NOT EXISTS admin_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  notification_type VARCHAR(100) NOT NULL COMMENT 'loan_application, loan_approval, member_registration, failed_login, backup_status, security_alert, etc',
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  related_entity_type VARCHAR(100) NULL COMMENT 'loan, member, user, system, etc',
  related_entity_id INT NULL,
  icon VARCHAR(50) NULL,
  action_url VARCHAR(500) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  INDEX (admin_id),
  INDEX (notification_type),
  INDEX (is_read),
  INDEX (created_at),
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- System Health & Backup Status
CREATE TABLE IF NOT EXISTS system_health_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  check_type VARCHAR(100) NOT NULL COMMENT 'database_backup, disk_space, cpu_usage, memory_usage, etc',
  status ENUM('healthy','warning','critical') NOT NULL DEFAULT 'healthy',
  message VARCHAR(500) NULL,
  metrics JSON NULL COMMENT 'Store additional metrics as JSON',
  check_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (check_type),
  INDEX (status),
  INDEX (check_timestamp)
);

-- Failed Login Attempts with Lockout Tracking
ALTER TABLE failed_logins ADD COLUMN IF NOT EXISTS
  device_info VARCHAR(255) NULL AFTER user_agent,
ADD COLUMN IF NOT EXISTS
  location_info VARCHAR(255) NULL AFTER device_info;

-- Enhance system_alerts with more fields
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS
  alert_type VARCHAR(100) NULL AFTER created_at,
ADD COLUMN IF NOT EXISTS
  related_entity_id INT NULL AFTER alert_type,
ADD COLUMN IF NOT EXISTS
  action_url VARCHAR(500) NULL AFTER related_entity_id,
ADD COLUMN IF NOT EXISTS
  read_by INT NULL AFTER is_read;

-- Enhance login_activity with location tracking
ALTER TABLE login_activity ADD COLUMN IF NOT EXISTS
  location_info VARCHAR(255) NULL AFTER os,
ADD COLUMN IF NOT EXISTS
  successful_auth TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

-- Enhance users table with security fields
ALTER TABLE users ADD COLUMN IF NOT EXISTS
  last_login DATETIME NULL AFTER updated_at,
ADD COLUMN IF NOT EXISTS
  last_login_ip VARCHAR(45) NULL AFTER last_login,
ADD COLUMN IF NOT EXISTS
  login_count INT DEFAULT 0 AFTER last_login_ip,
ADD COLUMN IF NOT EXISTS
  is_two_factor_enabled TINYINT(1) DEFAULT 0 AFTER login_count,
ADD COLUMN IF NOT EXISTS
  two_factor_method VARCHAR(50) NULL AFTER is_two_factor_enabled;

-- Create index on activity_logs for better performance
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_user_created (user_id, created_at);
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_module_action (module, action);

-- ============================================================
-- SAMPLE DATA / INITIAL SETUP
-- ============================================================

-- Insert initial system alert
INSERT INTO system_alerts (level, title, message, alert_type, is_read)
VALUES ('info', 'System Initialized', 'Security monitoring system initialized successfully.', 'system_init', 1)
ON DUPLICATE KEY UPDATE created_at = NOW();

-- Create a view for monitoring dashboard
CREATE OR REPLACE VIEW vw_login_summary AS
SELECT
  DATE(login_time) as login_date,
  COUNT(*) as total_logins,
  COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_logins,
  COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_logins,
  COUNT(DISTINCT user_id) as unique_users
FROM login_activity
GROUP BY DATE(login_time)
ORDER BY login_date DESC;

CREATE OR REPLACE VIEW vw_active_sessions AS
SELECT
  om.id,
  om.session_id,
  om.user_id,
  om.username,
  om.role,
  om.ip_address,
  om.browser,
  om.device,
  om.os,
  om.login_time,
  om.last_activity,
  om.status,
  TIMESTAMPDIFF(MINUTE, om.last_activity, NOW()) as idle_minutes
FROM online_users om
WHERE om.status != 'offline'
ORDER BY om.last_activity DESC;

CREATE OR REPLACE VIEW vw_suspicious_activity_summary AS
SELECT
  severity,
  activity_type,
  COUNT(*) as count,
  MAX(detected_at) as last_detected
FROM suspicious_activities
WHERE acknowledged = 0
GROUP BY severity, activity_type
ORDER BY severity DESC, last_detected DESC;

-- ============================================================
-- END OF MIGRATION
-- ============================================================
