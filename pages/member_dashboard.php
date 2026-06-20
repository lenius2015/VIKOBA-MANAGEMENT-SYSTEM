<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'My Overview';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

// Get the member linked to this user
$stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
$stmt->execute([$user['member_id']]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'No member profile linked to your account.');
    redirect(APP_URL . '/pages/logout.php');
}

$memberId = $member['id'];

// Get contributions
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM contributions WHERE member_id=?");
$stmt->execute([$memberId]);
$contribData = $stmt->fetch();

// Get loans
$stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount),0) as total_amount FROM loans WHERE member_id=? AND status IN ('disbursed','approved','submitted')");
$stmt->execute([$memberId]);
$loanData = $stmt->fetch();

$stmt = $db->prepare("SELECT COUNT(*) as completed FROM loans WHERE member_id=? AND status='completed'");
$stmt->execute([$memberId]);
$completedLoans = $stmt->fetchColumn();

// Get active loans with balances
$stmt = $db->prepare("SELECT l.*, COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid FROM loans l WHERE l.member_id=? AND l.status IN ('disbursed','approved','submitted') ORDER BY l.created_at DESC");
$stmt->execute([$memberId]);
$activeLoans = $stmt->fetchAll();

// Get fines
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM fines WHERE member_id=? AND paid=0");
$stmt->execute([$memberId]);
$fineData = $stmt->fetch();

// Recent activity
$stmt = $db->prepare("
    (SELECT 'contribution' as type, amount, date, NULL as status FROM contributions WHERE member_id=? ORDER BY date DESC LIMIT 3)
    UNION ALL
    (SELECT 'repayment' as type, amount, date, NULL as status FROM repayments WHERE member_id=? ORDER BY date DESC LIMIT 3)
    UNION ALL
    (SELECT 'fine' as type, amount, date, paid as status FROM fines WHERE member_id=? ORDER BY date DESC LIMIT 3)
    ORDER BY date DESC LIMIT 5
");
$stmt->execute([$memberId, $memberId, $memberId]);
$recentActivity = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Banner -->
<div class="welcome-banner mb-4">
  <div class="row align-items-center">
    <div class="col-md-8">
      <h4 class="mb-1">Welcome back, <?= escape($member['name']) ?>! 👋</h4>
      <p class="mb-0 text-muted">Member No: <strong><?= $member['member_no'] ?></strong> · Joined <?= date('F Y', strtotime($member['join_date'])) ?> · <?= $member['shares'] ?> Shares</p>
    </div>
    <div class="col-md-4 text-md-end">
      <span class="badge" style="background:<?= $member['status']==='active'?'#EAF3DE;color:#3B6D11':'#FCEBEB;color:#A32D2D' ?>;font-size:13px;padding:6px 16px;border-radius:20px;">
        <i class="ti ti-circle-check me-1 icon-success"></i>
        <?= ucfirst($member['status']) ?>
      </span>
    </div>
  </div>
</div>

<?php
// Group Information widget
try {
    $gc = new GroupCenter();
    $g_ann = $gc->getAnnouncements(3);
    $g_meet = $gc->getUpcomingMeetings(3);
} catch (Throwable $e) {
    $g_ann = [];
    $g_meet = [];
}
?>
<div class="row g-3 mb-4">
  <div class="col-md-12">
    <div class="card card-primary">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-1">Group Updates</h5>
            <p class="text-muted mb-0">Latest announcements and upcoming meetings</p>
          </div>
          <a href="<?= APP_URL ?>/pages/group_info_center.php" class="btn btn-sm btn-outline-primary">View Group Center</a>
        </div>

        <div class="row mt-3">
          <div class="col-md-6">
            <h6><i class="ti ti-info-circle me-1 icon-primary"></i>Announcements</h6>
            <?php if (empty($g_ann)): ?>
              <p class="text-muted fs-12">No recent announcements.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($g_ann as $a): ?>
                  <li class="mb-2 p-2" style="background:var(--gray-100);border-radius:8px;">
                    <strong class="fs-13"><?= escape($a['title']) ?></strong><br/>
                    <small class="text-muted"><?= date('d M Y', strtotime($a['publish_at'])) ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <h6><i class="ti ti-calendar me-1 icon-warning"></i>Upcoming Meetings</h6>
            <?php if (empty($g_meet)): ?>
              <p class="text-muted fs-12">No upcoming meetings.</p>
            <?php else: ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($g_meet as $m): ?>
                  <li class="mb-2 p-2" style="background:var(--gray-100);border-radius:8px;">
                    <strong class="fs-13"><?= escape($m['title']) ?></strong><br/>
                    <small class="text-muted"><?= date('d M Y H:i', strtotime($m['meeting_date'])) ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(24,95,165,0.1);color:#185FA5;">
        <i class="ti ti-coin icon-primary"></i>
      </div>
      <div class="stat-label">Total Contributions</div>
      <div class="stat-value"><?= tsh($contribData['total']) ?></div>
      <div class="stat-sub"><?= $contribData['count'] ?> payments</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(46,139,58,0.1);color:#2E8B3A;">
        <i class="ti ti-credit-card icon-success"></i>
      </div>
      <div class="stat-label">Active Loans</div>
      <div class="stat-value"><?= $loanData['total'] ?></div>
      <div class="stat-sub"><?= tsh($loanData['total_amount']) ?> total</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(15,110,86,0.1);color:#0F6E56;">
        <i class="ti ti-circle-check icon-info"></i>
      </div>
      <div class="stat-label">Loans Completed</div>
      <div class="stat-value"><?= $completedLoans ?></div>
      <div class="stat-sub">Fully repaid</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(163,45,45,0.1);color:#A32D2D;">
        <i class="ti ti-alert-triangle icon-danger"></i>
      </div>
      <div class="stat-label">Pending Fines</div>
      <div class="stat-value"><?= tsh($fineData['total']) ?></div>
      <div class="stat-sub"><?= $fineData['count'] ?> unpaid</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Active Loans -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-credit-card me-2 icon-warning"></i>My Loans</strong>
        <a href="<?= APP_URL ?>/pages/member_loans.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body">
        <?php if ($activeLoans): ?>
          <?php foreach ($activeLoans as $l):
            $pct = $l['total_repayable'] > 0 ? round(($l['total_paid'] / $l['total_repayable']) * 100) : 0;
          ?>
          <div class="mb-3 p-3" style="background:var(--gray-100);border-radius:10px;">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <strong><?= $l['loan_no'] ?></strong>
                <span class="text-muted ms-2 fs-12"><?= tsh($l['amount']) ?></span>
              </div>
              <?php
                $statusBadge = $l['status'] === 'disbursed' ? 'badge-primary' : 'badge-warning';
              ?>
              <span class="badge <?= $statusBadge ?>"><?= ucfirst($l['status']) ?></span>
            </div>
            <div class="d-flex justify-content-between fs-12 text-muted mb-1">
              <span>Repaid: <?= tsh($l['total_paid']) ?> / <?= tsh($l['total_repayable']) ?></span>
              <span><?= $pct ?>%</span>
            </div>
            <div class="progress mb-2">
              <div class="progress-bar <?= $pct >= 100 ? 'progress-bar-success' : '' ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <?php if ($l['status'] === 'disbursed'): 
              $balance = (float)$l['total_repayable'] - (float)$l['total_paid'];
            ?>
            <div class="d-flex justify-content-between align-items-center">
              <span class="fs-12 text-muted">Balance: <strong><?= tsh($balance) ?></strong></span>
              <a href="<?= APP_URL ?>/pages/member_payments.php" class="btn btn-sm btn-success">
                <i class="ti ti-cash me-1"></i>Pay Now
              </a>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-center py-4">
            <i class="ti ti-credit-card" style="font-size:48px;color:#ddd;"></i>
            <p class="text-muted mt-2 mb-0">No active loans</p>
            <a href="<?= APP_URL ?>/pages/member_loans.php" class="btn btn-sm btn-primary mt-2">Apply for Loan</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <strong><i class="ti ti-clock me-2 icon-info"></i>Recent Activity</strong>
      </div>
      <div class="card-body p-0">
        <?php if ($recentActivity): ?>
          <div class="list-group list-group-flush">
            <?php foreach ($recentActivity as $act): ?>
              <div class="list-group-item d-flex align-items-center gap-3 px-4 py-3">
                <div class="activity-icon" style="
                  <?php if ($act['type'] === 'contribution'): ?>background:rgba(24,95,165,0.1);color:#185FA5;
                  <?php elseif ($act['type'] === 'repayment'): ?>background:rgba(46,139,58,0.1);color:#2E8B3A;
                  <?php else: ?>background:rgba(163,45,45,0.1);color:#A32D2D;<?php endif; ?>
                ">
                  <i class="ti ti-<?= $act['type'] === 'contribution' ? 'coin' : ($act['type'] === 'repayment' ? 'cash' : 'alert-triangle') ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <div class="fw-500 fs-13"><?= ucfirst($act['type']) ?></div>
                  <div class="text-muted fs-12"><?= tsh($act['amount']) ?> · <?= date('d M Y', strtotime($act['date'])) ?></div>
                </div>
                <?php if ($act['type'] === 'fine'): ?>
                  <span class="badge <?= $act['status'] ? 'badge-success' : 'badge-danger' ?>"><?= $act['status'] ? 'Paid' : 'Unpaid' ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-center py-5">
            <i class="ti ti-clock" style="font-size:48px;color:#ddd;"></i>
            <p class="text-muted mt-2">No recent activity</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>