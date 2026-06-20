<?php
// AJAX endpoint to get live notification/message counts
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['user_id'] ?? 0;
$notif = new Notification();
$msg = new Message();

header('Content-Type: application/json');
echo json_encode([
    'notifications' => $notif->unreadCount($userId),
    'messages' => $msg->unreadCount($userId),
]);