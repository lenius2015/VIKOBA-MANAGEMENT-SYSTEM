<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Contribution Management';

$model  = new Contribution();
$memberModel = new Member();
$user   = $auth->getUser();
$db     = Database::getInstance()->getConnection();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $_POST['recorded_by'] = $user['id'];
        if ($model->create($_POST)) {
        $newId = $db->lastInsertId();
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'contributions', 'create', 'Contribution ID: ' . $newId . ' Member: ' . $_POST['member_id']);
        $auth->log('Record Contribution', 'contributions', 'Member ID: ' . $_POST['member_id']);

            // Notify the member
            $notif = new Notification();
            $stmt = $db->prepare("SELECT id FROM users WHERE member_id = ? AND role='member' AND status='active'");
            $stmt->execute([$_POST['member_id']]);
            $memberUser = $stmt->fetch();
            if ($memberUser) {
                $notif->create($memberUser['id'], 'contribution', 'Contribution Recorded',
                    'A contribution of Tsh ' . number_format($_POST['amount'], 2) . ' has been recorded on ' . $_POST['date'] . '.',
                    APP_URL . '/pages/member_contributions.php');
            }

            setFlash('success', 'Contribution recorded successfully.');
        } else {
            setFlash('error', 'Failed to record contribution.');
        }
    }
    if ($action === 'create_cycle') {
        if ($model->createCycle($_POST)) {
            setFlash('success', 'Cycle created.');
        }
        redirect(APP_URL . '/pages/contributions.php');
    }
    if ($action === 'update_cycle') {
        $id = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("UPDATE cycles SET name=?, start_date=?, end_date=?, amount_per_share=?, status=? WHERE id=?");
        $stmt->execute([$_POST['name'], $_POST['start_date'], $_POST['end_date'], $_POST['amount_per_share'], $_POST['status'], $id]);
        setFlash('success', 'Cycle updated successfully.');
        redirect(APP_URL . '/pages/contributions.php');
    }
    if ($action === 'delete_cycle') {
        $id = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM contributions WHERE cycle_id=?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete cycle with contributions. Close it instead.');
        } else {
            $stmt = $db->prepare("DELETE FROM cycles WHERE id=?");
            $stmt->execute([$id]);
            setFlash('success', 'Cycle deleted successfully.');
        }
        redirect(APP_URL . '/pages/contributions.php');
    }
}

if (isset($_GET['delete']) && $user['role'] === 'admin') {
  $delId = (int)$_GET['delete'];
  $model->delete($delId);
  if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'contributions', 'delete', 'Deleted contribution ID: ' . $delId);
  setFlash('success', 'Record deleted.');
    redirect(APP_URL . '/pages/contributions.php');
}

if (isset($_GET['edit'])) {
    $editContrib = $model->getById((int)$_GET['edit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update' && $user['role'] === 'admin') {
    $id = (int)$_POST['contrib_id'];
  // fetch old
  $old = $db->prepare("SELECT member_id,amount,date,payment_method,notes FROM contributions WHERE id=?"); $old->execute([$id]); $oldRow = $old->fetch();
  $updateData = [
    'member_id' => (int)$_POST['member_id'],
    'amount' => (float)$_POST['amount'],
    'date' => $_POST['date'],
    'payment_method' => $_POST['payment_method'],
    'notes' => $_POST['notes'] ?? ''
  ];
  $stmt = $db->prepare("UPDATE contributions SET member_id=?, amount=?, date=?, payment_method=?, notes=? WHERE id=");
  $stmt = $db->prepare("UPDATE contributions SET member_id=?, amount=?, date=?, payment_method=?, notes=? WHERE id=?");
  if ($stmt->execute([$updateData['member_id'], $updateData['amount'], $updateData['date'], $updateData['payment_method'], $updateData['notes'], $id])) {
    if ($audit && $oldRow) {
      foreach (['member_id','amount','date','payment_method','notes'] as $f) {
        $newVal = $updateData[$f]; $oldVal = $oldRow[$f] ?? null;
        if ((string)$newVal !== (string)$oldVal) {
          $audit->recordChange($user['id'], $user['name'], 'contributions', $id, $f, $oldVal, $newVal);
        }
      }
      $audit->logActivity($user['id'], $user['name'], $user['role'], 'contributions', 'update', 'Updated contribution ID: ' . $id);
    }
    $auth->log('Update Contribution', 'contributions', 'ID: ' . $id);
    setFlash('success', 'Contribution updated successfully.');
  } else {
    setFlash('error', 'Failed to update contribution.');
  }
    redirect(APP_URL . '/pages/contributions.php');
}

$filters = [];
if (!empty($_GET['member_id'])) $filters['member_id'] = (int)$_GET['member_id'];
if (!empty($_GET['cycle_id']))  $filters['cycle_id']  = (int)$_GET['cycle_id'];

$contribs = $model->getAll($filters);
$total    = array_sum(array_column($contribs, 'amount'));
$members  = $memberModel->getAll('active');
$cycles   = $model->getCycles();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Summary -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-label">Total Contributions</div><div class="stat-value"><?= tsh($total) ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-label">Total Records</div><div class="stat-value"><?= count($contribs) ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-label">Average per Record</div>
      <div class="stat-value"><?= tsh(count($contribs) ? $total/count($contribs) : 0) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong>Contribution Records</strong>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <!-- Filters -->
      <form method="GET" class="d-flex gap-2 align-items-center">
        <select name="member_id" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
          <option value="">All Members</option>
          <?php foreach ($members as $m): ?>
            <option value="<?=$m['id']?>" <?=($filters['member_id']??0)==$m['id']?'selected':''?>><?=escape($m['name'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="cycle_id" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
          <option value="">All Cycles</option>
          <?php foreach ($cycles as $cy): ?>
            <option value="<?=$cy['id']?>" <?=($filters['cycle_id']??0)==$cy['id']?'selected':''?>><?=escape($cy['name'])?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filters): ?><a href="contributions.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
      </form>
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addCycleModal">
        <i class="ti ti-plus me-1 icon-primary"></i>Add Cycle
      </button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addContribModal">
        <i class="ti ti-plus me-1 icon-white"></i>Record Contribution
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Member</th><th>Amount</th><th>Cycle</th><th>Method</th><th>Reference</th><th>Date</th><?php if ($user['role']==='admin'): ?><th>Action</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($contribs as $i => $c): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle"><?= strtoupper(substr($c['member_name'],0,2)) ?></div>
              <div><?= escape($c['member_name']) ?><br><span class="fs-11 text-muted"><?= $c['member_no'] ?></span></div>
            </div>
          </td>
          <td><strong><?= tsh($c['amount']) ?></strong></td>
          <td><?= escape($c['cycle_name'] ?? '—') ?></td>
          <td><span class="badge badge-primary"><?= ucfirst(str_replace('_',' ',$c['payment_method'])) ?></span></td>
          <td><?= escape($c['reference'] ?: '—') ?></td>
          <td><?= $c['date'] ?></td>
          <?php if ($user['role']==='admin'): ?>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editContribModal"
                data-contrib-id="<?=$c['id']?>" data-member-id="<?=$c['member_id']?>" data-amount="<?=$c['amount']?>"
                data-date="<?=$c['date']?>" data-method="<?=$c['payment_method']?>" data-notes="<?=escape($c['notes']??'')?>"><i class="ti ti-edit"></i></button>
              <a href="?delete=<?=$c['id']?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this contribution record?')"><i class="ti ti-trash"></i></a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Contribution Modal -->
<div class="modal fade" id="addContribModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">Record Contribution</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Member *</label>
            <select name="member_id" class="form-select" required>
              <option value="">— Select Member —</option>
              <?php foreach ($members as $m): ?><option value="<?=$m['id']?>"><?=escape($m['name'])?> (<?=$m['member_no']?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Amount (Tsh) *</label><input name="amount" type="number" step="0.01" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Cycle</label>
              <select name="cycle_id" class="form-select"><option value="">— None —</option>
                <?php foreach ($cycles as $cy): ?><option value="<?=$cy['id']?>"><?=escape($cy['name'])?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-6"><label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="col-6"><label class="form-label">Reference No.</label><input name="reference" class="form-control" placeholder="Optional"/></div>
            <div class="col-6"><label class="form-label">Date *</label><input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required/></div>
          </div>
          <div class="mt-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Contribution</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Cycle Modal -->
<div class="modal fade" id="addCycleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create_cycle"/>
        <div class="modal-header"><h5 class="modal-title">Create Contribution Cycle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Cycle Name *</label><input name="name" class="form-control" placeholder="e.g. March 2024" required/></div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Start Date</label><input name="start_date" type="date" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">End Date</label><input name="end_date" type="date" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Amount per Share</label><input name="amount_per_share" type="number" class="form-control" placeholder="0"/></div>
            <div class="col-6"><label class="form-label">Status</label>
              <select name="status" class="form-select"><option value="open">Open</option><option value="closed">Closed</option></select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Cycle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Cycles Management Table -->
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="ti ti-calendar me-2 icon-warning"></i>Contribution Cycles</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Start Date</th><th>End Date</th><th>Amount/Share</th><th>Status</th><?php if ($user['role']==='admin'): ?><th>Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($cycles as $cy): ?>
        <tr>
          <td><strong><?= escape($cy['name']) ?></strong></td>
          <td><?= $cy['start_date'] ?></td>
          <td><?= $cy['end_date'] ?></td>
          <td><?= tsh($cy['amount_per_share']) ?></td>
          <td><?= badgeStatus($cy['status']) ?></td>
          <?php if ($user['role']==='admin'): ?>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editCycleModal"
                data-cyc-id="<?=$cy['id']?>" data-cyc-name="<?=escape($cy['name'])?>"
                data-cyc-start="<?=$cy['start_date']?>" data-cyc-end="<?=$cy['end_date']?>"
                data-cyc-amount="<?=$cy['amount_per_share']?>" data-cyc-status="<?=$cy['status']?>">
                <i class="ti ti-edit"></i>
              </button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete cycle <?=escape($cy['name'])?>?');">
                <input type="hidden" name="action" value="delete_cycle"/>
                <input type="hidden" name="cycle_id" value="<?=$cy['id']?>"/>
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
</div>

<!-- Edit Cycle Modal -->
<div class="modal fade" id="editCycleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update_cycle"/>
        <input type="hidden" name="cycle_id" id="edit_cycle_id"/>
        <div class="modal-header"><h5 class="modal-title">Edit Cycle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Cycle Name *</label><input name="name" id="edit_cycle_name" class="form-control" required/></div>
          <div class="row g-3">
            <div class="col-6"><label class="form-label">Start Date</label><input name="start_date" id="edit_cycle_start" type="date" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">End Date</label><input name="end_date" id="edit_cycle_end" type="date" class="form-control" required/></div>
            <div class="col-6"><label class="form-label">Amount per Share</label><input name="amount_per_share" id="edit_cycle_amount" type="number" class="form-control"/></div>
            <div class="col-6"><label class="form-label">Status</label>
              <select name="status" id="edit_cycle_status" class="form-select"><option value="open">Open</option><option value="closed">Closed</option></select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Cycle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('editCycleModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('edit_cycle_id').value = btn.dataset.cycId;
  document.getElementById('edit_cycle_name').value = btn.dataset.cycName;
  document.getElementById('edit_cycle_start').value = btn.dataset.cycStart;
  document.getElementById('edit_cycle_end').value = btn.dataset.cycEnd;
  document.getElementById('edit_cycle_amount').value = btn.dataset.cycAmount;
  document.getElementById('edit_cycle_status').value = btn.dataset.cycStatus;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
