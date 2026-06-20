<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'treasurer']);
$pageTitle = 'Pending Payments';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

$loanModel = new Loan();
$notification = new Notification();

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $repaymentId = (int)($_POST['repayment_id'] ?? 0);

    if ($repaymentId && in_array($action, ['approve','reject','request_modification','cancel'])) {
        try {
            // Get the repayment (try status column first, fallback to any)
            $repayment = null;
            try {
                $stmt = $db->prepare("SELECT * FROM repayments WHERE id = ?");
                $stmt->execute([$repaymentId]);
                $repayment = $stmt->fetch();
            } catch (Exception $e) {}

            if (!$repayment) {
                setFlash('error', 'Repayment not found.');
                redirect(APP_URL . '/pages/pending_payments.php');
            }

            if ($action === 'approve') {
                $loanId = (int)$repayment['loan_id'];
                $amount = (float)$repayment['amount'];
                $memberId = (int)$repayment['member_id'];
                $date = $repayment['date'];

                // Mark the repayment record as approved (update in-place, no delete)
                try {
                    $upd = $db->prepare("UPDATE repayments SET status='approved', reviewed_by=?, reviewed_at=NOW(), recorded_by=? WHERE id=?");
                    $upd->execute([$user['id'], $user['id'], $repaymentId]);
                } catch (Exception $e) {
                    // Status column doesn't exist - just mark via notes
                    $upd = $db->prepare("UPDATE repayments SET recorded_by=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?");
                    $upd->execute([$user['id'], ' | Approved by ' . $user['name'] . ' on ' . date('Y-m-d H:i:s'), $repaymentId]);
                }

                // Apply payment directly to amortization schedule via public helper
                $loanModel->applyManualRepayment($loanId, $amount, $date);

                // Notify member that their repayment was approved
                try {
                  $stmtUser = $db->prepare("SELECT id FROM users WHERE member_id = ? AND status='active' LIMIT 1");
                  $stmtUser->execute([$memberId]);
                  $memberUser = $stmtUser->fetch();
                  if ($memberUser) {
                    $loanInfo = $loanModel->getById($loanId);
                    $loanNo = $loanInfo['loan_no'] ?? $loanId;
                    $notification->create($memberUser['id'], 'repayment_approved', 'Repayment Approved',
                      "Your repayment of " . tsh($amount) . " for loan " . $loanNo . " has been approved and applied.", APP_URL . '/pages/member_loans.php'
                    );
                  }
                } catch (Exception $e) {
                  // ignore notification failures
                }

                setFlash('success', 'Payment approved and applied to loan successfully.');
                $auth->log('Approve Payment', 'repayments', 'Repayment ID: ' . $repaymentId . ', Amount: ' . tsh($amount));
            } elseif ($action === 'reject') {
                // Reject
                $reason = trim($_POST['rejection_reason'] ?? '');
                try {
                  $stmt = $db->prepare("UPDATE repayments SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE id=?");
                  $stmt->execute([$user['id'], $reason, $repaymentId]);
                } catch (Exception $e) {
                  // Status column doesn't exist - update notes with rejection
                  $stmt = $db->prepare("UPDATE repayments SET recorded_by=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?");
                  $stmt->execute([$user['id'], ' | REJECTED by ' . $user['name'] . ': ' . $reason, $repaymentId]);
                }

                // Notify member about rejection
                try {
                  $stmtUser = $db->prepare("SELECT id FROM users WHERE member_id = ? AND status='active' LIMIT 1");
                  $stmtUser->execute([$repayment['member_id']]);
                  $memberUser = $stmtUser->fetch();
                  if ($memberUser) {
                    $loanInfo = $loanModel->getById((int)$repayment['loan_id']);
                    $loanNo = $loanInfo['loan_no'] ?? $repayment['loan_id'];
                    $notification->create($memberUser['id'], 'repayment_rejected', 'Repayment Rejected',
                      "Your repayment of " . tsh((float)$repayment['amount']) . " for loan " . $loanNo . " was rejected. Reason: " . $reason,
                      APP_URL . '/pages/member_payments.php'
                    );
                  }
                } catch (Exception $e) {
                  // ignore notification failures
                }
                setFlash('success', 'Payment rejected.');
                $auth->log('Reject Payment', 'repayments', 'Repayment ID: ' . $repaymentId);
            } elseif ($action === 'request_modification') {
                // Request modification - ask member to correct payment details or amount
                $note = trim($_POST['modification_note'] ?? 'Please review and update your payment details.');
                try {
                  $stmt = $db->prepare("UPDATE repayments SET status='modification_requested', reviewed_by=?, reviewed_at=NOW(), notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?");
                  $stmt->execute([$user['id'], ' | Modification requested by ' . $user['name'] . ': ' . $note, $repaymentId]);
                } catch (Exception $e) {
                  // Fallback: append to notes
                  $stmt = $db->prepare("UPDATE repayments SET recorded_by=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?");
                  $stmt->execute([$user['id'], ' | Modification requested by ' . $user['name'] . ': ' . $note, $repaymentId]);
                }

                // Notify member about modification request
                try {
                  $stmtUser = $db->prepare("SELECT id FROM users WHERE member_id = ? AND status='active' LIMIT 1");
                  $stmtUser->execute([$repayment['member_id']]);
                  $memberUser = $stmtUser->fetch();
                  if ($memberUser) {
                    $loanInfo = $loanModel->getById((int)$repayment['loan_id']);
                    $loanNo = $loanInfo['loan_no'] ?? $repayment['loan_id'];
                    $notification->create($memberUser['id'], 'repayment_modification_requested', 'Repayment Modification Requested',
                      "Your repayment of " . tsh((float)$repayment['amount']) . " for loan " . $loanNo . " requires modification: " . $note,
                      APP_URL . '/pages/member_payments.php'
                    );
                  }
                } catch (Exception $e) {}
                setFlash('success', 'Member requested to modify payment.');
                $auth->log('Request Modification', 'repayments', 'Repayment ID: ' . $repaymentId . ', Note: ' . $note);
            } elseif ($action === 'cancel') {
                // Cancel payment (mark as canceled)
                $reason = trim($_POST['cancellation_reason'] ?? 'Canceled by admin');
                try {
                  $stmt = $db->prepare("UPDATE repayments SET status='canceled', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE id=?");
                  $stmt->execute([$user['id'], $reason, $repaymentId]);
                } catch (Exception $e) {
                  $stmt = $db->prepare("UPDATE repayments SET recorded_by=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?");
                  $stmt->execute([$user['id'], ' | CANCELED by ' . $user['name'] . ': ' . $reason, $repaymentId]);
                }
                // Notify member about cancellation
                try {
                  $stmtUser = $db->prepare("SELECT id FROM users WHERE member_id = ? AND status='active' LIMIT 1");
                  $stmtUser->execute([$repayment['member_id']]);
                  $memberUser = $stmtUser->fetch();
                  if ($memberUser) {
                    $loanInfo = $loanModel->getById((int)$repayment['loan_id']);
                    $loanNo = $loanInfo['loan_no'] ?? $repayment['loan_id'];
                    $notification->create($memberUser['id'], 'repayment_canceled', 'Repayment Canceled',
                      "Your repayment of " . tsh((float)$repayment['amount']) . " for loan " . $loanNo . " was canceled. Reason: " . $reason,
                      APP_URL . '/pages/member_payments.php'
                    );
                  }
                } catch (Exception $e) {}
                setFlash('success', 'Payment canceled.');
                $auth->log('Cancel Payment', 'repayments', 'Repayment ID: ' . $repaymentId);
            }
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            setFlash('error', 'Error processing payment: ' . $e->getMessage());
        }
    }

    redirect(APP_URL . '/pages/pending_payments.php');
}

// Get all pending payments
$pendingPayments = [];
try {
    // Check if status column exists
    $hasStatusCol = false;
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM repayments LIKE 'status'");
        $hasStatusCol = (bool)$colCheck->fetch();
    } catch (Exception $e) {}

    if ($hasStatusCol) {
      $stmt = $db->query(
        "SELECT r.*, l.loan_no, m.name as member_name, m.member_no, m.phone as member_phone,
            u.name as reviewed_by_name
         FROM repayments r
         JOIN loans l ON r.loan_id = l.id
         JOIN members m ON r.member_id = m.id
         LEFT JOIN users u ON r.reviewed_by = u.id
         WHERE r.status = 'pending'
         ORDER BY r.created_at DESC"
      );
        $pendingPayments = $stmt->fetchAll();
    } else {
        // Status column doesn't exist - show recent member-submitted repayments instead
        // These are repayments where notes contain 'Online repayment by member'
        // Exclude ones that have been annotated as approved/rejected in notes
        $stmt = $db->query(
          "SELECT r.*, l.loan_no, m.name as member_name, m.member_no, m.phone as member_phone,
                  NULL as reviewed_by_name
           FROM repayments r
           JOIN loans l ON r.loan_id = l.id
           JOIN members m ON r.member_id = m.id
           WHERE r.notes LIKE '%Online repayment by member%'
           AND r.notes NOT LIKE '%Approved by %' AND r.notes NOT LIKE '%REJECTED by %'
           ORDER BY r.created_at DESC
           LIMIT 50"
        );
        $pendingPayments = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $pendingPayments = [];
}

// Get counts
$totalPending = count($pendingPayments);
$totalAmount = array_sum(array_column($pendingPayments, 'amount'));

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#FFEAA7;color:#8B6914;"><i class="ti ti-clock-hour-4"></i></div>
      <div class="stat-label">Pending Payments</div>
      <div class="stat-value"><?= $totalPending ?></div>
      <div class="stat-sub">Awaiting review</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#EAF3DE;color:#3B6D11;"><i class="ti ti-cash"></i></div>
      <div class="stat-label">Total Amount Pending</div>
      <div class="stat-value"><?= tsh($totalAmount) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#E6F1FB;color:#185FA5;"><i class="ti ti-users"></i></div>
      <div class="stat-label">Submitted By Members</div>
      <div class="stat-value">Requires Admin</div>
      <div class="stat-sub">Approval to apply to loans</div>
    </div>
  </div>
</div>

<?php if ($pendingPayments): ?>
<div class="card">
  <div class="card-header">
    <strong><i class="ti ti-list-check me-2"></i>Pending Payment Approvals</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Date</th>
          <th>Member</th>
          <th>Loan No</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Notes</th>
          <th>Reviewed</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingPayments as $p): ?>
        <tr>
          <td><?= date('d M Y', strtotime($p['date'])) ?></td>
          <td>
            <strong><?= escape($p['member_name']) ?></strong>
            <br><small class="text-muted"><?= $p['member_no'] ?> · <?= $p['member_phone'] ?></small>
          </td>
          <td><code><?= escape($p['loan_no']) ?></code></td>
          <td><strong><?= tsh($p['amount']) ?></strong></td>
          <td><span class="badge badge-primary"><?= ucfirst(str_replace('_', ' ', $p['payment_method'])) ?></span></td>
          <td><?= escape($p['reference'] ?? '—') ?></td>
          <td><small class="text-muted"><?= escape(substr($p['notes'] ?? '', 0, 50)) ?></small></td>
          <td>
            <?php if (!empty($p['reviewed_by_name']) || !empty($p['reviewed_at'])): ?>
              <small class="text-muted"><?= escape($p['reviewed_by_name'] ?? 'Reviewer') ?> · <?= escape(date('d M Y H:i', strtotime($p['reviewed_at'] ?? $p['updated_at'] ?? 'now'))) ?></small>
            <?php else: ?>
              <small class="text-muted">—</small>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-1" style="min-width:160px;">
                <form method="POST" class="d-inline" onsubmit="return confirm('Approve this payment? It will be applied to the loan.');">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="repayment_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success">
                    <i class="ti ti-check me-1"></i>Approve
                  </button>
                </form>

                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modifyModal"
                  data-repay-id="<?= $p['id'] ?>"
                  data-member="<?= escape($p['member_name']) ?>"
                  data-amount="<?= tsh($p['amount']) ?>">
                  <i class="ti ti-pencil me-1"></i>Request Modification
                </button>

                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                  data-repay-id="<?= $p['id'] ?>"
                  data-member="<?= escape($p['member_name']) ?>"
                  data-amount="<?= tsh($p['amount']) ?>">
                  <i class="ti ti-x me-1"></i>Reject
                </button>

                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this payment? This will mark the payment as canceled.');">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="repayment_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-ban me-1"></i>Cancel
                  </button>
                </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="repayment_id" id="reject_repay_id">
        <div class="modal-header">
          <h5 class="modal-title">Reject Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to reject this payment?</p>
          <div class="alert alert-info">
            <strong id="reject_member"></strong> — Amount: <strong id="reject_amount"></strong>
          </div>
          <div class="mb-3">
            <label class="form-label">Reason for Rejection *</label>
            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="e.g. Payment not received, incorrect amount, etc."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="ti ti-x me-1"></i>Reject Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modification Modal -->
<div class="modal fade" id="modifyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="request_modification">
        <input type="hidden" name="repayment_id" id="modify_repay_id">
        <div class="modal-header">
          <h5 class="modal-title">Request Modification</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Ask the member to modify their payment details or amount.</p>
          <div class="alert alert-info">
            <strong id="modify_member"></strong> — Amount: <strong id="modify_amount"></strong>
          </div>
          <div class="mb-3">
            <label class="form-label">Modification Note *</label>
            <textarea name="modification_note" class="form-control" rows="3" required placeholder="Describe what needs to be modified (amount, reference, method, etc.)"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning"><i class="ti ti-pencil me-1"></i>Request Modification</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('reject_repay_id').value = btn.dataset.repayId;
  document.getElementById('reject_member').textContent = btn.dataset.member;
  document.getElementById('reject_amount').textContent = btn.dataset.amount;
});
document.getElementById('modifyModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('modify_repay_id').value = btn.dataset.repayId;
  document.getElementById('modify_member').textContent = btn.dataset.member;
  document.getElementById('modify_amount').textContent = btn.dataset.amount;
});
</script>

<?php else: ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="ti ti-circle-check" style="font-size:48px;color:#3B6D11;"></i>
    <h5 class="mt-3">No Pending Payments</h5>
    <p class="text-muted">All member-submitted payments have been reviewed.</p>
    <a href="<?= APP_URL ?>/pages/loans.php" class="btn btn-primary">Manage Loans</a>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>