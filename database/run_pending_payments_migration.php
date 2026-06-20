<?php
/**
 * Run the pending payments migration
 * Adds status, reviewed_by, reviewed_at, rejection_reason columns to repayments table
 */

echo "Running Pending Payments Migration...\n";

try {
    $db = new PDO('mysql:host=localhost;dbname=vikoba_db', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Check if status column already exists
    $stmt = $db->query("SHOW COLUMNS FROM repayments LIKE 'status'");
    if ($stmt->fetch()) {
        echo "✓ 'status' column already exists.\n";
    } else {
        $db->exec("ALTER TABLE repayments 
            ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' 
            AFTER reference");
        echo "✓ Added 'status' column.\n";
    }

    // Check if reviewed_by column exists
    $stmt = $db->query("SHOW COLUMNS FROM repayments LIKE 'reviewed_by'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE repayments
            ADD COLUMN reviewed_by INT NULL AFTER notes,
            ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
            ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at");
        echo "✓ Added reviewed_by, reviewed_at, rejection_reason columns.\n";
    } else {
        echo "✓ 'reviewed_by' column already exists.\n";
    }

    // Update existing records to approved
    $count = $db->exec("UPDATE repayments SET status = 'approved' WHERE status IS NULL OR status = ''");
    echo "✓ Updated $count existing repayment(s) to 'approved' status.\n";

    echo "\nMigration completed successfully!\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}