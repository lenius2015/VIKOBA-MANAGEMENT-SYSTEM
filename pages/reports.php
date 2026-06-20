<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'treasurer']);
$pageTitle = 'Reports & Analytics';

$memberModel = new Member();
$contribModel = new Contribution();
$loanModel   = new Loan();
$fineModel   = new Fine();

$members   = $memberModel->getAll();
$allLoans  = $loanModel->getAll();
$allFines  = $fineModel->getAll();
$allContribs = $contribModel->getAll();

$totalContribs   = array_sum(array_column($allContribs, 'amount'));
$totalDisbursed  = $loanModel->getTotalDisbursed();
$totalRepaid     = $loanModel->getTotalRepaid();
$totalFinesCollected = $fineModel->totalCollected();
$totalFinesPending   = $fineModel->totalPending();

// Per member summary
$memberSummary = [];
foreach ($members as $m) {
    $mContribs = array_filter($allContribs, fn($c) => $c['member_id'] === $m['id']);
    $mLoans    = array_filter($allLoans,    fn($l) => $l['member_id'] === $m['id'] && in_array($l['status'],['disbursed','submitted']));
    $mFines    = array_filter($allFines,    fn($f) => $f['member_id'] === $m['id'] && !$f['paid']);
    $memberSummary[] = [
        'member'           => $m,
        'total_contrib'    => array_sum(array_column(array_values($mContribs), 'amount')),
        'active_loans'     => count($mLoans),
        'pending_fines'    => array_sum(array_column(array_values($mFines), 'amount')),
    ];
}

// By payment method
$methods = ['cash','mobile_money','bank_transfer'];
$methodCounts = [];
foreach ($methods as $m) {
    $methodCounts[$m] = count(array_filter($allContribs, fn($c) => $c['payment_method'] === $m));
}

// Loan status breakdown
$loanByStatus = [];
foreach ($allLoans as $l) {
    $loanByStatus[$l['status']] = ($loanByStatus[$l['status']] ?? 0) + 1;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Financial Summary -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-label">Total Savings Pool</div><div class="stat-value"><?= tsh($totalContribs) ?></div><div class="stat-sub"><?= count($allContribs) ?> contribution records</div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-label">Loans Disbursed</div><div class="stat-value"><?= tsh($totalDisbursed) ?></div><div class="stat-sub"><?= count($allLoans) ?> total loans</div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-label">Total Repaid</div><div class="stat-value"><?= tsh($totalRepaid) ?></div><div class="stat-sub"><?= $totalDisbursed > 0 ? round(($totalRepaid/$totalDisbursed)*100) : 0 ?>% recovery rate</div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-label">Fines Collected</div><div class="stat-value"><?= tsh($totalFinesCollected) ?></div><div class="stat-sub"><?= tsh($totalFinesPending) ?> pending</div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Loan status -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><strong>Loan Status Breakdown</strong></div>
      <div class="card-body">
        <?php
        $statusColors = ['submitted'=>'badge-warning','under_review'=>'badge-warning','review_requested'=>'badge-warning','approved'=>'badge-info','disbursed'=>'badge-primary','completed'=>'badge-success','rejected'=>'badge-danger','defaulted'=>'badge-danger'];
        foreach ($loanByStatus as $s => $cnt):
        ?>
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <span><?= ucfirst($s) ?></span>
          <span class="badge <?= $statusColors[$s] ?? 'badge-secondary' ?>"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Contribution methods -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><strong>Payment Methods</strong></div>
      <div class="card-body">
        <?php $total_c = array_sum($methodCounts) ?: 1; ?>
        <?php foreach ($methods as $m): $cnt = $methodCounts[$m]; $pct = round($cnt/$total_c*100); ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between fs-13 mb-1">
            <span><?= ucfirst(str_replace('_',' ',$m)) ?></span>
            <span><?= $pct ?>% (<?= $cnt ?>)</span>
          </div>
          <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Group fund balance -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><strong>Group Fund Summary</strong></div>
      <div class="card-body">
        <?php
        $outstanding = $totalDisbursed - $totalRepaid;
        $fund = $totalContribs + $totalRepaid + $totalFinesCollected - $totalDisbursed;
        $rows = [
            ['Total Contributions', $totalContribs],
            ['+ Repayments Received', $totalRepaid],
            ['+ Fines Collected', $totalFinesCollected],
            ['− Loans Disbursed', $totalDisbursed],
            ['= Estimated Balance', $fund],
        ];
        foreach ($rows as [$label,$val]):
        ?>
        <div class="d-flex justify-content-between py-2 border-bottom <?= $label[0]==='='?'fw-bold':'' ?>">
          <span><?= $label ?></span>
          <span><?= tsh($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Member Summary Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Member Financial Summary</strong>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i>Print</button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th><th>Member No</th><th>Name</th><th>Status</th>
          <th>Shares</th><th>Total Contributed</th><th>Active Loans</th><th>Pending Fines</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($memberSummary as $i => $row):
        $m = $row['member'];
      ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><code><?= escape($m['member_no']) ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle"><?= strtoupper(substr($m['name'],0,2)) ?></div>
              <?= escape($m['name']) ?>
            </div>
          </td>
          <td><?= badgeStatus($m['status']) ?></td>
          <td><?= $m['shares'] ?></td>
          <td><?= tsh($row['total_contrib']) ?></td>
          <td><?= $row['active_loans'] > 0 ? '<span class="badge badge-primary">'.$row['active_loans'].'</span>' : '—' ?></td>
          <td><?= $row['pending_fines'] > 0 ? '<span style="color:#A32D2D">'.tsh($row['pending_fines']).'</span>' : '<span class="text-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="table-light fw-bold">
          <td colspan="5">TOTALS</td>
          <td><?= tsh(array_sum(array_column($memberSummary,'total_contrib'))) ?></td>
          <td><?= array_sum(array_column($memberSummary,'active_loans')) ?></td>
          <td><?= tsh(array_sum(array_column($memberSummary,'pending_fines'))) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
