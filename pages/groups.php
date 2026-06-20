<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();

$auth->requireLogin();
$user = $auth->getUser();
if (!in_array($user['role'] ?? '', ['admin','treasurer'])) {
    flashMessage('error', 'Unauthorized');
    redirect('/pages/dashboard.php');
}

$groupModel = new Group();
$groups = $groupModel->getAll();

$pageTitle = 'Groups';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Groups</strong>
      <a href="<?= APP_URL ?>/pages/group_info.php" class="btn btn-sm btn-outline-primary">Create Group</a>
    </div>
    <div class="card-body p-0">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Description</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groups as $i => $g): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= escape($g['name']) ?></td>
            <td><?= escape(substr($g['description'] ?? '',0,80)) ?></td>
            <td><?= badgeStatus($g['status']) ?></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/group_info.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';
