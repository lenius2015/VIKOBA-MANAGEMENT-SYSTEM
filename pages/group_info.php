<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();

$auth->requireLogin();
$user = $auth->getUser();
$groupId = (int)($_GET['id'] ?? 0);

$groupModel = new Group();

if ($groupId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->requireRole(['admin','treasurer']);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'group-' . time();
    }

    if ($name === '') {
        setFlash('error', 'Group name is required.');
        redirect(APP_URL . '/pages/group_info.php');
    }

    $data = [
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'created_by' => $user['id'],
        'status' => 'active'
    ];

    if ($groupModel->create($data)) {
        $newGroupId = $groupModel->getAll();
        $newGroup = end($newGroupId);
        $newGroupId = $newGroup['id'];

        $selectedMembers = $_POST['members'] ?? [];
        if (is_array($selectedMembers)) {
            foreach ($selectedMembers as $memberId) {
                $memberId = (int)$memberId;
                if ($memberId > 0) {
                    $groupModel->addMember($newGroupId, $memberId, 'member');
                }
            }
        }

        setFlash('success', 'Group created successfully with ' . count($selectedMembers) . ' member(s).');
        redirect(APP_URL . '/pages/groups.php');
    }

    setFlash('error', 'Failed to create group. Please try again.');
    redirect(APP_URL . '/pages/group_info.php');
}

if ($groupId === 0) {
    $pageTitle = 'Create Group';
    include __DIR__ . '/../includes/header.php';
    $memberModel = new Member();
    $members = $memberModel->getAll();
    ?>
    <div class="container py-3">
      <div class="card">
        <div class="card-header">
          <strong>Create Group</strong>
        </div>
        <div class="card-body">
          <form method="post">
            <div class="mb-3">
              <label class="form-label">Group Name <span class="text-danger">*</span></label>
              <input type="text" name="name" value="<?= escape($_POST['name'] ?? '') ?>" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="4"><?= escape($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Add Members</label>
              <select name="members[]" multiple class="form-select" size="8">
                <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>"><?= escape($m['name']) ?> (<?= escape($m['member_no']) ?>)</option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Hold Ctrl/Cmd to select multiple members. Members can be added later too.</small>
            </div>
            <button type="submit" class="btn btn-primary">Create Group</button>
            <a href="<?= APP_URL ?>/pages/groups.php" class="btn btn-link">Cancel</a>
          </form>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/../includes/footer.php';
    exit;
}

$summary = $groupModel->summary($groupId);
if (!$summary['group']) {
    setFlash('error', 'Group not found.');
    redirect(APP_URL . '/pages/groups.php');
}

// Handle adding member to group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    if (!in_array($user['role'] ?? '', ['admin', 'treasurer'])) {
        setFlash('error', 'Unauthorized');
        redirect(APP_URL . '/pages/group_info.php?id=' . $groupId);
    }
    $memberId = (int)($_POST['member_id'] ?? 0);
    if ($memberId > 0 && $groupModel->addMember($groupId, $memberId, 'member')) {
        setFlash('success', 'Member added to group.');
    } else {
        setFlash('error', 'Member already in group or error adding member.');
    }
    redirect(APP_URL . '/pages/group_info.php?id=' . $groupId);
}

$pageTitle = 'Group: ' . $summary['group']['name'];
include __DIR__ . '/../includes/header.php';
$memberModel = new Member();
$allMembers = $memberModel->getAll();
$existingMemberIds = array_column($summary['members'], 'id');
$availableMembers = array_filter($allMembers, fn($m) => !in_array($m['id'], $existingMemberIds));
?>
<div class="container py-3">
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h4 class="mb-1"><?= escape($summary['group']['name']) ?></h4>
          <div class="text-muted"><?= escape($summary['group']['description'] ?? '') ?></div>
        </div>
        <div class="text-end">
          <div class="fw-600">Members: <?= $summary['member_count'] ?></div>
          <div class="text-muted">Status: <?= escape($summary['group']['status']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <div class="fs-12 text-muted">Total Contributions</div>
          <div class="fs-18 fw-600">KSH <?= number_format($summary['total_contributions'],2) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <div class="fs-12 text-muted">Total Loans Disbursed</div>
          <div class="fs-18 fw-600">KSH <?= number_format($summary['total_loans_disbursed'],2) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <div class="fs-12 text-muted">Total Fines / Debits</div>
          <div class="fs-18 fw-600">KSH <?= number_format($summary['total_fines'],2) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Members</strong>
      <?php if (in_array($user['role'] ?? '', ['admin', 'treasurer']) && !empty($availableMembers)): ?>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">Add Member</button>
      <?php endif; ?>
    <div class="card-body p-0">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Member</th>
            <th>Member No</th>
            <th>Role</th>
            <th>Joined</th>
            <th class="text-end">Total Contributions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['members'] as $i => $m):
            $memberContrib = (new Member())->getTotalContributions($m['id']);
          ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><a href="<?= APP_URL ?>/pages/member_profile.php?id=<?= $m['id'] ?>"><?= escape($m['name']) ?></a></td>
            <td><?= escape($m['member_no']) ?></td>
            <td><?= escape($m['role']) ?></td>
            <td><?= date('d M Y', strtotime($m['joined_at'])) ?></td>
            <td class="text-end">KSH <?= number_format($memberContrib,2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Member Modal -->
<?php if (in_array($user['role'] ?? '', ['admin', 'treasurer'])): ?>
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Member to Group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="add_member">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Select Member</label>
            <select name="member_id" class="form-select" required>
              <option value="">— Choose a member —</option>
              <?php foreach ($availableMembers as $m): ?>
              <option value="<?= $m['id'] ?>"><?= escape($m['name']) ?> (<?= escape($m['member_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Member</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';
?>