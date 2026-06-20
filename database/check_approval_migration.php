<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

$tables = $db->query("SHOW TABLES LIKE 'loan_approvals'")->fetchAll();
echo "loan_approvals table: " . count($tables) . " found\n";

$tables2 = $db->query("SHOW TABLES LIKE 'loan_conditions'")->fetchAll();
echo "loan_conditions table: " . count($tables2) . " found\n";

$col = $db->query("SHOW COLUMNS FROM loans LIKE 'current_approval_level'")->fetchAll();
echo "current_approval_level column: " . count($col) . " found\n";

$settings = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'approval_%'")->fetchAll(PDO::FETCH_ASSOC);
echo "Approval system settings:\n";
foreach ($settings as $s) {
    echo "  {$s['setting_key']} = {$s['setting_value']}\n";
}

echo "\nMigration check complete.\n";