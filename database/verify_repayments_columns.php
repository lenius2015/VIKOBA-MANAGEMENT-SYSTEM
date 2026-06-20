<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'repayments'");
    $stmt->execute([DB_NAME]);
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$cols) {
        echo "No repayments table found or no columns returned.\n";
        exit(1);
    }
    echo "Columns in repayments table:\n";
    foreach ($cols as $c) {
        echo " - {$c['COLUMN_NAME']} ({$c['COLUMN_TYPE']})\n";
    }
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Verification failed: " . $e->getMessage() . "\n");
    exit(1);
}
