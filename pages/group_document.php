<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$gc = new GroupCenter();
$db = Database::getInstance()->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    setFlash('error', 'Document not specified.');
    header('Location: ' . APP_URL . '/pages/group_info_center.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    setFlash('error', 'Document not found.');
    header('Location: ' . APP_URL . '/pages/group_info_center.php');
    exit;
}

// Permission checks
$perm = $doc['permission_level'] ?? 'public';
if ($perm === 'admins') {
    $auth->requireRole(['admin']);
} elseif ($perm === 'members') {
    $auth->requireRole(['member','treasurer','admin']);
}

$filePath = __DIR__ . '/../uploads/' . $doc['filename'];
if (!file_exists($filePath)) {
    setFlash('error', 'File missing on server.');
    header('Location: ' . APP_URL . '/pages/group_info_center.php');
    exit;
}

// Log download
try {
    $audit = new Audit();
    $audit->logModuleActivity($user['id'] ?? null, $user['name'] ?? null, $user['role'] ?? null, 'group_center', 'download_document', 'documents', $doc['id'], $doc['title']);
} catch (Throwable $e) { }

// Serve file
$mime = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($doc['filename']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
