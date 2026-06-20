<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'My Profile';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

// Get the member linked to this user
$stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
$stmt->execute([$user['member_id']]);
$member = $stmt->fetch();

if (!$member) {
    setFlash('error', 'No member profile linked.');
    redirect(APP_URL . '/pages/logout.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
  <!-- Profile Card -->
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body py-5">
        <?php if (!empty($user['profile_picture'])): ?>
          <img src="<?= APP_URL ?>/uploads/<?= escape($user['profile_picture']) ?>" alt="<?= escape($member['name']) ?>" class="rounded-circle mx-auto mb-3" style="width:80px;height:80px;object-fit:cover;" />
        <?php else: ?>
          <img src="<?= APP_URL ?>/public/images/default-avatar.svg" alt="Default avatar" class="rounded-circle mx-auto mb-3" style="width:80px;height:80px;object-fit:cover;" />
        <?php endif; ?>
        <h5 class="mb-1"><?= escape($member['name']) ?></h5>
        <p class="text-muted mb-2"><?= $member['member_no'] ?></p>
        <?= badgeStatus($member['status']) ?>
        <hr>
        <div class="text-start">
          <div class="d-flex justify-content-between py-1">
            <span class="text-muted">Shares</span>
            <span class="fw-500"><?= $member['shares'] ?></span>
          </div>
          <div class="d-flex justify-content-between py-1">
            <span class="text-muted">Joined</span>
            <span class="fw-500"><?= date('d M Y', strtotime($member['join_date'])) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1">
            <span class="text-muted">Gender</span>
            <span class="fw-500"><?= ucfirst($member['gender'] ?? '—') ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Details -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><strong><i class="ti ti-user me-2"></i>Personal Information</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <p class="fw-500"><?= escape($member['name']) ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone Number</label>
            <p class="fw-500"><?= escape($member['phone']) ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <p class="fw-500"><?= escape($member['email'] ?: '—') ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Address</label>
            <p class="fw-500"><?= escape($member['address'] ?: '—') ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Date of Birth</label>
            <p class="fw-500"><?= $member['dob'] ?: '—' ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Gender</label>
            <p class="fw-500"><?= ucfirst($member['gender'] ?? '—') ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">ID Type</label>
            <p class="fw-500"><?= escape($member['id_type'] ?: '—') ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">ID Number</label>
            <p class="fw-500"><?= escape($member['id_number'] ?: '—') ?></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Account Info -->
    <div class="card mt-3">
      <div class="card-header"><strong><i class="ti ti-shield me-2"></i>Account Information</strong></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Email (Login)</label>
            <p class="fw-500"><?= escape($user['email']) ?></p>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <p><span class="badge badge-warning">Member</span></p>
          </div>
        </div>
        <p class="text-muted fs-12 mt-2 mb-0">
          <i class="ti ti-info-circle me-1"></i>For any profile updates, please contact the administrator.
        </p>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>