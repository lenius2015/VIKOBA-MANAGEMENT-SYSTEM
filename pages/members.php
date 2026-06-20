<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Member Management';
$model = new Member();
$user  = $auth->getUser();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if ($model->create($_POST)) {
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'members', 'create', 'Member: ' . $_POST['name']);
        $auth->log('Create Member', 'members', 'Member: ' . $_POST['name']);
            setFlash('success', 'Member registered successfully.');
        } else {
            setFlash('error', 'Failed to register member.');
        }
        redirect(APP_URL . '/pages/members.php');
    }

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        if ($model->update($id, $_POST)) {
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'members', 'update', 'ID: ' . $id);
        $auth->log('Update Member', 'members', "ID $id");
            setFlash('success', 'Member updated successfully.');
        } else {
            setFlash('error', 'Failed to update member.');
        }
        redirect(APP_URL . '/pages/members.php');
    }
}

if (isset($_GET['delete']) && $user['role'] === 'admin') {
    $id = (int)$_GET['delete'];
    if ($model->delete($id)) {
    if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'members', 'delete', 'ID: ' . $id);
    setFlash('success', 'Member deleted.');
    }
    redirect(APP_URL . '/pages/members.php');
}

if (isset($_GET['toggle'])) {
    $m = $model->getById((int)$_GET['toggle']);
    if ($m) {
        $newStatus = $m['status'] === 'active' ? 'inactive' : 'active';
        $model->update($m['id'], array_merge($m, ['status' => $newStatus]));
    }
    redirect(APP_URL . '/pages/members.php');
}

$members = $model->getAll();
$editMember = null;
if (isset($_GET['edit'])) {
    $editMember = $model->getById((int)$_GET['edit']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong><i class="ti ti-users me-2 icon-primary"></i> Members (<?= count($members) ?>)</strong>
    <div class="d-flex gap-2 align-items-center">
      <input type="text" id="tableSearch" class="form-control form-control-sm" placeholder="Search members..." style="width:200px"/>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMemberModal">
        <i class="ti ti-plus me-1"></i>Add Member
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover searchable-table mb-0">
      <thead>
        <tr>
          <th>#</th><th>Member No</th><th>Name</th><th>Phone</th>
          <th>Address</th><th>Shares</th><th>Joined</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($members as $i => $m): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><code><?= escape($m['member_no']) ?></code></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="avatar-circle"><?= strtoupper(substr($m['name'],0,2)) ?></div>
              <?= escape($m['name']) ?>
            </div>
          </td>
          <td><?= escape($m['phone']) ?></td>
          <td><?= escape($m['address']) ?></td>
          <td><?= $m['shares'] ?></td>
          <td><?= $m['join_date'] ?></td>
          <td><?= badgeStatus($m['status']) ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="?edit=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="ti ti-edit"></i></a>
              <a href="?toggle=<?= $m['id'] ?>" class="btn btn-sm btn-outline-warning" title="Toggle status"><i class="ti ti-refresh"></i></a>
              <?php if ($user['role'] === 'admin'): ?>
              <a href="?delete=<?= $m['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirmDelete('Delete member <?= escape($m['name']) ?>?')" title="Delete"><i class="ti ti-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">Register New Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name *</label><input name="name" class="form-control" required/></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" class="form-control" required/></div>
            <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control"/></div>
            <div class="col-md-6"><label class="form-label">Gender</label>
              <select name="gender" class="form-select"><option value="">— Select —</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
            </div>
            <div class="col-md-12"><label class="form-label">Address</label><input name="address" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Date of Birth</label><input name="dob" type="date" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">ID Type</label><input name="id_type" class="form-control" placeholder="e.g. NIDA"/></div>
            <div class="col-md-4"><label class="form-label">ID Number</label><input name="id_number" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Number of Shares</label><input name="shares" type="number" class="form-control" value="1" min="1"/></div>
            <div class="col-md-4"><label class="form-label">Join Date *</label><input name="join_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required/></div>
            <div class="col-md-4"><label class="form-label">Status</label>
              <select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1 icon-white"></i>Save Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Member Modal (auto-open if ?edit=N) -->
<?php if ($editMember): ?>
<div class="modal fade" id="editMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="id" value="<?= $editMember['id'] ?>"/>
        <div class="modal-header"><h5 class="modal-title">Edit Member — <?= escape($editMember['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name *</label><input name="name" class="form-control" value="<?= escape($editMember['name']) ?>" required/></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" class="form-control" value="<?= escape($editMember['phone']) ?>" required/></div>
            <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?= escape($editMember['email']) ?>"/></div>
            <div class="col-md-6"><label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <?php foreach(['male','female','other'] as $g): ?><option value="<?=$g?>" <?=$editMember['gender']===$g?'selected':''?>><?=ucfirst($g)?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12"><label class="form-label">Address</label><input name="address" class="form-control" value="<?= escape($editMember['address']) ?>"/></div>
            <div class="col-md-4"><label class="form-label">Date of Birth</label><input name="dob" type="date" class="form-control" value="<?= $editMember['dob'] ?>"/></div>
            <div class="col-md-4"><label class="form-label">Shares</label><input name="shares" type="number" class="form-control" value="<?= $editMember['shares'] ?>"/></div>
            <div class="col-md-4"><label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach(['active','inactive','suspended'] as $s): ?><option value="<?=$s?>" <?=$editMember['status']===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="members.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1 icon-white"></i>Update Member</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
  });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
