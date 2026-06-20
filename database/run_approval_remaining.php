<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

echo "Running remaining approval workflow migration...\n\n";

// Add current_approval_level column
try {
    $db->exec("ALTER TABLE loans ADD COLUMN current_approval_level ENUM('officer','treasurer','admin','none') DEFAULT 'none' AFTER status");
    echo "[OK] Added current_approval_level column\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) echo "[SKIP] current_approval_level already exists\n";
    else echo "[ERR] " . $e->getMessage() . "\n";
}

// Create loan_approvals table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS loan_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        approver_id INT NOT NULL,
        approval_level ENUM('officer','treasurer','admin') NOT NULL,
        action ENUM('pending','approved','rejected','requested_changes','conditionally_approved') NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
        FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_la_loan (loan_id),
        INDEX idx_la_approver (approver_id),
        INDEX idx_la_level (approval_level, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created loan_approvals table\n";
} catch (PDOException $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
}

// Create loan_conditions table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS loan_conditions (
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
        INDEX idx_lc_loan (loan_id),
        INDEX idx_lc_status (is_met)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created loan_conditions table\n";
} catch (PDOException $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
}

// Insert system settings
try {
    $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES
        ('approval_officer_max_amount', '500000'),
        ('approval_treasurer_max_amount', '2000000'),
        ('approval_admin_max_amount', '999999999'),
        ('approval_high_risk_requires_admin', '1'),
        ('approval_auto_approve_enabled', '1')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    echo "[OK] Inserted system settings\n";
} catch (PDOException $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
}

echo "\nMigration completed.\n";