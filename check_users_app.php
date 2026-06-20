<?php
require_once __DIR__ . '/includes/bootstrap.php';
$db = Database::getInstance()->getConnection();
$users = $db->query('SELECT COUNT(*) as c FROM users')->fetch();
echo "COUNT=" . $users['c'] . "\n";
$rows = $db->query('SELECT id,email,role,status,member_id,created_at FROM users ORDER BY created_at DESC')->fetchAll();
foreach ($rows as $row) {
    echo implode('|', $row) . "\n";
}
