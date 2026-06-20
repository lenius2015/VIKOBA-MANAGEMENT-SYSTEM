<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();
$auth->requireRole(['admin']);
$user = $auth->getUser();
$gc = new GroupCenter();

// Handle form submissions (basic)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['action']) && $_POST['action'] === 'update_info') {
        $data = [
            'group_name' => trim($_POST['group_name'] ?? ''),
            'registration_number' => trim($_POST['registration_number'] ?? ''),
            'date_established' => trim($_POST['date_established'] ?? ''),
            'mission' => trim($_POST['mission'] ?? ''),
            'vision' => trim($_POST['vision'] ?? ''),
            'objectives' => trim($_POST['objectives'] ?? ''),
            'description' => trim($_POST['description'] ?? '')
        ];
        $gc->updateInfo($data, $user['id']);
        setFlash('success', 'Group information updated.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'create_announcement') {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'body' => trim($_POST['body'] ?? ''),
            'publish_at' => trim($_POST['publish_at'] ?? null),
            'expires_at' => trim($_POST['expires_at'] ?? null),
            'is_published' => isset($_POST['is_published']) ? 1 : 0
        ];
        $gc->createAnnouncement($data, $user['id']);
        setFlash('success', 'Announcement created.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'update_announcement') {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'body' => trim($_POST['body'] ?? ''),
            'publish_at' => trim($_POST['publish_at'] ?? null),
            'expires_at' => trim($_POST['expires_at'] ?? null),
            'is_published' => isset($_POST['is_published']) ? 1 : 0
        ];
        $gc->updateAnnouncement($data, (int)$_POST['announcement_id'], $user['id']);
        setFlash('success', 'Announcement updated.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'create_leader') {
        $photo = null;
        if (!empty($_FILES['leader_photo']) && $_FILES['leader_photo']['error'] === UPLOAD_ERR_OK) {
            $fn = basename($_FILES['leader_photo']['name']);
            $safe = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $fn);
            $destDir = __DIR__ . '/../uploads/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $target = $destDir . $safe;
            if (move_uploaded_file($_FILES['leader_photo']['tmp_name'], $target)) {
                $photo = $safe;
            }
        }
        $data = [
            'name' => trim($_POST['leader_name'] ?? ''),
            'position' => trim($_POST['leader_position'] ?? ''),
            'contact' => trim($_POST['leader_contact'] ?? ''),
            'photo' => $photo,
            'order_num' => (int)($_POST['leader_order'] ?? 0)
        ];
        $gc->addLeader($data, $user['id']);
        setFlash('success', 'Leadership member added.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'update_leader') {
        $leaderId = (int)($_POST['leader_id'] ?? 0);
        $existingLeader = $gc->getLeaderById($leaderId);
        if (!$existingLeader) {
            setFlash('error', 'Leadership member not found.');
            header('Location: ' . APP_URL . '/pages/group_admin.php');
            exit;
        }
        $photo = $existingLeader['photo'] ?? null;
        if (!empty($_POST['remove_photo'])) {
            $photo = null;
        } elseif (!empty($_FILES['leader_photo']) && $_FILES['leader_photo']['error'] === UPLOAD_ERR_OK) {
            $fn = basename($_FILES['leader_photo']['name']);
            $safe = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $fn);
            $destDir = __DIR__ . '/../uploads/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $target = $destDir . $safe;
            if (move_uploaded_file($_FILES['leader_photo']['tmp_name'], $target)) {
                $photo = $safe;
            }
        }
        $data = [
            'name' => trim($_POST['leader_name'] ?? ''),
            'position' => trim($_POST['leader_position'] ?? ''),
            'contact' => trim($_POST['leader_contact'] ?? ''),
            'photo' => $photo,
            'order_num' => (int)($_POST['leader_order'] ?? 0)
        ];
        $gc->updateLeader($data, $leaderId, $user['id']);
        setFlash('success', 'Leadership member updated.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'create_calendar_event') {
        $data = [
            'title' => trim($_POST['event_title'] ?? ''),
            'start_datetime' => trim($_POST['start_datetime'] ?? ''),
            'end_datetime' => trim($_POST['end_datetime'] ?? ''),
            'event_type' => trim($_POST['event_type'] ?? ''),
            'description' => trim($_POST['event_description'] ?? ''),
        ];
        $gc->addCalendarEvent($data, $user['id']);
        setFlash('success', 'Event added to calendar.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'upload_document') {
        if (!empty($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $fn = basename($_FILES['document']['name']);
            $ext = pathinfo($fn, PATHINFO_EXTENSION);
            $safe = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($fn));
            $destDir = __DIR__ . '/../uploads/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $target = $destDir . $safe;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
                $gc->addDocument(['title'=>trim($_POST['title_doc'] ?? $fn), 'filename'=>$safe, 'file_type'=>$ext, 'permission_level'=>($_POST['perm'] ?? 'members')], $user['id']);
                setFlash('success', 'Document uploaded.');
            } else {
                setFlash('error', 'Failed to move uploaded file.');
            }
        } else {
            setFlash('error', 'No file uploaded or upload error.');
        }
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'schedule_meeting') {
        $data = [
            'title' => trim($_POST['meeting_title'] ?? ''),
            'meeting_date' => trim($_POST['meeting_date'] ?? ''),
            'location' => trim($_POST['meeting_location'] ?? ''),
            'notes' => trim($_POST['meeting_notes'] ?? ''),
            'agenda_file' => null,
            'minutes_file' => null,
        ];
        if (!empty($_FILES['meeting_agenda']) && $_FILES['meeting_agenda']['error'] === UPLOAD_ERR_OK) {
            $fn = basename($_FILES['meeting_agenda']['name']);
            $safe = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fn);
            $destDir = __DIR__ . '/../uploads/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $target = $destDir . $safe;
            if (move_uploaded_file($_FILES['meeting_agenda']['tmp_name'], $target)) {
                $data['agenda_file'] = $safe;
            }
        }
        $gc->scheduleMeeting($data, $user['id']);
        setFlash('success', 'Meeting scheduled.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'add_faq') {
        $data = [
            'question' => trim($_POST['faq_question'] ?? ''),
            'answer' => trim($_POST['faq_answer'] ?? ''),
            'order_num' => (int)($_POST['faq_order'] ?? 0)
        ];
        $gc->addFaq($data, $user['id']);
        setFlash('success', 'FAQ added.');
        header('Location: ' . APP_URL . '/pages/group_admin.php');
        exit;
    }
}

// Handle deletion via GET
if (!empty($_GET['delete_announcement'])) {
    $id = (int)$_GET['delete_announcement'];
    $gc->deleteAnnouncement($id, $user['id']);
    setFlash('success', 'Announcement deleted.');
    header('Location: ' . APP_URL . '/pages/group_admin.php');
    exit;
}

if (!empty($_GET['delete_leader'])) {
    $id = (int)$_GET['delete_leader'];
    $gc->deleteLeader($id, $user['id']);
    setFlash('success', 'Leadership member deleted.');
    header('Location: ' . APP_URL . '/pages/group_admin.php');
    exit;
}

$pageTitle = 'Group Admin';
include __DIR__ . '/../includes/header.php';
$info = $gc->getInfo();
$announcements = $gc->getAllAnnouncements(50);
$docs = $gc->getDocuments('all');
$leadership = $gc->getLeadership();
$events = $gc->getCalendarEvents(10);
$faqs = $gc->getFaqs();
$editingAnnouncement = null;
if (!empty($_GET['edit_announcement'])) {
    $editingAnnouncement = $gc->getAnnouncementById((int)$_GET['edit_announcement']);
}
$editingLeader = null;
if (!empty($_GET['edit_leader'])) {
    $editingLeader = $gc->getLeaderById((int)$_GET['edit_leader']);
}
?>
<div class="container py-3">
  <div class="card mb-3">
    <div class="card-body">
      <h4>Manage Group Information</h4>
      <form method="post">
        <input type="hidden" name="action" value="update_info"/>
        <div class="mb-2">
          <label class="form-label">Group Name</label>
          <input class="form-control" name="group_name" value="<?= escape($info['group_name'] ?? '') ?>" required/>
        </div>
        <div class="mb-2">
          <label class="form-label">Registration Number</label>
          <input class="form-control" name="registration_number" value="<?= escape($info['registration_number'] ?? '') ?>" />
        </div>
        <div class="mb-2">
          <label class="form-label">Date Established</label>
          <input type="date" class="form-control" name="date_established" value="<?= escape($info['date_established'] ?? '') ?>" />
        </div>
        <div class="mb-2">
          <label class="form-label">Mission</label>
          <textarea class="form-control" name="mission"><?= escape($info['mission'] ?? '') ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Vision</label>
          <textarea class="form-control" name="vision"><?= escape($info['vision'] ?? '') ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Objectives</label>
          <textarea class="form-control" name="objectives"><?= escape($info['objectives'] ?? '') ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description"><?= escape($info['description'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary">Save</button>
      </form>
    </div>
  </div>

  <div class="row">
    <div class="col-md-6">
      <?php if ($editingAnnouncement): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5>Edit Announcement</h5>
          <form method="post">
            <input type="hidden" name="action" value="update_announcement"/>
            <input type="hidden" name="announcement_id" value="<?= (int)$editingAnnouncement['id'] ?>" />
            <div class="mb-2"><input class="form-control" name="title" placeholder="Title" required value="<?= escape($editingAnnouncement['title']) ?>"/></div>
            <div class="mb-2"><textarea class="form-control" name="body" placeholder="Message" required><?= escape($editingAnnouncement['body']) ?></textarea></div>
            <div class="mb-2"><label>Publish At</label><input class="form-control" type="datetime-local" name="publish_at" value="<?= date('Y-m-d\TH:i', strtotime($editingAnnouncement['publish_at'])) ?>"/></div>
            <div class="mb-2"><label>Expires At</label><input class="form-control" type="datetime-local" name="expires_at" value="<?= $editingAnnouncement['expires_at'] ? date('Y-m-d\TH:i', strtotime($editingAnnouncement['expires_at'])) : '' ?>"/></div>
            <div class="mb-2 form-check"><input type="checkbox" class="form-check-input" id="ispub_edit" name="is_published" <?= $editingAnnouncement['is_published'] ? 'checked' : '' ?>/><label class="form-check-label" for="ispub_edit">Published</label></div>
            <button class="btn btn-warning">Update</button>
            <a href="<?= APP_URL ?>/pages/group_admin.php" class="btn btn-link">Cancel</a>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Create Announcement</h5>
          <form method="post">
            <input type="hidden" name="action" value="create_announcement"/>
            <div class="mb-2"><input class="form-control" name="title" placeholder="Title" required/></div>
            <div class="mb-2"><textarea class="form-control" name="body" placeholder="Message" required></textarea></div>
            <div class="mb-2"><label>Publish At</label><input class="form-control" type="datetime-local" name="publish_at"/></div>
            <div class="mb-2"><label>Expires At</label><input class="form-control" type="datetime-local" name="expires_at"/></div>
            <div class="mb-2 form-check"><input type="checkbox" class="form-check-input" id="ispub" name="is_published" checked/><label class="form-check-label" for="ispub">Published</label></div>
            <button class="btn btn-success">Publish</button>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Schedule Meeting</h5>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="schedule_meeting"/>
            <div class="mb-2"><input class="form-control" name="meeting_title" placeholder="Meeting Title" required/></div>
            <div class="mb-2"><input type="datetime-local" class="form-control" name="meeting_date" required/></div>
            <div class="mb-2"><input class="form-control" name="meeting_location" placeholder="Location" required/></div>
            <div class="mb-2"><textarea class="form-control" name="meeting_notes" placeholder="Notes"></textarea></div>
            <div class="mb-2"><label>Agenda File</label><input type="file" name="meeting_agenda" class="form-control"/></div>
            <button class="btn btn-warning">Schedule</button>
          </form>
        </div>
      </div>


      <div class="card mb-3">
        <div class="card-body">
          <h5>Add Leadership Member</h5>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_leader"/>
            <div class="mb-2"><input class="form-control" name="leader_name" placeholder="Name" required/></div>
            <div class="mb-2"><input class="form-control" name="leader_position" placeholder="Position" required/></div>
            <div class="mb-2"><input class="form-control" name="leader_contact" placeholder="Contact"/></div>
            <div class="mb-2"><input type="number" class="form-control" name="leader_order" placeholder="Display order" value="0"/></div>
            <div class="mb-2"><label>Photo</label><input type="file" name="leader_photo" class="form-control"/></div>
            <button class="btn btn-success">Add Leader</button>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Add Calendar Event</h5>
          <form method="post">
            <input type="hidden" name="action" value="create_calendar_event"/>
            <div class="mb-2"><input class="form-control" name="event_title" placeholder="Event Title" required/></div>
            <div class="mb-2"><label>Start</label><input type="datetime-local" class="form-control" name="start_datetime" required/></div>
            <div class="mb-2"><label>End</label><input type="datetime-local" class="form-control" name="end_datetime"/></div>
            <div class="mb-2"><input class="form-control" name="event_type" placeholder="Event Type (e.g. Meeting, Deadline)"/></div>
            <div class="mb-2"><textarea class="form-control" name="event_description" placeholder="Description"></textarea></div>
            <button class="btn btn-primary">Add Event</button>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Upload Document</h5>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_document"/>
            <div class="mb-2"><input class="form-control" name="title_doc" placeholder="Document Title" required/></div>
            <div class="mb-2"><input type="file" name="document" class="form-control" required/></div>
            <div class="mb-2">
              <label>Permission</label>
              <select name="perm" class="form-control">
                <option value="public">Public</option>
                <option value="members" selected>Members</option>
                <option value="admins">Admins</option>
              </select>
            </div>
            <button class="btn btn-secondary">Upload</button>
          </form>
        </div>
      </div>

    </div>
    <div class="col-md-6">
      <div class="card mb-3">
        <div class="card-body">
          <h5>Announcements</h5>
          <?php if (empty($announcements)): ?><p class="text-muted">No announcements.</p><?php else: ?>
            <ul class="list-unstyled">
              <?php foreach ($announcements as $a): ?>
                <li class="mb-2">
                  <strong><?= escape($a['title']) ?></strong>
                  <div class="text-muted small"><?= date('d M Y', strtotime($a['publish_at'])) ?> — <a href="<?= APP_URL ?>/pages/group_admin.php?edit_announcement=<?= $a['id'] ?>">Edit</a> | <a href="<?= APP_URL ?>/pages/group_admin.php?delete_announcement=<?= $a['id'] ?>">Delete</a></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Leadership</h5>
          <?php if (empty($leadership)): ?><p class="text-muted">No leadership members added.</p><?php else: ?>
            <ul class="list-unstyled">
              <?php foreach ($leadership as $l): ?>
                <li class="mb-3 d-flex gap-3 align-items-center">
                  <?php if (!empty($l['photo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= escape($l['photo']) ?>" alt="<?= escape($l['name']) ?>" class="rounded-circle" style="width:48px;height:48px;object-fit:cover;" />
                  <?php else: ?>
                    <div class="avatar-circle" style="width:48px;height:48px;font-size:16px;line-height:48px;text-align:center;background:#F1F5F9;color:#0F172A;"><?= strtoupper(substr($l['name'],0,2)) ?></div>
                  <?php endif; ?>
                  <div>
                    <strong><?= escape($l['name']) ?></strong><br/>
                    <small class="text-muted"><?= escape($l['position']) ?> — <?= escape($l['contact']) ?></small><br/>
                    <div class="text-muted small"><a href="<?= APP_URL ?>/pages/group_admin.php?edit_leader=<?= $l['id'] ?>">Edit</a> | <a href="<?= APP_URL ?>/pages/group_admin.php?delete_leader=<?= $l['id'] ?>">Delete</a></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($editingLeader): ?>
      <div class="card mb-3 border-warning border-2 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0">Editing Leadership Member</h5>
            <span class="badge bg-warning text-dark">Edit mode</span>
          </div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_leader"/>
            <input type="hidden" name="leader_id" value="<?= (int)$editingLeader['id'] ?>"/>
            <input type="hidden" name="current_photo" value="<?= escape($editingLeader['photo']) ?>"/>
            <?php if (!empty($editingLeader['photo'])): ?>
              <div class="mb-3 text-center">
                <img src="<?= APP_URL ?>/uploads/<?= escape($editingLeader['photo']) ?>" alt="<?= escape($editingLeader['name']) ?>" class="rounded-circle" style="width:80px;height:80px;object-fit:cover;" />
              </div>
            <?php endif; ?>
            <div class="mb-2"><input class="form-control" name="leader_name" placeholder="Name" required value="<?= escape($editingLeader['name']) ?>"/></div>
            <div class="mb-2"><input class="form-control" name="leader_position" placeholder="Position" required value="<?= escape($editingLeader['position']) ?>"/></div>
            <div class="mb-2"><input class="form-control" name="leader_contact" placeholder="Contact" value="<?= escape($editingLeader['contact']) ?>"/></div>
            <div class="mb-2"><input type="number" class="form-control" name="leader_order" placeholder="Display order" value="<?= (int)$editingLeader['order_num'] ?>"/></div>
            <div class="mb-2"><label>Photo</label><input type="file" name="leader_photo" accept="image/*" class="form-control"/></div>
            <?php if (!empty($editingLeader['photo'])): ?>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remove_photo" id="remove_leader_photo" value="1" />
                <label class="form-check-label" for="remove_leader_photo">Remove current photo</label>
              </div>
            <?php endif; ?>
            <button class="btn btn-warning">Save Leadership</button>
            <a href="<?= APP_URL ?>/pages/group_admin.php" class="btn btn-link">Cancel</a>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Upcoming Calendar Events</h5>
          <?php if (empty($events)): ?><p class="text-muted">No calendar events.</p><?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($events as $e): ?>
                <li class="mb-2">
                  <strong><?= escape($e['title']) ?></strong><br/>
                  <small class="text-muted"><?= date('d M Y H:i', strtotime($e['start_datetime'])) ?><?= $e['end_datetime'] ? ' - ' . date('d M Y H:i', strtotime($e['end_datetime'])) : '' ?></small><br/>
                  <small class="text-muted"><?= escape($e['event_type']) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Documents</h5>
          <?php if (empty($docs)): ?><p class="text-muted">No documents.</p><?php else: ?>
            <ul class="list-unstyled">
              <?php foreach ($docs as $d): ?>
                <li class="mb-2"><a href="<?= APP_URL ?>/pages/group_document.php?id=<?= $d['id'] ?>" target="_blank"><?= escape($d['title']) ?></a> <small class="text-muted"><?= escape($d['permission_level']) ?></small></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Add FAQ</h5>
          <form method="post">
            <input type="hidden" name="action" value="add_faq"/>
            <div class="mb-2"><input class="form-control" name="faq_question" placeholder="Question" required/></div>
            <div class="mb-2"><textarea class="form-control" name="faq_answer" placeholder="Answer" required></textarea></div>
            <div class="mb-2"><input type="number" class="form-control" name="faq_order" placeholder="Display order" value="0"/></div>
            <button class="btn btn-info">Add FAQ</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';
