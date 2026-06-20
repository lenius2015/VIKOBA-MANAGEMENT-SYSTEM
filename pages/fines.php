<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Fines & Penalties';

$model       = new Fine();
$memberModel = new Member();
$user        = $auth->getUser();
$db          = Database::getInstance()->getConnection();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $_POST['issued_by'] = $user['id'];
        if ($model->create($_POST)) {
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'fines', 'create', 'Member ID: ' . $_POST['member_id'] . ' Amount: ' . $_POST['amount']);
        $auth->log('Issue Fine', 'fines', 'Member ID: ' . $_POST['member_id']);

            // Notify the member
            $notif = new Notification();
            $stmt = $db->prepare("SELECT id FROM users WHERE member_id = ? AND role='member' AND status='active'");
            $stmt->execute([$_POST['member_id']]);
            $memberUser = $stmt->fetch();
            if ($memberUser) {
                $notif->create($memberUser['id'], 'fine_issued', 'Fine Issued',
                    'A fine of Tsh ' . number_format($_POST['amount'], 2) . ' has been issued for: ' . $_POST['reason'],
                    APP_URL . '/pages/member_fines.php');
            }

            setFlash('success', 'Fine issued successfully.');
        } else {
            setFlash('error', 'Failed to issue fine.');
        }
        redirect(APP_URL . '/pages/fines.php');
    }

    if ($action === 'update' && in_array($user['role'], ['admin', 'treasurer'])) {
        $id = (int)$_POST['fine_id'];
        if ($model->update($id, $_POST)) {
            if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'fines', 'update', 'Fine ID: ' . $id);
            $auth->log('Update Fine', 'fines', 'Fine ID: ' . $id);
            setFlash('success', 'Fine updated successfully.');
        } else {
            setFlash('error', 'Failed to update fine.');
        }
        redirect(APP_URL . '/pages/fines.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)$_POST['fine_id'];
        $model->markPaid($id);
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'fines', 'mark_paid', 'Fine ID: ' . $id);
        setFlash('success', 'Fine marked as paid.');
        redirect(APP_URL . '/pages/fines.php');
    }
}

if (isset($_GET['paid'])) {
    $model->markPaid((int)$_GET['paid']);
  if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'fines', 'mark_paid', 'Fine ID: ' . (int)$_GET['paid']);
  setFlash('success', 'Fine marked as paid.');
    redirect(APP_URL . '/pages/fines.php');
}
if (isset($_GET['delete']) && $user['role'] === 'admin') {
  $delId = (int)$_GET['delete'];
  $model->delete($delId);
  if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'fines', 'delete', 'Fine ID: ' . $delId);
  setFlash('success', 'Fine deleted.');
    redirect(APP_URL . '/pages/fines.php');
}

$filterPaid = $_GET['paid_filter'] ?? '';
$filters = [];
if ($filterPaid !== '') $filters['paid'] = (int)$filterPaid;
$fines    = $model->getAll($filters);
$members  = $memberModel->getAll();
$totalPending   = $model->totalPending();
$totalCollected = $model->totalCollected();
$editFine = null;
if (isset($_GET['edit'])) {
    $editFine = $model->getById((int)$_GET['edit']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Total Fines Issued</div><div class="stat-value"><?= count($model->getAll()) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Pending Fines</div><div class="stat-value"><?= tsh($totalPending) ?></div></div></div>
  <div class="col-md-4"><div class="stat-card"><div class="stat-label">Collected</div><div class="stat-value"><?= tsh($totalCollected) ?></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong>Fines & Penalties</strong>
    <div class="d-flex gap-2 align-items-center">
      <form method="GET" class="d-flex gap-2">
        <select name="paid_filter" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="0" <?=$filterPaid==='0'?'selected':''?>>Unpaid</option>
          <option value="1" <?=$filterPaid==='1'?'selected':''?>>Paid</option>
        </select>
        <?php if ($filterPaid!==''): ?><a href="fines.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
      </form>
      <?php if (in_array($user['role'],['admin','treasurer'])): ?>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFineModal">
        <i class="ti ti-plus me-1 icon-white"></i>Issue Fine
      </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 searchable-table">
      <thead><tr><th>#</th><th>Member</th><th>Reason</th><th>Amount</th><th>Issued</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($fines as $i => $f): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= escape($f['member_name']) ?></td>
          <td><?= escape($f['reason']) ?></td>
          <td><?= tsh($f['amount']) ?></td>
          <td><?= escape($f['issued_by_name'] ?? '—') ?></td>
          <td><?= $f['date'] ?></td>
          <td><?= badgeStatus($f['paid'] ? 'paid' : 'unpaid') ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if (in_array($user['role'], ['admin', 'treasurer'])): ?>
                <a href="?edit=<?=$f['id']?>" class="btn btn-sm btn-outline-info" title="Edit fine"><i class="ti ti-edit"></i></a>
              <?php endif; ?>
              <?php if (!$f['paid']): ?>
                <?php if (in_array($user['role'], ['admin', 'treasurer'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Mark this fine as paid?');">
                  <input type="hidden" name="action" value="mark_paid"/>
                  <input type="hidden" name="fine_id" value="<?=$f['id']?>"/>
                  <button type="submit" class="btn btn-sm btn-outline-success"><i class="ti ti-check"></i> Pay</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($user['role']==='admin'): ?>
                <a href="?delete=<?=$f['id']?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this fine?')"><i class="ti ti-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Issue Fine Modal -->
<div class="modal fade" id="addFineModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">Issue Fine / Penalty</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Member *</label>
            <select name="member_id" class="form-select" required>
              <option value="">— Select Member —</option>
              <?php foreach ($members as $m): ?><option value="<?=$m['id']?>"><?=escape($m['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><label class="form-label">Reason *</label><input name="reason" class="form-control" placeholder="e.g. Late contribution" required/></div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Amount (Tsh) *</label><input name="amount" type="number" step="0.01" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Date *</label><input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required/></div>
          </div>
          <div class="mt-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Issue Fine</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Fine Modal -->
<?php if ($editFine): ?>
<div class="modal fade show d-block" id="editFineModal" tabindex="-1" style="background:rgba(0,0,0,.4)">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="fine_id" value="<?= $editFine['id'] ?>"/>
        <div class="modal-header"><h5 class="modal-title">Edit Fine</h5><a href="fines.php" class="btn-close"></a></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Member</label>
            <select name="member_id" class="form-select" required>
              <option value="">— Select Member —</option>
              <?php foreach ($members as $m): ?>
                <option value="<?=$m['id']?>" <?=$editFine['member_id']==$m['id']?'selected':''?>><?=escape($m['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><label class="form-label">Reason *</label><input name="reason" class="form-control" value="<?=escape($editFine['reason'])?>" required/></div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Amount (Tsh) *</label><input name="amount" type="number" step="0.01" class="form-control" value="<?=$editFine['amount']?>" required/></div>
            <div class="col-6"><label class="form-label">Date *</label><input name="date" type="date" class="form-control" value="<?=$editFine['date']?>" required/></div>
          </div>
          <div class="mt-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?=escape($editFine['notes']??'')?></textarea></div>
        </div>
        <div class="modal-footer">
          <a href="fines.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1 icon-white"></i>Update Fine</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>document.addEventListener('DOMContentLoaded', function() { new bootstrap.Modal(document.getElementById('editFineModal')).show(); });</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>