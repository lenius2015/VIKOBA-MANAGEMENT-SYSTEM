-- Audit & Monitoring tables for Vikoba Management System
-- Run this in your database (vikoba_db)

-- Login activity (successful logins and logouts)
CREATE TABLE IF NOT EXISTS login_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(191) NULL,
  role VARCHAR(50) NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  device VARCHAR(100) NULL,
  browser VARCHAR(100) NULL,
  os VARCHAR(100) NULL,
  login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logout_time DATETIME NULL,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  session_id VARCHAR(191) NULL,
  INDEX (user_id),
  INDEX (username),
  INDEX (login_time)
);

-- Failed login attempts (to support lockout/monitoring)
CREATE TABLE IF NOT EXISTS failed_logins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempted_username VARCHAR(191) NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (attempted_username),
  INDEX (ip),
  INDEX (attempt_time)
);

-- Generic activity logs for significant actions
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(191) NULL,
  role VARCHAR(50) NULL,
  module VARCHAR(100) NOT NULL,
  action VARCHAR(255) NOT NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (module),
  INDEX (created_at)
);

-- Field-level audit trail (records before/after values)
CREATE TABLE IF NOT EXISTS audit_trail (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(191) NULL,
  table_name VARCHAR(100) NOT NULL,
  record_id VARCHAR(191) NOT NULL,
  field_name VARCHAR(191) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  change_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip VARCHAR(45) NULL,
  INDEX (table_name),
  INDEX (record_id),
  INDEX (user_id)
);

-- Optional sessions table to track active sessions
CREATE TABLE IF NOT EXISTS sessions_monitor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(191) NOT NULL,
  user_id INT NULL,
  username VARCHAR(191) NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (session_id),
  INDEX (user_id),
  INDEX (last_activity)
);

-- Small helper table for alerts (can be extended)
CREATE TABLE IF NOT EXISTS system_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_read TINYINT(1) NOT NULL DEFAULT 0
);
