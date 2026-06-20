<?php
// Run all SQL migrations in this database folder safely and record applied migrations
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Ensure migrations table exists
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Collect SQL files (ordered)
    $files = glob(__DIR__ . "/*.sql");
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);

        // Skip migrations that are already applied
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM migrations WHERE name = ?");
        $stmt->execute([$name]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo "Skipping already applied migration: $name\n";
            continue;
        }

        echo "Applying migration: $name ...\n";
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Failed to read $file");
        }

        // Execute statements; simple split by semicolon - acceptable for most migrations
        $stmts = array_filter(array_map('trim', explode(';', $sql)));

        $db->beginTransaction();
        try {
            foreach ($stmts as $stmtSql) {
                if ($stmtSql === '') continue;
                $db->exec($stmtSql);
            }
            $ins = $db->prepare("INSERT INTO migrations (name) VALUES (?)");
            $ins->execute([$name]);
            $db->commit();
            echo "Applied: $name\n";
        } catch (PDOException $e) {
            $db->rollBack();
            fwrite(STDERR, "Failed applying $name: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "All migrations processed.\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Migration runner failed: " . $e->getMessage() . "\n");
    exit(1);
}
