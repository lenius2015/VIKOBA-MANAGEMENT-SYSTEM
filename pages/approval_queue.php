<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'treasurer']);

$pageTitle = 'Approval Queue';
$user = $auth->getUser();
$db = Database::getInstance()->getConnection();
$model = new Loan();
$notif = new Notification();
$productModel = new LoanProduct();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'];
    $loanIds = isset($_POST['loan_ids']) ? array_map('intval', (array)$_POST['loan_ids']) : [];
    $notes = trim($_POST['bulk_notes'] ?? '');
    $reason = trim($_POST['bulk_reason'] ?? '');

    if (empty($loanIds)) {
        setFlash('error', 'No loans selected.');
        redirect(APP_URL . '/pages/approval_queue.php');
    }

    $count = 0;
    $actionLabel = '';

    if ($bulkAction === 'approve') {
        $level = $_POST['approval_level'] ?? 'officer';
        $count = $model->bulkApprove($loanIds, $user['id'], $level, $notes ?: 'Bulk approval');
        $actionLabel = "approved $count loans";
    } elseif ($bulkAction === 'reject') {
        $count = $model->bulkReject($loanIds, $user['id'], $reason ?: 'Bulk rejection');
        $actionLabel = "rejected $count loans";
    } elseif ($bulkAction === 'request_changes') {
        $count = $model->bulkRequestChanges($loanIds, $user['id'], $notes ?: 'Bulk request for changes');
        $actionLabel = "requested changes for $count loans";
    }

    $notif->notifyBulkAction($count, $actionLabel, $user['name']);
    $auth->log("Bulk $bulkAction", 'loans', "Processed $count loans");

    if ($count > 0) {
        setFlash('success', "Bulk action completed: $actionLabel.");
    } else {
        setFlash('error', 'No loans were processed.');
    }
    redirect(APP_URL . '/pages/approval_queue.php');
}

// Get filters
$filterLevel = $_GET['level'] ?? '';
$filterRisk = $_GET['risk'] ?? '';
$filterMinAmt = $_GET['min_amount'] ?? '';
$filterMaxAmt = $_GET['max_amount'] ?? '';
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterDays = (int)($_GET['days_pending'] ?? 0);

// Get pending counts
$pendingCounts = $model->getPendingCountsByLevel();

// Determine which levels this user can see based on their role
$userRole = $user['role'];
$roleLevelMap = ['admin' => ['officer','treasurer','admin'], 'treasurer' => ['treasurer'], 'officer' => ['officer']];
$allowedLevels = $roleLevelMap[$userRole] ?? ['officer'];

// Get pending loans based on filters
$filters = [];
if ($filterRisk) $filters['risk_level'] = $filterRisk;
if ($filterMinAmt) $filters['min_amount'] = (float)$filterMinAmt;
if ($filterMaxAmt) $filters['max_amount'] = (float)$filterMaxAmt;
if ($filterProduct) $filters['product_id'] = $filterProduct;
if ($filterDays) $filters['days_pending'] = $filterDays;

// If user selected a filter level, only apply if it's allowed for their role
$level = $filterLevel ?: null;
if ($level && !in_array($level, $allowedLevels)) {
    $level = $allowedLevels[0];
} elseif (!$level && count($allowedLevels) === 1) {
    // Auto-filter to the only level this role can see
    $level = $allowedLevels[0];
}

$pendingLoans = $model->getPendingApprovals($level, $filters);
$products = $productModel->getAll(true);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stat-card-clickable { cursor: pointer; transition: all 0.2s; }
.stat-card-clickable:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.stat-card-clickable.active { border-color: #185FA5; box-shadow: 0 0 0 2px rgba(24,95,165,0.2); }
.fs-10 { font-size: 10px; }
</style>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <a href="?level=officer" class="text-decoration-none">
      <div class="stat-card <?= $filterLevel === 'officer' ? 'active' : '' ?> stat-card-clickable">
        <div class="stat-label">Pending Officer</div>
        <div class="stat-value"><?= $pendingCounts['counts']['officer'] ?? 0 ?></div>
        <div class="fs-12 text-muted mt-1">
          <?= $pendingCounts['high_risk']['officer'] ?? 0 ?> high risk &middot;
          <?= $pendingCounts['aged']['officer'] ?? 0 ?> aged 7+ days
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <a href="?level=treasurer" class="text-decoration-none">
      <div class="stat-card <?= $filterLevel === 'treasurer' ? 'active' : '' ?> stat-card-clickable">
        <div class="stat-label">Pending Treasurer</div>
        <div class="stat-value"><?= $pendingCounts['counts']['treasurer'] ?? 0 ?></div>
        <div class="fs-12 text-muted mt-1">
          <?= $pendingCounts['high_risk']['treasurer'] ?? 0 ?> high risk &middot;
          <?= $pendingCounts['aged']['treasurer'] ?? 0 ?> aged 7+ days
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <a href="?level=admin" class="text-decoration-none">
      <div class="stat-card <?= $filterLevel === 'admin' ? 'active' : '' ?> stat-card-clickable">
        <div class="stat-label">Pending Admin</div>
        <div class="stat-value"><?= $pendingCounts['counts']['admin'] ?? 0 ?></div>
        <div class="fs-12 text-muted mt-1">
          <?= $pendingCounts['high_risk']['admin'] ?? 0 ?> high risk &middot;
          <?= $pendingCounts['aged']['admin'] ?? 0 ?> aged 7+ days
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label">Total Pending</div>
      <div class="stat-value"><?= array_sum($pendingCounts['counts']) ?></div>
      <div class="fs-12 text-muted mt-1">
        <?= array_sum($pendingCounts['high_risk']) ?> high risk total
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label fs-11 mb-1">Approval Level</label>
        <select name="level" class="form-select form-select-sm">
          <option value="">All Levels</option>
          <option value="officer" <?= $filterLevel === 'officer' ? 'selected' : '' ?>>Officer</option>
          <option value="treasurer" <?= $filterLevel === 'treasurer' ? 'selected' : '' ?>>Treasurer</option>
          <option value="admin" <?= $filterLevel === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fs-11 mb-1">Risk Level</label>
        <select name="risk" class="form-select form-select-sm">
          <option value="">All Risks</option>
          <option value="low" <?= $filterRisk === 'low' ? 'selected' : '' ?>>Low</option>
          <option value="medium" <?= $filterRisk === 'medium' ? 'selected' : '' ?>>Medium</option>
          <option value="high" <?= $filterRisk === 'high' ? 'selected' : '' ?>>High</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fs-11 mb-1">Min Amount</label>
        <input type="number" name="min_amount" class="form-control form-control-sm" value="<?= $filterMinAmt ?>" placeholder="0"/>
      </div>
      <div class="col-md-2">
        <label class="form-label fs-11 mb-1">Max Amount</label>
        <input type="number" name="max_amount" class="form-control form-control-sm" value="<?= $filterMaxAmt ?>" placeholder="999999"/>
      </div>
      <div class="col-md-2">
        <label class="form-label fs-11 mb-1">Days Pending</label>
        <select name="days_pending" class="form-select form-select-sm">
          <option value="">Any</option>
          <option value="1" <?= $filterDays === 1 ? 'selected' : '' ?>>1+ day</option>
          <option value="3" <?= $filterDays === 3 ? 'selected' : '' ?>>3+ days</option>
          <option value="7" <?= $filterDays === 7 ? 'selected' : '' ?>>7+ days</option>
          <option value="14" <?= $filterDays === 14 ? 'selected' : '' ?>>14+ days</option>
          <option value="30" <?= $filterDays === 30 ? 'selected' : '' ?>>30+ days</option>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="ti ti-filter me-1"></i>Filter</button>
        <a href="approval_queue.php" class="btn btn-sm btn-outline-secondary"><i class="ti ti-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Actions Form -->
<form method="POST" id="bulkForm">
  <input type="hidden" name="bulk_action" id="bulkActionInput" value=""/>
  <input type="hidden" name="approval_level" id="bulkLevelInput" value="<?= $filterLevel ?: 'officer' ?>"/>

  <!-- Bulk Action Toolbar (hidden by default) -->
  <div id="bulkToolbar" class="card mb-3" style="display:none;">
    <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
      <span><strong id="selectedCount">0</strong> selected</span>
      <button type="button" class="btn btn-success btn-sm" onclick="confirmBulkAction('approve')">
        <i class="ti ti-check me-1"></i>Approve Selected
      </button>
      <button type="button" class="btn btn-danger btn-sm" onclick="confirmBulkAction('reject')">
        <i class="ti ti-x me-1"></i>Reject Selected
      </button>
      <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmBulkAction('request_changes')">
        <i class="ti ti-edit me-1"></i>Request Changes
      </button>
      <div class="ms-auto">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">Deselect All</button>
      </div>
    </div>
    <!-- Bulk notes/reason input -->
    <div id="bulkNotesSection" class="card-body pt-0" style="display:none;">
      <div class="row g-2">
        <div class="col-md-8">
          <label class="form-label fs-11">Notes / Rejection Reason</label>
          <textarea id="bulkNotes" name="bulk_notes" class="form-control form-control-sm" rows="2" placeholder="Enter notes or rejection reason for selected loans..."></textarea>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-sm btn-primary w-100" onclick="return confirm('Confirm bulk action?')">
            <i class="ti ti-send me-1"></i>Execute
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Loans Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><i class="ti ti-file-check me-2"></i>Pending Loans</strong>
      <span class="text-muted fs-12"><?= count($pendingLoans) ?> loans found</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th width="40"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"/></th>
            <th>Loan No</th>
            <th>Member</th>
            <th>Amount</th>
            <th>Risk</th>
            <th>Level</th>
            <th>Days</th>
            <th>Product</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($pendingLoans): ?>
          <?php foreach ($pendingLoans as $l): 
            $riskBg = match($l['risk_level'] ?? '') {
              'low' => '#EAF3DE',
              'medium' => '#FAEEDA',
              'high' => '#FCEBEB',
              default => '#f0f0e8'
            };
            $riskColor = match($l['risk_level'] ?? '') {
              'low' => '#3B6D11',
              'medium' => '#854F0B',
              'high' => '#A32D2D',
              default => '#888'
            };
            $level = $l['current_approval_level'] ?? 'officer';
            $days = (int)$l['days_pending'];
            $daysClass = $days >= 14 ? 'text-danger fw-bold' : ($days >= 7 ? 'text-warning' : '');
          ?>
          <tr>
            <td><input type="checkbox" name="loan_ids[]" value="<?= $l['id'] ?>" class="loan-checkbox" onchange="updateBulkToolbar()"/></td>
            <td><code><?= $l['loan_no'] ?></code></td>
            <td>
              <?= escape($l['member_name']) ?>
              <div class="fs-11 text-muted"><?= $l['member_no'] ?></div>
            </td>
            <td><strong><?= tsh($l['amount']) ?></strong></td>
            <td>
              <span style="display:inline-block;padding:2px 10px;border-radius:10px;background:<?= $riskBg ?>;color:<?= $riskColor ?>;font-size:11px;font-weight:600;">
                <?= ucfirst($l['risk_level'] ?? 'N/A') ?>
              </span>
            </td>
            <td>
              <span class="badge bg-<?= $level === 'admin' ? 'danger' : ($level === 'treasurer' ? 'warning text-dark' : 'primary') ?>">
                <?= ucfirst($level) ?>
              </span>
            </td>
            <td class="<?= $daysClass ?>"><?= $days ?>d</td>
            <td class="fs-12"><?= escape($l['product_name'] ?? 'General') ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="loan_approve.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary" title="Review loan">
                  <i class="ti ti-file-list"></i> Review
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No pending loans found matching your criteria.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<!-- Bulk Action Confirmation Modal -->
<div class="modal fade" id="bulkConfirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Bulk Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="bulkConfirmText">Are you sure?</p>
        <div class="mb-3">
          <label class="form-label">Notes / Reason</label>
          <textarea id="bulkConfirmNotes" class="form-control" rows="3" placeholder="Enter notes or reason for this action..."></textarea>
        </div>
        <input type="hidden" id="bulkConfirmAction"/>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="executeBulkAction()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
function toggleSelectAll() {
  var checked = document.getElementById('selectAll').checked;
  document.querySelectorAll('.loan-checkbox').forEach(function(cb) {
    cb.checked = checked;
  });
  updateBulkToolbar();
}

function updateBulkToolbar() {
  var checked = document.querySelectorAll('.loan-checkbox:checked');
  var toolbar = document.getElementById('bulkToolbar');
  var countEl = document.getElementById('selectedCount');
  
  if (checked.length > 0) {
    toolbar.style.display = 'block';
    countEl.textContent = checked.length;
  } else {
    toolbar.style.display = 'none';
    document.getElementById('bulkNotesSection').style.display = 'none';
  }
}

function deselectAll() {
  document.querySelectorAll('.loan-checkbox').forEach(function(cb) {
    cb.checked = false;
  });
  document.getElementById('selectAll').checked = false;
  updateBulkToolbar();
}

function confirmBulkAction(action) {
  var checked = document.querySelectorAll('.loan-checkbox:checked');
  if (checked.length === 0) return;
  
  var actionLabel = action === 'approve' ? 'approve' : (action === 'reject' ? 'reject' : 'request changes for');
  document.getElementById('bulkConfirmText').textContent = 'Are you sure you want to ' + actionLabel + ' ' + checked.length + ' selected loan(s)?';
  document.getElementById('bulkConfirmAction').value = action;
  
  var notesField = document.getElementById('bulkConfirmNotes');
  if (action === 'reject') {
    notesField.placeholder = 'Enter rejection reason for all selected loans...';
  } else {
    notesField.placeholder = 'Enter notes for this action...';
  }
  notesField.value = '';
  
  var modal = new bootstrap.Modal(document.getElementById('bulkConfirmModal'));
  modal.show();
}

function executeBulkAction() {
  var action = document.getElementById('bulkConfirmAction').value;
  var notes = document.getElementById('bulkConfirmNotes').value;
  
  document.getElementById('bulkActionInput').value = action;
  
  if (action === 'reject') {
    document.getElementById('bulkNotes').value = '';
    var reasonInput = document.createElement('input');
    reasonInput.type = 'hidden';
    reasonInput.name = 'bulk_reason';
    reasonInput.value = notes;
    document.getElementById('bulkForm').appendChild(reasonInput);
  } else {
    document.getElementById('bulkNotes').value = notes;
  }
  
  document.getElementById('bulkForm').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>