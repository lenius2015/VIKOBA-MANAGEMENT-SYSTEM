<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['member']);

$pageTitle = 'My Contributions';
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

$memberId = $member['id'];

// Handle contribution submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_contribution') {
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $reference = trim($_POST['reference'] ?? '');
    $contribDate = $_POST['date'] ?? date('Y-m-d');
    $cycleId = !empty($_POST['cycle_id']) ? (int)$_POST['cycle_id'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        setFlash('error', 'Please enter a valid contribution amount.');
        redirect(APP_URL . '/pages/member_contributions.php');
    }

    $stmt = $db->prepare(
        "INSERT INTO contributions (member_id, cycle_id, amount, payment_method, reference, date, recorded_by, notes)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    if ($stmt->execute([$memberId, $cycleId, $amount, $paymentMethod, $reference, $contribDate, $user['id'], $notes])) {
        // Notify admin
        try {
            $notif = new Notification();
            $notif->notifyAdmins('contribution', 'New Contribution',
                $member['name'] . ' recorded a contribution of Tsh ' . number_format($amount, 2) . ' on ' . $contribDate,
                APP_URL . '/pages/contributions.php');
        } catch (Throwable $e) {}

        setFlash('success', 'Contribution of ' . tsh($amount) . ' recorded successfully.');
    } else {
        setFlash('error', 'Failed to record contribution.');
    }
    redirect(APP_URL . '/pages/member_contributions.php');
}

// Get contributions
$stmt = $db->prepare("
    SELECT c.*, cy.name as cycle_name
    FROM contributions c
    LEFT JOIN cycles cy ON c.cycle_id = cy.id
    WHERE c.member_id = ?
    ORDER BY c.date DESC
");
$stmt->execute([$memberId]);
$contributions = $stmt->fetchAll();

// Get active cycles
$cycles = $db->query("SELECT * FROM cycles WHERE status='open' ORDER BY start_date DESC")->fetchAll();

$totalAmount = array_sum(array_column($contributions, 'amount'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="stat-card">
      <div class="stat-label">Total Contributions</div>
      <div class="stat-value"><?= tsh($totalAmount) ?></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="stat-card">
      <div class="stat-label">Number of Payments</div>
      <div class="stat-value"><?= count($contributions) ?></div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="ti ti-coin me-2 icon-primary"></i>
      My Contribution History
    </strong>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addContributionModal">
      <i class="ti ti-plus me-1 icon-white"></i>
      Make Contribution
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>#</th><th>Date</th><th>Amount</th><th>Cycle</th><th>Payment Method</th><th>Reference</th></tr>
      </thead>
      <tbody>
      <?php if ($contributions): ?>
        <?php foreach ($contributions as $i => $c): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= $c['date'] ?></td>
          <td><strong><?= tsh($c['amount']) ?></strong></td>
          <td><?= escape($c['cycle_name'] ?? '—') ?></td>
          <td><span class="badge badge-primary"><?= ucfirst(str_replace('_',' ',$c['payment_method'])) ?></span></td>
          <td><?= escape($c['reference'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" class="text-center py-4 text-muted">No contributions recorded yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Contribution Modal -->
<div class="modal fade" id="addContributionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="add_contribution"/>
        <div class="modal-header">
          <h5 class="modal-title">Make a Contribution</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="ti ti-info-circle me-1 mt-1 icon-primary"></i>
            <div>Record your savings contribution. Admin will be notified for confirmation.</div>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Amount (Tsh) *</label>
              <input name="amount" type="number" step="0.01" class="form-control" required min="1000"/>
            </div>
            <div class="col-6">
              <label class="form-label">Cycle (Optional)</label>
              <select name="cycle_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($cycles as $cy): ?>
                  <option value="<?=$cy['id']?>"><?=escape($cy['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
              <div class="col-6">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-select">
                  <option value="cash">Cash</option>
                  <option value="mobile_money">Mobile Money</option>
                  <option value="bank_transfer">Bank Transfer</option>
                </select>
              </div>
            <div class="col-6">
              <label class="form-label">Date *</label>
              <input name="date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required/>
            </div>
            <div class="col-6">
              <label class="form-label">Reference No.</label>
              <input name="reference" class="form-control" placeholder="Transaction ID (optional)"/>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="ti ti-send me-1 icon-white"></i>
            Submit Contribution
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>