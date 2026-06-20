<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$pageTitle = 'Messages';

$user = $auth->getUser();
$userId = $user['id'];
$db   = Database::getInstance()->getConnection();
$msg  = new Message();
try { require_once __DIR__ . '/../classes/Audit.php'; $audit = new Audit(); } catch (Throwable $e) { $audit = null; }

// Start new conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'start_conversation') {
        $recipientId = (int)$_POST['recipient_id'];
        $subject = $_POST['subject'] ?? 'Chat';
        $body = trim($_POST['body'] ?? '');

        if ($recipientId && $body) {
            $convId = $msg->startConversation($subject, [$userId, $recipientId]);
            if ($convId) {
                $msg->send($convId, $userId, $body);
            if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'messages', 'start_conversation', 'Conversation ID: ' . $convId . ' Recipient: ' . $recipientId);
            setFlash('success', 'Message sent!');
                redirect(APP_URL . '/pages/messages.php?conversation=' . $convId);
            } else {
                setFlash('error', 'Failed to start conversation.');
            }
        } else {
            setFlash('error', 'Please select a recipient and write a message.');
        }
        redirect(APP_URL . '/pages/messages.php');
    }

    // Send reply
    if ($_POST['action'] === 'send_reply') {
        $convId = (int)$_POST['conversation_id'];
        $body = trim($_POST['body'] ?? '');
        if ($body) {
            $msg->send($convId, $userId, $body);
          if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'messages', 'send_reply', 'Conversation: ' . $convId);
        }
        redirect(APP_URL . '/pages/messages.php?conversation=' . $convId);
    }

    // Delete conversation
    if ($_POST['action'] === 'delete_conversation') {
        $convId = (int)$_POST['conversation_id'];
        $db->prepare("DELETE FROM messages WHERE conversation_id=? AND sender_id=?") ->execute([$convId, $userId]);
        $db->prepare("DELETE FROM conversation_participants WHERE conversation_id=? AND user_id=?") ->execute([$convId, $userId]);
      if ($audit) $audit->logActivity($userId, $user['name'], $user['role'], 'messages', 'delete_conversation', 'Conversation: ' . $convId);
      setFlash('success', 'Conversation deleted.');
        redirect(APP_URL . '/pages/messages.php');
    }
}

// Get conversations
$conversations = $msg->getConversations($userId);
$totalUnread = $msg->unreadCount($userId);

// Get active conversation
$activeConv = null;
$messages = [];
$participants = [];
$convId = isset($_GET['conversation']) ? (int)$_GET['conversation'] : 0;
if ($convId) {
    $activeConv = $msg->getConversation($convId, $userId);
    if ($activeConv) {
        $messages = $msg->getMessages($convId, $userId);
        $participants = $msg->getParticipants($convId);
    } else {
        $convId = 0;
    }
}

// Available users for new chat
$availableUsers = $msg->getAvailableUsers($userId);

// Get role labels
function roleLabel($role) {
    if ($role === 'admin') return '<span class="badge badge-primary">Admin</span>';
    if ($role === 'treasurer') return '<span class="badge badge-success">Treasurer</span>';
    return '<span class="badge badge-warning">Member</span>';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="messages-container">
  <!-- Sidebar: Conversation List -->
  <div class="messages-sidebar">
    <div class="messages-sidebar-header">
      <strong><i class="ti ti-messages me-2"></i>Conversations</strong>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal">
        <i class="ti ti-plus"></i>
      </button>
    </div>
    <div class="conversation-list">
      <?php if ($conversations): ?>
        <?php foreach ($conversations as $conv):
          $isActive = $conv['id'] === $convId;
          $otherParticipants = array_filter($participants ?: [], fn($p) => $p['id'] != $userId);
          $otherNames = implode(', ', array_map(fn($p) => $p['name'], $otherParticipants ?: []));
          $displayName = $otherNames ?: $conv['subject'];
        ?>
          <a href="?conversation=<?= $conv['id'] ?>" class="conversation-item <?= $isActive ? 'active' : '' ?>">
            <div class="conv-avatar"><?= strtoupper(substr($displayName, 0, 2)) ?></div>
            <div class="conv-info">
              <div class="conv-name">
                <?= escape($displayName) ?>
                <?php if ($conv['unread_count'] > 0 && !$isActive): ?>
                  <span class="badge bg-danger ms-1"><?= $conv['unread_count'] ?></span>
                <?php endif; ?>
              </div>
              <div class="conv-preview">
                <?php if ($conv['last_message']): ?>
                  <?= escape(substr($conv['last_message'], 0, 40)) ?>
                  <?= strlen($conv['last_message']) > 40 ? '...' : '' ?>
                <?php else: ?>
                  <span class="text-muted">No messages yet</span>
                <?php endif; ?>
              </div>
              <div class="conv-time"><?= $conv['last_message_at'] ? date('d M', strtotime($conv['last_message_at'])) : '' ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="text-center py-4 text-muted">
          <i class="ti ti-message-off" style="font-size:28px;"></i>
          <p class="mb-0 fs-12 mt-1">No conversations yet</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Main: Chat Area -->
  <div class="messages-main">
    <?php if ($activeConv && $messages !== null): ?>
      <!-- Chat Header -->
      <div class="chat-header">
        <div class="d-flex align-items-center gap-2">
          <span class="chat-avatar">
            <?php
            $otherP = array_filter($participants, fn($p) => $p['id'] != $userId);
            $firstName = $otherP ? reset($otherP)['name'] : 'Chat';
            echo strtoupper(substr($firstName, 0, 2));
            ?>
          </span>
          <div>
            <strong><?= escape($firstName) ?></strong>
            <div class="fs-11 text-muted">
              <?php foreach ($participants as $p): ?>
                <?php if ($p['id'] != $userId): ?>
                  <?= roleLabel($p['role']) ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <div class="chat-messages" id="chatMessages">
        <?php if ($messages): ?>
          <?php foreach ($messages as $m): ?>
            <?php $isMine = $m['sender_id'] === $userId; ?>
            <div class="message <?= $isMine ? 'message-mine' : 'message-other' ?>">
              <?php if (!$isMine): ?>
                <div class="message-sender"><?= escape($m['sender_name']) ?></div>
              <?php endif; ?>
              <div class="message-bubble"><?= nl2br(escape($m['body'])) ?></div>
              <div class="message-time"><?= date('H:i, d M', strtotime($m['created_at'])) ?></div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-center py-5 text-muted">
            <i class="ti ti-message" style="font-size:36px;"></i>
            <p class="mb-0 mt-2">Start a conversation</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Reply Box -->
      <div class="chat-input">
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="action" value="send_reply"/>
          <input type="hidden" name="conversation_id" value="<?= $convId ?>"/>
          <input type="text" name="body" class="form-control" placeholder="Type a message..." required autocomplete="off"/>
          <button type="submit" class="btn btn-primary">
            <i class="ti ti-send"></i>
          </button>
        </form>
      </div>

    <?php else: ?>
      <!-- No conversation selected -->
      <div class="chat-empty">
        <i class="ti ti-message-2" style="font-size:64px;color:#ddd;"></i>
        <h5 class="text-muted mt-3">Select a conversation</h5>
        <p class="text-muted fs-12">Choose a conversation from the left or start a new one</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal">
          <i class="ti ti-plus me-1"></i>New Message
        </button>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Message Modal -->
<div class="modal fade" id="newMessageModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="start_conversation"/>
        <div class="modal-header"><h5 class="modal-title">New Message</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Recipient *</label>
            <select name="recipient_id" class="form-select" required>
              <option value="">— Select person —</option>
              <?php foreach ($availableUsers as $au): ?>
                <option value="<?= $au['id'] ?>">
                  <?= escape($au['name']) ?> (<?= ucfirst($au['role']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <input name="subject" class="form-control" placeholder="Optional subject" value="Chat"/>
          </div>
          <div class="mb-3">
            <label class="form-label">Message *</label>
            <textarea name="body" class="form-control" rows="4" required placeholder="Write your message..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>Send Message</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Scroll to bottom -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  var chatMsgs = document.getElementById('chatMessages');
  if (chatMsgs) {
    chatMsgs.scrollTop = chatMsgs.scrollHeight;
  }
});
</script>

<style>
.messages-container {
  display: flex;
  height: calc(100vh - 130px);
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e8e8e0;
  overflow: hidden;
}
.messages-sidebar {
  width: 320px;
  border-right: 1px solid #e8e8e0;
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}
.messages-sidebar-header {
  padding: 14px 16px;
  border-bottom: 1px solid #e8e8e0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.conversation-list {
  flex: 1;
  overflow-y: auto;
}
.conversation-item {
  display: flex;
  gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid #f0f0e8;
  text-decoration: none;
  color: #111;
  transition: background 0.15s;
}
.conversation-item:hover { background: #f8f8f6; }
.conversation-item.active { background: #E6F1FB; }
.conv-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: #E6F1FB; color: #185FA5;
  display: flex; align-items: center; justify-content: center;
  font-weight: 600; font-size: 14px; flex-shrink: 0;
}
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-size: 13px; font-weight: 500; display: flex; align-items: center; }
.conv-preview { font-size: 12px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-time { font-size: 10px; color: #aaa; text-align: right; }

.messages-main {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.chat-header {
  padding: 14px 20px;
  border-bottom: 1px solid #e8e8e0;
  background: #fafaf7;
}
.chat-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: #E6F1FB; color: #185FA5;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 600; font-size: 13px;
}
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  background: #f5f5f0;
}
.message { margin-bottom: 16px; max-width: 75%; }
.message-mine { margin-left: auto; }
.message-other { margin-right: auto; }
.message-sender { font-size: 11px; color: #888; margin-bottom: 2px; }
.message-bubble {
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.4;
}
.message-mine .message-bubble {
  background: #185FA5;
  color: #fff;
  border-bottom-right-radius: 4px;
}
.message-other .message-bubble {
  background: #fff;
  color: #111;
  border: 1px solid #e8e8e0;
  border-bottom-left-radius: 4px;
}
.message-time { font-size: 10px; color: #aaa; margin-top: 2px; }
.message-mine .message-time { text-align: right; }

.chat-input {
  padding: 12px 20px;
  border-top: 1px solid #e8e8e0;
  background: #fff;
}
.chat-input .form-control {
  border-radius: 20px;
  border: 2px solid #e8e8e0;
  padding-left: 16px;
}
.chat-input .btn {
  border-radius: 50%;
  width: 42px; height: 42px;
  padding: 0;
  display: flex; align-items: center; justify-content: center;
}
.chat-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>