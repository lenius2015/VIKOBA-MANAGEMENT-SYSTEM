<?php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'treasurer']);

$pageTitle = 'Loan Products';
$user = $auth->getUser();
$db   = Database::getInstance()->getConnection();

$productModel = new LoanProduct();

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && $user['role'] === 'admin') {
        try {
            $productModel->create($_POST);
            $auth->log('Create Loan Product', 'loan_products', 'Product: ' . $_POST['name']);
            setFlash('success', 'Loan product created successfully.');
        } catch (Exception $e) {
            setFlash('error', 'Failed to create product: ' . $e->getMessage());
        }
        redirect(APP_URL . '/pages/loan_products.php');
    }

    if ($action === 'update' && $user['role'] === 'admin') {
        $id = (int)$_POST['product_id'];
        try {
            $productModel->update($id, $_POST);
            $auth->log('Update Loan Product', 'loan_products', 'Product ID: ' . $id);
            setFlash('success', 'Loan product updated.');
        } catch (Exception $e) {
            setFlash('error', 'Failed to update product.');
        }
        redirect(APP_URL . '/pages/loan_products.php');
    }

    if ($action === 'delete' && $user['role'] === 'admin') {
        $id = (int)$_POST['product_id'];
        try {
            $productModel->delete($id);
            $auth->log('Delete Loan Product', 'loan_products', 'Product ID: ' . $id);
            setFlash('success', 'Loan product deleted successfully.');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
        redirect(APP_URL . '/pages/loan_products.php');
    }

    if ($action === 'toggle_status') {
        $id = (int)$_POST['product_id'];
        $stmt = $db->prepare("SELECT status FROM loan_products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if ($product) {
            $newStatus = $product['status'] === 'active' ? 'inactive' : 'active';
            $stmt = $db->prepare("UPDATE loan_products SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $auth->log('Toggle Loan Product Status', 'loan_products', 'Product ID: ' . $id . ' -> ' . $newStatus);
            setFlash('success', 'Product status updated.');
        }
        redirect(APP_URL . '/pages/loan_products.php');
    }
}

$products = $productModel->getAll(false);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong><i class="ti ti-package me-2"></i>Loan Products</strong>
    <?php if ($user['role'] === 'admin'): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
      <i class="ti ti-plus me-1"></i>New Product
    </button>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Code</th><th>Name</th><th>Interest</th><th>Term</th><th>Amount Range</th>
          <th>Savings %</th><th>Share Mult.</th><th>Guarantor</th><th>Auto-Approve</th><th>Status</th>
          <?php if ($user['role'] === 'admin'): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><code><?= escape($p['code']) ?></code></td>
          <td><strong><?= escape($p['name']) ?></strong>
            <?php if ($p['description']): ?><br><small class="text-muted"><?= escape($p['description']) ?></small><?php endif; ?>
          </td>
          <td><?= $p['default_interest_rate'] ?>%</td>
          <td><?= $p['min_term_months'] ?>-<?= $p['max_term_months'] ?> mo</td>
          <td><?= tsh($p['min_amount']) ?> - <?= tsh($p['max_amount']) ?></td>
          <td><?= $p['min_savings_pct'] ?>%</td>
          <td><?= $p['share_multiplier'] ?>x</td>
          <td>
            <?php if ($p['requires_guarantor']): ?>
              <span class="badge bg-warning text-dark">Yes (min <?= $p['min_guarantors'] ?>)</span>
            <?php else: ?>
              <span class="badge bg-secondary">No</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['auto_approve_threshold'] || $p['auto_approve_min_score']): ?>
              <span class="badge bg-success">
                ≤ <?= tsh($p['auto_approve_threshold'] ?: 0) ?>
                <?php if ($p['auto_approve_min_score']): ?> + Score ≥ <?= $p['auto_approve_min_score'] ?><?php endif; ?>
              </span>
            <?php else: ?>
              <span class="badge bg-secondary">Manual only</span>
            <?php endif; ?>
          </td>
          <td><?= $p['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>' ?></td>
          <?php if ($user['role'] === 'admin'): ?>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProductModal"
                data-id="<?=$p['id']?>" data-code="<?=escape($p['code'])?>" data-name="<?=escape($p['name'])?>"
                data-description="<?=escape($p['description']??'')?>" data-min-amount="<?=$p['min_amount']?>"
                data-max-amount="<?=$p['max_amount']?>" data-interest="<?=$p['default_interest_rate']?>"
                data-min-term="<?=$p['min_term_months']?>" data-max-term="<?=$p['max_term_months']?>"
                data-savings-pct="<?=$p['min_savings_pct']?>" data-share-mult="<?=$p['share_multiplier']?>"
                data-late-fee="<?=$p['late_fee_pct']?>" data-grace="<?=$p['late_fee_grace_days']?>"
                data-guarantor="<?=$p['requires_guarantor']?>" data-min-guarantors="<?=$p['min_guarantors']?>"
                data-collateral="<?=$p['requires_collateral']?>" data-auto-threshold="<?=$p['auto_approve_threshold']?>"
                data-auto-score="<?=$p['auto_approve_min_score']?>">
                <i class="ti ti-edit"></i>
              </button>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="toggle_status"/>
                <input type="hidden" name="product_id" value="<?=$p['id']?>"/>
                <button type="submit" class="btn btn-sm btn-outline-<?=$p['status']==='active'?'danger':'success'?>"
                  onclick="return confirm('Toggle status for <?=escape($p['name'])?>?')">
                  <i class="ti ti-<?=$p['status']==='active'?'pause':'play'?>"></i>
                </button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete product <?=escape($p['name'])?>? This cannot be undone if no loans reference it.');">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="product_id" value="<?=$p['id']?>"/>
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($products)): ?>
        <tr><td colspan="11" class="text-center py-4 text-muted">No loan products configured yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="create"/>
        <div class="modal-header"><h5 class="modal-title">New Loan Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Product Code *</label><input name="code" class="form-control" required placeholder="e.g. BUSINESS"/></div>
            <div class="col-md-6"><label class="form-label">Product Name *</label><input name="name" class="form-control" required/></div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Min Amount (Tsh)</label><input name="min_amount" type="number" step="1000" class="form-control" value="10000"/></div>
            <div class="col-md-4"><label class="form-label">Max Amount (Tsh)</label><input name="max_amount" type="number" step="1000" class="form-control" value="5000000"/></div>
            <div class="col-md-4"><label class="form-label">Interest Rate (%)</label><input name="default_interest_rate" type="number" step="0.01" class="form-control" value="15"/></div>
            <div class="col-md-3"><label class="form-label">Min Term (months)</label><input name="min_term_months" type="number" class="form-control" value="1"/></div>
            <div class="col-md-3"><label class="form-label">Max Term (months)</label><input name="max_term_months" type="number" class="form-control" value="12"/></div>
            <div class="col-md-3"><label class="form-label">Min Savings %</label><input name="min_savings_pct" type="number" step="0.01" class="form-control" value="20"/></div>
            <div class="col-md-3"><label class="form-label">Share Multiplier</label><input name="share_multiplier" type="number" step="0.5" class="form-control" value="3"/></div>
            <div class="col-md-3"><label class="form-label">Late Fee % / month</label><input name="late_fee_pct" type="number" step="0.25" class="form-control" value="1"/></div>
            <div class="col-md-3"><label class="form-label">Grace Days</label><input name="late_fee_grace_days" type="number" class="form-control" value="7"/></div>
            <div class="col-md-3">
              <label class="form-label">Requires Guarantor</label>
              <select name="requires_guarantor" class="form-select">
                <option value="0">No</option><option value="1">Yes</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Min Guarantors</label><input name="min_guarantors" type="number" class="form-control" value="0"/></div>
            <div class="col-md-4"><label class="form-label">Auto-Approve ≤ Amount</label><input name="auto_approve_threshold" type="number" step="1000" class="form-control" placeholder="Leave empty to disable"/></div>
            <div class="col-md-4"><label class="form-label">Auto-Approve Min Score</label><input name="auto_approve_min_score" type="number" class="form-control" placeholder="e.g. 80"/></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="product_id" id="edit_product_id"/>
        <div class="modal-header"><h5 class="modal-title">Edit Loan Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Product Code *</label><input name="code" id="edit_code" class="form-control" required/></div>
            <div class="col-md-6"><label class="form-label">Product Name *</label><input name="name" id="edit_name" class="form-control" required/></div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="edit_description" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Min Amount</label><input name="min_amount" id="edit_min_amount" type="number" step="1000" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Max Amount</label><input name="max_amount" id="edit_max_amount" type="number" step="1000" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Interest Rate (%)</label><input name="default_interest_rate" id="edit_interest" type="number" step="0.01" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Min Term (months)</label><input name="min_term_months" id="edit_min_term" type="number" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Max Term (months)</label><input name="max_term_months" id="edit_max_term" type="number" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Min Savings %</label><input name="min_savings_pct" id="edit_savings_pct" type="number" step="0.01" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Share Multiplier</label><input name="share_multiplier" id="edit_share_mult" type="number" step="0.5" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Late Fee % / month</label><input name="late_fee_pct" id="edit_late_fee" type="number" step="0.25" class="form-control"/></div>
            <div class="col-md-3"><label class="form-label">Grace Days</label><input name="late_fee_grace_days" id="edit_grace" type="number" class="form-control"/></div>
            <div class="col-md-3">
              <label class="form-label">Requires Guarantor</label>
              <select name="requires_guarantor" id="edit_guarantor" class="form-select">
                <option value="0">No</option><option value="1">Yes</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Min Guarantors</label><input name="min_guarantors" id="edit_min_guarantors" type="number" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Auto-Approve ≤ Amount</label><input name="auto_approve_threshold" id="edit_auto_threshold" type="number" step="1000" class="form-control"/></div>
            <div class="col-md-4"><label class="form-label">Auto-Approve Min Score</label><input name="auto_approve_min_score" id="edit_auto_score" type="number" class="form-control"/></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('editProductModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('edit_product_id').value = btn.dataset.id;
  document.getElementById('edit_code').value = btn.dataset.code;
  document.getElementById('edit_name').value = btn.dataset.name;
  document.getElementById('edit_description').value = btn.dataset.description;
  document.getElementById('edit_min_amount').value = btn.dataset.minAmount;
  document.getElementById('edit_max_amount').value = btn.dataset.maxAmount;
  document.getElementById('edit_interest').value = btn.dataset.interest;
  document.getElementById('edit_min_term').value = btn.dataset.minTerm;
  document.getElementById('edit_max_term').value = btn.dataset.maxTerm;
  document.getElementById('edit_savings_pct').value = btn.dataset.savingsPct;
  document.getElementById('edit_share_mult').value = btn.dataset.shareMult;
  document.getElementById('edit_late_fee').value = btn.dataset.lateFee;
  document.getElementById('edit_grace').value = btn.dataset.grace;
  document.getElementById('edit_guarantor').value = btn.dataset.guarantor;
  document.getElementById('edit_min_guarantors').value = btn.dataset.minGuarantors;
  document.getElementById('edit_auto_threshold').value = btn.dataset.autoThreshold || '';
  document.getElementById('edit_auto_score').value = btn.dataset.autoScore || '';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>