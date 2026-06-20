<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireRole(['admin']);
$pageTitle = 'User Management';

$db   = Database::getInstance()->getConnection();
$user = $auth->getUser();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

// Create user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $hashedPw = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
        $profilePicture = null;
        if (!empty($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $fn = basename($_FILES['profile_picture']['name']);
            $safe = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $fn);
            $destDir = __DIR__ . '/../uploads/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $target = $destDir . $safe;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
                $profilePicture = $safe;
            }
        }
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, member_id, status, profile_picture) VALUES (?,?,?,?,?,'active',?)");
        if ($stmt->execute([$_POST['name'], $_POST['email'], $hashedPw, $_POST['role'], $memberId, $profilePicture])) {
          $newId = $db->lastInsertId();
          if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'users', 'create', 'Created user ID: ' . $newId);
          setFlash('success', 'User "' . $_POST['name'] . '" created successfully. They can now login.');
        } else {
            setFlash('error', 'Failed to create user. Email may already exist.');
        }
    }

    if ($action === 'change_password') {
        $id = (int)$_POST['user_id'];
        $hashed = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password=? WHERE id=?");
        if ($stmt->execute([$hashed, $id])) {
        if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'users', 'change_password', 'Changed password for user ID: ' . $id);
        setFlash('success', 'Password updated successfully.');
        } else {
            setFlash('error', 'Failed to update password.');
        }
    }

    if ($action === 'toggle') {
        $id = (int)$_POST['user_id'];
        $stmt = $db->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id=? AND id != ?");
        $stmt->execute([$id, $user['id']]);
      if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'users', 'toggle_status', 'Toggled status for user ID: ' . $id);
      setFlash('success', 'User status updated.');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['user_id'];
        if ($id === $user['id']) {
            setFlash('error', 'You cannot delete your own account.');
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id=?");
            if ($stmt->execute([$id])) {
          if ($audit) $audit->logActivity($user['id'], $user['name'], $user['role'], 'users', 'delete', 'Deleted user ID: ' . $id);
          setFlash('success', 'User deleted successfully.');
            } else {
                setFlash('error', 'Failed to delete user.');
            }
        }
    }

    if ($action === 'update') {
        $id = (int)$_POST['user_id'];
      // capture old values for audit
      $old = $db->prepare("SELECT name,email,role,member_id,profile_picture FROM users WHERE id=?");
      $old->execute([$id]); $oldRow = $old->fetch();
      $profilePicture = $oldRow['profile_picture'] ?? null;
      if (!empty($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
          $fn = basename($_FILES['profile_picture']['name']);
          $safe = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $fn);
          $destDir = __DIR__ . '/../uploads/';
          if (!is_dir($destDir)) mkdir($destDir, 0755, true);
          $target = $destDir . $safe;
          if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
              $profilePicture = $safe;
          }
      }
      $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=?, member_id=?, profile_picture=? WHERE id=?");
      $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
      if ($stmt->execute([$_POST['name'], $_POST['email'], $_POST['role'], $memberId, $profilePicture, $id])) {
        if ($id === $user['id']) {
            $_SESSION['user_profile_picture'] = $profilePicture;
        }
        if ($audit && $oldRow) {
          foreach (['name','email','role','member_id','profile_picture'] as $field) {
            $newVal = $field === 'profile_picture' ? $profilePicture : ($_POST[$field] ?? ($field==='member_id'? $memberId : null));
            $oldVal = $oldRow[$field] ?? null;
            if ((string)$newVal !== (string)$oldVal) {
              $audit->recordChange($user['id'], $user['name'], 'users', $id, $field, $oldVal, $newVal);
            }
          }
          $audit->logActivity($user['id'], $user['name'], $user['role'], 'users', 'update', 'Updated user ID: ' . $id);
        }
        setFlash('success', 'User updated successfully.');
      } else {
        setFlash('error', 'Failed to update user.');
      }
    }

    redirect(APP_URL . '/pages/users.php');
}

$stmt = $db->query("SELECT u.*, m.name as linked_member_name FROM users u LEFT JOIN members m ON u.member_id = m.id ORDER BY u.created_at DESC");
$users = $stmt->fetchAll();

// Get all members for linking
$members = $db->query("SELECT id, member_no, name FROM members WHERE status='active' ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="ti ti-users me-2 icon-primary"></i>System Users (<?= count($users) ?>)</strong>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
      <i class="ti ti-plus me-1 icon-white"></i> Add User
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Linked Member</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $i => $u): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($u['profile_picture'])): ?>
                <img src="<?= APP_URL ?>/uploads/<?= escape($u['profile_picture'] ?? '') ?>" class="rounded-circle" alt="<?= escape($u['name']) ?>" style="width:36px;height:36px;object-fit:cover;" />
              <?php else: ?>
                <img src="<?= APP_URL ?>/public/images/default-avatar.svg" class="rounded-circle" alt="Default avatar" style="width:36px;height:36px;object-fit:cover;" />
              <?php endif; ?>
              <?= escape($u['name']) ?>
              <?= $u['id'] === $user['id'] ? '<span class="badge badge-info ms-1">You</span>' : '' ?>
            </div>
          </td>
          <td><?= escape($u['email']) ?></td>
          <td>
            <?php
              $roleBadge = match($u['role']) {
                'admin' => 'badge-primary',
                'treasurer' => 'badge-success',
                'member' => 'badge-warning',
                default => 'badge-secondary'
              };
            ?>
            <span class="badge <?= $roleBadge ?>"><?= ucfirst($u['role']) ?></span>
          </td>
          <td>
            <?php if ($u['role'] === 'member'): ?>
              <?= $u['linked_member_name'] ? '<span class="fs-12">' . escape($u['linked_member_name']) . '</span>' : '<span class="text-danger fs-12"><i class="ti ti-link-off me-1"></i> Not linked</span>' ?>
            <?php else: ?>
              <span class="text-muted fs-12">—</span>
            <?php endif; ?>
          </td>
          <td><?= badgeStatus($u['status']) ?></td>
          <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#changePwModal"
                data-user-id="<?=$u['id']?>" data-user-name="<?=escape($u['name'])?>">
                <i class="ti ti-key"></i>
              </button>
              <button class="btn btn-sm btn-outline-info"
                data-bs-toggle="modal" data-bs-target="#editUserModal"
                data-user-id="<?=$u['id']?>" data-user-name="<?=escape($u['name'])?>" data-user-email="<?=escape($u['email'])?>"
                data-user-role="<?=$u['role']?>" data-member-id="<?=$u['member_id']?>" data-user-profile_picture="<?= escape($u['profile_picture'] ?? '') ?>">
                <i class="ti ti-edit"></i>
              </button>
              <?php if ($u['id'] !== $user['id']): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Toggle active/inactive?');">
                <input type="hidden" name="action" value="toggle"/>
                <input type="hidden" name="user_id" value="<?=$u['id']?>"/>
                <button type="submit" class="btn btn-sm btn-outline-warning" title="Toggle active/inactive"><i class="ti ti-refresh"></i></button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this user? This action cannot be undone.');">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="user_id" value="<?=$u['id']?>"/>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">Add System User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            <i class="ti ti-info-circle me-2 icon-primary"></i>New users are created with <strong>active</strong> status and can login immediately.
          </div>
          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input name="name" class="form-control" required placeholder="e.g. John Mwanga"/>
          </div>
          <div class="mb-3">
            <label class="form-label">Email (Login) *</label>
            <input name="email" type="email" class="form-control" required placeholder="e.g. john@vikoba.co.tz"/>
          </div>
          <div class="mb-3">
            <label class="form-label">Role *</label>
            <select name="role" class="form-select" id="userRoleSelect" onchange="toggleMemberLink()">
              <option value="admin">Admin — Full system access</option>
              <option value="treasurer">Treasurer — Financial operations</option>
              <option value="member">Member — Self-service portal</option>
            </select>
          </div>
          <div class="mb-3" id="memberLinkField" style="display:none;">
            <label class="form-label">Link to Member *</label>
            <select name="member_id" class="form-select">
              <option value="">— Select member —</option>
              <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>"><?= escape($m['name']) ?> (<?= $m['member_no'] ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="fs-12 text-muted mt-1">
              <i class="ti ti-info-circle icon-primary"></i> Required for member accounts to access contributions, loans, and fines.
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Password *</label>
            <input name="password" type="password" class="form-control" required minlength="6" placeholder="Minimum 6 characters"/>
            <div class="fs-12 text-muted mt-1">Use a strong password (min 6 characters)</div>
          </div>
          <div class="mb-3 pt-3 border-top">
            <div class="fw-semibold mb-2">Profile Image</div>
            <label class="form-label">Upload Picture</label>
            <input name="profile_picture" type="file" accept="image/*" class="form-control" />
            <div class="fs-12 text-muted mt-1">Optional. All users can have a profile picture. Only admins can upload or change it from this panel.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-user-plus me-1 icon-white"></i>Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="change_password"/>
        <input type="hidden" name="user_id" id="pw_user_id"/>
        <div class="modal-header"><h5 class="modal-title">Change Password — <span id="pw_user_name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">New Password *</label>
            <input name="new_password" type="password" class="form-control" required minlength="6"/>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-key me-1 icon-white"></i>Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="user_id" id="edit_user_id"/>
        <div class="modal-header"><h5 class="modal-title">Edit User — <span id="edit_user_name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input name="name" id="edit_user_name_input" class="form-control" required/>
          </div>
          <div class="mb-3">
            <label class="form-label">Email *</label>
            <input name="email" id="edit_user_email" type="email" class="form-control" required/>
          </div>
          <div class="mb-3">
            <label class="form-label">Role *</label>
            <select name="role" id="edit_user_role" class="form-select" required onchange="toggleEditMemberLink()">
              <option value="admin">Admin</option>
              <option value="treasurer">Treasurer</option>
              <option value="member">Member</option>
            </select>
          </div>
          <div class="mb-3" id="edit_member_link_field" style="display:none;">
            <label class="form-label">Linked Member</label>
            <select name="member_id" id="edit_member_id" class="form-select">
              <option value="">— Select member —</option>
              <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>"><?= escape($m['name']) ?> (<?= $m['member_no'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3 pt-3 border-top">
            <div class="fw-semibold mb-2">Profile Image</div>
            <div class="mb-3" id="edit_profile_picture_preview" style="display:none;">
              <label class="form-label">Current Photo</label>
              <div><img id="edit_profile_picture_img" src="" alt="Current profile picture" class="rounded-circle" style="width:80px;height:80px;object-fit:cover;" /></div>
            </div>
            <div class="mb-3">
              <label class="form-label">Upload Picture</label>
              <input name="profile_picture" type="file" accept="image/*" class="form-control" />
              <div class="fs-12 text-muted mt-1">Upload or replace the user's profile picture. Only admins can set profile pictures for users.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1 icon-white"></i>Update User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Show/hide member linking field based on role selection
function toggleMemberLink() {
  var role = document.getElementById('userRoleSelect').value;
  document.getElementById('memberLinkField').style.display = role === 'member' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleMemberLink);

document.getElementById('changePwModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('pw_user_id').value   = btn.dataset.userId;
  document.getElementById('pw_user_name').textContent = btn.dataset.userName;
});

function toggleEditMemberLink() {
  var role = document.getElementById('edit_user_role').value;
  document.getElementById('edit_member_link_field').style.display = role === 'member' ? 'block' : 'none';
}

var editModal = document.getElementById('editUserModal');
if (editModal) {
  editModal.addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    var profilePicture = btn.dataset.userProfile_picture || '';
    document.getElementById('edit_user_id').value = btn.dataset.userId;
    document.getElementById('edit_user_name').textContent = btn.dataset.userName;
    document.getElementById('edit_user_name_input').value = btn.dataset.userName;
    document.getElementById('edit_user_email').value = btn.dataset.userEmail;
    document.getElementById('edit_user_role').value = btn.dataset.userRole;
    document.getElementById('edit_member_id').value = btn.dataset.memberId || '';
    if (profilePicture) {
      document.getElementById('edit_profile_picture_img').src = '<?= APP_URL ?>/uploads/' + profilePicture;
      document.getElementById('edit_profile_picture_preview').style.display = 'block';
    } else {
      document.getElementById('edit_profile_picture_preview').style.display = 'none';
    }
    toggleEditMemberLink();
  });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>