<?php
// Run SQL migration(s) in this database folder
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$sqlFile = __DIR__ . '/2026_06_17_audit_tables.sql';
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
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        $db->exec($stmt);
    }

    echo "Migrations executed successfully.\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
