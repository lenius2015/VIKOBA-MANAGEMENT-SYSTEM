<?php
// ============================================================
// VIKOBA - Notification Class
// ============================================================

class Notification {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a notification for a user
     */
    public function create(int $userId, string $type, string $title, string $message, string $link = null): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)"
        );
        return $stmt->execute([$userId, $type, $title, $message, $link]);
    }

    /**
     * Create notification for all admin/treasurer users
     */
    public function notifyAdmins(string $type, string $title, string $message, string $link = null): void {
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('admin','treasurer') AND status='active'");
        $admins = $stmt->fetchAll();
        foreach ($admins as $admin) {
            $this->create($admin['id'], $type, $title, $message, $link);
        }
    }

    public function getUsers(string $status = 'active'): array {
        $sql = "SELECT id, name, email, role FROM users";
        $params = [];
        if ($status !== '') {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function notifyUsers(array $userIds, string $type, string $title, string $message, string $link = null): void {
        $userIds = array_unique(array_map('intval', $userIds));
        foreach ($userIds as $userId) {
            if ($userId > 0) {
                $this->create($userId, $type, $title, $message, $link);
            }
        }
    }

    public function notifyAll(string $type, string $title, string $message, string $link = null): void {
        $stmt = $this->db->query("SELECT id FROM users WHERE status='active'");
        $users = $stmt->fetchAll();
        foreach ($users as $user) {
            $this->create($user['id'], $type, $title, $message, $link);
        }
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread(int $userId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get all notifications for a user
     */
    public function getAll(int $userId, int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $perPage, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Get unread count
     */
    public function unreadCount(int $userId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mark notification as read
     */
    public function markRead(int $id, int $userId): bool {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllRead(int $userId): bool {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Delete notification
     */
    public function delete(int $id, int $userId): bool {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }

    // ============================================================
    // APPROVAL WORKFLOW NOTIFICATIONS
    // ============================================================

    /**
     * Notify the next approver in the chain that a loan needs their review
     */
    public function notifyNextApprover(string $level, int $loanId, string $loanNo, string $memberName, float $amount): void {
        $roleMap = ['officer' => 'admin', 'treasurer' => 'treasurer', 'admin' => 'admin'];
        $role = $roleMap[$level] ?? 'admin';
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = ? AND status='active'");
        $stmt->execute([$role]);
        $approvers = $stmt->fetchAll();
        foreach ($approvers as $a) {
            $this->create($a['id'], 'loan_pending_approval', 'Loan Pending Approval',
                "Loan $loanNo from $memberName (Tsh " . number_format($amount, 2) . ") is awaiting your review at $level level.",
                APP_URL . '/pages/loan_approve.php?id=' . $loanId
            );
        }
    }

    /**
     * Notify loan officer/treasurer/admin about a new eligible loan for assignment
     */
    public function notifyNewLoanForReview(int $loanId, string $loanNo, string $memberName, float $amount, string $level): void {
        $roleMap = ['officer' => 'admin', 'treasurer' => 'treasurer', 'admin' => 'admin'];
        $role = $roleMap[$level] ?? 'admin';
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = ? AND status='active'");
        $stmt->execute([$role]);
        $users = $stmt->fetchAll();
        foreach ($users as $u) {
            $this->create($u['id'], 'loan_for_review', 'New Loan for Review',
                "New loan application $loanNo from $memberName for Tsh " . number_format($amount, 2) . " needs $level review.",
                APP_URL . '/pages/loan_approve.php?id=' . $loanId
            );
        }
    }

    /**
     * Notify member that conditions have been set on their loan
     */
    public function notifyConditionsSet(int $memberUserId, string $loanNo, int $conditionCount): void {
        $this->create($memberUserId, 'loan_conditions_set', 'Loan Conditions Added',
            "Your loan $loanNo has been conditionally approved with $conditionCount condition(s) to fulfill before disbursement.",
            APP_URL . '/pages/member_loans.php'
        );
    }

    /**
     * Notify admin/treasurer that a member has fulfilled a condition
     */
    public function notifyConditionMet(int $loanId, string $loanNo, string $memberName, string $conditionText): void {
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('admin','treasurer') AND status='active'");
        $users = $stmt->fetchAll();
        foreach ($users as $u) {
            $this->create($u['id'], 'loan_condition_met', 'Loan Condition Met',
                "$memberName fulfilled condition on loan $loanNo: $conditionText",
                APP_URL . '/pages/loan_approve.php?id=' . $loanId
            );
        }
    }

    /**
     * Notify member about loan rejection with specific reason
     */
    public function notifyRejectionWithReason(int $memberUserId, string $loanNo, string $reason): void {
        $this->create($memberUserId, 'loan_rejected', 'Loan Application Not Approved',
            "Your loan application $loanNo was not approved. Reason: $reason",
            APP_URL . '/pages/member_loans.php'
        );
    }

    /**
     * Notify admin/treasurer that loan is ready for disbursement authorization
     */
    public function notifyReadyForDisbursement(int $loanId, string $loanNo, string $memberName): void {
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('admin','treasurer') AND status='active'");
        $users = $stmt->fetchAll();
        foreach ($users as $u) {
            $this->create($u['id'], 'loan_ready_disbursement', 'Loan Ready for Disbursement',
                "Loan $loanNo to $memberName has been fully approved and conditions met. Ready to disburse.",
                APP_URL . '/pages/loan_approve.php?id=' . $loanId
            );
        }
    }

    /**
     * Notify member that their loan has been disbursed
     */
    public function notifyDisbursed(int $memberUserId, string $loanNo, float $amount): void {
        $this->create($memberUserId, 'loan_disbursed', 'Loan Disbursed',
            "Your loan $loanNo of Tsh " . number_format($amount, 2) . " has been disbursed. Repayment schedule is now active.",
            APP_URL . '/pages/member_loans.php'
        );
    }

    /**
     * Notify approver that a bulk action was performed
     */
    public function notifyBulkAction(int $count, string $action, string $performedBy): void {
        $stmt = $this->db->query("SELECT id FROM users WHERE role IN ('admin','treasurer') AND status='active'");
        $users = $stmt->fetchAll();
        foreach ($users as $u) {
            $this->create($u['id'], 'bulk_action', 'Bulk Action Completed',
                "$performedBy performed bulk '$action' on $count loan(s).",
                APP_URL . '/pages/approval_queue.php'
            );
        }
    }
}
