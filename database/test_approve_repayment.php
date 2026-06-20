<?php
require_once __DIR__ . '/../includes/bootstrap.php';
// Run a simulated test: create a pending repayment, then approve it as an admin
$db = Database::getInstance()->getConnection();
$loanModel = new Loan();
$notif = new Notification();

try {
    // Find a loan to use
    $loanRow = $db->query("SELECT id, member_id, loan_no FROM loans LIMIT 1")->fetch();
    if (!$loanRow) {
        throw new Exception('No loan found in database.');
    }
    $loanId = (int)$loanRow['id'];
    $memberId = (int)$loanRow['member_id'];
    $loanNo = $loanRow['loan_no'];

    // Find an admin user to act as reviewer
    $admin = $db->query("SELECT id, name FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch();
    if (!$admin) {
        throw new Exception('No active admin user found.');
    }
    $adminId = (int)$admin['id'];

    // Find a user account for the member (for notification)
    $memberUser = $db->prepare("SELECT id FROM users WHERE member_id = ? AND status='active' LIMIT 1");
    $memberUser->execute([$memberId]);
    $memberUser = $memberUser->fetch();

    $amount = 1000.00;
    $date = date('Y-m-d');

    // Insert pending repayment
    $stmt = $db->prepare("INSERT INTO repayments (loan_id, member_id, amount, payment_method, reference, status, date, recorded_by, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([$loanId, $memberId, $amount, 'cash', 'TESTREF' . time(), 'pending', $date, $memberUser['id'] ?? null, 'Test pending repayment']);
    $repaymentId = (int)$db->lastInsertId();
    echo "Inserted pending repayment id: $repaymentId for loan $loanNo\n";

    // Now simulate admin approving it (same as pending_payments.php)
    $upd = $db->prepare("UPDATE repayments SET status='approved', reviewed_by=?, reviewed_at=NOW(), recorded_by=? WHERE id=?");
    $upd->execute([$adminId, $adminId, $repaymentId]);
    echo "Marked repayment $repaymentId as approved by admin $adminId\n";

    // Apply payment
    $loanModel->applyManualRepayment($loanId, $amount, $date);
    echo "Applied repayment to loan schedule.\n";

    // Create notification for member user if exists
    if (!empty($memberUser)) {
        $notif->create($memberUser['id'], 'repayment_approved', 'Repayment Approved', "Your repayment of " . tsh($amount) . " for loan " . $loanNo . " has been approved and applied.", APP_URL . '/pages/member_loans.php');
        echo "Notification created for user id: {$memberUser['id']}\n";

        // Fetch the notification to confirm
        $n = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $n->execute([$memberUser['id']]);
        $note = $n->fetch();
        echo "Notification: " . json_encode($note) . "\n";
    } else {
        echo "No user account linked to member; skipping notification.\n";
    }

    // Show repayment record
    $r = $db->prepare("SELECT * FROM repayments WHERE id = ?");
    $r->execute([$repaymentId]);
    $rep = $r->fetch();
    echo "Repayment DB row: " . json_encode($rep) . "\n";

    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Test failed: " . $e->getMessage() . "\n");
    exit(1);
}
