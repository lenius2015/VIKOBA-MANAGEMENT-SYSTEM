<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$auth = new Auth();
$auth->requireLogin();
$user = $auth->getUser();
$gc = new GroupCenter();

$pageTitle = 'Group Information Center';
include __DIR__ . '/../includes/header.php';

$info = $gc->getInfo();
$leadership = $gc->getLeadership();
$announcements = $gc->getAnnouncements(10);
$upcoming = $gc->getUpcomingMeetings(5);
$events = $gc->getCalendarEvents(5);
$docs = $gc->getDocuments('public');
$faqs = $gc->getFaqs();
?>
<div class="container py-3">
  <div class="row">
    <div class="col-md-8">
      <div class="card mb-3">
        <div class="card-body">
          <h4 class="mb-1"><?= escape($info['group_name'] ?? 'Group') ?></h4>
          <div class="text-muted mb-2">Registration: <?= escape($info['registration_number'] ?? '-') ?> | Established: <?= escape($info['date_established'] ?? '-') ?></div>
          <p class="mb-0"><?= nl2br(escape($info['description'] ?? '')) ?></p>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Announcements</h5>
          <?php if (empty($announcements)): ?>
            <p class="text-muted">No announcements at this time.</p>
          <?php else: ?>
            <?php foreach ($announcements as $a): ?>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <div><strong><?= escape($a['title']) ?></strong> <small class="text-muted"><?= date('d M Y', strtotime($a['publish_at'])) ?></small></div>
                </div>
                <div class="text-muted"><?= nl2br(escape($a['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Upcoming Meetings</h5>
          <?php if (empty($upcoming)): ?>
            <p class="text-muted">No upcoming meetings scheduled.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($upcoming as $m): ?>
                <li class="mb-2">
                  <strong><?= escape($m['title']) ?></strong><br/>
                  <small class="text-muted"><?= date('d M Y H:i', strtotime($m['meeting_date'])) ?> — <?= escape($m['location']) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Calendar Events</h5>
          <?php if (empty($events)): ?>
            <p class="text-muted">No upcoming events on the calendar.</p>
          <?php else: ?>
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
          <?php if (empty($docs)): ?>
            <p class="text-muted">No documents available.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($docs as $d): ?>
                <li class="mb-2">
                  <a href="<?= APP_URL ?>/pages/group_document.php?id=<?= $d['id'] ?>" target="_blank"><?= escape($d['title']) ?></a>
                  <small class="text-muted"> — <?= date('d M Y', strtotime($d['uploaded_at'])) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>FAQ</h5>
          <?php if (empty($faqs)): ?>
            <p class="text-muted">No FAQs yet.</p>
          <?php else: ?>
            <?php foreach ($faqs as $f): ?>
              <div class="mb-2">
                <strong><?= escape($f['question']) ?></strong>
                <div class="text-muted"><?= nl2br(escape($f['answer'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
    <div class="col-md-4">
      <div class="card mb-3">
        <div class="card-body">
          <h5>Leadership</h5>
          <?php if (empty($leadership)): ?>
            <p class="text-muted">Leadership details not available.</p>
          <?php else: ?>
            <?php foreach ($leadership as $l): ?>
              <div class="d-flex gap-2 mb-2">
                <div style="width:54px;height:54px;background:#f0f0f0;border-radius:6px;overflow:hidden;flex:0 0 54px;">
                  <?php if (!empty($l['photo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= escape($l['photo']) ?>" alt="<?= escape($l['name']) ?>" style="width:100%;height:100%;object-fit:cover;"/>
                  <?php else: ?>
                    <div class="text-muted p-2">No Photo</div>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="fw-600"><?= escape($l['name']) ?></div>
                  <div class="text-muted small"><?= escape($l['position']) ?> | <?= escape($l['contact']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5>Group Statistics</h5>
          <?php
          // Basic stats - do not expose private financials
          try {
            $db = Database::getInstance()->getConnection();
            $totalMembers = (int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn();
            $activeMembers = (int)$db->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
            $totalContrib = (float)$db->query("SELECT IFNULL(SUM(amount),0) FROM contributions")->fetchColumn();
            $activeLoans = (int)$db->query("SELECT COUNT(*) FROM loans WHERE status='active'")->fetchColumn();
            $repaidLoans = (int)$db->query("SELECT COUNT(*) FROM loans WHERE status='repaid'")->fetchColumn();
            $totalSavings = 0;
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_savings'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > 0) {
                $totalSavings = (float)$db->query("SELECT IFNULL(SUM(balance),0) FROM member_savings")->fetchColumn();
            }
          } catch (Throwable $e) {
            $totalMembers = $activeMembers = $activeLoans = $repaidLoans = 0;
            $totalContrib = $totalSavings = 0;
          }
          ?>

          <ul class="list-unstyled mb-0">
            <li>Total Members: <strong><?= $totalMembers ?></strong></li>
            <li>Active Members: <strong><?= $activeMembers ?></strong></li>
            <li>Total Contributions: <strong><?= number_format($totalContrib,2) ?></strong></li>
            <li>Active Loans: <strong><?= $activeLoans ?></strong></li>
            <li>Repaid Loans: <strong><?= $repaidLoans ?></strong></li>
            <li>Total Savings: <strong><?= number_format($totalSavings,2) ?></strong></li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';
