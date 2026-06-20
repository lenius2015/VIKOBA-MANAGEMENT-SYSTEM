<?php
// includes/header.php
// Requires: $pageTitle (string), $auth->getUser()
$user = $auth->getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$role = $user['role'] ?? '';
$userId = $user['id'] ?? 0;

// Touch session monitor for online user tracking
try {
  require_once __DIR__ . '/../classes/Audit.php';
  if (session_status() === PHP_SESSION_ACTIVE && $userId) {
    $auditMonitor = new Audit();
    $auditMonitor->touchSession(session_id(), $userId, $user['name']);
  }
} catch (Throwable $e) { /* ignore */ }

// Get database connection for sidebar queries
$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {}

// Get notification and message counts if logged in
$notifCount = 0;
$msgCount = 0;
$recentNotifs = [];
if ($userId) {
    try {
        $notif = new Notification();
        $notifCount = $notif->unreadCount($userId);
        $recentNotifs = $notif->getUnread($userId, 5);
        $msg = new Message();
        $msgCount = $msg->unreadCount($userId);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= escape($pageTitle ?? APP_NAME) ?> — <?= APP_NAME ?></title>
  <!-- Faster font loading -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <meta name="theme-color" content="#185FA5">
  <link rel="icon" href="<?= APP_URL ?>/public/images/favicon.svg" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css"/>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css?v=2.0"/>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <i class="ti ti-building-bank fs-4 me-2 icon-primary"></i>
    <div>
      <div class="brand-name">VIKOBA</div>
      <div class="brand-sub">Management System</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php if ($role === 'member'): ?>
      <!-- MEMBER NAVIGATION -->
      <div class="nav-section">My Dashboard</div>
      <a href="<?= APP_URL ?>/pages/member_dashboard.php" class="nav-link <?= $currentPage==='member_dashboard'?'active':'' ?>">
        <i class="ti ti-dashboard"></i> My Overview
      </a>
      <a href="<?= APP_URL ?>/pages/member_contributions.php" class="nav-link <?= $currentPage==='member_contributions'?'active':'' ?>">
        <i class="ti ti-coin"></i> My Contributions
      </a>
      <a href="<?= APP_URL ?>/pages/member_loans.php" class="nav-link <?= $currentPage==='member_loans'?'active':'' ?>">
        <i class="ti ti-credit-card"></i> My Loans
      </a>
      <a href="<?= APP_URL ?>/pages/member_fines.php" class="nav-link <?= $currentPage==='member_fines'?'active':'' ?>">
        <i class="ti ti-alert-triangle"></i> My Fines
      </a>
      <a href="<?= APP_URL ?>/pages/member_payments.php" class="nav-link <?= $currentPage==='member_payments'?'active':'' ?>">
        <i class="ti ti-cash"></i> Make Payment
      </a>
      <a href="<?= APP_URL ?>/pages/member_profile.php" class="nav-link <?= $currentPage==='member_profile'?'active':'' ?>">
        <i class="ti ti-user"></i> My Profile
      </a>

      <div class="nav-section">Group</div>
      <a href="<?= APP_URL ?>/pages/my_groups.php" class="nav-link <?= in_array($currentPage, ['my_groups','group_info'])?'active':'' ?>">
        <i class="ti ti-users"></i> My Groups
      </a>
      <a href="<?= APP_URL ?>/pages/group_info_center.php" class="nav-link <?= $currentPage==='group_info_center'?'active':'' ?>">
        <i class="ti ti-info-circle"></i> Group Information Center
      </a>
      <?php
      $memberId = $user['member_id'] ?? null;
      if ($memberId) {
        try {
          $groupModel = new Group();
          $userGroups = $groupModel->getByMemberId($memberId);
        } catch (Throwable $e) {
          $userGroups = [];
        }
      } else {
        $userGroups = [];
      }
      if (!empty($userGroups)): ?>
        <div class="nav-text text-muted ps-4 fs-11 mt-1"><?= escape($userGroups[0]['name']) ?></div>
      <?php endif; ?>

      <div class="nav-section">Communication</div>
      <a href="<?= APP_URL ?>/pages/notifications.php" class="nav-link <?= $currentPage==='notifications'?'active':'' ?>">
        <i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge bg-danger ms-auto"><?= $notifCount ?></span><?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/pages/messages.php" class="nav-link <?= $currentPage==='messages'?'active':'' ?>">
        <i class="ti ti-messages"></i> Messages
        <?php if ($msgCount > 0): ?><span class="badge bg-danger ms-auto"><?= $msgCount ?></span><?php endif; ?>
      </a>

    <?php else: ?>
      <!-- ADMIN / TREASURER NAVIGATION -->
      <div class="nav-section">Main Menu</div>
      <a href="<?= APP_URL ?>/pages/dashboard.php" class="nav-link <?= $currentPage==='dashboard'?'active':'' ?>">
        <i class="ti ti-dashboard"></i> Dashboard
      </a>
      <a href="<?= APP_URL ?>/pages/members.php" class="nav-link <?= $currentPage==='members'?'active':'' ?>">
        <i class="ti ti-users"></i> Members
      </a>
      <a href="<?= APP_URL ?>/pages/contributions.php" class="nav-link <?= $currentPage==='contributions'?'active':'' ?>">
        <i class="ti ti-coin"></i> Contributions
      </a>
      <a href="<?= APP_URL ?>/pages/loans.php" class="nav-link <?= $currentPage==='loans'?'active':'' ?>">
        <i class="ti ti-credit-card"></i> Loans
      </a>
      <?php
      // Get pending approval count for badge
      $pendingApprovalCount = 0;
      try {
        $tmpLoan = new Loan();
        $pendingCounts = $tmpLoan->getPendingCountsByLevel();
        $pendingApprovalCount = array_sum($pendingCounts['counts'] ?? []);
      } catch (Throwable $e) {}
      ?>
      <a href="<?= APP_URL ?>/pages/approval_queue.php" class="nav-link <?= $currentPage==='approval_queue'?'active':'' ?>">
        <i class="ti ti-file-check"></i> Approval Queue
        <?php if ($pendingApprovalCount > 0): ?><span class="badge bg-danger ms-auto"><?= $pendingApprovalCount ?></span><?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/pages/loan_products.php" class="nav-link <?= $currentPage==='loan_products'?'active':'' ?>">
        <i class="ti ti-package"></i> Loan Products
      </a>
      <a href="<?= APP_URL ?>/pages/fines.php" class="nav-link <?= $currentPage==='fines'?'active':'' ?>">
        <i class="ti ti-alert-triangle"></i> Fines
      </a>
      <?php
      // Get pending payments count for badge
      $pendingPaymentsCount = 0;
      if ($db) {
        try {
          $stmt = $db->query("SELECT COUNT(*) FROM repayments WHERE status = 'pending'");
          $pendingPaymentsCount = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
      }
      ?>
      <a href="<?= APP_URL ?>/pages/pending_payments.php" class="nav-link <?= $currentPage==='pending_payments'?'active':'' ?>">
        <i class="ti ti-list-check"></i> Pending Payments
        <?php if ($pendingPaymentsCount > 0): ?><span class="badge bg-warning text-dark ms-auto"><?= $pendingPaymentsCount ?></span><?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/pages/member_payments.php" class="nav-link <?= $currentPage==='member_payments'?'active':'' ?>">
        <i class="ti ti-cash"></i> Make Payment
      </a>

      <?php if (in_array($role, ['admin','treasurer'])): ?>
      <div class="nav-section">Communication</div>
      <a href="<?= APP_URL ?>/pages/notifications.php" class="nav-link <?= $currentPage==='notifications'?'active':'' ?>">
        <i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge bg-danger ms-auto"><?= $notifCount ?></span><?php endif; ?>
      </a>
      <a href="<?= APP_URL ?>/pages/messages.php" class="nav-link <?= $currentPage==='messages'?'active':'' ?>">
        <i class="ti ti-messages"></i> Messages
        <?php if ($msgCount > 0): ?><span class="badge bg-danger ms-auto"><?= $msgCount ?></span><?php endif; ?>
      </a>
      <?php endif; ?>

      <?php if (in_array($role, ['admin','treasurer'])): ?>
      <div class="nav-section">Management</div>
      <a href="<?= APP_URL ?>/pages/reports.php" class="nav-link <?= $currentPage==='reports'?'active':'' ?>">
        <i class="ti ti-chart-bar"></i> Reports
      </a>
      <a href="<?= APP_URL ?>/pages/groups.php" class="nav-link <?= $currentPage==='groups'?'active':'' ?>">
        <i class="ti ti-users"></i> Groups
      </a>
      <a href="<?= APP_URL ?>/pages/group_info_center.php" class="nav-link <?= $currentPage==='group_info_center'?'active':'' ?>">
        <i class="ti ti-info-circle"></i> Group Information Center
      </a>
      <a href="<?= APP_URL ?>/pages/group_admin.php" class="nav-link <?= $currentPage==='group_admin'?'active':'' ?>">
        <i class="ti ti-settings"></i> Group Admin
      </a>
      <?php endif; ?>

      <?php if ($role === 'admin'): ?>
      <a href="<?= APP_URL ?>/pages/security_alerts.php" class="nav-link <?= $currentPage==='security_alerts'?'active':'' ?>">
        <i class="ti ti-shield-alert"></i> Security Alerts
      </a>
      <a href="<?= APP_URL ?>/pages/users.php" class="nav-link <?= $currentPage==='users'?'active':'' ?>">
        <i class="ti ti-settings"></i> Users
      </a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="d-flex align-items-center gap-2">
      <?php if (!empty($user['profile_picture'])): ?>
        <img src="<?= APP_URL ?>/uploads/<?= escape($user['profile_picture']) ?>" alt="<?= escape($user['name']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;" />
      <?php else: ?>
        <img src="<?= APP_URL ?>/public/images/default-avatar.svg" alt="Default avatar" style="width:40px;height:40px;border-radius:50%;object-fit:cover;" />
      <?php endif; ?>
      <div>
        <div class="fw-500 fs-13"><?= escape($user['name']) ?></div>
        <div class="text-muted fs-11">
          <?php if ($role === 'admin'): ?><span class="badge badge-primary">Admin</span>
          <?php elseif ($role === 'treasurer'): ?><span class="badge badge-success">Treasurer</span>
          <?php else: ?><span class="badge badge-warning">Member</span><?php endif; ?>
        </div>
      </div>
    </div>
    <a href="<?= APP_URL ?>/pages/logout.php" class="btn btn-sm btn-outline-secondary mt-2 w-100">
      <i class="ti ti-logout me-1"></i> Logout
    </a>
  </div>
</div>

<!-- Main -->
<div class="main-content">
  <!-- Topbar -->
  <div class="topbar">
    <button class="btn btn-sm sidebar-toggle d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')">
      <i class="ti ti-menu-2"></i>
    </button>
    <h5 class="mb-0"><?= escape($pageTitle ?? '') ?></h5>
    <div class="d-flex align-items-center gap-2">
      <!-- Notifications Bell -->
      <div class="dropdown" id="notifDropdown">
        <button class="btn btn-sm btn-icon position-relative" data-bs-toggle="dropdown" aria-expanded="false" style="background:transparent;border:none;font-size:22px;color:#555;">
          <i class="ti ti-bell icon-muted"></i>
          <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:9px;<?= $notifCount ? '' : 'display:none' ?>">
            <?= $notifCount ?>
          </span>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown" style="width:340px;padding:0;">
          <div class="dropdown-header d-flex justify-content-between align-items-center py-2 px-3" style="background:#f8f8f6;border-radius:8px 8px 0 0;">
            <strong>Notifications</strong>
            <a href="<?= APP_URL ?>/pages/notifications.php" class="fs-12">View All</a>
          </div>
          <div id="notifList" style="max-height:300px;overflow-y:auto;">
            <?php if ($recentNotifs): ?>
              <?php foreach ($recentNotifs as $n): ?>
                <a href="<?= $n['link'] ?: APP_URL . '/pages/notifications.php?read=' . $n['id'] ?>" class="dropdown-item notif-item px-3 py-2" style="border-bottom:1px solid #f0f0e8;white-space:normal;">
                  <div class="d-flex gap-2">
                    <div class="notif-icon mt-1">
                      <?php
                      $iconName = 'info-circle';
                      if ($n['type'] === 'loan_approved') $iconName = 'circle-check';
                      elseif ($n['type'] === 'loan_rejected') $iconName = 'circle-x';
                      elseif ($n['type'] === 'loan_applied') $iconName = 'credit-card';
                      elseif ($n['type'] === 'contribution') $iconName = 'coins';
                      elseif ($n['type'] === 'fine_issued') $iconName = 'alert-triangle';
                      elseif ($n['type'] === 'repayment') $iconName = 'cash';
                      elseif ($n['type'] === 'message') $iconName = 'messages';
                      ?>
                      <span style="font-size:18px;color:#0F6ED6;"><i class="ti ti-<?= $iconName ?>"></i></span>
                    </div>
                    <div class="flex-grow-1">
                      <div class="fs-13 fw-500"><?= escape($n['title']) ?></div>
                      <div class="fs-12 text-muted"><?= escape(substr($n['message'], 0, 80)) ?></div>
                      <div class="fs-11 text-muted mt-1"><?= date('d M H:i', strtotime($n['created_at'])) ?></div>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-center py-4 text-muted">
                <div style="font-size:24px;margin-bottom:8px;"><i class="ti ti-bell-off icon-muted"></i></div>
                <p class="mb-0 fs-12 mt-1">No new notifications</p>
              </div>
            <?php endif; ?>
          </div>
          <div class="dropdown-footer text-center py-2" style="border-top:1px solid #eee;">
            <a href="<?= APP_URL ?>/pages/notifications.php?mark_all=1" class="fs-12 text-muted">Mark all as read</a>
          </div>
        </div>
      </div>

      <!-- Messages Icon -->
      <a href="<?= APP_URL ?>/pages/messages.php" class="btn btn-sm btn-icon position-relative" style="background:transparent;border:none;font-size:22px;color:#555;text-decoration:none;">
        <i class="ti ti-messages icon-muted"></i>
        <span id="msgBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:9px;<?= $msgCount ? '' : 'display:none' ?>">
          <?= $msgCount ?>
        </span>
      </a>

      <span class="text-muted fs-12 mx-1"><?= date('l, d M Y') ?></span>
      <span class="badge bg-success">Live</span>
    </div>
  </div>

  <!-- Alerts -->
  <div class="px-4 pt-3">
  <?php if ($success = flashMessage('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <span class="me-2"><i class="ti ti-check icon-success"></i></span><?= escape($success) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($error = flashMessage('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <span class="me-2"><i class="ti ti-alert-triangle icon-danger"></i></span><?= escape($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  </div>

  <div class="content-area">