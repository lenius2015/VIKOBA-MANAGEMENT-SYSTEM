<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();

$auth = new Auth();
$auth->requireRole(['admin']);

$pageTitle = 'Security Alerts';

$db = Database::getInstance()->getConnection();

// Ensure Audit class exists (for consistent table naming / future extensions)
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE system_alerts SET is_read = 1 WHERE id = ?")->execute([$id]);
        if ($audit) {
            $audit->logActivity(
                $_SESSION['user_id'] ?? null,
                $_SESSION['user_name'] ?? null,
                $_SESSION['user_role'] ?? null,
                'security',
                'mark_read',
                'Alert ID: ' . $id
            );
        }

        setFlash('success', 'Alert marked as read.');
    }
    redirect(APP_URL . '/pages/security_alerts.php');
}

// DELETE Failed Login Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_failed_login') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM failed_logins WHERE id = ?")->execute([$id]);
        setFlash('success', 'Failed login record deleted.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=failed');
}

// DELETE Login Activity Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_login_activity') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM login_activity WHERE id = ?")->execute([$id]);
        setFlash('success', 'Login activity record deleted.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=logins');
}

// ACKNOWLEDGE Suspicious Activity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_suspicious') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE suspicious_activities SET acknowledged = 1, acknowledged_at = NOW() WHERE id = ?")->execute([$id]);
        setFlash('success', 'Suspicious activity marked as acknowledged.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=suspicious');
}

// DELETE Suspicious Activity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_suspicious') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM suspicious_activities WHERE id = ?")->execute([$id]);
        setFlash('success', 'Suspicious activity record deleted.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=suspicious');
}

// MARK Activity Log as Reviewed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_activity') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE module_activity_logs SET reviewed = 1 WHERE id = ?")->execute([$id]);
        setFlash('success', 'Activity marked as reviewed.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=activity');
}

// DELETE Activity Log Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_activity') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM module_activity_logs WHERE id = ?")->execute([$id]);
        setFlash('success', 'Activity log record deleted.');
    }
    redirect(APP_URL . '/pages/security_alerts.php?tab=activity');
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->query("SELECT id, level, title, message, created_at, is_read FROM system_alerts ORDER BY created_at DESC")->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="system_alerts.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'level', 'title', 'message', 'created_at', 'is_read']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['level'], $r['title'], $r['message'], $r['created_at'], $r['is_read']]);
    }
    fclose($out);
    exit;
} elseif (isset($_GET['export']) && $_GET['export'] === 'failed_logins') {
    $rows = $db->query("SELECT id, attempted_username, ip, user_agent, attempt_time FROM failed_logins ORDER BY attempt_time DESC LIMIT 100")->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="failed_logins.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'attempted_username', 'ip', 'user_agent', 'attempt_time']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['attempted_username'], $r['ip'], $r['user_agent'], $r['attempt_time']]);
    }
    fclose($out);
    exit;
}

$filterLevel = trim($_GET['level'] ?? '');
$filterText  = trim($_GET['q'] ?? '');
$failedSearch = trim($_GET['search'] ?? '');

$sql = "SELECT id, level, title, message, created_at, is_read
        FROM system_alerts";
$params = [];
$where = [];

if ($filterLevel) {
    $where[] = "level = ?";
    $params[] = $filterLevel;
}

if ($filterText) {
    $where[] = "(title LIKE ? OR message LIKE ?)";
    $like = "%$filterText%";
    $params[] = $like;
    $params[] = $like;
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY created_at DESC LIMIT 200";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$counts = [
    'unread' => 0,
    'warning' => 0,
    'critical' => 0,
    'info' => 0,
];

try {
    $counts['unread'] = (int)($db->query("SELECT COUNT(*) c FROM system_alerts WHERE is_read = 0")->fetch()['c'] ?? 0);
    $counts['warning'] = (int)($db->query("SELECT COUNT(*) c FROM system_alerts WHERE is_read = 0 AND level='warning'")->fetch()['c'] ?? 0);
    $counts['critical'] = (int)($db->query("SELECT COUNT(*) c FROM system_alerts WHERE is_read = 0 AND level='critical'")->fetch()['c'] ?? 0);
    $counts['info'] = (int)($db->query("SELECT COUNT(*) c FROM system_alerts WHERE is_read = 0 AND level='info'")->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    // ignore
}

// Fetch Login Activity
$login_activities = [];
try {
    $login_activities = $db->query("SELECT id, user_id, username, role AS user_role, login_time, logout_time, ip AS ip_address, browser, device, os FROM login_activity ORDER BY login_time DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) {}

// Fetch Failed Logins
$failed_logins = [];
try {
    $failedSql = "SELECT MIN(id) AS id, attempted_username, ip AS ip_address, user_agent, COUNT(*) AS attempt_count, MAX(attempt_time) AS attempt_time
                  FROM failed_logins";
    $failedParams = [];
    if ($failedSearch !== '') {
        $failedSql .= " WHERE attempted_username LIKE ? OR ip LIKE ? OR user_agent LIKE ?";
        $like = "%$failedSearch%";
        $failedParams = [$like, $like, $like];
    }
    $failedSql .= " GROUP BY attempted_username, ip, user_agent ORDER BY attempt_time DESC LIMIT 100";
    $stmt = $db->prepare($failedSql);
    $stmt->execute($failedParams);
    $failed_logins = $stmt->fetchAll();
} catch (Throwable $e) {}

// Fetch Online Users
$online_users = [];
try {
    $online_users = $db->query("SELECT id, user_id, username, role AS user_role, ip_address, browser, device, os, login_time, last_activity, status FROM online_users WHERE status = 'active' ORDER BY last_activity DESC")->fetchAll();
} catch (Throwable $e) {}

// Fetch Suspicious Activities
$suspicious_activities = [];
try {
    // Migration defines detected_at; keep backward compatibility with older datasets that may use created_at
    $suspicious_activities = $db->query("SELECT id, user_id, username, activity_type, severity, details, ip_address, device_info, detected_at, created_at FROM suspicious_activities ORDER BY COALESCE(detected_at, created_at) DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) {}


// Fetch Activity Logs
$activity_logs = [];
try {
    $activity_logs = $db->query("SELECT id, user_id, username, role AS user_role, module, action, entity_type, entity_id, details, ip_address, created_at FROM module_activity_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) {}

// Fetch Loan Approval Logs
$loan_approvals = [];
try {
    $loan_approvals = $db->query("SELECT id, loan_id, loan_no, member_id, approving_officer_name, stage, action, status_before, status_after, comments, approval_date FROM loan_approval_logs ORDER BY approval_date DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) {}

function levelBadge(string $level): string {
    return match($level) {
        'critical' => 'badge-danger',
        'warning'  => 'badge-warning',
        default    => 'badge-secondary',
    };
}

require_once __DIR__ . '/../includes/header.php';

// Display flash messages
if ($msg = flashMessage('success')) {
    echo '<div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="ti ti-check me-2"></i>' . escape($msg) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}
if ($msg = flashMessage('error')) {
    echo '<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="ti ti-alert-circle me-2"></i>' . escape($msg) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

// Determine active tab
$activeTab = $_GET['tab'] ?? 'alerts';
?>

<!-- Tab Navigation -->
<?php
// Safety defaults to avoid undefined array keys if counts queries fail
$counts = $counts ?? ['unread'=>0,'critical'=>0,'warning'=>0,'info'=>0];
?>

<div class="row g-3 mb-4">

    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0"><?= count($login_activities) ?></h5>
                <small class="text-muted">Login Records</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0 text-danger"><?= count($failed_logins) ?></h5>
                <small class="text-muted">Failed Logins</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0 text-success"><?= count($online_users) ?></h5>
                <small class="text-muted">Online Users</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0 text-warning"><?= count($suspicious_activities) ?></h5>
                <small class="text-muted">Suspicious Activity</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0"><?= count($activity_logs) ?></h5>
                <small class="text-muted">Activity Logs</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center">
            <div class="card-body py-3">
                <h5 class="mb-0"><?= count($loan_approvals) ?></h5>
                <small class="text-muted">Loan Approvals</small>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'alerts' ? 'active' : '' ?>" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#alerts-pane" type="button" role="tab">
            <i class="ti ti-bell me-2"></i>Security Alerts
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'logins' ? 'active' : '' ?>" id="logins-tab" data-bs-toggle="tab" data-bs-target="#logins-pane" type="button" role="tab">
            <i class="ti ti-login me-2"></i>Login Activity
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'failed' ? 'active' : '' ?>" id="failed-tab" data-bs-toggle="tab" data-bs-target="#failed-pane" type="button" role="tab">
            <i class="ti ti-lock-exclamation me-2"></i>Failed Logins
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'online' ? 'active' : '' ?>" id="online-tab" data-bs-toggle="tab" data-bs-target="#online-pane" type="button" role="tab">
            <i class="ti ti-users-group me-2"></i>Online Users
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'suspicious' ? 'active' : '' ?>" id="suspicious-tab" data-bs-toggle="tab" data-bs-target="#suspicious-pane" type="button" role="tab">
            <i class="ti ti-alert-triangle me-2"></i>Suspicious Activity
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity-pane" type="button" role="tab">
            <i class="ti ti-history me-2"></i>Activity Logs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'loans' ? 'active' : '' ?>" id="loans-tab" data-bs-toggle="tab" data-bs-target="#loans-pane" type="button" role="tab">
            <i class="ti ti-check-circle me-2"></i>Loan Approvals
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Alerts Tab -->
    <div class="tab-pane fade <?= $activeTab === 'alerts' ? 'show active' : '' ?>" id="alerts-pane" role="tabpanel">

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Unread Alerts</div>
            <div class="stat-value"><?= (int)$counts['unread'] ?></div>
            <div class="stat-sub">Total unread system alerts</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Critical</div>
            <div class="stat-value"><?= (int)$counts['critical'] ?></div>
            <div class="stat-sub">Needs immediate attention</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Warning</div>
            <div class="stat-value"><?= (int)$counts['warning'] ?></div>
            <div class="stat-sub">Potential risk detected</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Info</div>
            <div class="stat-value"><?= (int)$counts['info'] ?></div>
            <div class="stat-sub">Informational security events</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>System Security Alerts</strong>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/pages/security_alerts.php?export=csv" class="btn btn-sm btn-outline-secondary">Export CSV</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Level</label>
                <select name="level" class="form-select form-select-sm">
                    <option value="">All levels</option>
                    <option value="critical" <?= $filterLevel==='critical'?'selected':'' ?>>Critical</option>
                    <option value="warning" <?= $filterLevel==='warning'?'selected':'' ?>>Warning</option>
                    <option value="info" <?= $filterLevel==='info'?'selected':'' ?>>Info</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" name="q" value="<?= escape($filterText) ?>" class="form-control form-control-sm" placeholder="Search title/message"/>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary btn-sm">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Level</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Created At</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$alerts): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No security alerts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($alerts as $i => $a): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><span class="badge <?= levelBadge($a['level']) ?>"><?= escape($a['level']) ?></span></td>
                            <td style="max-width:220px;"><?= escape($a['title']) ?></td>
                            <td style="max-width:520px;"><?= escape($a['message']) ?></td>
                            <td style="white-space:nowrap;"><?= $a['created_at'] ?></td>
                            <td>
                                <?php if ((int)$a['is_read'] === 1): ?>
                                    <span class="badge bg-success">Read</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Unread</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$a['is_read'] === 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Mark this alert as read?')">
                                        <input type="hidden" name="action" value="mark_read"/>
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"/>
                                        <button class="btn btn-sm btn-outline-success">Mark read</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

    </div>

    <!-- Login Activity Tab -->
    <div class="tab-pane fade <?= $activeTab === 'logins' ? 'show active' : '' ?>" id="logins-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <strong>Login Activity History</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Login Time</th>
                                <th>Logout Time</th>
                                <th>IP Address</th>
                                <th>Browser</th>
                                <th>Device</th>
                                <th>OS</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($login_activities)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No login activity records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($login_activities as $log): ?>
                                <tr>
                                    <td><?= escape($log['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= escape($log['user_role']) ?></span></td>
                                    <td><?= $log['login_time'] ?></td>
                                    <td><?= $log['logout_time'] ?? 'Still logged in' ?></td>
                                    <td><code><?= escape($log['ip_address']) ?></code></td>
                                    <td><?= escape($log['browser'] ?? 'Unknown') ?></td>
                                    <td><?= escape($log['device'] ?? 'Unknown') ?></td>
                                    <td><?= escape($log['os'] ?? 'Unknown') ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                            <input type="hidden" name="action" value="delete_login_activity"/>
                                            <input type="hidden" name="id" value="<?= $log['id'] ?>"/>
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="ti ti-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Failed Logins Tab -->
    <div class="tab-pane fade <?= $activeTab === 'failed' ? 'show active' : '' ?>" id="failed-pane" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Failed Login Attempts (Last 100 Records)</strong>
                <a href="<?= APP_URL ?>/pages/security_alerts.php?tab=failed&export=failed_logins" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-download"></i> Export CSV
                </a>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="tab" value="failed"/>
                    <div class="col-md-6">
                        <label class="form-label">Search Username/IP</label>
                        <input type="text" name="search" value="<?= escape($failedSearch) ?>" class="form-control form-control-sm" placeholder="Search..."/>
                    </div>
                    <div class="col-md-6 d-grid">
                        <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>Attempted Username</th>
                                <th>IP Address</th>
                                <th>Attempt Count</th>
                                <th>Last Attempt</th>
                                <th>User Agent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($failed_logins)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No failed login attempts found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($failed_logins as $fail): ?>
                                <tr class="<?= ($fail['attempt_count'] >= 5) ? 'table-danger' : '' ?>">
                                    <td><strong><?= escape($fail['attempted_username']) ?></strong></td>
                                    <td><code><?= escape($fail['ip_address']) ?></code></td>
                                    <td>
                                        <?php if ($fail['attempt_count'] >= 5): ?>
                                            <span class="badge bg-danger"><?= $fail['attempt_count'] ?> (LOCKED)</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><?= $fail['attempt_count'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $fail['attempt_time'] ?></td>
                                    <td><small><?= escape(substr($fail['user_agent'], 0, 50)) ?>...</small></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                            <input type="hidden" name="action" value="delete_failed_login"/>
                                            <input type="hidden" name="id" value="<?= $fail['id'] ?>"/>
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="ti ti-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Online Users Tab -->
    <div class="tab-pane fade <?= $activeTab === 'online' ? 'show active' : '' ?>" id="online-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <strong>Currently Online Users (<?= count($online_users) ?>)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Browser</th>
                                <th>Device</th>
                                <th>OS</th>
                                <th>Login Time</th>
                                <th>Last Activity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($online_users)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No users currently online.</td></tr>
                        <?php else: ?>
                            <?php foreach ($online_users as $user): ?>
                                <tr>
                                    <td><strong><?= escape($user['username']) ?></strong></td>
                                    <td><span class="badge bg-info"><?= escape($user['user_role']) ?></span></td>
                                    <td><code><?= escape($user['ip_address']) ?></code></td>
                                    <td><?= escape($user['browser'] ?? 'Unknown') ?></td>
                                    <td><?= escape($user['device'] ?? 'Unknown') ?></td>
                                    <td><?= escape($user['os'] ?? 'Unknown') ?></td>
                                    <td><?= $user['login_time'] ?></td>
                                    <td><?= $user['last_activity'] ?></td>
                                    <td><span class="badge bg-success"><?= escape($user['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Suspicious Activity Tab -->
    <div class="tab-pane fade <?= $activeTab === 'suspicious' ? 'show active' : '' ?>" id="suspicious-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <strong>Suspicious Activities Detected</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Activity Type</th>
                                <th>Severity</th>
                                <th>Details</th>
                                <th>IP Address</th>
                                <th>Detected At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($suspicious_activities)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No suspicious activities detected.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suspicious_activities as $sus): ?>
                                <tr class="<?= ($sus['severity'] === 'critical') ? 'table-danger' : (($sus['severity'] === 'high') ? 'table-warning' : '') ?>">
                                    <td><strong><?= escape($sus['username']) ?></strong></td>
                                    <td><?= escape($sus['activity_type']) ?></td>
                                    <td>
                                        <?php 
                                            $severityBadge = match($sus['severity']) {
                                                'critical' => 'bg-danger',
                                                'high' => 'bg-warning text-dark',
                                                'medium' => 'bg-info',
                                                default => 'bg-secondary'
                                            };
                                        ?>
                                        <span class="badge <?= $severityBadge ?>"><?= escape($sus['severity']) ?></span>
                                    </td>
                                    <td><?= escape($sus['details']) ?></td>
                                    <td><code><?= escape($sus['ip_address']) ?></code></td>
                                    <td><?php
                                        // Migration uses detected_at, but keep fallback for older datasets
                                        echo escape($sus['detected_at'] ?? $sus['created_at'] ?? '—');
                                    ?></td>

                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="acknowledge_suspicious"/>
                                                <input type="hidden" name="id" value="<?= $sus['id'] ?>"/>
                                                <button type="submit" class="btn btn-outline-success" title="Mark as acknowledged">
                                                    <i class="ti ti-check"></i> Acknowledge
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                                <input type="hidden" name="action" value="delete_suspicious"/>
                                                <input type="hidden" name="id" value="<?= $sus['id'] ?>"/>
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Logs Tab -->
    <div class="tab-pane fade <?= $activeTab === 'activity' ? 'show active' : '' ?>" id="activity-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <strong>System Activity Logs</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Details</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activity_logs)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No activity logs found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($activity_logs as $log): ?>
                                <tr>
                                    <td><?= escape($log['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= escape($log['user_role']) ?></span></td>
                                    <td><?= escape($log['module']) ?></td>
                                    <td><span class="badge bg-primary"><?= escape($log['action']) ?></span></td>
                                    <td><?= escape($log['entity_type']) ?> #<?= $log['entity_id'] ?></td>
                                    <td><?= escape(substr($log['details'], 0, 50)) ?></td>
                                    <td><code><?= escape($log['ip_address']) ?></code></td>
                                    <td><?= $log['created_at'] ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="review_activity"/>
                                                <input type="hidden" name="id" value="<?= $log['id'] ?>"/>
                                                <button type="submit" class="btn btn-outline-info" title="Mark as reviewed">
                                                    <i class="ti ti-eye-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                                <input type="hidden" name="action" value="delete_activity"/>
                                                <input type="hidden" name="id" value="<?= $log['id'] ?>"/>
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Loan Approvals Tab -->
    <div class="tab-pane fade <?= $activeTab === 'loans' ? 'show active' : '' ?>" id="loans-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <strong>Loan Approval Audit Trail</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-sm">
                        <thead>
                            <tr>
                                <th>Loan No</th>
                                <th>Member ID</th>
                                <th>Officer</th>
                                <th>Stage</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Comments</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($loan_approvals)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No loan approvals found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($loan_approvals as $approval): ?>
                                <tr>
                                    <td><strong><?= escape($approval['loan_no']) ?></strong></td>
                                    <td><?= $approval['member_id'] ?></td>
                                    <td><?= escape($approval['approving_officer_name']) ?></td>
                                    <td><?= escape($approval['stage']) ?></td>
                                    <td><span class="badge bg-primary"><?= escape($approval['action']) ?></span></td>
                                    <td>
                                        <small><?= escape($approval['status_before']) ?> → <?= escape($approval['status_after']) ?></small>
                                    </td>
                                    <td><?= escape(substr($approval['comments'], 0, 40)) ?></td>
                                    <td><?= $approval['approval_date'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
    </div>
</div>

