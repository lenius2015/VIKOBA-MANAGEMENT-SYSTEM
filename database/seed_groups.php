<?php
// Simple seeder to create a test group and attach some existing members.
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $db = Database::getInstance()->getConnection();
    $group = new Group();

    // Create group if not exists
    $name = 'Test Savings Group';
    $stmt = $db->prepare("SELECT id FROM `groups` WHERE name = ?");
    $stmt->execute([$name]);
    $gId = $stmt->fetchColumn();
    if (!$gId) {
        $group->create(['name'=>$name,'description'=>'Seeded test group','created_by'=>1,'status'=>'active']);
        $gId = $db->lastInsertId();
        echo "Created group id={$gId}\n";
    } else {
        echo "Group already exists id={$gId}\n";
    }

    // Attach up to 5 members (first ones in members table)
    $stmt = $db->query("SELECT id FROM members ORDER BY id ASC LIMIT 5");
    $members = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($members as $mId) {
        $added = $group->addMember($gId, (int)$mId);
        echo "Attached member {$mId}: " . ($added ? 'ok' : 'skipped') . "\n";
    }

    echo "Seeding completed. Visit /pages/group_info.php?id={$gId}\n";
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
