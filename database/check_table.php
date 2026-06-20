<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SHOW TABLES LIKE 'loan_products'");
if ($stmt->rowCount() > 0) {
    echo "loan_products table EXISTS\n";
    $stmt2 = $db->query("SELECT COUNT(*) as cnt FROM loan_products");
    $row = $stmt2->fetch();
    echo "Records: " . $row['cnt'] . "\n";
} else {
    echo "loan_products table MISSING\n";
}