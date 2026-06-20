<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'My Loans';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

// Get the member linked to this user
$stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
$stmt->execute([$user['member_id']]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'No member profile linked.');
    redirect(APP_URL . '/pages/logout.php');
}

$memberId = $member['id'];
$loanModel = new Loan();
$productModel = new LoanProduct();
$products = $productModel->getAll(true);

// Handle loan application from member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  
  if ($_POST['action'] === 'apply') {
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $applicationDate = trim($_POST['application_date'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0);
    $termMonths = (int)($_POST['term_months'] ?? 12);
    $paymentFrequency = $_POST['payment_frequency'] ?? 'monthly';

    $missing = [];
    if (!$amount || $amount < 10000) $missing[] = 'amount (minimum Tsh 10,000)';
    if (empty($applicationDate)) $missing[] = 'application date';
    if (!$productId) $missing[] = 'loan product';

    if (!empty($missing)) {
      setFlash('error', 'Missing or invalid fields: ' . implode(', ', $missing));
      redirect(APP_URL . '/pages/member_loans.php');
    }

    $product = $productModel->getById($productId);
    if (!$product || $product['status'] !== 'active') {
      setFlash('error', 'Invalid loan product selected.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    if ($amount < (float)$product['min_amount']) {
      setFlash('error', 'Amount below minimum for this product (min: ' . tsh($product['min_amount']) . ').');
      redirect(APP_URL . '/pages/member_loans.php');
    }
    if ($amount > (float)$product['max_amount']) {
      setFlash('error', 'Amount exceeds maximum for this product (max: ' . tsh($product['max_amount']) . ').');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    if ($termMonths < (int)$product['min_term_months'] || $termMonths > (int)$product['max_term_months']) {
      setFlash('error', 'Term must be between ' . $product['min_term_months'] . ' and ' . $product['max_term_months'] . ' months.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    $eligibility = $loanModel->checkEligibility($memberId, $amount, $product);

    if (!$eligibility['eligible']) {
      setFlash('error', 'You are not eligible for this loan. Please check the requirements below.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    $dueDate = date('Y-m-d', strtotime("+$termMonths months", strtotime($applicationDate)));

    $data = [
      'member_id'        => $memberId,
      'product_id'       => $productId,
      'amount'           => $amount,
      'interest_rate'    => (float)$product['default_interest_rate'],
      'term_months'      => $termMonths,
      'payment_frequency'=> $paymentFrequency,
      'purpose'          => $_POST['purpose'] ?? '',
      'application_date' => $applicationDate ?: date('Y-m-d'),
      'due_date'         => $dueDate,
    ];

    $loanId = $loanModel->create($data);
    if ($loanId) {
      $creditScore = $loanModel->calculateCreditScore($memberId, $amount, $product);
      if ($loanModel->canAutoApprove($product, ['requested_amount' => $amount, 'credit_score' => $creditScore])) {
        $loanModel->approveWithScoring($loanId, $user['id'], 'Auto-approved via credit scoring',
          $creditScore['score'], json_encode($creditScore['breakdown']), true);
      }

      $notif = new Notification();
      $notif->notifyAdmins('loan_applied', 'New Loan Application',
        $member['name'] . ' applied for a ' . $product['name'] . ' of Tsh ' . number_format($data['amount'], 2),
        APP_URL . '/pages/loans.php');

      try { $audit = new Audit(); $audit->logModuleActivity($user['id'], $user['name'], $user['role'], 'loans', 'submit', 'loan', $loanId, $member['name'], 'Submitted loan application'); } catch (Throwable $e) {}

      setFlash('success', 'Loan application submitted successfully. Awaiting review.');
    } else {
      setFlash('error', 'Failed to submit loan application.');
    }

    redirect(APP_URL . '/pages/member_loans.php');
  }

  // Cancel/delete pending loan
  if ($_POST['action'] === 'cancel_loan') {
    $loanId = (int)$_POST['loan_id'];
    $stmt = $db->prepare("SELECT status FROM loans WHERE id=? AND member_id=?");
    $stmt->execute([$loanId, $memberId]);
    $loan = $stmt->fetch();
    if ($loan && in_array($loan['status'], ['draft', 'submitted', 'review_requested'])) {
      $stmt = $db->prepare("UPDATE loans SET status='rejected' WHERE id=?");
      $stmt->execute([$loanId]);
      setFlash('success', 'Loan application cancelled.');
    } else {
      setFlash('error', 'Cannot cancel loan in its current status.');
    }
    redirect(APP_URL . '/pages/member_loans.php');
  }

    // Make repayment
  if ($_POST['action'] === 'repay') {
    $loanId = (int)$_POST['loan_id'];
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $reference = trim((string)($_POST['reference'] ?? ''));
    $repaymentDate = $_POST['date'] ?? date('Y-m-d');

    // Normalize/validate payment method (must match select options)
    $allowedMethods = ['cash', 'mobile_money', 'bank_transfer'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
      setFlash('error', 'Invalid payment method.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    // Verify loan belongs to member and is disbursed
    $stmt = $db->prepare("SELECT l.id, l.status, l.total_repayable,
                                 COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
                          FROM loans l
                          WHERE l.id=? AND l.member_id=?");
    $stmt->execute([$loanId, $memberId]);
    $loan = $stmt->fetch();

    if (!$loan || $loan['status'] !== 'disbursed') {
      setFlash('error', 'Invalid loan or loan not yet disbursed.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    $outstanding = (float)$loan['total_repayable'] - (float)$loan['total_paid'];
    if ($amount <= 0) {
      setFlash('error', 'Invalid repayment amount.');
      redirect(APP_URL . '/pages/member_loans.php');
    }
    if ($outstanding <= 0) {
      setFlash('error', 'This loan is already fully repaid.');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    // Prevent accidental overpayment beyond outstanding
    if ($amount > $outstanding) {
      setFlash('error', 'Repayment amount cannot exceed outstanding balance (' . tsh($outstanding) . ').');
      redirect(APP_URL . '/pages/member_loans.php');
    }

    $repayData = [
      'loan_id' => $loanId,
      'member_id' => $memberId,
      'amount' => $amount,
      'payment_method' => $paymentMethod,
      'reference' => $reference,
      'date' => $repaymentDate,
      'recorded_by' => $user['id'],
      'notes' => 'Online repayment by member'
    ];

    if ($loanModel->addRepayment($repayData)) {
      setFlash('success', 'Repayment of ' . tsh($amount) . ' recorded successfully.');
    } else {
      setFlash('error', 'Failed to record repayment.');
    }
    redirect(APP_URL . '/pages/member_loans.php');
  }
}

// Calculate eligibility for the form
$prefillAmount = $_POST['amount'] ?? 100000;
$prefillProductId = (int)($_POST['product_id'] ?? ($products[0]['id'] ?? 0));
$prefillProduct = $productModel->getById($prefillProductId);
$eligibility = $loanModel->checkEligibility($memberId, (float)$prefillAmount, $prefillProduct ?: null);

// Get loans
$stmt = $db->prepare("
    SELECT l.*,
           COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
    FROM loans l
    WHERE l.member_id = ?
    ORDER BY l.created_at DESC
");
$stmt->execute([$memberId]);
$loans = $stmt->fetchAll();

// Get repayments for a specific loan if viewing
$viewLoan = null;
$repayments = [];
$amortization = [];
if (isset($_GET['view'])) {
    $stmt = $db->prepare("
        SELECT l.*,
               COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
        FROM loans l WHERE l.id=? AND l.member_id=?
    ");
    $stmt->execute([(int)$_GET['view'], $memberId]);
    $viewLoan = $stmt->fetch();

    if ($viewLoan) {
    $stmt = $db->prepare("SELECT r.*, u.name as reviewed_by_name, r.reviewed_at FROM repayments r LEFT JOIN users u ON r.reviewed_by = u.id WHERE r.loan_id=? ORDER BY r.date DESC");
    $stmt->execute([$viewLoan['id']]);
    $repayments = $stmt->fetchAll();

        $amortization = $loanModel->getAmortizationSchedule($viewLoan['id']);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label">Total Loans</div>
      <div class="stat-value"><?= count($loans) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label">Active Loans</div>
      <div class="stat-value"><?= count(array_filter($loans, fn($l) => in_array($l['status'], ['disbursed','approved']))) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label">Total Savings</div>
      <div class="stat-value"><?= tsh($eligibility['total_savings']) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label">Max Loan</div>
      <div class="stat-value"><?= tsh($eligibility['max_loan_by_shares']) ?></div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="mb-1">Loan Eligibility Summary</h5>
        <div class="fs-12 text-muted">Based on current profile (select a product & amount to check specific eligibility)</div>
      </div>
      <span class="badge bg-<?= $eligibility['eligible'] ? 'success' : 'danger' ?>">
        <?= $eligibility['eligible'] ? 'Eligible to Apply' : 'Not Eligible' ?>
      </span>
    </div>
    <div class="row g-3">
      <div class="col-md-3"><strong>Member Tenure</strong><br><?= $eligibility['months_active'] ?> months</div>
      <div class="col-md-3"><strong>Total Savings</strong><br><?= tsh($eligibility['total_savings']) ?></div>
      <div class="col-md-3"><strong>Min Savings Required</strong><br><?= tsh($eligibility['min_savings_needed']) ?></div>
      <div class="col-md-3"><strong>Max Loan (Shares)</strong><br><?= tsh($eligibility['max_loan_by_shares']) ?></div>
    </div>
    <?php if (!empty($eligibility['credit_score'])): ?>
    <div class="mt-2 d-flex align-items-center gap-2">
      <strong>Credit Score:</strong>
      <span class="badge bg-<?= $eligibility['credit_score']['score'] >= 80 ? 'success' : ($eligibility['credit_score']['score'] >= 60 ? 'warning text-dark' : 'danger') ?>">
        <?= $eligibility['credit_score']['score'] ?>/100
      </span>
      <span class="fs-12 text-muted">Rating: <?= ucfirst($eligibility['credit_score']['rating']) ?></span>
    </div>
    <?php endif; ?>
    <div class="mt-3">
      <?php foreach ($eligibility['checks'] as $check): ?>
        <div class="d-flex align-items-start gap-2 p-2 mb-1" style="background:<?= $check['passed'] ? '#EAF3DE' : '#FCEBEB' ?>;border-radius:8px;">
          <i class="ti ti-<?= $check['passed'] ? 'circle-check' : 'circle-x' ?> text-<?= $check['passed'] ? 'success' : 'danger' ?>" style="font-size:18px;flex-shrink:0;margin-top:1px;"></i>
          <div class="fs-12"><?= $check['message'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!$eligibility['eligible']): ?>
<div class="card mb-4 border-danger">
  <div class="card-header bg-danger text-white">
    <strong><i class="ti ti-alert-triangle me-2"></i>Application Needs Attention</strong>
  </div>
  <div class="card-body">
    <p class="mb-3">The current requested amount is not eligible based on your financial profile. Please review the failed checks below before applying, or change the loan amount in the application modal.</p>
    <?php foreach ($eligibility['checks'] as $check): ?>
      <div class="d-flex align-items-start gap-2 p-2 mb-2" style="background:<?= $check['passed'] ? '#EAF3DE' : '#FCEBEB' ?>;border-radius:8px;">
        <i class="ti ti-<?= $check['passed'] ? 'circle-check' : 'circle-x' ?> text-<?= $check['passed'] ? 'success' : 'danger' ?>" style="font-size:18px;flex-shrink:0;margin-top:1px;"></i>
        <div>
          <div class="fw-semibold"><?= $check['name'] ?></div>
          <div class="fs-12"><?= $check['message'] ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="ti ti-credit-card me-2 icon-warning"></i>My Loans</strong>
    <div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#applyLoanModal">
        <i class="ti ti-plus me-1 icon-white"></i>Apply for Loan
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Loan No</th><th>Product</th><th>Amount</th><th>Interest</th><th>Term</th><th>Repaid</th><th>Progress</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if ($loans): ?>
        <?php foreach ($loans as $l):
          $pct = $l['total_repayable'] > 0 ? round(($l['total_paid']/$l['total_repayable'])*100) : 0;
        ?>
        <tr>
          <td><code><?= $l['loan_no'] ?></code></td>
          <td><?= escape($l['product_name'] ?? 'General') ?></td>
          <td><?= tsh($l['amount']) ?></td>
          <td><?= $l['interest_rate'] ?>%</td>
          <td><?= $l['term_months'] ? $l['term_months'] . ' mo' : '—' ?></td>
          <td><?= tsh($l['total_paid']) ?></td>
          <td style="min-width:100px">
            <div class="progress"><div class="progress-bar" style="width:<?=$pct?>%"></div></div>
            <small class="text-muted"><?=$pct?>%</small>
          </td>
          <td><?= badgeStatus($l['status']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="?view=<?=$l['id']?>" class="btn btn-sm btn-outline-primary"><i class="ti ti-eye"></i></a>
              <?php if ($l['status'] === 'disbursed'): ?>
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#repayModal"
                  data-loan-id="<?=$l['id']?>"
                  data-loan-no="<?=$l['loan_no']?>"
                  data-balance="<?= round($l['total_repayable']-$l['total_paid'], 2) ?>">
                  <i class="ti ti-cash"></i> Pay
                </button>
              <?php endif; ?>
              <?php if (in_array($l['status'], ['draft', 'submitted', 'review_requested'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this loan application?');">
                  <input type="hidden" name="action" value="cancel_loan"/>
                  <input type="hidden" name="loan_id" value="<?=$l['id']?>"/>
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-x"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="9" class="text-center py-4 text-muted">No loan applications yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Repay Loan Modal -->
<div class="modal fade" id="repayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="repay"/>
        <input type="hidden" name="loan_id" id="repay_loan_id"/>
        <div class="modal-header">
          <h5 class="modal-title">Repay Loan — <span id="repay_loan_no"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">Outstanding balance: <strong id="repay_balance"></strong></div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Amount (Tsh) *</label>
              <input name="amount" type="number" step="0.01" class="form-control" required/>
            </div>
            <div class="col-6">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Date</label>
              <input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>"/>
            </div>
            <div class="col-6">
              <label class="form-label">Reference No.</label>
              <input name="reference" class="form-control" placeholder="Optional"/>
            </div>
          </div>
          <div class="mt-3 small text-muted">
            <i class="ti ti-info-circle me-1"></i> Your repayment will be recorded and will need admin confirmation if paid in cash.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-cash me-1 icon-white"></i>Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('repayModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('repay_loan_id').value = btn.dataset.loanId;
  document.getElementById('repay_loan_no').textContent = btn.dataset.loanNo;
  document.getElementById('repay_balance').textContent = 'Tsh ' + parseFloat(btn.dataset.balance).toLocaleString('en-TZ', {minimumFractionDigits:2});
});
</script>

<!-- Apply for Loan Modal -->
<div class="modal fade" id="applyLoanModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="loanForm">
        <input type="hidden" name="action" value="apply"/>
        <div class="modal-header"><h5 class="modal-title">Apply for Loan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div id="eligibilityResult" class="mb-3"></div>
          <div id="amortizationPreview" class="mb-3" style="display:none;"></div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Loan Product *</label>
              <select name="product_id" id="product_id" class="form-select" required onchange="onProductChange()">
                <option value="">— Select Product —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?=$p['id']?>"
                    data-interest="<?=$p['default_interest_rate']?>"
                    data-min-term="<?=$p['min_term_months']?>"
                    data-max-term="<?=$p['max_term_months']?>"
                    data-min-amount="<?=$p['min_amount']?>"
                    data-max-amount="<?=$p['max_amount']?>"
                    data-code="<?=$p['code']?>"
                    <?= $p['id'] === $prefillProductId ? 'selected' : '' ?>>
                    <?= escape($p['name']) ?> (<?= $p['default_interest_rate'] ?>%)
                  </option>
                <?php endforeach; ?>
              </select>
              <div id="productInfo" class="fs-11 text-muted mt-1"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Loan Amount (Tsh) *</label>
              <input id="loan_amount_input" name="amount" type="number" step="1000" class="form-control"
                     required min="10000" value="<?= (float)$prefillAmount ?>" onchange="checkEligibility()" onkeyup="checkEligibility()"/>
              <div id="amountInfo" class="fs-11 text-muted mt-1"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Term (months) *</label>
              <input id="term_months" name="term_months" type="number" class="form-control" required min="1" max="24" value="12" onchange="checkEligibility()"/>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Frequency</label>
              <select name="payment_frequency" class="form-select" onchange="checkEligibility()">
                <option value="monthly">Monthly</option>
                <option value="biweekly">Bi-Weekly</option>
                <option value="weekly">Weekly</option>
                <option value="lump_sum">Lump Sum (End of Term)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Application Date</label>
              <input name="application_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="checkEligibility()"/>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Purpose of Loan</label>
            <textarea name="purpose" class="form-control" rows="2" placeholder="e.g. Business capital, school fees..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="submitLoanBtn" disabled>
            <i class="ti ti-send me-1 icon-white"></i>Submit Application
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Product data
var products = <?= json_encode($products) ?>;

// Live eligibility check
function checkEligibility() {
  var amount = document.getElementById('loan_amount_input').value;
  var resultDiv = document.getElementById('eligibilityResult');
  var submitBtn = document.getElementById('submitLoanBtn');
  var amortDiv = document.getElementById('amortizationPreview');
  var productId = document.getElementById('product_id').value;
  var termMonths = document.getElementById('term_months').value;
  var appDate = document.querySelector('input[name="application_date"]').value;
  var frequency = document.querySelector('select[name="payment_frequency"]').value;

  if (!amount || amount < 10000 || !productId || !termMonths || !appDate) {
    resultDiv.innerHTML = '<div class="alert alert-info mb-0"><i class="ti ti-info-circle me-2"></i>Fill in all required fields to check eligibility.</div>';
    amortDiv.style.display = 'none';
    submitBtn.disabled = true;
    return;
  }

  resultDiv.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm me-2"></div>Checking eligibility...</div>';

  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'ajax_check_eligibility.php?member_id=<?= $memberId ?>&amount=' + amount + '&product_id=' + productId, true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      try {
        var data = JSON.parse(xhr.responseText);
        var html = '';

        if (data.eligible) {
          html += '<div class="alert alert-success mb-2"><i class="ti ti-circle-check me-2"></i><strong>You are eligible!</strong> All requirements met.</div>';
        } else {
          html += '<div class="alert alert-danger mb-2"><i class="ti ti-alert-circle me-2"></i><strong>Some requirements not met.</strong> See details below.</div>';
        }

        html += '<div class="eligibility-checks">';
        (data.checks || []).forEach(function(check) {
          var icon = check.passed ? 'ti ti-circle-check text-success' : 'ti ti-circle-x text-danger';
          var bg = check.passed ? '#EAF3DE' : '#FCEBEB';
          html += '<div class="d-flex align-items-start gap-2 p-2 mb-1" style="background:' + bg + ';border-radius:8px;">';
          html += '  <i class="' + icon + '" style="font-size:16px;flex-shrink:0;margin-top:2px;"></i>';
          html += '  <div class="fs-12">' + check.message + '</div>';
          html += '</div>';
        });
        html += '</div>';

        if (data.credit_score) {
          var cs = data.credit_score;
          var badgeClass = cs.score >= 80 ? 'success' : (cs.score >= 60 ? 'warning text-dark' : 'danger');
          html += '<div class="mt-2 d-flex align-items-center gap-2 p-2" style="background:#f5f5f0;border-radius:8px;">';
          html += '  <strong>Credit Score:</strong>';
          html += '  <span class="badge bg-' + badgeClass + '">' + cs.score + '/' + cs.max_score + '</span>';
          html += '  <span class="fs-12 text-muted">Rating: ' + cs.rating.charAt(0).toUpperCase() + cs.rating.slice(1) + '</span>';
          html += '</div>';
        }

        resultDiv.innerHTML = html;

        showAmortizationPreview(amount, termMonths, frequency);

        var amountValid = amount && parseFloat(amount) >= 10000;
        var eligible = data.eligible === true || (data.eligible === undefined && true);
        submitBtn.disabled = !(eligible && appDate && amountValid && productId);

      } catch(e) {
        resultDiv.innerHTML = '<div class="alert alert-warning mb-0">Error checking eligibility. Please try again.</div>';
        amortDiv.style.display = 'none';
        submitBtn.disabled = true;
      }
    }
  };
  xhr.send();
}

function showAmortizationPreview(amount, termMonths, frequency) {
  var amortDiv = document.getElementById('amortizationPreview');
  if (!amount || !termMonths) {
    amortDiv.style.display = 'none';
    return;
  }

  var productId = document.getElementById('product_id').value;
  var product = products.find(function(p) { return p.id == productId; });
  if (!product) { amortDiv.style.display = 'none'; return; }

  var interestRate = parseFloat(product.default_interest_rate) || 15;
  var term = parseInt(termMonths) || 12;
  var monthlyRate = (interestRate / 100) / 12;

  var installments = term;
  if (frequency === 'biweekly') installments = term * 2;
  else if (frequency === 'weekly') installments = term * 4;
  else if (frequency === 'lump_sum') installments = 1;

  var periodRate = monthlyRate;
  if (frequency === 'biweekly') periodRate = monthlyRate / 2;
  else if (frequency === 'weekly') periodRate = monthlyRate / 4;
  else if (frequency === 'lump_sum') periodRate = monthlyRate * term;

  var emi = 0;
  if (periodRate > 0 && installments > 1) {
    var factor = Math.pow(1 + periodRate, installments);
    emi = amount * periodRate * factor / (factor - 1);
  } else if (installments === 1) {
    emi = amount * (1 + periodRate);
  } else {
    emi = amount / installments;
  }

  var totalRepayable = emi * installments;
  var totalInterest = totalRepayable - amount;

  amortDiv.innerHTML =
    '<div class="card bg-light">' +
    '  <div class="card-body p-3">' +
    '    <h6 class="mb-2"><i class="ti ti-calculator me-2"></i>Repayment Preview</h6>' +
    '    <div class="row g-2 text-center">' +
    '      <div class="col-3"><strong>' + tshFormat(emi) + '</strong><br><small>Per Installment</small></div>' +
    '      <div class="col-3"><strong>' + installments + '</strong><br><small>Installments</small></div>' +
    '      <div class="col-3"><strong>' + tshFormat(totalRepayable) + '</strong><br><small>Total Repayable</small></div>' +
    '      <div class="col-3"><strong>' + tshFormat(totalInterest) + '</strong><br><small>Total Interest</small></div>' +
    '    </div>' +
    '  </div>' +
    '</div>';
  amortDiv.style.display = 'block';
}

function tshFormat(num) {
  return 'Tsh ' + parseFloat(num).toLocaleString('en-TZ', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

function onProductChange() {
  var select = document.getElementById('product_id');
  var selected = select.options[select.selectedIndex];
  var info = document.getElementById('productInfo');
  var amountInfo = document.getElementById('amountInfo');
  var amountInput = document.getElementById('loan_amount_input');
  var termInput = document.getElementById('term_months');

  if (selected && selected.value) {
    var interest = selected.dataset.interest;
    var minTerm = parseInt(selected.dataset.minTerm);
    var maxTerm = parseInt(selected.dataset.maxTerm);
    var minAmt = parseFloat(selected.dataset.minAmount);
    var maxAmt = parseFloat(selected.dataset.maxAmount);

    info.innerHTML = 'Interest: ' + interest + '% · Term: ' + minTerm + '-' + maxTerm + ' months';
    amountInfo.innerHTML = 'Range: ' + tshFormat(minAmt) + ' - ' + tshFormat(maxAmt);
    amountInput.min = minAmt;
    amountInput.max = maxAmt;
    termInput.min = minTerm;
    termInput.max = maxTerm;
    if (parseInt(termInput.value) < minTerm) termInput.value = minTerm;
    if (parseInt(termInput.value) > maxTerm) termInput.value = maxTerm;
  } else {
    info.innerHTML = '';
    amountInfo.innerHTML = '';
  }
  checkEligibility();
}

document.getElementById('applyLoanModal').addEventListener('shown.bs.modal', function() {
  onProductChange();
});
</script>

<!-- View Loan Details Modal -->
<?php if ($viewLoan): ?>
<div class="modal fade show d-block" id="viewLoanModal" tabindex="-1" style="background:rgba(0,0,0,.4)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Loan Details — <?= $viewLoan['loan_no'] ?></h5>
        <a href="member_loans.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><strong>Amount:</strong> <?= tsh($viewLoan['amount']) ?></div>
          <div class="col-md-4"><strong>Interest:</strong> <?= $viewLoan['interest_rate'] ?>%</div>
          <div class="col-md-4"><strong>Term:</strong> <?= $viewLoan['term_months'] ? $viewLoan['term_months'] . ' months' : '—' ?></div>
          <div class="col-md-4"><strong>Total Repayable:</strong> <?= tsh($viewLoan['total_repayable']) ?></div>
          <div class="col-md-4"><strong>Paid So Far:</strong> <?= tsh($viewLoan['total_paid']) ?></div>
          <div class="col-md-4"><strong>Balance:</strong> <?= tsh($viewLoan['total_repayable'] - $viewLoan['total_paid']) ?></div>
          <div class="col-md-4"><strong>Status:</strong> <?= badgeStatus($viewLoan['status']) ?></div>
          <div class="col-md-4"><strong>Applied:</strong> <?= $viewLoan['application_date'] ?></div>
          <div class="col-md-4"><strong>Due Date:</strong> <?= $viewLoan['due_date'] ?? '—' ?></div>
          <div class="col-12"><strong>Purpose:</strong> <?= escape($viewLoan['purpose'] ?: '—') ?></div>
          <?php if ($viewLoan['credit_score']): ?>
          <div class="col-12">
            <strong>Credit Score:</strong>
            <span class="badge bg-<?= $viewLoan['credit_score'] >= 80 ? 'success' : ($viewLoan['credit_score'] >= 60 ? 'warning text-dark' : 'danger') ?>">
              <?= $viewLoan['credit_score'] ?>/100
            </span>
            <?php if ($viewLoan['auto_approved']): ?>
              <span class="badge bg-info ms-2">Auto-Approved</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($viewLoan['status'] === 'disbursed'): ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span>Repayment Progress</span>
            <span><?= round(($viewLoan['total_paid']/$viewLoan['total_repayable'])*100) ?>%</span>
          </div>
          <div class="progress" style="height:10px">
            <div class="progress-bar" style="width:<?= round(($viewLoan['total_paid']/$viewLoan['total_repayable'])*100) ?>%"></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Amortization Schedule -->
        <?php if ($amortization): ?>
        <h6 class="mb-2 mt-3">Repayment Schedule</h6>
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead>
              <tr>
                <th>#</th><th>Due Date</th><th>Principal</th><th>Interest</th><th>Total</th>
                <th>Paid</th><th>Late Fee</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $totalPrincipal = 0; $totalInterest = 0; $totalAmount = 0; $totalPaid = 0;
              foreach ($amortization as $a): 
                $totalPrincipal += $a['principal'];
                $totalInterest += $a['interest'];
                $totalAmount += $a['total_amount'];
                $totalPaid += $a['paid_amount'];
              ?>
              <tr>
                <td><?= $a['installment_no'] ?></td>
                <td><?= $a['due_date'] ?></td>
                <td><?= tsh($a['principal']) ?></td>
                <td><?= tsh($a['interest']) ?></td>
                <td><strong><?= tsh($a['total_amount']) ?></strong></td>
                <td><?= tsh($a['paid_amount']) ?></td>
                <td><?= $a['late_fee'] > 0 ? tsh($a['late_fee']) : '—' ?></td>
                <td>
                  <?php 
                    $statusClasses = ['pending' => 'secondary', 'paid' => 'success', 'overdue' => 'danger', 'partial' => 'warning text-dark'];
                    $statusLabel = ['pending' => 'Pending', 'paid' => 'Paid', 'overdue' => 'Overdue', 'partial' => 'Partial'];
                  ?>
                  <span class="badge bg-<?= $statusClasses[$a['status']] ?? 'secondary' ?>">
                    <?= $statusLabel[$a['status']] ?? $a['status'] ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="fw-bold">
                <td colspan="1">Total</td>
                <td></td>
                <td><?= tsh($totalPrincipal) ?></td>
                <td><?= tsh($totalInterest) ?></td>
                <td><?= tsh($totalAmount) ?></td>
                <td><?= tsh($totalPaid) ?></td>
                <td></td><td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>

        <h6 class="mb-2 mt-3">Repayment History</h6>
        <?php if ($repayments): ?>
        <table class="table table-sm">
          <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Reviewed</th></tr></thead>
          <tbody>
          <?php foreach ($repayments as $r): ?>
            <tr>
              <td><?=$r['date']?></td>
              <td><?=tsh($r['amount'])?></td>
              <td><?=ucfirst(str_replace('_',' ',$r['payment_method']))?></td>
              <td><?=escape($r['reference']??'—')?></td>
              <td>
                <?php if (!empty($r['reviewed_by_name']) || !empty($r['reviewed_at'])): ?>
                  <small class="text-muted"><?= escape($r['reviewed_by_name'] ?? 'Reviewer') ?> · <?= escape(date('d M Y H:i', strtotime($r['reviewed_at'] ?? $r['updated_at'] ?? 'now'))) ?></small>
                <?php else: ?>
                  <small class="text-muted">—</small>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p class="text-muted">No repayments recorded yet.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><a href="member_loans.php" class="btn btn-secondary">Close</a></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>