<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'Make Payment';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

// Get member linked to this user
$stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
$stmt->execute([$user['member_id']]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'No member profile linked.');
    redirect(APP_URL . '/pages/logout.php');
}

$memberId = $member['id'];
$mpesa = new Mpesa();
$loanModel = new Loan();

// Get active loans for this member (loans that need repayment)
// Include: disbursed, active (legacy), approved — any status where repayment is needed
$activeLoans = $loanModel->getAll(['member_id' => $memberId, 'status' => 'disbursed']);
// Also get loans with other active statuses (legacy 'active', 'approved' that may have been disbursed)
$stmt = $db->prepare(
    "SELECT l.*, m.name as member_name, m.member_no,
            COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
     FROM loans l
     JOIN members m ON l.member_id = m.id
     WHERE l.member_id = ? AND l.status IN ('active', 'approved')
     ORDER BY l.created_at DESC"
);
$stmt->execute([$memberId]);
$additionalLoans = $stmt->fetchAll();
$seenIds = array_column($activeLoans, 'id');
foreach ($additionalLoans as $al) {
    if (!in_array($al['id'], $seenIds)) {
        $activeLoans[] = $al;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ========== MANUAL REPAYMENT (Cash / Bank / Mobile Money) ==========
    if ($_POST['action'] === 'manual_repay') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $reference = trim((string)($_POST['reference'] ?? ''));
        $repaymentDate = $_POST['date'] ?? date('Y-m-d');

        // Validate payment method
        $allowedMethods = ['cash', 'mobile_money', 'bank_transfer'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            setFlash('error', 'Invalid payment method.');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        // Verify loan belongs to member and is disbursed/active
        $stmt = $db->prepare("SELECT l.id, l.status, l.total_repayable,
                                     COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
                              FROM loans l
                              WHERE l.id=? AND l.member_id=?");
        $stmt->execute([$loanId, $memberId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            setFlash('error', 'Invalid loan selected.');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        $repayableStatuses = ['disbursed', 'active', 'approved'];
        if (!in_array($loan['status'], $repayableStatuses)) {
            setFlash('error', 'This loan is not in a repayable status (' . $loan['status'] . ').');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        $outstanding = (float)$loan['total_repayable'] - (float)$loan['total_paid'];
        if ($amount <= 0) {
            setFlash('error', 'Invalid repayment amount.');
            redirect(APP_URL . '/pages/member_payments.php');
        }
        if ($outstanding <= 0) {
            setFlash('error', 'This loan is already fully repaid.');
            redirect(APP_URL . '/pages/member_payments.php');
        }
        if ($amount > $outstanding) {
            setFlash('error', 'Repayment amount cannot exceed outstanding balance (' . tsh($outstanding) . ').');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        $repayData = [
            'loan_id' => $loanId,
            'member_id' => $memberId,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'date' => $repaymentDate,
            'recorded_by' => $user['id'],
            'notes' => 'Online repayment by member',
            'status' => 'pending' // Requires admin approval
        ];

        if ($loanModel->addRepayment($repayData)) {
            // Notify admins about the new pending repayment
            try {
              $notification = new Notification();
              $loanInfo = $loanModel->getById($loanId);
              $loanNo = $loanInfo['loan_no'] ?? $loanId;
              $message = $member['name'] . " submitted a repayment of " . tsh($amount) . " for loan " . $loanNo . ". Please review and confirm.";
              $notification->notifyAdmins('repayment', 'New Repayment Pending Approval', $message, APP_URL . '/pages/pending_payments.php');
            } catch (Exception $e) {
              // silent failure for notifications
            }

            setFlash('success', 'Repayment of ' . tsh($amount) . ' recorded successfully. Awaiting admin confirmation.');
        } else {
            setFlash('error', 'Failed to record repayment.');
        }
        redirect(APP_URL . '/pages/member_payments.php');
    }

    // ========== M-PESA PAYMENT ==========
    if ($_POST['action'] === 'mpesa_pay') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $phone = trim($_POST['phone'] ?? $member['phone']);

        if (!$loanId || $amount <= 0) {
            setFlash('error', 'Please select a loan and enter a valid amount.');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        if ($amount < 100) {
            setFlash('error', 'Minimum payment amount is Tsh 100.');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        // Verify loan belongs to member and is in repayable status
        $stmt = $db->prepare("SELECT l.id, l.status, l.total_repayable,
                                     COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
                              FROM loans l
                              WHERE l.id=? AND l.member_id=?");
        $stmt->execute([$loanId, $memberId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            setFlash('error', 'Invalid loan selected.');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        $repayableStatuses = ['disbursed', 'active', 'approved'];
        if (!in_array($loan['status'], $repayableStatuses)) {
            setFlash('error', 'This loan is not in a repayable status (' . $loan['status'] . ').');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        $outstanding = (float)$loan['total_repayable'] - (float)$loan['total_paid'];
        if ($amount > $outstanding) {
            setFlash('error', 'Payment amount cannot exceed outstanding balance (' . tsh($outstanding) . ').');
            redirect(APP_URL . '/pages/member_payments.php');
        }

        // Initiate STK Push
        $result = $mpesa->stkPush($amount, $phone, 'LN-' . $loanId, 'Loan Repayment');

        if ($result['success']) {
            $_SESSION['mpesa_checkout'] = $result['checkout_request_id'];
            $_SESSION['mpesa_loan_id'] = $loanId;
            $_SESSION['mpesa_amount'] = $amount;

            setFlash('success', 'M-Pesa payment request sent. Please check your phone and enter your PIN to complete payment.');
        } else {
            setFlash('error', 'Payment failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        redirect(APP_URL . '/pages/member_payments.php');
    }

    // ========== CHECK M-PESA STATUS ==========
    if ($_POST['action'] === 'check_status') {
        $checkoutId = $_POST['checkout_request_id'] ?? '';
        if ($checkoutId) {
            $status = $mpesa->queryStatus($checkoutId);
            if (!empty($status['ResultCode']) && $status['ResultCode'] == 0) {
                setFlash('success', 'Payment confirmed! Your repayment has been recorded.');
            } elseif (!empty($status['ResultCode'])) {
                setFlash('error', 'Payment was not completed. Please try again.');
            } else {
                setFlash('info', 'Payment is still being processed. Please check again later.');
            }
        }
        redirect(APP_URL . '/pages/member_payments.php');
    }

    // ========== CLEAR PENDING M-PESA ==========
    if ($_POST['action'] === 'clear_pending') {
        unset($_SESSION['mpesa_checkout'], $_SESSION['mpesa_loan_id'], $_SESSION['mpesa_amount']);
        redirect(APP_URL . '/pages/member_payments.php');
    }
}

// Get M-Pesa transactions for this member
$transactions = [];
try {
    $stmt = $db->prepare(
        "SELECT t.*, l.loan_no FROM mpesa_transactions t 
         LEFT JOIN loans l ON t.loan_id = l.id 
         WHERE t.phone_number LIKE ? OR t.phone_number LIKE ?
         ORDER BY t.created_at DESC LIMIT 10"
    );
    $stmt->execute(['%' . $member['phone'] . '%', '%' . substr($member['phone'], -9) . '%']);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    $transactions = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#E6F1FB;color:#185FA5;"><i class="ti ti-credit-card icon-primary"></i></div>
      <div class="stat-label">Active Loans</div>
      <div class="stat-value"><?= count($activeLoans) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#EAF3DE;color:#3B6D11;"><i class="ti ti-coin icon-success"></i></div>
      <div class="stat-label">Your Phone</div>
      <div class="stat-value" style="font-size:15px;"><?= escape($member['phone']) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#E1F5EE;color:#0F6E56;"><i class="ti ti-cash icon-info"></i></div>
      <div class="stat-label">Total Repaid</div>
      <div class="stat-value"><?= tsh($loanModel->getTotalRepaid()) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#FCEBEB;color:#A32D2D;"><i class="ti ti-brand-mpesa icon-danger"></i></div>
      <div class="stat-label">M-Pesa</div>
      <div class="stat-value">
        <span class="badge bg-<?= $mpesa->isActive() ? 'success' : 'danger' ?>" style="font-size:13px;">
          <?= $mpesa->isActive() ? 'Active' : 'Not Configured' ?>
        </span>
      </div>
    </div>
  </div>
</div>

<?php if (isset($_SESSION['mpesa_checkout'])): ?>
<!-- Pending M-Pesa Payment -->
<div class="card mb-4 border-info">
  <div class="card-header bg-info text-white">
    <strong><i class="ti ti-cash me-2 icon-white"></i>Pending M-Pesa Payment</strong>
  </div>
  <div class="card-body text-center py-4">
    <i class="ti ti-clock-hour-4 icon-primary" style="font-size:48px;display:block;margin-bottom:12px;"></i>
    <h5>Payment Request Sent</h5>
    <p class="text-muted mb-3">
      Amount: <strong>Tsh <?= number_format($_SESSION['mpesa_amount']) ?></strong><br>
      Please check your phone and enter your M-Pesa PIN to complete the payment.
    </p>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action" value="check_status">
      <input type="hidden" name="checkout_request_id" value="<?= $_SESSION['mpesa_checkout'] ?>">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-refresh me-1 icon-white"></i>Check Payment Status
      </button>
    </form>
    <form method="POST" style="display:inline;margin-left:8px;">
      <input type="hidden" name="action" value="clear_pending">
      <button type="submit" class="btn btn-outline-secondary">
        <i class="ti ti-x me-1 icon-muted"></i>Clear
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!$activeLoans): ?>
<div class="alert alert-info">
  <i class="ti ti-info-circle me-2 icon-primary"></i>You have no active loans that require repayment at this time.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- ===== SECTION 1: MANUAL REPAYMENT (Cash / Bank / Mobile Money) ===== -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <strong><i class="ti ti-hand-stop me-2 icon-primary"></i>Manual Repayment</strong>
      </div>
      <div class="card-body">
        <p class="text-muted fs-12 mb-3">
          Record a payment you've made via <strong>Cash</strong>, <strong>Bank Transfer</strong>, or <strong>Mobile Money</strong> (non-M-Pesa).
          This will be recorded and confirmed by an administrator.
        </p>
        <form method="POST">
          <input type="hidden" name="action" value="manual_repay">

          <div class="mb-3">
            <label class="form-label fw-500">Select Loan *</label>
            <select name="loan_id" class="form-select" required>
              <option value="">— Select a loan to repay —</option>
              <?php foreach ($activeLoans as $l):
                $balance = (float)$l['total_repayable'] - (float)$l['total_paid'];
              ?>
                <option value="<?= $l['id'] ?>">
                  <?= escape($l['loan_no']) ?> — Balance: <?= tsh($balance) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Amount (Tsh) *</label>
              <input name="amount" type="number" step="0.01" class="form-control" required min="100" placeholder="e.g. 50000">
            </div>
            <div class="col-6">
              <label class="form-label">Payment Method *</label>
              <select name="payment_method" class="form-select" required>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Payment Date</label>
              <input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Reference No.</label>
              <input name="reference" class="form-control" placeholder="Optional ref number">
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100" <?= !$activeLoans ? 'disabled' : '' ?>>
            <i class="ti ti-check me-1 icon-white"></i>Record Payment
          </button>
          <div class="fs-11 text-muted mt-2 text-center">
            <i class="ti ti-info-circle me-1 icon-primary"></i>
            Payment will be recorded and requires admin confirmation
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== SECTION 2: M-PESA PAYMENT ===== -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <strong><i class="ti ti-brand-mpesa me-2 icon-success"></i>M-Pesa Payment</strong>
      </div>
      <div class="card-body">
        <?php if (!$mpesa->isActive()): ?>
          <div class="alert alert-warning mb-0">
            <i class="ti ti-alert-triangle me-2 icon-warning"></i>M-Pesa payment is not yet configured. Please contact the administrator.
          </div>
        <?php else: ?>
          <p class="text-muted fs-12 mb-3">
            Pay instantly via <strong>M-Pesa STK Push</strong>. An prompt will be sent to your phone — just enter your PIN to complete.
          </p>
          <form method="POST" id="mpesaForm">
            <input type="hidden" name="action" value="mpesa_pay">

            <div class="mb-3">
              <label class="form-label fw-500">Select Loan *</label>
              <select name="loan_id" class="form-select" required>
                <option value="">— Select a loan to repay —</option>
                <?php foreach ($activeLoans as $l):
                  $balance = (float)$l['total_repayable'] - (float)$l['total_paid'];
                ?>
                  <option value="<?= $l['id'] ?>">
                    <?= escape($l['loan_no']) ?> — Balance: <?= tsh($balance) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-6">
                <label class="form-label">Amount (Tsh) *</label>
                <input name="amount" type="number" class="form-control" required min="100" step="100" placeholder="e.g. 50000">
              </div>
              <div class="col-6">
                <label class="form-label">M-Pesa Phone *</label>
                <input name="phone" type="text" class="form-control" required
                       value="<?= escape($member['phone']) ?>"
                       placeholder="e.g. 0712345678">
              </div>
            </div>

            <button type="submit" class="btn btn-success w-100" <?= !$activeLoans ? 'disabled' : '' ?>>
            <i class="ti ti-brand-mpesa me-1 icon-white"></i>Pay with M-Pesa
            </button>
            <div class="fs-11 text-muted mt-2 text-center">
              <i class="ti ti-info-circle me-1"></i>
              You will receive an STK Push prompt on your phone
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Loan Repayment Summary -->
<div class="card mb-4">
  <div class="card-header">
    <strong><i class="ti ti-credit-card me-2 icon-warning"></i>Your Active Loans</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Loan No</th>
          <th>Amount</th>
          <th>Total Repayable</th>
          <th>Paid So Far</th>
          <th>Balance</th>
          <th>Progress</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($activeLoans): ?>
        <?php foreach ($activeLoans as $l):
          $balance = (float)$l['total_repayable'] - (float)$l['total_paid'];
          $pct = $l['total_repayable'] > 0 ? round(($l['total_paid'] / $l['total_repayable']) * 100) : 0;
        ?>
        <tr>
          <td><code><?= escape($l['loan_no']) ?></code></td>
          <td><?= tsh($l['amount']) ?></td>
          <td><?= tsh($l['total_repayable']) ?></td>
          <td><?= tsh($l['total_paid']) ?></td>
          <td><strong><?= tsh($balance) ?></strong></td>
          <td style="min-width:100px">
            <div class="progress" style="height:6px;">
              <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#3B6D11' : '#185FA5' ?>"></div>
            </div>
            <small class="text-muted"><?= $pct ?>%</small>
          </td>
          <td>
            <a href="<?= APP_URL ?>/pages/member_loans.php?view=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary">
              <i class="ti ti-eye"></i> View
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">No active loans.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Recent M-Pesa Transactions -->
<div class="card">
  <div class="card-header">
    <strong><i class="ti ti-history me-2 icon-info"></i>Recent M-Pesa Transactions</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Date</th><th>Transaction ID</th><th>Loan</th><th>Amount</th><th>Phone</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php if ($transactions): ?>
        <?php foreach ($transactions as $t): ?>
        <tr>
          <td><?= date('d M H:i', strtotime($t['created_at'])) ?></td>
          <td><code><?= escape(substr($t['transaction_id'], 0, 15)) ?>...</code></td>
          <td><?= escape($t['loan_no'] ?? '—') ?></td>
          <td><?= tsh($t['amount']) ?></td>
          <td><?= $mpesa->formatPhone($t['phone_number']) ?></td>
          <td>
            <?php
              $statusClasses = ['pending' => 'warning text-dark', 'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary'];
            ?>
            <span class="badge bg-<?= $statusClasses[$t['status']] ?? 'secondary' ?>">
              <?= ucfirst($t['status']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" class="text-center py-4 text-muted">No M-Pesa transactions yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>