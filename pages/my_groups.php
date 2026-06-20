<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();

$auth->requireLogin();
$user = $auth->getUser();
$groupModel = new Group();
$memberId = $user['member_id'] ?? 0;
$groups = $groupModel->getByMemberId($memberId);

$pageTitle = 'My Groups';
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <div class="card mb-3">
    <div class="card-body">
      <h4 class="mb-1">My Groups</h4>
      <p class="text-muted mb-0">View groups you belong to and access group details.</p>
    </div>
  </div>

  <?php if (empty($groups)): ?>
    <div class="card">
      <div class="card-body text-center">
        <i class="ti ti-users" style="font-size:36px;color:#6c757d;"></i>
        <p class="mt-3 mb-0">You are not currently assigned to any group.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($groups as $group): ?>
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <h5 class="mb-1"><?= escape($group['name']) ?></h5>
                  <div class="text-muted mb-2"><?= escape($group['description'] ?? '') ?></div>
                  <div class="badge badge-secondary"><?= escape(ucfirst($group['status'])) ?></div>
                </div>
                <a href="<?= APP_URL ?>/pages/group_info.php?id=<?= $group['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php';
