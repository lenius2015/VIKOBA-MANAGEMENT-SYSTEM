<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'My Fines';
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

// Handle pay fine action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_fine') {
    $fineId = (int)$_POST['fine_id'];
    $amount = (float)$_POST['amount'];
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $reference = $_POST['reference'] ?? '';
    $paymentDate = $_POST['date'] ?? date('Y-m-d');

    // Verify fine belongs to member and is unpaid
    $stmt = $db->prepare("SELECT id, amount, paid FROM fines WHERE id=? AND member_id=?");
    $stmt->execute([$fineId, $memberId]);
    $fine = $stmt->fetch();

    if (!$fine || $fine['paid']) {
        setFlash('error', 'Fine not found or already paid.');
        redirect(APP_URL . '/pages/member_fines.php');
    }

    if ($amount <= 0 || $amount > $fine['amount']) {
        setFlash('error', 'Invalid payment amount. Maximum: ' . tsh($fine['amount']));
        redirect(APP_URL . '/pages/member_fines.php');
    }

    // Mark as paid
    $stmt = $db->prepare("UPDATE fines SET paid=1, paid_date=? WHERE id=?");
    if ($stmt->execute([$paymentDate, $fineId])) {
        // Notify admin
        $notif = new Notification();
        $notif->notifyAdmins('fine_paid', 'Fine Paid',
            $member['name'] . ' paid a fine of Tsh ' . number_format($amount, 2),
            APP_URL . '/pages/fines.php');
        
        setFlash('success', 'Fine of ' . tsh($amount) . ' marked as paid successfully.');
    } else {
        setFlash('error', 'Failed to process fine payment.');
    }
    redirect(APP_URL . '/pages/member_fines.php');
}

// Get fines
$stmt = $db->prepare("SELECT f.*, u.name as issued_by_name FROM fines f LEFT JOIN users u ON f.issued_by=u.id WHERE f.member_id=? ORDER BY f.date DESC");
$stmt->execute([$memberId]);
$fines = $stmt->fetchAll();

$totalPending = array_sum(array_column(array_filter($fines, fn($f) => !$f['paid']), 'amount'));
$totalPaid = array_sum(array_column(array_filter($fines, fn($f) => $f['paid']), 'amount'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Total Fines</div>
      <div class="stat-value"><?= count($fines) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Pending Payment</div>
      <div class="stat-value" style="color:#A32D2D;"><?= tsh($totalPending) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Paid</div>
      <div class="stat-value" style="color:#3B6D11;"><?= tsh($totalPaid) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <strong><i class="ti ti-alert-triangle me-2 icon-danger"></i>My Fines & Penalties</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>#</th><th>Reason</th><th>Amount</th><th>Date</th><th>Issued By</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if ($fines): ?>
        <?php foreach ($fines as $i => $f): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= escape($f['reason']) ?></td>
          <td><?= tsh($f['amount']) ?></td>
          <td><?= $f['date'] ?></td>
          <td><?= escape($f['issued_by_name'] ?? '—') ?></td>
          <td><?= badgeStatus($f['paid'] ? 'paid' : 'unpaid') ?></td>
          <td>
            <?php if (!$f['paid']): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#payFineModal"
                data-fine-id="<?=$f['id']?>" data-fine-amount="<?=$f['amount']?>" data-fine-reason="<?=escape($f['reason'])?>">
                <i class="ti ti-cash me-1"></i>Pay Now
              </button>
            <?php else: ?>
              <span class="fs-11 text-muted">Paid on <?= $f['paid_date'] ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">No fines recorded.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pay Fine Modal -->
<div class="modal fade" id="payFineModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="pay_fine"/>
        <input type="hidden" name="fine_id" id="pay_fine_id"/>
        <div class="modal-header">
          <h5 class="modal-title">Pay Fine — <span id="pay_fine_reason"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">Fine amount: <strong id="pay_fine_amount"></strong></div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Payment Amount (Tsh) *</label>
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
                <i class="ti ti-info-circle me-1 icon-primary"></i> You are confirming payment of this fine. Admin will be notified.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check me-1 icon-white"></i>Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('payFineModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('pay_fine_id').value = btn.dataset.fineId;
  document.getElementById('pay_fine_reason').textContent = btn.dataset.fineReason;
  document.getElementById('pay_fine_amount').textContent = 'Tsh ' + parseFloat(btn.dataset.fineAmount).toLocaleString('en-TZ', {minimumFractionDigits:2});
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>