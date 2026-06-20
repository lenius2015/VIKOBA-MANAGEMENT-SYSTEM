<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireRole(['admin']);
$pageTitle = 'System Logs';

$db = Database::getInstance()->getConnection();

try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

$filterUser = trim($_GET['user'] ?? '');

// Handle session termination
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'terminate_session') {
  $sid = $_POST['session_id'] ?? null;
  if ($sid) {
    $del = $db->prepare("DELETE FROM sessions_monitor WHERE session_id = ?");
    $del->execute([$sid]);
    $db->prepare("UPDATE login_activity SET logout_time = NOW() WHERE session_id = ? AND logout_time IS NULL")->execute([$sid]);
    if ($audit) $audit->logActivity($_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'system', 'terminate_session', 'Terminated session ' . $sid);
    setFlash('success', 'Session terminated.');
  }
  redirect(APP_URL . '/pages/system_logs.php');
}

// Export CSV if requested
if (isset($_GET['export'])) {
  $type = $_GET['export'];
  if ($type === 'logins') {
    $q = "SELECT la.*, u.name as user_name FROM login_activity la LEFT JOIN users u ON la.user_id = u.id ORDER BY la.login_time DESC";
    $rows = $db->query($q)->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="login_activity.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','user_name','username','role','ip','device','browser','os','login_time','logout_time','status','session_id']);
    foreach ($rows as $r) fputcsv($out, [$r['id'],$r['user_name'],$r['username'],$r['role'],$r['ip'],$r['device'],$r['browser'],$r['os'],$r['login_time'],$r['logout_time'],$r['status'],$r['session_id']]);
    fclose($out);
    exit;
  }
  if ($type === 'activities') {
    $q = "SELECT * FROM activity_logs ORDER BY created_at DESC";
    $rows = $db->query($q)->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_logs.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','user_id','username','role','module','action','details','ip','created_at']);
    foreach ($rows as $r) fputcsv($out, [$r['id'],$r['user_id'],$r['username'],$r['role'],$r['module'],$r['action'],$r['details'],$r['ip'],$r['created_at']]);
    fclose($out);
    exit;
  }
}

// Login activity
$loginSql = "SELECT la.*, u.name AS user_name FROM login_activity la LEFT JOIN users u ON la.user_id = u.id";
if ($filterUser) {
    $loginSql .= " WHERE la.username LIKE :fu OR u.name LIKE :fu";
}
$loginSql .= " ORDER BY la.login_time DESC LIMIT 200";
$stmt = $db->prepare($loginSql);
if ($filterUser) $stmt->execute([':fu' => "%$filterUser%"]); else $stmt->execute();
$logins = $stmt->fetchAll();

// Activity logs
$actSql = "SELECT al.* FROM activity_logs al";
if ($filterUser) {
    $actSql .= " WHERE al.username LIKE :fu";
}
$actSql .= " ORDER BY al.created_at DESC LIMIT 200";
$stmt2 = $db->prepare($actSql);
if ($filterUser) $stmt2->execute([':fu' => "%$filterUser%"]); else $stmt2->execute();
$acts = $stmt2->fetchAll();

// Counts for display (security overview)
try {
  $countLogin = $db->query("SELECT COUNT(*) AS c FROM login_activity")->fetch()['c'] ?? 0;
} catch (Throwable $e) { $countLogin = 'n/a'; }
try {
  $countActs = $db->query("SELECT COUNT(*) AS c FROM activity_logs")->fetch()['c'] ?? 0;
} catch (Throwable $e) { $countActs = 'n/a'; }
try {
  $countTrail = $db->query("SELECT COUNT(*) AS c FROM audit_trail")->fetch()['c'] ?? 0;
} catch (Throwable $e) { $countTrail = 'n/a'; }

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>System Logs</strong>
    <form method="GET" class="d-flex gap-2 align-items-center">
      <input name="user" value="<?= escape($filterUser) ?>" placeholder="Filter by username" class="form-control form-control-sm"/>
      <button class="btn btn-sm btn-outline-primary">Filter</button>
      <a href="?export=logins" class="btn btn-sm btn-outline-secondary">Export Logins</a>
      <a href="?export=activities" class="btn btn-sm btn-outline-secondary">Export Activities</a>
    </form>
  </div>
    <div class="card-body">
    <div class="mb-2" id="security-overview">
      <span class="me-3">Security overview:</span>
      <span class="badge bg-secondary" id="ov-logins">Logins: <?= is_numeric($countLogin) ? number_format($countLogin) : escape($countLogin) ?></span>
      <span class="badge bg-secondary ms-2" id="ov-acts">Activities: <?= is_numeric($countActs) ? number_format($countActs) : escape($countActs) ?></span>
      <span class="badge bg-secondary ms-2" id="ov-trail">Field changes: <?= is_numeric($countTrail) ? number_format($countTrail) : escape($countTrail) ?></span>
    </div>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Security Events</strong>
        <div class="btn-group btn-group-sm" role="group">
          <button type="button" class="btn btn-outline-primary" id="refresh-security-overview">Refresh</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#recent-security-panel" aria-expanded="true" aria-controls="recent-security-panel">Toggle</button>
        </div>
      </div>
      <div class="collapse show" id="recent-security-panel">
        <div class="card-body">
          <div id="recent-activities-panel">
            <p class="text-muted mb-0">Loading latest activity logs...</p>
          </div>
          <div class="mt-4">
            <h6>Recent Login Events</h6>
            <div id="recent-logins-panel">
              <p class="text-muted mb-0">Loading latest login events...</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    <ul class="nav nav-tabs" id="logsTabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-logins">Login Activity <span class="badge bg-light text-dark ms-1" id="tab-count-logins"><?= is_numeric($countLogin) ? number_format($countLogin) : escape($countLogin) ?></span></button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-acts">Activity Logs <span class="badge bg-light text-dark ms-1" id="tab-count-acts"><?= is_numeric($countActs) ? number_format($countActs) : escape($countActs) ?></span></button></li>
    </ul>
    <div class="tab-content mt-3">
      <div class="tab-pane fade show active" id="tab-logins">
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead><tr><th>#</th><th>User</th><th>Username</th><th>Role</th><th>IP</th><th>Device</th><th>Browser/OS</th><th>Login Time</th><th>Logout Time</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($logins as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= escape($r['user_name'] ?? '') ?></td>
                <td><?= escape($r['username']) ?></td>
                <td><?= escape($r['role']) ?></td>
                <td><?= escape($r['ip']) ?></td>
                <td><?= escape($r['device']) ?></td>
                <td><?= escape(($r['browser'] ?? '') . ' / ' . ($r['os'] ?? '')) ?></td>
                <td><?= $r['login_time'] ?></td>
                <td><?= $r['logout_time'] ?? '—' ?></td>
                <td><?= escape($r['status']) ?></td>
                <td>
                  <?php if ($r['session_id']): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Terminate this session?')">
                      <input type="hidden" name="action" value="terminate_session"/>
                      <input type="hidden" name="session_id" value="<?= escape($r['session_id']) ?>"/>
                      <button class="btn btn-sm btn-outline-danger">Terminate</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="tab-pane fade" id="tab-acts">
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead><tr><th>#</th><th>User</th><th>Role</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($acts as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= escape($r['username']) ?></td>
                <td><?= escape($r['role']) ?></td>
                <td><?= escape($r['module']) ?></td>
                <td><?= escape($r['action']) ?></td>
                <td><?= escape($r['details']) ?></td>
                <td><?= escape($r['ip']) ?></td>
                <td><?= $r['created_at'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Ensure admin-only overview is visible by fetching server counts
document.addEventListener('DOMContentLoaded', function(){
  fetch('api/admin_security_overview.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      const c = data.counts || {};
      function setText(id, prefix, val){
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = prefix + ' ' + (typeof val === 'number' ? val.toLocaleString() : val);
      }
      setText('ov-logins','Logins:', c.login_activity ?? 'n/a');
      setText('ov-acts','Activities:', c.activity_logs ?? 'n/a');
      setText('ov-trail','Field changes:', c.audit_trail ?? 'n/a');
      setText('tab-count-logins','', c.login_activity ?? 'n/a');
      setText('tab-count-acts','', c.activity_logs ?? 'n/a');

      renderRecentActivities(data.recent_activities || []);
      renderRecentLogins(data.recent_logins || []);
    }).catch(()=>{});

  const refreshButton = document.getElementById('refresh-security-overview');
  if (refreshButton) {
    refreshButton.addEventListener('click', function(){
      refreshButton.disabled = true;
      refreshButton.textContent = 'Refreshing…';
      loadSecurityOverview().finally(() => {
        refreshButton.disabled = false;
        refreshButton.textContent = 'Refresh';
      });
    });
  }
});

function loadSecurityOverview() {
  return fetch('api/admin_security_overview.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      const c = data.counts || {};
      setText('ov-logins','Logins:', c.login_activity ?? 'n/a');
      setText('ov-acts','Activities:', c.activity_logs ?? 'n/a');
      setText('ov-trail','Field changes:', c.audit_trail ?? 'n/a');
      setText('tab-count-logins','', c.login_activity ?? 'n/a');
      setText('tab-count-acts','', c.activity_logs ?? 'n/a');
      renderRecentActivities(data.recent_activities || []);
      renderRecentLogins(data.recent_logins || []);
    });
}

function renderRecentActivities(entries) {
  const panel = document.getElementById('recent-activities-panel');
  if (!panel) return;
  if (!Array.isArray(entries) || entries.length === 0) {
    panel.innerHTML = '<p class="text-muted mb-0">No recent activity logs found.</p>';
    return;
  }
  const rows = entries.map((item, idx) => {
    return `<tr>
      <td>${idx + 1}</td>
      <td>${escapeHtml(item.username || 'system')}</td>
      <td>${escapeHtml(item.role || '')}</td>
      <td>${escapeHtml(item.module)}</td>
      <td>${escapeHtml(item.action)}</td>
      <td>${escapeHtml(item.details || '')}</td>
      <td>${escapeHtml(item.ip || '')}</td>
      <td>${escapeHtml(item.created_at)}</td>
    </tr>`;
  }).join('');
  panel.innerHTML = `
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr><th>#</th><th>User</th><th>Role</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function renderRecentLogins(entries) {
  const panel = document.getElementById('recent-logins-panel');
  if (!panel) return;
  if (!Array.isArray(entries) || entries.length === 0) {
    panel.innerHTML = '<p class="text-muted mb-0">No recent login events found.</p>';
    return;
  }
  const rows = entries.map((item, idx) => {
    return `<tr>
      <td>${idx + 1}</td>
      <td>${escapeHtml(item.user_name || item.username || 'system')}</td>
      <td>${escapeHtml(item.username || '')}</td>
      <td>${escapeHtml(item.role || '')}</td>
      <td>${escapeHtml(item.ip || '')}</td>
      <td>${escapeHtml(item.device || '')}</td>
      <td>${escapeHtml((item.browser || '') + ' / ' + (item.os || ''))}</td>
      <td>${escapeHtml(item.status || '')}</td>
      <td>${escapeHtml(item.login_time || '')}</td>
    </tr>`;
  }).join('');
  panel.innerHTML = `
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr><th>#</th><th>User</th><th>Username</th><th>Role</th><th>IP</th><th>Device</th><th>Browser/OS</th><th>Status</th><th>Login Time</th></tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function setText(id, prefix, val){
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = prefix + ' ' + (typeof val === 'number' ? val.toLocaleString() : val);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
</script>
