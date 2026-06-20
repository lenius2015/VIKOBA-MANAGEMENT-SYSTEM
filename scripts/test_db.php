<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo $row[0] . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
