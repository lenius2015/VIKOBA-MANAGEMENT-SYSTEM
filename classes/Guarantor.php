<?php
// ============================================================
// VIKOBA - Guarantor Management
// ============================================================

class Guarantor {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get guarantors for a loan
     */
    public function getByLoan(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT g.*, m.name as member_name, m.member_no, m.shares,
                    COALESCE((SELECT SUM(c.amount) FROM contributions c WHERE c.member_id = g.member_id), 0) as total_savings
             FROM guarantors g
             JOIN members m ON g.member_id = m.id
             WHERE g.loan_id = ?
             ORDER BY g.created_at ASC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all loans where a member is a guarantor
     */
    public function getByMember(int $memberId): array {
        $stmt = $this->db->prepare(
            "SELECT g.*, l.loan_no, l.amount, l.status as loan_status,
                    m.name as borrower_name, m.member_no as borrower_no
             FROM guarantors g
             JOIN loans l ON g.loan_id = l.id
             JOIN members m ON l.member_id = m.id
             WHERE g.member_id = ?
             ORDER BY g.created_at DESC"
        );
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    /**
     * Add a guarantor to a loan
     */
    public function add(int $loanId, int $memberId, float $amount): bool {
        // Check if already a guarantor
        $stmt = $this->db->prepare("SELECT id FROM guarantors WHERE loan_id = ? AND member_id = ?");
        $stmt->execute([$loanId, $memberId]);
        if ($stmt->fetch()) return false;

        // Check member exists and can be guarantor
        $stmt = $this->db->prepare("SELECT can_be_guarantor, max_guarantee_amount, shares FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member || !$member['can_be_guarantor']) return false;

        // Check max guarantee amount
        $maxGuarantee = (float)($member['max_guarantee_amount'] ?? ($member['shares'] * 2500 * 3));
        if ($amount > $maxGuarantee) return false;

        // Also check total active guarantees
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_guaranteed), 0) as total
             FROM guarantors WHERE member_id = ? AND status IN ('pending','approved')"
        );
        $stmt->execute([$memberId]);
        $currentGuarantees = (float)$stmt->fetchColumn();
        if (($currentGuarantees + $amount) > $maxGuarantee) return false;

        $stmt = $this->db->prepare(
            "INSERT INTO guarantors (loan_id, member_id, amount_guaranteed, status) VALUES (?,?,?,?)"
        );
        return $stmt->execute([$loanId, $memberId, $amount, 'pending']);
    }

    /**
     * Respond to a guarantor request (approve/decline)
     */
    public function respond(int $id, string $status, ?string $notes = null): bool {
        if (!in_array($status, ['approved', 'declined'])) return false;

        $stmt = $this->db->prepare(
            "UPDATE guarantors SET status = ?, response_date = CURDATE(), notes = ? WHERE id = ? AND status = 'pending'"
        );
        return $stmt->execute([$status, $notes, $id]);
    }

    /**
     * Release a guarantor from their obligation
     */
    public function release(int $id): bool {
        $stmt = $this->db->prepare(
            "UPDATE guarantors SET status = 'released', response_date = CURDATE() WHERE id = ? AND status = 'approved'"
        );
        return $stmt->execute([$id]);
    }

    /**
     * Check if a member is eligible to be a guarantor
     */
    public function checkEligibility(int $memberId, float $amount = 0): array {
        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            return ['eligible' => false, 'reason' => 'Member not found'];
        }

        if (!$member['can_be_guarantor']) {
            return ['eligible' => false, 'reason' => 'Member is not eligible to be a guarantor'];
        }

        $maxGuarantee = (float)($member['max_guarantee_amount'] ?? ($member['shares'] * 2500 * 3));

        // Total current guarantees
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_guaranteed), 0) as total
             FROM guarantors WHERE member_id = ? AND status IN ('pending','approved')"
        );
        $stmt->execute([$memberId]);
        $currentGuarantees = (float)$stmt->fetchColumn();

        $availableCapacity = $maxGuarantee - $currentGuarantees;

        // Check active loans
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM loans WHERE member_id = ? AND status IN ('disbursed','approved')"
        );
        $stmt->execute([$memberId]);
        $activeLoans = (int)$stmt->fetchColumn();

        // Check defaulted loans
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE member_id = ? AND status = 'defaulted'");
        $stmt->execute([$memberId]);
        $defaultedLoans = (int)$stmt->fetchColumn();

        $issues = [];
        if ($activeLoans > 0) $issues[] = "Has $activeLoans active loan(s)";
        if ($defaultedLoans > 0) $issues[] = "Has defaulted loans history";
        if ($amount > 0 && $amount > $availableCapacity) $issues[] = "Guarantee amount Tsh " . number_format($amount) . " exceeds available capacity Tsh " . number_format($availableCapacity);

        return [
            'eligible'            => empty($issues),
            'reason'              => empty($issues) ? 'Eligible to guarantee' : implode('; ', $issues),
            'member'              => $member,
            'max_guarantee'       => $maxGuarantee,
            'current_guarantees'  => $currentGuarantees,
            'available_capacity'  => $availableCapacity,
        ];
    }

    /**
     * Get pending guarantor requests for a member
     */
    public function getPendingRequests(int $memberId): array {
        $stmt = $this->db->prepare(
            "SELECT g.*, l.loan_no, l.amount as loan_amount,
                    m.name as borrower_name, m.member_no as borrower_no
             FROM guarantors g
             JOIN loans l ON g.loan_id = l.id
             JOIN members m ON l.member_id = m.id
             WHERE g.member_id = ? AND g.status = 'pending'
             ORDER BY g.created_at DESC"
        );
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    /**
     * Count active guarantor obligations for a member
     */
    public function countActiveObligations(int $memberId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM guarantors g
             JOIN loans l ON g.loan_id = l.id
             WHERE g.member_id = ? AND g.status = 'approved'
             AND l.status IN ('approved','disbursed')"
        );
        $stmt->execute([$memberId]);
        return (int)$stmt->fetchColumn();
    }
}