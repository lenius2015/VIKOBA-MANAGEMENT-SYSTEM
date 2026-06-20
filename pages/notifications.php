<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Notifications';

$user = $auth->getUser();
$userId = $user['id'];
$notif = new Notification();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

// Send admin notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_notification' && in_array($user['role'], ['admin','treasurer'])) {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $recipientType = $_POST['recipient_type'] ?? 'all';
    $recipientIds = $_POST['recipient_ids'] ?? [];
    $recipientRoles = $_POST['recipient_roles'] ?? [];

    if ($title === '' || $message === '') {
        setFlash('error', 'Title and message are required to send a notification.');
        redirect(APP_URL . '/pages/notifications.php');
    }

    if ($recipientType === 'selected') {
        if (!is_array($recipientIds) || count($recipientIds) === 0) {
            setFlash('error', 'Please select at least one recipient.');
            redirect(APP_URL . '/pages/notifications.php');
        }
        $notif->notifyUsers($recipientIds, 'announcement', $title, $message, $link ?: null);
        if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'notifications', 'send_selected', 'Recipients: ' . implode(',', $recipientIds));
        setFlash('success', 'Notification sent to ' . count($recipientIds) . ' selected user(s).');
    } elseif ($recipientType === 'role') {
        if (!is_array($recipientRoles) || count($recipientRoles) === 0) {
            setFlash('error', 'Please select at least one role.');
            redirect(APP_URL . '/pages/notifications.php');
        }
        $roleUsers = array_filter($availableUsers, function($u) use ($recipientRoles) {
            return in_array($u['role'], $recipientRoles);
        });
        $roleUserIds = array_map(function($u) { return $u['id']; }, $roleUsers);
        $notif->notifyUsers($roleUserIds, 'announcement', $title, $message, $link ?: null);
        if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'notifications', 'send_role', 'Roles: ' . implode(',', $recipientRoles));
        setFlash('success', 'Notification sent to ' . count($roleUserIds) . ' user(s) in selected role(s).');
    } else {
        $notif->notifyAll('announcement', $title, $message, $link ?: null);
        if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'notifications', 'send_all', 'Broadcast announcement');
        setFlash('success', 'Notification sent to all active users.');
    }
    redirect(APP_URL . '/pages/notifications.php');
}

// Mark all as read
if (isset($_GET['mark_all'])) {
    $notif->markAllRead($userId);
    setFlash('success', 'All notifications marked as read.');
    redirect(APP_URL . '/pages/notifications.php');
}

// Mark single as read
if (isset($_GET['read'])) {
    $notif->markRead((int)$_GET['read'], $userId);
    redirect(APP_URL . '/pages/notifications.php');
}

// Delete
if (isset($_GET['delete'])) {
    $notif->delete((int)$_GET['delete'], $userId);
    setFlash('success', 'Notification deleted.');
    redirect(APP_URL . '/pages/notifications.php');
}

// Update notification (admin only - for editing announcements)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_notification' && in_array($user['role'], ['admin','treasurer'])) {
    $id = (int)$_POST['notif_id'];
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $link = trim($_POST['link'] ?? '');

    if ($title === '' || $message === '') {
        setFlash('error', 'Title and message are required.');
        redirect(APP_URL . '/pages/notifications.php');
    }

    $stmt = $db->prepare("UPDATE notifications SET title=?, message=?, link=? WHERE id=?");
    if ($stmt->execute([$title, $message, $link ?: null, $id])) {
        setFlash('success', 'Notification updated successfully.');
    } else {
        setFlash('error', 'Failed to update notification.');
    }
    redirect(APP_URL . '/pages/notifications.php');
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$notifications = $notif->getAll($userId, $page);
$unreadCount = $notif->unreadCount($userId);
$availableUsers = [];
if (in_array($user['role'], ['admin','treasurer'])) {
    $availableUsers = $notif->getUsers('active');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">Total Unread</div>
      <div class="stat-value"><?= $unreadCount ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-label">All Notifications</div>
      <div class="stat-value"><?= count($notifications) ?></div>
    </div>
  </div>
  <div class="col-md-4 d-flex align-items-center">
    <a href="?mark_all=1" class="btn btn-outline-primary btn-sm me-2"><i class="ti ti-check-all me-1"></i>Mark All Read</a>
  </div>
</div>

<?php if (in_array($user['role'], ['admin','treasurer'])): ?>
<div class="card mb-4">
  <div class="card-header">
    <strong><i class="ti ti-mail"></i> Send Notification</strong>
  </div>
  <div class="card-body">
    <form method="POST" id="notificationForm">
      <input type="hidden" name="action" value="send_notification"/>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Title</label>
          <input type="text" name="title" class="form-control" required placeholder="Notification title" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Link (optional)</label>
          <input type="url" name="link" class="form-control" placeholder="https://..." />
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label">Message</label>
        <textarea name="message" class="form-control" rows="4" required placeholder="Write the notification message..."></textarea>
      </div>
      <div class="mt-3">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="recipient_type" id="recipient_all" value="all" checked onchange="toggleRecipients()">
          <label class="form-check-label" for="recipient_all">All Active Users</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="recipient_type" id="recipient_selected" value="selected" onchange="toggleRecipients()">
          <label class="form-check-label" for="recipient_selected">Selected Users</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="recipient_type" id="recipient_role" value="role" onchange="toggleRecipients()">
          <label class="form-check-label" for="recipient_role">By Role</label>
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label">Recipients</label>
        <div id="selectedRecipientsBadge" class="mb-2 d-none">
          <span class="badge bg-info"><span id="selectedCount">0</span> recipients selected</span>
        </div>
        <select id="recipient_ids" name="recipient_ids[]" class="form-select" multiple size="6" disabled>
          <?php foreach ($availableUsers as $u): ?>
            <option value="<?= $u['id'] ?>" data-role="<?= $u['role'] ?>"><?= escape($u['name']) ?> (<?= $u['role'] ?><?= $u['email'] ? ' • ' . escape($u['email']) : '' ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Hold Ctrl/Cmd to select multiple users.</div>
      </div>
      <div class="mt-3 d-none" id="roleSelectDiv">
        <label class="form-label">Select Roles</label>
        <div>
          <?php $roles = array_unique(array_column($availableUsers, 'role')); sort($roles); ?>
          <?php foreach ($roles as $role): ?>
            <div class="form-check">
              <input class="form-check-input role-checkbox" type="checkbox" name="recipient_roles" value="<?= $role ?>" id="role_<?= $role ?>" onchange="updateRoleSelection()">
              <label class="form-check-label" for="role_<?= $role ?>"><?= ucfirst($role) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt-3">
        <div id="notificationPreviewError" class="alert alert-danger d-none" role="alert"></div>
      </div>
      <div class="mt-3 text-end">
        <button type="button" class="btn btn-secondary me-2" id="previewNotificationBtn">Preview</button>
        <button type="submit" class="btn btn-primary d-none" id="sendNotificationSubmit">Send Notification</button>
      </div>
    </form>
  </div>
</div>

<!-- Notification Preview Modal -->
<div class="modal fade" id="notificationPreviewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Preview Notification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <strong>Title</strong>
          <p id="previewTitle" class="mb-0"></p>
        </div>
        <div class="mb-3">
          <strong>Message</strong>
          <p id="previewMessage" class="mb-0"></p>
        </div>
        <div class="mb-3">
          <strong>Link</strong>
          <p id="previewLink" class="mb-0"></p>
        </div>
        <div class="mb-3">
          <strong>Recipient Count</strong>
          <p id="previewRecipientCount" class="mb-0"></p>
        </div>
        <div class="mb-3">
          <strong>Recipients</strong>
          <p id="previewRecipients" class="mb-0"></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmSendNotificationBtn">Send Notification</button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<div class="card">
  <div class="card-header">
    <strong><i class="ti ti-bell me-2"></i>All Notifications</strong>
  </div>
  <div class="list-group list-group-flush">
    <?php if ($notifications): ?>
      <?php foreach ($notifications as $n): ?>
        <div class="list-group-item d-flex align-items-start gap-3 px-4 py-3 <?= !$n['is_read'] ? 'unread-notif' : '' ?>">
          <div class="notif-icon mt-1" style="
            width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
            <?php
            $bg = '#E6F1FB';
            if (strpos($n['type'], 'approved') !== false) $bg = '#EAF3DE';
            elseif (strpos($n['type'], 'rejected') !== false || strpos($n['type'], 'fine') !== false) $bg = '#FCEBEB';
            elseif (strpos($n['type'], 'message') !== false) $bg = '#FAEEDA';
            echo "background:$bg;color:#0F6ED6;";
            ?>
          ">
            <?php
            $iconName = 'info-circle';
            if ($n['type'] === 'loan_approved') $iconName = 'circle-check';
            elseif ($n['type'] === 'loan_rejected') $iconName = 'circle-x';
            elseif ($n['type'] === 'loan_applied') $iconName = 'credit-card';
            elseif ($n['type'] === 'contribution') $iconName = 'coin';
            elseif ($n['type'] === 'fine_issued') $iconName = 'alert-triangle';
            elseif ($n['type'] === 'repayment') $iconName = 'cash';
            elseif ($n['type'] === 'message') $iconName = 'message';
            ?>
            <i class="ti ti-<?= $iconName ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between">
              <strong class="fs-13"><?= escape($n['title']) ?></strong>
              <div class="d-flex gap-2">
                <?php if (!$n['is_read']): ?>
                  <a href="?read=<?= $n['id'] ?>" class="text-muted" title="Mark read"><i class="ti ti-check"></i></a>
                <?php endif; ?>
                <a href="?delete=<?= $n['id'] ?>" class="text-danger" onclick="return confirm('Delete this notification?')" title="Delete"><i class="ti ti-x"></i></a>
              </div>
            </div>
            <?php if ($n['type'] === 'announcement'): ?>
              <span class="badge bg-light text-dark fs-11 mb-2">Admin Notification</span>
            <?php endif; ?>
            <p class="mb-1 fs-12 text-muted"><?= escape($n['message']) ?></p>
            <div class="d-flex justify-content-between">
              <span class="fs-11 text-muted"><?= date('d M Y H:i', strtotime($n['created_at'])) ?></span>
              <?php if ($n['link']): ?>
                <a href="<?= $n['link'] ?>" class="fs-11">View Details <i class="ti ti-arrow-right"></i></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="text-center py-5">
        <i class="ti ti-bell-off" style="font-size:48px;color:#ddd;"></i>
        <p class="text-muted mt-2">No notifications yet</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.unread-notif {
  background: #f8faff;
  border-left: 3px solid #185FA5;
}
.unread-notif strong {
  color: #185FA5;
}
</style>

<script>
function toggleRecipients() {
  var selected = document.querySelector('input[name="recipient_type"]:checked').value;
  var recipientSelect = document.getElementById('recipient_ids');
  var roleSelectDiv = document.getElementById('roleSelectDiv');
  if (recipientSelect) {
    recipientSelect.disabled = selected !== 'selected';
  }
  if (roleSelectDiv) {
    roleSelectDiv.classList.toggle('d-none', selected !== 'role');
  }
  if (selected === 'selected') {
    updateSelectedCount();
  }
}

function updateSelectedCount() {
  var select = document.getElementById('recipient_ids');
  var count = select.selectedOptions.length;
  var badge = document.getElementById('selectedRecipientsBadge');
  document.getElementById('selectedCount').textContent = count;
  badge.classList.toggle('d-none', count === 0);
}

function updateRoleSelection() {
  var roles = Array.from(document.querySelectorAll('.role-checkbox:checked')).map(function(el) { return el.value; });
  var select = document.getElementById('recipient_ids');
  var options = select.querySelectorAll('option');
  var count = 0;
  options.forEach(function(opt) {
    if (roles.length === 0 || roles.includes(opt.getAttribute('data-role'))) {
      opt.selected = false;
    }
    if (roles.includes(opt.getAttribute('data-role'))) {
      opt.selected = true;
      count++;
    }
  });
  document.getElementById('selectedCount').textContent = count;
  document.getElementById('selectedRecipientsBadge').classList.toggle('d-none', count === 0);
}

document.addEventListener('DOMContentLoaded', function() {
  toggleRecipients();
  var recipientSelect = document.getElementById('recipient_ids');
  if (recipientSelect) {
    recipientSelect.addEventListener('change', updateSelectedCount);
  }

  var previewBtn = document.getElementById('previewNotificationBtn');
  var confirmBtn = document.getElementById('confirmSendNotificationBtn');
  var sendSubmit = document.getElementById('sendNotificationSubmit');
  var form = document.getElementById('notificationForm');

  if (previewBtn && confirmBtn && sendSubmit && form) {
    var errorBox = document.getElementById('notificationPreviewError');
    function showError(message, focusElement) {
      errorBox.textContent = message;
      errorBox.classList.remove('d-none');
      if (focusElement && typeof focusElement.focus === 'function') {
        focusElement.focus();
      }
    }
    function clearError() {
      errorBox.textContent = '';
      errorBox.classList.add('d-none');
    }

    previewBtn.addEventListener('click', function() {
      clearError();

      var titleElement = form.querySelector('[name="title"]');
      var messageElement = form.querySelector('[name="message"]');
      var title = titleElement.value.trim();
      var message = messageElement.value.trim();
      var link = form.querySelector('[name="link"]').value.trim();
      var recipientType = form.querySelector('[name="recipient_type"]:checked').value;
      var recipientSelect = form.querySelector('[name="recipient_ids[]"]');

      if (!title) {
        showError('Please enter a notification title before previewing.', titleElement);
        return;
      }

      if (!message) {
        showError('Please enter a message before previewing.', messageElement);
        return;
      }

      var recipients = 'All Active Users';
      var recipientCount = 'All active users';
      if (recipientType === 'selected') {
        var selected = Array.from(recipientSelect.selectedOptions).map(function(opt) { return opt.text; });
        if (!selected.length) {
          showError('Please select at least one recipient or choose All Active Users.', recipientSelect);
          return;
        }
        recipientCount = selected.length + ' selected';
        recipients = selected.join(', ');
      }

      document.getElementById('previewTitle').textContent = title;
      document.getElementById('previewMessage').textContent = message;
      document.getElementById('previewLink').textContent = link ? link : 'No link provided';
      document.getElementById('previewRecipientCount').textContent = recipientCount;
      document.getElementById('previewRecipients').textContent = recipients;

      var previewModal = new bootstrap.Modal(document.getElementById('notificationPreviewModal'));
      previewModal.show();
    });

    confirmBtn.addEventListener('click', function() {
      sendSubmit.click();
    });
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>