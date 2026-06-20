<?php
// Run the loan modernization migration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$sqlFile = __DIR__ . '/2026_06_18_loan_modernization.sql';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "SQL file not found: $sqlFile\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Failed to read SQL file: $sqlFile\n");
    exit(1);
}

try {
    $db = Database::getInstance()->getConnection();

    // Split statements on semicolons and execute each non-empty statement.
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    $count = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        // Skip comment lines
        if (preg_match('/^--/', $stmt)) continue;
        try {
            $db->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            // Ignore "already exists" errors for tables/indexes
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                echo "  [SKIP] " . substr($stmt, 0, 60) . "... (already exists)\n";
                continue;
            }
            throw $e;
        }
    }

    echo "Loan modernization migration completed. $count statements executed.\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}