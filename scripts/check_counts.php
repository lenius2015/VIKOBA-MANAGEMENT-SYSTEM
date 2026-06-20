<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../classes/Database.php';

$tables = ['users','members','loans','contributions','repayments'];
try {
    $db = Database::getInstance()->getConnection();
    foreach ($tables as $t) {
        $stmt = $db->query("SELECT COUNT(*) as c FROM `".$t."`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $t . ': ' . $row['c'] . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
