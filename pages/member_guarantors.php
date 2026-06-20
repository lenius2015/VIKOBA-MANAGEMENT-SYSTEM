<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'Guarantor Requests';
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
$guarantor = new Guarantor();

// Handle response to guarantor request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    if ($_POST['action'] === 'accept') {
        if ($guarantor->respond($requestId, 'approved', $_POST['notes'] ?? '')) {
            setFlash('success', 'You have accepted the guarantor request.');
            
            // Notify the borrower
            $stmt = $db->prepare("SELECT g.loan_id, l.member_id, m.name as borrower_name FROM guarantors g JOIN loans l ON g.loan_id = l.id JOIN members m ON l.member_id = m.id WHERE g.id = ?");
            $stmt->execute([$requestId]);
            $loanInfo = $stmt->fetch();
            if ($loanInfo) {
                $notif = new Notification();
                $notif->notifyAdmins('guarantor_accepted', 'Guarantor Accepted',
                    $member['name'] . ' accepted guarantor request for loan',
                    APP_URL . '/pages/loans.php?view=' . $loanInfo['loan_id']);
            }
        } else {
            setFlash('error', 'Failed to accept request. It may have already been responded to.');
        }
    } elseif ($_POST['action'] === 'decline') {
        if ($guarantor->respond($requestId, 'declined', $_POST['notes'] ?? '')) {
            setFlash('success', 'You have declined the guarantor request.');
        } else {
            setFlash('error', 'Failed to decline request.');
        }
    }
    redirect(APP_URL . '/pages/member_guarantors.php');
}

// Get pending requests
$pendingRequests = $guarantor->getPendingRequests($memberId);

// Get active guarantor obligations
$activeObligations = $guarantor->getByMember($memberId);

// Get eligibility info
$eligibility = $guarantor->checkEligibility($memberId);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Pending Requests</div>
      <div class="stat-value"><?= count($pendingRequests) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Active Guarantees</div>
      <div class="stat-value"><?= count(array_filter($activeObligations, fn($g) => in_array($g['loan_status'], ['approved','disbursed']))) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Available Capacity</div>
      <div class="stat-value" style="font-size:14px;"><?= tsh($eligibility['available_capacity'] ?? 0) ?></div>
    </div>
  </div>
</div>

<!-- Pending Requests -->
<div class="card mb-4">
  <div class="card-header">
    <strong><i class="ti ti-bell-ringing me-2"></i>Pending Guarantor Requests</strong>
  </div>
  <div class="card-body">
    <?php if ($pendingRequests): ?>
      <?php foreach ($pendingRequests as $req): ?>
        <div class="border rounded p-3 mb-3" style="background:#fafaf8;">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h6 class="mb-1">
                <i class="ti ti-user text-primary me-1"></i>
                <?= escape($req['borrower_name']) ?> (<?= $req['borrower_no'] ?>)
              </h6>
              <div class="fs-12 text-muted mb-2">
                Loan: <strong><?= escape($req['loan_no']) ?></strong> · 
                Amount: <strong><?= tsh($req['loan_amount']) ?></strong> · 
                Guarantee: <strong><?= tsh($req['amount_guaranteed']) ?></strong>
              </div>
              <div class="fs-12 text-muted">
                <i class="ti ti-calendar me-1"></i> Requested: <?= date('d M Y', strtotime($req['created_at'])) ?>
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-success btn-sm" onclick="respond(<?= $req['id'] ?>, 'accept')">
                <i class="ti ti-check me-1"></i>Accept
              </button>
              <button class="btn btn-danger btn-sm" onclick="respond(<?= $req['id'] ?>, 'decline')">
                <i class="ti ti-x me-1"></i>Decline
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-muted text-center py-4 mb-0">
        <i class="ti ti-check-circle" style="font-size:32px;display:block;margin-bottom:8px;"></i>
        No pending guarantor requests.
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- Active Guarantees -->
<div class="card mb-4">
  <div class="card-header">
    <strong><i class="ti ti-shield-check me-2"></i>My Guarantor Obligations</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Loan No</th><th>Borrower</th><th>Loan Amount</th><th>Guaranteed Amount</th><th>Status</th><th>Date</th></tr>
      </thead>
      <tbody>
      <?php if ($activeObligations): ?>
        <?php foreach ($activeObligations as $g): ?>
        <tr>
          <td><code><?= escape($g['loan_no']) ?></code></td>
          <td><?= escape($g['borrower_name']) ?></td>
          <td><?= tsh($g['amount']) ?></td>
          <td><?= tsh($g['amount_guaranteed']) ?></td>
          <td>
            <?php 
              $statusStyles = [
                'pending' => 'warning text-dark', 
                'approved' => 'success', 
                'declined' => 'danger', 
                'released' => 'secondary'
              ];
            ?>
            <span class="badge bg-<?= $statusStyles[$g['status']] ?? 'secondary' ?>">
              <?= ucfirst($g['status']) ?>
            </span>
            <?php if ($g['loan_status'] === 'disbursed'): ?>
              <span class="badge bg-primary ms-1">Active</span>
            <?php endif; ?>
          </td>
          <td><?= date('d M Y', strtotime($g['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" class="text-center py-4 text-muted">No guarantor records found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Guarantor Eligibility -->
<div class="card">
  <div class="card-header">
    <strong><i class="ti ti-info-circle me-2"></i>My Guarantor Eligibility</strong>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <strong>Status</strong><br>
        <span class="badge bg-<?= $eligibility['eligible'] ? 'success' : 'danger' ?>">
          <?= $eligibility['eligible'] ? 'Eligible' : 'Not Eligible' ?>
        </span>
      </div>
      <div class="col-md-3">
        <strong>Max Guarantee</strong><br><?= tsh($eligibility['max_guarantee'] ?? 0) ?>
      </div>
      <div class="col-md-3">
        <strong>Current Guarantees</strong><br><?= tsh($eligibility['current_guarantees'] ?? 0) ?>
      </div>
      <div class="col-md-3">
        <strong>Available Capacity</strong><br><strong><?= tsh($eligibility['available_capacity'] ?? 0) ?></strong>
      </div>
    </div>
    <?php if (!$eligibility['eligible']): ?>
      <div class="alert alert-warning mt-3 mb-0">
        <i class="ti ti-alert-triangle me-2"></i><?= escape($eligibility['reason']) ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Response Modal -->
<div class="modal fade" id="responseModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="request_id" id="responseRequestId">
        <input type="hidden" name="action" id="responseAction">
        <div class="modal-header">
          <h5 class="modal-title" id="responseModalTitle">Respond to Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="responseMessage">Are you sure you want to respond to this guarantor request?</p>
          <div class="mb-3">
            <label class="form-label">Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Add any notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="responseConfirmBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function respond(id, action) {
  document.getElementById('responseRequestId').value = id;
  document.getElementById('responseAction').value = action;
  
  var title = action === 'accept' ? 'Accept Guarantor Request' : 'Decline Guarantor Request';
  var msg = action === 'accept' 
    ? 'By accepting, you agree to guarantee this loan if the borrower defaults.'
    : 'Are you sure you want to decline this guarantor request?';
  var btnClass = action === 'accept' ? 'btn-success' : 'btn-danger';
  var btnText = action === 'accept' ? 'Accept Request' : 'Decline Request';
  
  document.getElementById('responseModalTitle').textContent = title;
  document.getElementById('responseMessage').textContent = msg;
  var btn = document.getElementById('responseConfirmBtn');
  btn.className = 'btn ' + btnClass;
  btn.textContent = btnText;
  
  var modal = new bootstrap.Modal(document.getElementById('responseModal'));
  modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>