<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$sqlFile = __DIR__ . '/2026_06_18_loan_modernization.sql';
echo "SQL file: $sqlFile\n";
echo "DB: " . DB_NAME . " on " . DB_HOST . "\n";

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die("Failed to read SQL file\n");
}

try {
    $db = Database::getInstance()->getConnection();
    echo "Connected to database.\n";

    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    $count = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '' || preg_match('/^--/', $stmt)) continue;
        try {
            $db->exec($stmt);
            $count++;
            echo "  [OK] " . substr(str_replace("\n", " ", $stmt), 0, 80) . "\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                echo "  [SKIP] " . substr(str_replace("\n", " ", $stmt), 0, 60) . "... (already exists)\n";
                continue;
            }
            throw $e;
        }
    }

    echo "\nMigration completed. $count statements executed.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}