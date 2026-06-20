<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $counts = [];
    foreach (['activity_logs','login_activity','audit_trail'] as $t) {
        try {
            $stmt = $db->query("SELECT COUNT(*) AS c FROM `" . $t . "`");
            $row = $stmt->fetch();
            $counts[$t] = $row['c'] ?? 0;
        } catch (Throwable $e) {
            $counts[$t] = 'missing';
        }
    }
    foreach ($counts as $table => $cnt) {
        echo "$table: $cnt\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}
