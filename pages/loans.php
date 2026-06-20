<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Loan Management';

$model       = new Loan();
$memberModel = new Member();
$productModel = new LoanProduct();
$user        = $auth->getUser();
$db          = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if ($model->create($_POST)) {
            $auth->log('Apply Loan', 'loans', 'Member ID: ' . $_POST['member_id']);
            setFlash('success', 'Loan application submitted successfully.');
        } else {
            setFlash('error', 'Failed to submit loan application.');
        }
    }

    if ($action === 'repay') {
        $_POST['recorded_by'] = $user['id'];
        $loan = $model->getById((int)$_POST['loan_id']);
        $_POST['member_id'] = $loan['member_id'] ?? 0;
        if ($model->addRepayment($_POST)) {
            $auth->log('Loan Repayment', 'loans', 'Loan ID: ' . $_POST['loan_id']);
            setFlash('success', 'Repayment recorded.');
        } else {
            setFlash('error', 'Failed to record repayment.');
        }
    }

    if ($action === 'update' && in_array($user['role'], ['admin', 'treasurer'])) {
        $id = (int)$_POST['loan_id'];
        $stmt = $db->prepare("UPDATE loans SET member_id=?, amount=?, interest_rate=?, term_months=?, purpose=? WHERE id=?");
        if ($stmt->execute([$_POST['member_id'], $_POST['amount'], $_POST['interest_rate'], $_POST['term_months'], $_POST['purpose'], $id])) {
            $auth->log('Update Loan', 'loans', 'Loan ID: ' . $id);
            setFlash('success', 'Loan updated successfully.');
        } else {
            setFlash('error', 'Failed to update loan.');
        }
    }

    if ($action === 'delete' && in_array($user['role'], ['admin'])) {
        $id = (int)$_POST['loan_id'];
        $stmt = $db->prepare("DELETE FROM loans WHERE id=?");
        if ($stmt->execute([$id])) {
            $auth->log('Delete Loan', 'loans', 'Loan ID: ' . $id);
            setFlash('success', 'Loan deleted successfully.');
        } else {
            setFlash('error', 'Failed to delete loan.');
        }
    }

    // Repayment CRUD operations
    if ($action === 'edit_repayment' && $user['role'] === 'admin') {
        $repayId = (int)$_POST['repayment_id'];
        $stmt = $db->prepare("UPDATE repayments SET amount=?, payment_method=?, reference=?, date=?, notes=? WHERE id=?");
        $stmt->execute([
            $_POST['amount'], $_POST['payment_method'], $_POST['reference'] ?? '',
            $_POST['date'], $_POST['notes'] ?? '', $repayId
        ]);
        $auth->log('Edit Repayment', 'loans', 'Repayment ID: ' . $repayId);
        setFlash('success', 'Repayment updated successfully.');
        redirect(APP_URL . '/pages/loans.php?view=' . (int)$_POST['loan_id']);
    }

    if ($action === 'delete_repayment' && $user['role'] === 'admin') {
        $repayId = (int)$_POST['repayment_id'];
        $loanId = (int)$_POST['loan_id'];
        $stmt = $db->prepare("DELETE FROM repayments WHERE id=?");
        if ($stmt->execute([$repayId])) {
            $auth->log('Delete Repayment', 'loans', 'Repayment ID: ' . $repayId);
            setFlash('success', 'Repayment deleted successfully.');
        } else {
            setFlash('error', 'Failed to delete repayment.');
        }
        redirect(APP_URL . '/pages/loans.php?view=' . $loanId);
    }

    redirect(APP_URL . '/pages/loans.php');
}


$filterStatus = $_GET['status'] ?? '';
$loans   = $model->getAll($filterStatus ? ['status' => $filterStatus] : []);
$members = $memberModel->getAll('active');
$products = $productModel->getAll(true);

$viewLoan = null;
$repayments = [];
$amortization = [];
if (isset($_GET['view'])) {
    $viewLoan = $model->getById((int)$_GET['view']);
    if ($viewLoan) {
        $repayments = $model->getRepayments($viewLoan['id']);
        $amortization = $model->getAmortizationSchedule($viewLoan['id']);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $allLoans = $model->getAll();
  $byStatus = [];
  foreach ($allLoans as $l) $byStatus[$l['status']] = ($byStatus[$l['status']] ?? 0) + 1;
  $statuses = [
    'draft' => 'Draft',
    'submitted' => 'Pending',
    'under_review' => 'Under Review',
    'review_requested' => 'Review Requested',
    'resubmitted' => 'Resubmitted',
    'approved' => 'Approved',
    'disbursed' => 'Disbursed',
    'completed' => 'Completed',
    'rejected' => 'Rejected'
  ];
  foreach ($statuses as $s => $label):
  ?>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-label"><?= $label ?> Loans</div>
      <div class="stat-value"><?= $byStatus[$s] ?? 0 ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong>Loans</strong>
    <div class="d-flex gap-2 flex-wrap">
      <form method="GET" class="d-flex gap-2">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Status</option>
          <?php foreach ($statuses as $s => $label): ?>
            <option value="<?=$s?>" <?=$filterStatus===$s?'selected':''?>><?=$label?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filterStatus): ?><a href="loans.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
      </form>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLoanModal">
        <i class="ti ti-plus me-1 icon-white"></i>Apply for Loan
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Loan No</th><th>Member</th><th>Amount</th><th>Interest</th><th>Total</th><th>Repaid</th><th>Progress</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($loans as $l):
        $pct = $l['total_repayable'] > 0 ? round(($l['total_paid']/$l['total_repayable'])*100) : 0;
      ?>
        <tr>
          <td><code><?= $l['loan_no'] ?></code></td>
          <td><?= escape($l['member_name']) ?></td>
          <td><?= tsh($l['amount']) ?></td>
          <td><?= $l['interest_rate'] ?>%</td>
          <td><?= tsh($l['total_repayable']) ?></td>
          <td><?= tsh($l['total_paid']) ?></td>
          <td style="min-width:80px">
            <div class="progress"><div class="progress-bar" style="width:<?=$pct?>%"></div></div>
            <small class="text-muted"><?=$pct?>%</small>
          </td>
          <td><?= $l['due_date'] ?? '—' ?></td>
          <td><?= badgeStatus($l['status']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="?view=<?=$l['id']?>" class="btn btn-sm btn-outline-primary"><i class="ti ti-eye"></i></a>
              <?php if (in_array($l['status'], ['submitted','review_requested','resubmitted']) && in_array($user['role'],['admin','treasurer'])): ?>
                <a href="loan_approve.php?id=<?=$l['id']?>" class="btn btn-sm btn-outline-warning" title="Review loan"><i class="ti ti-file-list"></i></a>
              <?php endif; ?>
              <?php if ($l['status'] === 'under_review' && in_array($user['role'],['admin','treasurer'])): ?>
                <a href="loan_approve.php?id=<?=$l['id']?>" class="btn btn-sm btn-outline-info" title="Continue review"><i class="ti ti-file-list"></i></a>
              <?php endif; ?>
              <?php if (in_array($l['status'], ['disbursed','active','approved']) && in_array($user['role'], ['admin','treasurer'])): ?>
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#repayModal"
                  data-loan-id="<?=$l['id']?>" data-loan-no="<?=$l['loan_no']?>"
                  data-balance="<?= round($l['total_repayable']-$l['total_paid'], 2) ?>">
                  <i class="ti ti-cash"></i> Repay
                </button>
              <?php endif; ?>
              <?php if (in_array($l['status'], ['submitted','review_requested']) && in_array($user['role'],['admin'])): ?>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editLoanModal"
                  data-loan-id="<?=$l['id']?>" data-member-id="<?=$l['member_id']?>" data-amount="<?=$l['amount']?>"
                  data-interest="<?=$l['interest_rate']?>" data-term="<?=$l['term_months']?>" data-purpose="<?=escape($l['purpose']??'')?>"><i class="ti ti-edit"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this loan?');">
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="loan_id" value="<?=$l['id']?>"/>
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- View Loan + Repayments -->
<?php if ($viewLoan): ?>
<div class="modal fade show d-block" id="viewLoanModal" tabindex="-1" style="background:rgba(0,0,0,.4)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Loan Details — <?= $viewLoan['loan_no'] ?></h5>
        <a href="loans.php" class="btn-close"></a>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><strong>Member:</strong> <?= escape($viewLoan['member_name']) ?></div>
          <div class="col-md-6"><strong>Amount:</strong> <?= tsh($viewLoan['amount']) ?></div>
          <div class="col-md-6"><strong>Interest Rate:</strong> <?= $viewLoan['interest_rate'] ?>%</div>
          <div class="col-md-6"><strong>Total Repayable:</strong> <?= tsh($viewLoan['total_repayable']) ?></div>
          <div class="col-md-6"><strong>Total Paid:</strong> <?= tsh($viewLoan['total_paid']) ?></div>
          <div class="col-md-6"><strong>Balance:</strong> <?= tsh($viewLoan['total_repayable']-$viewLoan['total_paid']) ?></div>
          <div class="col-md-6"><strong>Due Date:</strong> <?= $viewLoan['due_date'] ?? '—' ?></div>
          <div class="col-md-6"><strong>Status:</strong> <?= badgeStatus($viewLoan['status']) ?></div>
          <div class="col-12"><strong>Purpose:</strong> <?= escape($viewLoan['purpose']) ?></div>
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
        <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Reviewed</th><?php if ($user['role']==='admin'): ?><th>Actions</th><?php endif; ?></tr></thead>
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
                  <?php if ($user['role']==='admin'): ?>
                  <td>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editRepaymentModal"
                        data-repay-id="<?=$r['id']?>" data-repay-amount="<?=$r['amount']?>"
                        data-repay-method="<?=$r['payment_method']?>" data-repay-ref="<?=escape($r['reference']??'')?>"
                        data-repay-date="<?=$r['date']?>" data-repay-notes="<?=escape($r['notes']??'')?>">
                        <i class="ti ti-edit"></i>
                      </button>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Delete this repayment record?');">
                        <input type="hidden" name="action" value="delete_repayment"/>
                        <input type="hidden" name="repayment_id" value="<?=$r['id']?>"/>
                        <input type="hidden" name="loan_id" value="<?=$viewLoan['id']?>"/>
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                      </form>
                    </div>
                  </td>
                  <?php endif; ?>
                </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php else: ?>
          <p class="text-muted">No repayments recorded yet.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><a href="loans.php" class="btn btn-secondary">Close</a></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Apply Loan Modal -->
<div class="modal fade" id="addLoanModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="loanForm">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">Loan Application</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div id="eligibilityResult" class="mb-3"></div>
          <div id="amortizationPreview" class="mb-3" style="display:none;"></div>

          <div class="mb-3"><label class="form-label">Member *</label>
            <select name="member_id" id="member_id" class="form-select" required onchange="checkEligibility()">
              <option value="">— Select Member —</option>
              <?php foreach ($members as $m): ?><option value="<?=$m['id']?>"><?=escape($m['name'])?> (<?=$m['member_no']?>)</option><?php endforeach; ?>
            </select>
          </div>
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
                    data-code="<?=$p['code']?>">
                    <?= escape($p['name']) ?> (<?= $p['default_interest_rate'] ?>%)
                  </option>
                <?php endforeach; ?>
              </select>
              <div id="productInfo" class="fs-11 text-muted mt-1"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Loan Amount (Tsh) *</label>
              <input id="loan_amount" name="amount" type="number" step="1000" class="form-control" required min="10000" onchange="checkEligibility()" onkeyup="checkEligibility()"/>
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
            <div class="col-md-6">
              <label class="form-label">Interest Rate (%)</label>
              <input id="loan_interest" name="interest_rate" type="number" step="0.01" class="form-control" value="15"/>
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date</label>
              <input name="due_date" type="date" class="form-control"/>
            </div>
          </div>
          <div class="mt-3"><label class="form-label">Purpose</label><textarea name="purpose" class="form-control" rows="2"></textarea></div>
          <div class="alert alert-info mt-3 mb-0"><span id="loan_total_display">Total repayable: Tsh 0.00</span></div>
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

<!-- Repay Modal -->
<div class="modal fade" id="repayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="repay"/>
        <input type="hidden" name="loan_id" id="repay_loan_id"/>
        <div class="modal-header"><h5 class="modal-title">Record Repayment — <span id="repay_loan_no"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="alert alert-info">Outstanding balance: <strong id="repay_balance"></strong></div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Amount (Tsh) *</label><input name="amount" type="number" step="0.01" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="col-6"><label class="form-label">Date</label><input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>"/></div>
            <div class="col-6"><label class="form-label">Reference No.</label><input name="reference" class="form-control"/></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Repayment Modal -->
<div class="modal fade" id="editRepaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="edit_repayment"/>
        <input type="hidden" name="repayment_id" id="edit_repay_id"/>
        <input type="hidden" name="loan_id" value="<?= $viewLoan['id'] ?? 0 ?>"/>
        <div class="modal-header"><h5 class="modal-title">Edit Repayment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Amount (Tsh) *</label><input name="amount" id="edit_repay_amount" type="number" step="0.01" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Payment Method</label>
              <select name="payment_method" id="edit_repay_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="col-6"><label class="form-label">Date</label><input name="date" id="edit_repay_date" type="date" class="form-control"/></div>
            <div class="col-6"><label class="form-label">Reference No.</label><input name="reference" id="edit_repay_ref" class="form-control"/></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" id="edit_repay_notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Repayment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Product data for amortization preview
var products = <?= json_encode($products) ?>;

document.getElementById('repayModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('repay_loan_id').value = btn.dataset.loanId;
  document.getElementById('repay_loan_no').textContent = btn.dataset.loanNo;
  document.getElementById('repay_balance').textContent = 'Tsh ' + parseFloat(btn.dataset.balance).toLocaleString('en-TZ', {minimumFractionDigits:2});
});

document.getElementById('editRepaymentModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('edit_repay_id').value = btn.dataset.repayId;
  document.getElementById('edit_repay_amount').value = btn.dataset.repayAmount;
  document.getElementById('edit_repay_method').value = btn.dataset.repayMethod;
  document.getElementById('edit_repay_ref').value = btn.dataset.repayRef;
  document.getElementById('edit_repay_date').value = btn.dataset.repayDate;
  document.getElementById('edit_repay_notes').value = btn.dataset.repayNotes;
});

// Live eligibility check
function checkEligibility() {
  var memberId = document.getElementById('member_id').value;
  var amount = document.getElementById('loan_amount').value;
  var resultDiv = document.getElementById('eligibilityResult');
  var submitBtn = document.getElementById('submitLoanBtn');
  var amortDiv = document.getElementById('amortizationPreview');
  var productId = document.getElementById('product_id').value;
  var termMonths = document.getElementById('term_months').value;
  var appDate = document.querySelector('input[name="application_date"]').value;

  if (!memberId || !amount || amount < 10000 || !productId || !termMonths || !appDate) {
    resultDiv.innerHTML = '<div class="alert alert-info mb-0"><i class="ti ti-info-circle me-2"></i>Fill in all required fields to check eligibility.</div>';
    amortDiv.style.display = 'none';
    submitBtn.disabled = true;
    return;
  }

  resultDiv.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm me-2"></div>Checking eligibility...</div>';

  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'ajax_check_eligibility.php?member_id=' + memberId + '&amount=' + amount + '&product_id=' + productId, true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      try {
        var data = JSON.parse(xhr.responseText);
        var html = '';

        if (data.eligible) {
          html += '<div class="alert alert-success mb-2"><i class="ti ti-circle-check me-2"></i><strong>Eligible!</strong> All requirements met.</div>';
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

        showAmortizationPreview(amount, termMonths);

        var amountValid = amount && parseFloat(amount) >= 10000;
        var eligible = data.eligible === true || (data.eligible === undefined && true);
        submitBtn.disabled = !(eligible && appDate && amountValid && productId && memberId);

      } catch(e) {
        resultDiv.innerHTML = '<div class="alert alert-warning mb-0">Error checking eligibility. Please try again.</div>';
        amortDiv.style.display = 'none';
        submitBtn.disabled = true;
      }
    }
  };
  xhr.send();
}

function showAmortizationPreview(amount, termMonths) {
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

  var frequency = document.querySelector('select[name="payment_frequency"]').value;
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

  // Update total repayable display
  document.getElementById('loan_total_display').textContent = 'Total repayable: ' + tshFormat(totalRepayable);
}

function tshFormat(num) {
  return 'Tsh ' + parseFloat(num).toLocaleString('en-TZ', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

function onProductChange() {
  var select = document.getElementById('product_id');
  var selected = select.options[select.selectedIndex];
  var info = document.getElementById('productInfo');
  var amountInfo = document.getElementById('amountInfo');
  var amountInput = document.getElementById('loan_amount');
  var termInput = document.getElementById('term_months');
  var interestInput = document.getElementById('loan_interest');

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
    interestInput.value = interest;
    if (parseInt(termInput.value) < minTerm) termInput.value = minTerm;
    if (parseInt(termInput.value) > maxTerm) termInput.value = maxTerm;
  } else {
    info.innerHTML = '';
    amountInfo.innerHTML = '';
  }
  checkEligibility();
}

document.getElementById('addLoanModal').addEventListener('shown.bs.modal', function() {
  onProductChange();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>