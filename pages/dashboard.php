<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Dashboard';

$memberModel = new Member();
$contribModel = new Contribution();
$loanModel = new Loan();
$fineModel = new Fine();

$totalMembers   = $memberModel->count();
$activeMembers  = $memberModel->count('active');
$totalContribs  = $contribModel->totalAmount();
$activeLoans    = $loanModel->getAll(['status' => 'disbursed']);
$pendingLoans   = $loanModel->getAll(['status' => 'submitted']);
$totalDisbursed = $loanModel->getTotalDisbursed();
$totalRepaid    = $loanModel->getTotalRepaid();
$pendingFines   = $fineModel->totalPending();

$recentContribs = array_slice($contribModel->getAll(), 0, 6);
$allLoans       = $loanModel->getAll();
$recentLoans    = array_slice($allLoans, 0, 5);

// Approval queue stats
$pendingCounts = [];
$totalPendingApprovals = 0;
try {
    $pendingCounts = $loanModel->getPendingCountsByLevel();
    $totalPendingApprovals = array_sum($pendingCounts['counts']);
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stats row with gradient cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(24,95,165,0.1);color:#185FA5;">
        <i class="ti ti-users icon-primary"></i>
      </div>
      <div class="stat-label">Total Members</div>
      <div class="stat-value" data-target="<?= $totalMembers ?>"><?= $totalMembers ?></div>
      <div class="stat-sub"><?= $activeMembers ?> active</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(46,139,58,0.1);color:#2E8B3A;">
        <i class="ti ti-coin icon-success"></i>
      </div>
      <div class="stat-label">Total Contributions</div>
      <div class="stat-value"><?= tsh($totalContribs) ?></div>
      <div class="stat-sub"><?= count($contribModel->getAll()) ?> records</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(212,160,23,0.1);color:#D4A017;">
        <i class="ti ti-credit-card icon-warning"></i>
      </div>
      <div class="stat-label">Loans Disbursed</div>
      <div class="stat-value"><?= tsh($totalDisbursed) ?></div>
      <div class="stat-sub"><?= count($activeLoans) ?> disbursed, <?= count($pendingLoans) ?> pending</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card card-hover-effect">
      <div class="stat-icon" style="background:rgba(163,45,45,0.1);color:#A32D2D;">
        <i class="ti ti-alert-triangle icon-danger"></i>
      </div>
      <div class="stat-label">Pending Fines</div>
      <div class="stat-value"><?= tsh($pendingFines) ?></div>
      <div class="stat-sub"><?= count($fineModel->getAll(['paid' => 0])) ?> unpaid</div>
    </div>
  </div>
</div>

<!-- Quick Actions with enhanced buttons -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <strong><i class="ti ti-zap me-2 icon-primary"></i>Quick Actions</strong>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <a href="<?= APP_URL ?>/pages/member_payments.php" class="quick-action-btn success">
              <span class="qab-icon"><i class="ti ti-cash"></i></span>
              <span class="qab-label">Make Payment</span>
            </a>
          </div>
          <div class="col-md-3">
            <a href="<?= APP_URL ?>/pages/loans.php" class="quick-action-btn primary">
              <span class="qab-icon"><i class="ti ti-credit-card"></i></span>
              <span class="qab-label">Manage Loans</span>
            </a>
          </div>
          <div class="col-md-3">
            <a href="<?= APP_URL ?>/pages/approval_queue.php" class="quick-action-btn warning">
              <span class="qab-icon"><i class="ti ti-file-check"></i></span>
              <span class="qab-label">Approval Queue <?= $totalPendingApprovals > 0 ? '(' . $totalPendingApprovals . ')' : '' ?></span>
            </a>
          </div>
          <div class="col-md-3">
            <a href="<?= APP_URL ?>/pages/contributions.php" class="quick-action-btn info">
              <span class="qab-icon"><i class="ti ti-coin"></i></span>
              <span class="qab-label">Record Contribution</span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Recent Contributions -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-coin me-2 icon-success"></i>Recent Contributions</strong>
        <a href="<?= APP_URL ?>/pages/contributions.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Member</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentContribs as $c): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar-circle"><?= strtoupper(substr($c['member_name'],0,2)) ?></div>
                  <?= escape($c['member_name']) ?>
                </div>
              </td>
              <td><strong><?= tsh($c['amount']) ?></strong></td>
              <td><span class="badge badge-primary"><?= ucfirst(str_replace('_',' ',$c['payment_method'])) ?></span></td>
              <td><?= $c['date'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Loan Overview -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-credit-card me-2 icon-warning"></i>Loan Overview</strong>
        <a href="<?= APP_URL ?>/pages/loans.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body">
        <?php foreach ($recentLoans as $l):
          $pct = $l['total_repayable'] > 0 ? round(($l['total_paid'] / $l['total_repayable']) * 100) : 0;
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fs-13 fw-500"><?= escape($l['member_name']) ?> <span class="text-muted fs-11">(<?= $l['loan_no'] ?>)</span></span>
            <?= badgeStatus($l['status']) ?>
          </div>
          <div class="fs-11 text-muted mb-1"><?= tsh($l['total_paid']) ?> / <?= tsh($l['total_repayable']) ?> repaid</div>
          <div class="progress">
            <div class="progress-bar <?= $pct >= 100 ? 'progress-bar-success' : '' ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>