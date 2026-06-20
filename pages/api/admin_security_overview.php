<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireRole(['admin']);

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $counts = [];
    $counts['login_activity'] = (int) ($db->query("SELECT COUNT(*) AS c FROM login_activity")->fetch()['c'] ?? 0);
    $counts['activity_logs'] = (int) ($db->query("SELECT COUNT(*) AS c FROM activity_logs")->fetch()['c'] ?? 0);
    $counts['audit_trail'] = (int) ($db->query("SELECT COUNT(*) AS c FROM audit_trail")->fetch()['c'] ?? 0);

    $recentActs = $db->query("SELECT id,user_id,username,role,module,action,details,ip,created_at FROM activity_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
    $recentLogins = $db->query("SELECT la.id, la.user_id, la.username, u.name AS user_name, la.role, la.ip, la.device, la.browser, la.os, la.login_time, la.logout_time, la.status, la.session_id FROM login_activity la LEFT JOIN users u ON la.user_id = u.id ORDER BY la.login_time DESC LIMIT 10")->fetchAll();

    echo json_encode(['ok' => true, 'counts' => $counts, 'recent_activities' => $recentActs, 'recent_logins' => $recentLogins]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
