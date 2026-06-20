<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'treasurer']);

$pageTitle = 'Loan Approval Review';
$user = $auth->getUser();
$db = Database::getInstance()->getConnection();
$model = new Loan();
$notif = new Notification();

$loanId = (int)($_GET['id'] ?? 0);
$loan = $model->getById($loanId);

if (!$loan || in_array($loan['status'], ['rejected','disbursed','completed','defaulted'])) {
  setFlash('error', 'Loan not found or already processed.');
  redirect(APP_URL . '/pages/loans.php');
}

// Enforce role-based access: determine which approval level this user is authorized for
$userRole = $user['role'];
$roleLevelMap = ['admin' => ['admin','treasurer','officer'], 'treasurer' => ['treasurer'], 'officer' => ['officer']];
$allowedLevels = $roleLevelMap[$userRole] ?? [];
$loanLevel = $loan['current_approval_level'] ?? 'officer';

// Admin can approve at any level. Others can only see/approve loans at their level.
if ($userRole !== 'admin' && !in_array($loanLevel, $allowedLevels)) {
  setFlash('error', 'You are not authorized to review loans at the ' . ucfirst($loanLevel) . ' level.');
  redirect(APP_URL . '/pages/loans.php');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reviewNotes = trim($_POST['review_notes'] ?? '');
    $approvedAmount = isset($_POST['approved_amount']) ? (float)$_POST['approved_amount'] : (float)$loan['amount'];
    $eligibility = $model->checkEligibility($loan['member_id'], $loan['amount']);
    $riskLevel = $model->calculateRiskLevel($loan['member_id'], $eligibility);
    $currentLevel = $loan['current_approval_level'] ?? 'officer';
    try { $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

    // Get member user ID for notifications
    $stmt = $db->prepare("SELECT id FROM users WHERE member_id = ? AND role='member' AND status='active'");
    $stmt->execute([$loan['member_id']]);
    $memberUser = $stmt->fetch();

    if ($action === 'approve') {
        $result = $model->approveAtLevel($loanId, $user['id'], $currentLevel, $reviewNotes);
        if ($result['success']) {
            $auth->log('Approve Loan', 'loans', 'Loan ID: ' . $loanId . ' at ' . $currentLevel . ' level');
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'approve', 'Approved loan ID: ' . $loanId . ' at ' . $currentLevel);

            if ($result['final']) {
                // Fully approved - notify member
                if ($memberUser) {
                    $notif->create($memberUser['id'], 'loan_approved', 'Loan Approved',
                        'Your loan ' . $loan['loan_no'] . ' of Tsh ' . number_format($approvedAmount, 2) . ' has been fully approved and is awaiting disbursement.',
                        APP_URL . '/pages/member_loans.php?view=' . $loanId);
                }
                setFlash('success', 'Loan ' . $loan['loan_no'] . ' fully approved. Risk level: ' . strtoupper($riskLevel));
            } else {
                // Notify next approver
                $nextLevel = $loan['current_approval_level'] === 'officer' ? 'treasurer' : 'admin';
                $notif->notifyNextApprover($nextLevel, $loanId, $loan['loan_no'], $loan['member_name'], (float)$loan['amount']);
                setFlash('success', 'Loan ' . $loan['loan_no'] . ' approved at ' . ucfirst($currentLevel) . ' level. Sent to ' . ucfirst($nextLevel) . ' for review.');
            }
        } else {
            setFlash('error', $result['message']);
        }
        redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
    }

    if ($action === 'approve_conditional') {
        $conditions = [];
        $condTexts = $_POST['condition_text'] ?? [];
        $condTypes = $_POST['condition_type'] ?? [];
        foreach ($condTexts as $i => $text) {
            if (trim($text)) {
                $conditions[] = ['text' => trim($text), 'type' => $condTypes[$i] ?? 'other'];
            }
        }
        if (empty($conditions)) {
            setFlash('error', 'Please add at least one condition.');
            redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
        }
        if ($model->approveConditionally($loanId, $user['id'], $conditions, $reviewNotes)) {
            $auth->log('Conditional Approve Loan', 'loans', 'Loan ID: ' . $loanId);
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'conditional_approve', 'Conditionally approved loan ID: ' . $loanId);
            if ($memberUser) {
                $notif->notifyConditionsSet($memberUser['id'], $loan['loan_no'], count($conditions));
            }
            setFlash('success', 'Loan ' . $loan['loan_no'] . ' conditionally approved with ' . count($conditions) . ' condition(s).');
        } else {
            setFlash('error', 'Failed to conditionally approve loan.');
        }
        redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
    }

    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (empty($reason)) {
            setFlash('error', 'Please provide a rejection reason.');
            redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
        }
        if ($model->rejectWithReason($loanId, $user['id'], $reason, $reviewNotes)) {
            $model->addApprovalAction($loanId, $user['id'], $currentLevel, 'rejected', $reason);
            $auth->log('Reject Loan', 'loans', 'Loan ID: ' . $loanId);
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'reject', 'Rejected loan ID: ' . $loanId);
            if ($memberUser) {
                $notif->notifyRejectionWithReason($memberUser['id'], $loan['loan_no'], $reason);
            }
            setFlash('success', 'Loan ' . $loan['loan_no'] . ' rejected with reason.');
        } else {
            setFlash('error', 'Failed to reject loan.');
        }
        redirect(APP_URL . '/pages/loans.php');
    }

    if ($action === 'request_changes') {
        if ($model->requestChanges($loanId, $user['id'], $reviewNotes)) {
            $model->addApprovalAction($loanId, $user['id'], $currentLevel, 'requested_changes', $reviewNotes);
            $auth->log('Request Loan Changes', 'loans', 'Loan ID: ' . $loanId);
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'request_changes', 'Requested changes for loan ID: ' . $loanId);
            if ($memberUser) {
                $notif->create($memberUser['id'], 'loan_review_requested', 'Loan Requires Changes',
                    'Your loan application ' . $loan['loan_no'] . ' requires changes. Please review the feedback.',
                    APP_URL . '/pages/member_loans.php');
            }
            setFlash('success', 'Requested changes for loan ' . $loan['loan_no'] . '.');
        } else {
            setFlash('error', 'Failed to request changes.');
        }
        redirect(APP_URL . '/pages/loans.php');
    }

    if ($action === 'disburse') {
        if ($model->disburse($loanId, $user['id'])) {
            $auth->log('Disburse Loan', 'loans', 'Loan ID: ' . $loanId);
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'disburse', 'Disbursed loan ID: ' . $loanId);
            if ($memberUser) {
                $notif->notifyDisbursed($memberUser['id'], $loan['loan_no'], (float)$loan['amount']);
            }
            setFlash('success', 'Loan disbursed successfully.');
        } else {
            setFlash('error', 'Failed to disburse loan.');
        }
        redirect(APP_URL . '/pages/loans.php');
    }

    if ($action === 'authorize_disbursement') {
        if ($model->authorizeDisbursement($loanId, $user['id'])) {
            $auth->log('Authorize Disbursement', 'loans', 'Loan ID: ' . $loanId);
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'loans', 'authorize_disbursement', 'Authorized disbursement for loan ID: ' . $loanId);
            setFlash('success', 'Disbursement authorized. Loan is ready to disburse.');
        } else {
            setFlash('error', 'Failed to authorize disbursement. Check if all conditions are met.');
        }
        redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
    }

    if ($action === 'mark_condition_met') {
        $conditionId = (int)($_POST['condition_id'] ?? 0);
        if ($model->markConditionMet($conditionId, $user['id'])) {
            $condStmt = $db->prepare("SELECT condition_text FROM loan_conditions WHERE id = ?");
            $condStmt->execute([$conditionId]);
            $condText = $condStmt->fetchColumn();
            $notif->notifyConditionMet($loanId, $loan['loan_no'], $loan['member_name'], $condText ?: 'Condition fulfilled');
            setFlash('success', 'Condition marked as met.');
        } else {
            setFlash('error', 'Failed to mark condition as met.');
        }
        redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
    }

    redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
}

// Load data for display
$eligibility = $model->checkEligibility($loan['member_id'], (float)$loan['amount']);
$riskLevel = $model->calculateRiskLevel($loan['member_id'], $eligibility);
$profile = $eligibility['profile'];
$currentLevel = $loan['current_approval_level'] ?? 'officer';
$requiredLevel = $model->determineRequiredLevel((float)$loan['amount'], $riskLevel);

// Load documents
$docModel = new LoanDocument();
$documents = $docModel->getByLoan($loanId);

// Load guarantors
$guarantorModel = new Guarantor();
$guarantors = $guarantorModel->getByLoan($loanId);

// Load approval chain
$approvalChain = $model->getApprovalChain($loanId);

// Load conditions
$conditions = $model->getConditions($loanId);
$allConditionsMet = $model->areConditionsMet($loanId);

// Check disbursement readiness
$disbursementReady = $model->isReadyForDisbursement($loanId);

$riskColor = match($riskLevel) {
    'low' => '#3B6D11',
    'medium' => '#854F0B',
    'high' => '#A32D2D',
    default => '#888'
};
$riskBg = match($riskLevel) {
    'low' => '#EAF3DE',
    'medium' => '#FAEEDA',
    'high' => '#FCEBEB',
    default => '#f0f0e8'
};

$loanToSavings = $loan['savings_at_application'] > 0
    ? round(($loan['amount'] / $loan['savings_at_application']) * 100) . '%'
    : 'N/A';

$activeTab = $_GET['tab'] ?? 'profile';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.profile-field { padding: 6px 0; border-bottom: 1px solid #f0f0e8; }
.profile-field .label { display: block; font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.03em; }
.profile-field .value { display: block; font-size: 14px; font-weight: 500; color: #111; margin-top: 1px; }
.approval-timeline { position: relative; padding-left: 30px; }
.approval-timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #e0e0d8; }
.timeline-item { position: relative; margin-bottom: 16px; }
.timeline-item::before { content: ''; position: absolute; left: -20px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #ccc; border: 2px solid #fff; }
.timeline-item.approved::before { background: #3B6D11; }
.timeline-item.rejected::before { background: #A32D2D; }
.timeline-item.pending::before { background: #854F0B; }
.timeline-item.conditionally_approved::before { background: #185FA5; }
.timeline-item.requested_changes::before { background: #854F0B; }
.level-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.level-officer { background: #E8F0FE; color: #185FA5; }
.level-treasurer { background: #FAEEDA; color: #854F0B; }
.level-admin { background: #FCEBEB; color: #A32D2D; }
.level-none { background: #EAF3DE; color: #3B6D11; }
</style>

<div class="row g-4">
  <!-- Left Column: Tabs -->
  <div class="col-md-7">
    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'profile' ? 'active' : '' ?>" href="?id=<?= $loanId ?>&tab=profile">
          <i class="ti ti-user me-1"></i>Profile & Eligibility
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'documents' ? 'active' : '' ?>" href="?id=<?= $loanId ?>&tab=documents">
          <i class="ti ti-file me-1"></i>Documents <?= count($documents) ? '<span class="badge bg-secondary ms-1">' . count($documents) . '</span>' : '' ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'guarantors' ? 'active' : '' ?>" href="?id=<?= $loanId ?>&tab=guarantors">
          <i class="ti ti-users me-1"></i>Guarantors <?= count($guarantors) ? '<span class="badge bg-secondary ms-1">' . count($guarantors) . '</span>' : '' ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'conditions' ? 'active' : '' ?>" href="?id=<?= $loanId ?>&tab=conditions">
          <i class="ti ti-checklist me-1"></i>Conditions <?= count($conditions) ? '<span class="badge bg-' . ($allConditionsMet ? 'success' : 'warning') . ' ms-1">' . count($conditions) . '</span>' : '' ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'history' ? 'active' : '' ?>" href="?id=<?= $loanId ?>&tab=history">
          <i class="ti ti-history me-1"></i>Approval History
        </a>
      </li>
    </ul>

    <!-- Tab: Profile & Eligibility -->
    <?php if ($activeTab === 'profile'): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-user me-2"></i>Member Financial Profile</strong>
        <span class="badge bg-<?= $loan['status'] === 'review_requested' ? 'warning text-dark' : ($loan['status']==='approved' ? 'info' : 'secondary') ?>">
          <?= ucfirst(str_replace('_', ' ', $loan['status'])) ?>
        </span>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="profile-field">
              <span class="label">Member Name</span>
              <span class="value"><?= escape($loan['member_name']) ?></span>
            </div>
          </div>
          <div class="col-md-6">
            <div class="profile-field">
              <span class="label">Member No</span>
              <span class="value"><code><?= $loan['member_no'] ?></code></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Total Savings</span>
              <span class="value"><?= tsh($eligibility['total_savings']) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Shares Held</span>
              <span class="value"><?= $eligibility['member']['shares'] ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Member Since</span>
              <span class="value fs-12"><?= date('M Y', strtotime($eligibility['member']['join_date'])) ?> (<?= $eligibility['months_active'] ?> months)</span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Active Loans</span>
              <span class="value"><?= $profile['active_loans']['count'] ?> (<?= tsh($profile['active_loans']['total']) ?>)</span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Loans Completed</span>
              <span class="value"><?= $profile['completed_loans'] ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-field">
              <span class="label">Pending Fines</span>
              <span class="value"><?= tsh($profile['fines']['total']) ?> (<?= $profile['fines']['count'] ?>)</span>
            </div>
          </div>
        </div>

        <?php if ($profile['previous_loans']): ?>
        <h6 class="mt-4 mb-2">Previous Loan Repayment History</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Loan No</th><th>Amount</th><th>Status</th><th>Repaid %</th></tr></thead>
            <tbody>
            <?php foreach ($profile['previous_loans'] as $pl):
              $pct = $pl['total_repayable'] > 0 ? round(($pl['total_paid'] / $pl['total_repayable']) * 100) : 0;
            ?>
              <tr>
                <td><code><?= $pl['loan_no'] ?></code></td>
                <td><?= tsh($pl['amount']) ?></td>
                <td><?= badgeStatus($pl['status']) ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:5px;">
                      <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#3B6D11' : '#185FA5' ?>"></div>
                    </div>
                    <span class="fs-11"><?= $pct ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Credit Score -->
    <?php if (!empty($eligibility['credit_score']['breakdown'])): ?>
    <div class="card mt-3">
      <div class="card-header">
        <strong><i class="ti ti-chart-bar me-2"></i>Weighted Credit Score</strong>
        <span class="badge bg-<?= $eligibility['credit_score']['score'] >= 80 ? 'success' : ($eligibility['credit_score']['score'] >= 60 ? 'warning text-dark' : 'danger') ?> float-end">
          <?= $eligibility['credit_score']['score'] ?>/<?= $eligibility['credit_score']['max_score'] ?>
        </span>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <div class="progress" style="height:20px;border-radius:10px;">
            <div class="progress-bar bg-<?= $eligibility['credit_score']['score'] >= 80 ? 'success' : ($eligibility['credit_score']['score'] >= 60 ? 'warning' : 'danger') ?>"
                 style="width:<?= $eligibility['credit_score']['score'] ?>%;border-radius:10px;">
              <?= $eligibility['credit_score']['score'] ?>/100
            </div>
          </div>
        </div>
        <?php foreach ($eligibility['credit_score']['breakdown'] as $item): ?>
          <div class="d-flex align-items-center gap-2 p-2 mb-1" style="background:#f5f5f0;border-radius:8px;">
            <div class="flex-grow-1">
              <div class="fw-500 fs-13"><?= $item['factor'] ?> <span class="text-muted fs-11">(<?= $item['weight'] ?> pts)</span></div>
              <div class="fs-11 text-muted"><?= $item['detail'] ?></div>
            </div>
            <div class="text-center" style="min-width:50px;">
              <div class="fw-bold fs-14"><?= $item['score'] ?></div>
              <div class="fs-10 text-muted">/ <?= $item['weight'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Eligibility Checks -->
    <div class="card mt-3">
      <div class="card-header">
        <strong><i class="ti ti-checklist me-2"></i>Eligibility Checks</strong>
      </div>
      <div class="card-body">
        <?php foreach ($eligibility['checks'] as $check): ?>
          <div class="d-flex align-items-start gap-2 p-2 mb-1" style="background:<?= $check['passed'] ? '#EAF3DE' : '#FCEBEB' ?>;border-radius:8px;">
            <i class="ti ti-<?= $check['passed'] ? 'circle-check' : 'circle-x' ?> text-<?= $check['passed'] ? 'success' : 'danger' ?>" style="font-size:18px;flex-shrink:0;margin-top:1px;"></i>
            <div>
              <div class="fw-500 fs-13"><?= $check['name'] ?></div>
              <div class="fs-12"><?= $check['message'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Documents -->
    <?php if ($activeTab === 'documents'): ?>
    <div class="card">
      <div class="card-header">
        <strong><i class="ti ti-file me-2"></i>Loan Documents</strong>
      </div>
      <div class="card-body">
        <?php if ($documents): ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Type</th><th>File</th><th>Uploaded</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($documents as $doc): ?>
                <tr>
                  <td><?= LoanDocument::getTypeLabel($doc['document_type']) ?></td>
                  <td><a href="<?= APP_URL . '/' . $doc['file_path'] ?>" target="_blank"><?= escape($doc['file_name']) ?></a></td>
                  <td><?= date('d M Y', strtotime($doc['created_at'])) ?></td>
                  <td>
                    <?php if ($doc['verified']): ?>
                      <span class="badge bg-success">Verified</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$doc['verified']): ?>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Verify this document?')">
                        <input type="hidden" name="action" value="verify_document"/>
                        <input type="hidden" name="document_id" value="<?= $doc['id'] ?>"/>
                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="ti ti-check"></i> Verify</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No documents uploaded for this loan.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Guarantors -->
    <?php if ($activeTab === 'guarantors'): ?>
    <div class="card">
      <div class="card-header">
        <strong><i class="ti ti-users me-2"></i>Guarantors</strong>
      </div>
      <div class="card-body">
        <?php if ($guarantors): ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Name</th><th>Member No</th><th>Shares</th><th>Savings</th><th>Amount Guaranteed</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($guarantors as $g): ?>
                <tr>
                  <td><?= escape($g['member_name']) ?></td>
                  <td><code><?= $g['member_no'] ?></code></td>
                  <td><?= $g['shares'] ?></td>
                  <td><?= tsh($g['total_savings']) ?></td>
                  <td><?= tsh($g['amount_guaranteed']) ?></td>
                  <td>
                    <?php
                      $statusBadge = match($g['status']) {
                        'approved' => 'success',
                        'pending' => 'warning text-dark',
                        'declined' => 'danger',
                        'released' => 'secondary',
                        default => 'secondary'
                      };
                    ?>
                    <span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($g['status']) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No guarantors assigned to this loan.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Conditions -->
    <?php if ($activeTab === 'conditions'): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-checklist me-2"></i>Loan Conditions</strong>
        <?php if ($conditions && $allConditionsMet): ?>
          <span class="badge bg-success">All Conditions Met</span>
        <?php elseif ($conditions): ?>
          <span class="badge bg-warning text-dark">Pending Fulfillment</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($conditions): ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>#</th><th>Condition</th><th>Type</th><th>Created By</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($conditions as $i => $c): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td><?= escape($c['condition_text']) ?></td>
                  <td><span class="badge bg-info"><?= ucfirst($c['condition_type']) ?></span></td>
                  <td><?= escape($c['created_by_name'] ?? '—') ?></td>
                  <td>
                    <?php if ($c['is_met']): ?>
                      <span class="badge bg-success">Met</span>
                      <small class="text-muted d-block"><?= $c['met_date'] ?> by <?= escape($c['met_by_name'] ?? '—') ?></small>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$c['is_met']): ?>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Mark this condition as met?')">
                        <input type="hidden" name="action" value="mark_condition_met"/>
                        <input type="hidden" name="condition_id" value="<?= $c['id'] ?>"/>
                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="ti ti-check"></i> Mark Met</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No conditions set for this loan.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Approval History -->
    <?php if ($activeTab === 'history'): ?>
    <div class="card">
      <div class="card-header">
        <strong><i class="ti ti-history me-2"></i>Approval History</strong>
      </div>
      <div class="card-body">
        <?php if ($approvalChain): ?>
          <div class="approval-timeline">
            <?php foreach ($approvalChain as $a): ?>
              <div class="timeline-item <?= $a['action'] ?>">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong><?= escape($a['approver_name'] ?? 'Unknown') ?></strong>
                    <span class="level-badge level-<?= $a['approval_level'] ?> ms-2"><?= ucfirst($a['approval_level']) ?></span>
                    <span class="badge bg-<?= $a['action'] === 'approved' ? 'success' : ($a['action'] === 'rejected' ? 'danger' : ($a['action'] === 'conditionally_approved' ? 'info' : 'warning text-dark')) ?> ms-1">
                      <?= ucfirst(str_replace('_', ' ', $a['action'])) ?>
                    </span>
                  </div>
                  <small class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></small>
                </div>
                <?php if ($a['notes']): ?>
                  <div class="fs-12 text-muted mt-1"><?= escape($a['notes']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No approval actions recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right Column: Decision Panel -->
  <div class="col-md-5">
    <!-- Risk Assessment -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="ti ti-shield me-2"></i>Risk Assessment</strong>
        <span class="level-badge level-<?= $currentLevel ?>"><?= ucfirst($currentLevel) ?> Level</span>
      </div>
      <div class="card-body text-center">
        <div style="display:inline-block;padding:16px 32px;border-radius:16px;background:<?= $riskBg ?>;color:<?= $riskColor ?>;font-size:24px;font-weight:700;text-transform:uppercase;">
          <?= $riskLevel ?>
        </div>
        <div class="mt-2 fs-12 text-muted">
          Required approval: <strong><?= ucfirst($requiredLevel) ?></strong> level
          <?php if ($currentLevel !== 'none'): ?>
            · Current: <strong><?= ucfirst($currentLevel) ?></strong>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Loan Summary -->
    <div class="card mb-3">
      <div class="card-header">
        <strong><i class="ti ti-credit-card me-2"></i>Loan Request Summary</strong>
      </div>
      <div class="card-body">
        <div class="profile-field">
          <span class="label">Loan No</span>
          <span class="value"><strong><?= $loan['loan_no'] ?></strong></span>
        </div>
        <div class="profile-field">
          <span class="label">Product</span>
          <span class="value"><?= escape($loan['product_name'] ?? 'General') ?></span>
        </div>
        <div class="profile-field">
          <span class="label">Requested Amount</span>
          <span class="value" style="font-size:18px;font-weight:700;color:#185FA5;"><?= tsh($loan['amount']) ?></span>
        </div>
        <div class="profile-field">
          <span class="label">Interest Rate</span>
          <span class="value"><?= $loan['interest_rate'] ?>%</span>
        </div>
        <div class="profile-field">
          <span class="label">Total Repayable</span>
          <span class="value"><?= tsh($loan['total_repayable']) ?></span>
        </div>
        <div class="profile-field">
          <span class="label">Duration</span>
          <span class="value">
            <?php
              $appDate = new DateTime($loan['application_date']);
              $dueDate = $loan['due_date'] ? new DateTime($loan['due_date']) : null;
              echo $dueDate ? $appDate->diff($dueDate)->m . ' months' : 'Not specified';
            ?>
          </span>
        </div>
        <div class="profile-field">
          <span class="label">Purpose</span>
          <span class="value"><?= escape($loan['purpose'] ?: 'Not specified') ?></span>
        </div>
        <div class="profile-field">
          <span class="label">Savings at Application</span>
          <span class="value"><?= tsh($loan['savings_at_application']) ?></span>
        </div>
        <div class="profile-field">
          <span class="label">Loan-to-Savings Ratio</span>
          <span class="value"><?= $loanToSavings ?></span>
        </div>
        <?php if ($loan['credit_score']): ?>
        <div class="profile-field">
          <span class="label">Credit Score</span>
          <span class="value">
            <span class="badge bg-<?= $loan['credit_score'] >= 80 ? 'success' : ($loan['credit_score'] >= 60 ? 'warning text-dark' : 'danger') ?>">
              <?= $loan['credit_score'] ?>/100
            </span>
            <?php if ($loan['auto_approved']): ?>
              <span class="badge bg-info ms-1">Auto-Approved</span>
            <?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if ($loan['rejection_reason']): ?>
        <div class="profile-field">
          <span class="label">Rejection Reason</span>
          <span class="value text-danger"><?= escape($loan['rejection_reason']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Decision Form -->
    <div class="card">
      <div class="card-body">
        <form method="POST">
          <div class="mb-3">
            <label class="form-label">Approve Amount (Tsh)</label>
            <input type="number" name="approved_amount" class="form-control" value="<?= number_format($loan['amount'], 2, '.', '') ?>" step="0.01" min="1000" required />
          </div>
          <div class="mb-3">
            <label class="form-label">Review Notes</label>
            <textarea name="review_notes" class="form-control" rows="3" placeholder="Enter notes for member or internal review..."></textarea>
          </div>

          <!-- Rejection Reason (shown when rejecting) -->
          <div class="mb-3" id="rejectionReasonGroup" style="display:none;">
            <label class="form-label text-danger">Rejection Reason <span class="text-danger">*</span></label>
            <textarea name="rejection_reason" class="form-control" rows="2" placeholder="Provide specific reason for rejection (communicated to member)..."></textarea>
            <div class="fs-11 text-muted mt-1">This reason will be communicated to the member.</div>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('Approve this loan at <?= ucfirst($currentLevel) ?> level?')">
              <i class="ti ti-check me-1"></i>Approve at <?= ucfirst($currentLevel) ?> Level
            </button>

            <!-- Conditional Approval -->
            <button type="button" class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#conditionalForm">
              <i class="ti ti-list-check me-1"></i>Approve with Conditions
            </button>
          </div>
        </form>

        <!-- Conditional Approval Form -->
        <div class="collapse mt-3" id="conditionalForm">
          <div class="card card-body bg-light">
            <h6 class="mb-2"><i class="ti ti-list-check me-1"></i>Add Conditions</h6>
            <form method="POST" id="conditionalFormSubmit">
              <input type="hidden" name="action" value="approve_conditional"/>
              <div id="conditionsList">
                <div class="row g-2 mb-2 condition-row">
                  <div class="col-8">
                    <input type="text" name="condition_text[]" class="form-control form-control-sm" placeholder="e.g., Provide additional guarantor" required/>
                  </div>
                  <div class="col-4">
                    <select name="condition_type[]" class="form-select form-select-sm">
                      <option value="document">Document</option>
                      <option value="guarantor">Guarantor</option>
                      <option value="payment">Payment</option>
                      <option value="other">Other</option>
                    </select>
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="addConditionRow()">
                <i class="ti ti-plus me-1"></i>Add Another Condition
              </button>
              <div class="mb-2">
                <textarea name="review_notes" class="form-control form-control-sm" rows="2" placeholder="Notes about conditional approval..."></textarea>
              </div>
              <button type="submit" class="btn btn-info btn-sm w-100" onclick="return confirm('Approve with conditions? Member must fulfill conditions before disbursement.')">
                <i class="ti ti-list-check me-1"></i>Approve with Conditions
              </button>
            </form>
          </div>
        </div>

        <!-- Other Actions -->
        <div class="mt-3 d-grid gap-2">
          <button type="button" class="btn btn-outline-warning" onclick="document.getElementById('rejectionReasonGroup').style.display='block';this.style.display='none'">
            <i class="ti ti-x me-1"></i>Reject with Reason
          </button>
          <div id="rejectActions" style="display:none;">
            <form method="POST" class="d-grid gap-2">
              <input type="hidden" name="action" value="reject"/>
              <input type="hidden" name="review_notes" value=""/>
              <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Reject this loan application? The reason will be sent to the member.')">
                <i class="ti ti-x me-1"></i>Confirm Rejection
              </button>
            </form>
          </div>

          <form method="POST">
            <input type="hidden" name="action" value="request_changes"/>
            <button type="submit" class="btn btn-outline-warning w-100" onclick="return confirm('Request changes from member?')">
              <i class="ti ti-edit me-1"></i>Request Changes
            </button>
          </form>

          <?php if ($loan['status'] === 'approved' && !$loan['disbursement_authorized_by'] && $user['role'] === 'admin'): ?>
            <form method="POST">
              <input type="hidden" name="action" value="authorize_disbursement"/>
              <button type="submit" class="btn btn-primary w-100" onclick="return confirm('Authorize disbursement?')"
                <?= !$allConditionsMet && count($conditions) > 0 ? 'disabled' : '' ?>>
                <i class="ti ti-shield-check me-1"></i>Authorize Disbursement
              </button>
            </form>
            <?php if (!$allConditionsMet && count($conditions) > 0): ?>
              <div class="fs-11 text-danger text-center">All conditions must be met before authorizing disbursement.</div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($loan['disbursement_authorized_by'] && $loan['status'] === 'approved'): ?>
            <form method="POST">
              <input type="hidden" name="action" value="disburse"/>
              <button type="submit" class="btn btn-success w-100" onclick="return confirm('Disburse this loan? This will generate the repayment schedule.')">
                <i class="ti ti-cash me-1"></i>Disburse Loan
              </button>
            </form>
          <?php endif; ?>
        </div>

        <div class="text-center mt-3">
          <a href="loans.php" class="fs-12 text-muted">Back to Loan List</a>
          &middot;
          <a href="approval_queue.php" class="fs-12 text-muted">Approval Queue</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Show rejection reason field and hide the "Reject with Reason" button
document.querySelector('[onclick*="rejectionReasonGroup"]')?.addEventListener('click', function() {
  document.getElementById('rejectionReasonGroup').style.display = 'block';
  document.getElementById('rejectActions').style.display = 'block';
  this.style.display = 'none';
});

// Add condition row
function addConditionRow() {
  var list = document.getElementById('conditionsList');
  var row = document.createElement('div');
  row.className = 'row g-2 mb-2 condition-row';
  row.innerHTML = `
    <div class="col-8">
      <input type="text" name="condition_text[]" class="form-control form-control-sm" placeholder="e.g., Provide additional document" required/>
    </div>
    <div class="col-4">
      <select name="condition_type[]" class="form-select form-select-sm">
        <option value="document">Document</option>
        <option value="guarantor">Guarantor</option>
        <option value="payment">Payment</option>
        <option value="other">Other</option>
      </select>
    </div>
  `;
  list.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>