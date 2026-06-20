<?php
// ============================================================
// VIKOBA - Loan Restructuring Management
// Extension, Payment Holiday, Top-Up workflows
// ============================================================

class LoanRestructure {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get restructure requests for a loan
     */
    public function getByLoan(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT r.*, u.name as requested_by_name, a.name as approved_by_name
             FROM loan_restructures r
             LEFT JOIN users u ON r.requested_by = u.id
             LEFT JOIN users a ON r.approved_by = a.id
             WHERE r.loan_id = ?
             ORDER BY r.created_at DESC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Get pending restructure requests (for admin)
     */
    public function getPendingRequests(): array {
        $stmt = $this->db->query(
            "SELECT r.*, l.loan_no, m.name as member_name, m.member_no,
                    u.name as requested_by_name
             FROM loan_restructures r
             JOIN loans l ON r.loan_id = l.id
             JOIN members m ON l.member_id = m.id
             LEFT JOIN users u ON r.requested_by = u.id
             WHERE r.status = 'pending'
             ORDER BY r.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Get all restructure requests (for admin)
     */
    public function getAll(int $page = 1, int $perPage = 50): array {
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->query("SELECT COUNT(*) FROM loan_restructures");
        $total = (int)$countStmt->fetchColumn();
        
        $stmt = $this->db->query(
            "SELECT r.*, l.loan_no, m.name as member_name, m.member_no,
                    u.name as requested_by_name, a.name as approved_by_name
             FROM loan_restructures r
             JOIN loans l ON r.loan_id = l.id
             JOIN members m ON l.member_id = m.id
             LEFT JOIN users u ON r.requested_by = u.id
             LEFT JOIN users a ON r.approved_by = a.id
             ORDER BY r.created_at DESC
             LIMIT $perPage OFFSET $offset"
        );
        
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => ceil($total / $perPage),
        ];
    }

    /**
     * Request a loan extension
     */
    public function requestExtension(int $loanId, int $additionalMonths, string $reason, int $requestedBy): ?int {
        $loan = $this->getLoan($loanId);
        if (!$loan) return null;

        $currentTerm = (int)($loan['term_months'] ?: 12);
        $newTerm = $currentTerm + $additionalMonths;

        $stmt = $this->db->prepare(
            "INSERT INTO loan_restructures 
                (loan_id, restructure_type, previous_value, new_value, reason, requested_by, requested_date, status)
             VALUES (?, 'extension', ?, ?, ?, ?, CURDATE(), 'pending')"
        );
        $stmt->execute([$loanId, $currentTerm, $newTerm, $reason, $requestedBy]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Request a payment holiday (skip next installment)
     */
    public function requestPaymentHoliday(int $loanId, string $reason, int $requestedBy): ?int {
        $loan = $this->getLoan($loanId);
        if (!$loan) return null;

        // Get the current balance
        $balance = $this->getLoanBalance($loanId);

        $stmt = $this->db->prepare(
            "INSERT INTO loan_restructures 
                (loan_id, restructure_type, previous_value, new_value, reason, requested_by, requested_date, loan_balance_before, status)
             VALUES (?, 'payment_holiday', ?, ?, ?, ?, CURDATE(), ?, 'pending')"
        );
        $stmt->execute([
            $loanId, $balance, $balance, $reason, $requestedBy, $balance
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Request a top-up (additional loan on existing)
     */
    public function requestTopUp(int $loanId, float $additionalAmount, string $reason, int $requestedBy): ?int {
        $loan = $this->getLoan($loanId);
        if (!$loan) return null;

        $currentAmount = (float)$loan['amount'];
        $newAmount = $currentAmount + $additionalAmount;

        $stmt = $this->db->prepare(
            "INSERT INTO loan_restructures 
                (loan_id, restructure_type, previous_value, new_value, reason, requested_by, requested_date, status)
             VALUES (?, 'top_up', ?, ?, ?, ?, CURDATE(), 'pending')"
        );
        $stmt->execute([$loanId, $currentAmount, $newAmount, $reason, $requestedBy]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Approve a restructure request
     */
    public function approve(int $id, int $approvedBy, ?string $reviewNotes = null): bool {
        $stmt = $this->db->prepare("SELECT * FROM loan_restructures WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $request = $stmt->fetch();

        if (!$request) return false;

        $this->db->beginTransaction();
        try {
            // Update restructure status
            $upd = $this->db->prepare(
                "UPDATE loan_restructures SET status = 'approved', approved_by = ?, approved_date = CURDATE(), review_notes = ? WHERE id = ?"
            );
            $upd->execute([$approvedBy, $reviewNotes, $id]);

            $loanId = (int)$request['loan_id'];

            // Apply the restructure based on type
            switch ($request['restructure_type']) {
                case 'extension':
                    $this->applyExtension($loanId, (int)$request['new_value'], $approvedBy);
                    break;
                case 'payment_holiday':
                    $this->applyPaymentHoliday($loanId, $approvedBy);
                    break;
                case 'top_up':
                    $this->applyTopUp($loanId, (float)$request['new_value'], $approvedBy);
                    break;
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Reject a restructure request
     */
    public function reject(int $id, int $reviewedBy, ?string $reviewNotes = null): bool {
        $stmt = $this->db->prepare(
            "UPDATE loan_restructures SET status = 'rejected', reviewed_by = ?, review_notes = ? WHERE id = ? AND status = 'pending'"
        );
        return $stmt->execute([$reviewedBy, $reviewNotes, $id]);
    }

    /**
     * Apply extension: increase term months and regenerate schedule
     */
    private function applyExtension(int $loanId, int $newTermMonths, int $approvedBy): void {
        $loan = $this->getLoan($loanId);
        if (!$loan) return;

        // Update loan term
        $stmt = $this->db->prepare("UPDATE loans SET term_months = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newTermMonths, $loanId]);

        // Get current schedule to find paid installments
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as paid_count, MAX(installment_no) as last_paid_no 
             FROM amortization_schedule 
             WHERE loan_id = ? AND status = 'paid'"
        );
        $stmt->execute([$loanId]);
        $paidInfo = $stmt->fetch();
        $lastPaidNo = (int)($paidInfo['last_paid_no'] ?? 0);

        // Remove future pending installments
        $stmt = $this->db->prepare(
            "DELETE FROM amortization_schedule WHERE loan_id = ? AND installment_no > ? AND status = 'pending'"
        );
        $stmt->execute([$loanId, $lastPaidNo]);

        // Generate new schedule for remaining term
        $remainingMonths = max(1, $newTermMonths - $lastPaidNo);
        $outstandingBalance = $this->getOutstandingPrincipal($loanId);

        if ($outstandingBalance > 0) {
            $productModel = new LoanProduct();
            $newSchedule = $productModel->generateAmortizationSchedule(
                $outstandingBalance,
                (float)$loan['interest_rate'],
                $remainingMonths,
                $loan['payment_frequency'] ?? 'monthly',
                date('Y-m-d')
            );

            $insertStmt = $this->db->prepare(
                "INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal, interest, total_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            $baseNo = $lastPaidNo;
            foreach ($newSchedule['schedule'] as $inst) {
                $baseNo++;
                $insertStmt->execute([
                    $loanId, $baseNo, $inst['due_date'],
                    $inst['principal'], $inst['interest'], $inst['total_amount']
                ]);
            }

            // Update total repayable
            $totalRepayable = $outstandingBalance + $newSchedule['total_interest'];
            $stmt = $this->db->prepare("UPDATE loans SET total_repayable = ?, due_date = ? WHERE id = ?");
            $stmt->execute([$totalRepayable, end($newSchedule['schedule'])['due_date'], $loanId]);
        }
    }

    /**
     * Apply payment holiday: skip next installment due date
     */
    private function applyPaymentHoliday(int $loanId, int $approvedBy): void {
        // Find the next pending installment
        $stmt = $this->db->prepare(
            "SELECT * FROM amortization_schedule 
             WHERE loan_id = ? AND status IN ('pending', 'overdue') 
             ORDER BY installment_no ASC LIMIT 1"
        );
        $stmt->execute([$loanId]);
        $installment = $stmt->fetch();

        if ($installment) {
            // Extend due date by 30 days
            $newDate = date('Y-m-d', strtotime($installment['due_date'] . ' + 30 days'));
            $stmt = $this->db->prepare(
                "UPDATE amortization_schedule SET due_date = ?, status = 'pending' WHERE id = ?"
            );
            $stmt->execute([$newDate, $installment['id']]);

            // Also push all future installments by 30 days
            $stmt = $this->db->prepare(
                "SELECT * FROM amortization_schedule 
                 WHERE loan_id = ? AND installment_no > ? AND status IN ('pending', 'partial')
                 ORDER BY installment_no ASC"
            );
            $stmt->execute([$loanId, $installment['installment_no']]);
            $futureInstallments = $stmt->fetchAll();

            foreach ($futureInstallments as $fi) {
                $newFiDate = date('Y-m-d', strtotime($fi['due_date'] . ' + 30 days'));
                $stmt = $this->db->prepare("UPDATE amortization_schedule SET due_date = ? WHERE id = ?");
                $stmt->execute([$newFiDate, $fi['id']]);
            }

            // Update loan due date
            $stmt = $this->db->prepare("UPDATE loans SET due_date = DATE_ADD(due_date, INTERVAL 30 DAY) WHERE id = ?");
            $stmt->execute([$loanId]);
        }
    }

    /**
     * Apply top-up: add additional funds to existing loan
     */
    private function applyTopUp(int $loanId, float $newAmount, int $approvedBy): void {
        $loan = $this->getLoan($loanId);
        if (!$loan) return;

        $additionalAmount = $newAmount - (float)$loan['amount'];
        if ($additionalAmount <= 0) return;

        // Create a new separate loan for the top-up amount
        $loanModel = new Loan();
        $data = [
            'member_id' => $loan['member_id'],
            'product_id' => $loan['product_id'],
            'amount' => $additionalAmount,
            'interest_rate' => (float)$loan['interest_rate'],
            'term_months' => (int)($loan['term_months'] ?: 12),
            'payment_frequency' => $loan['payment_frequency'] ?? 'monthly',
            'purpose' => 'Top-up on ' . $loan['loan_no'] . ': ' . ($loan['purpose'] ?? ''),
            'application_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+' . ($loan['term_months'] ?: 12) . ' months')),
        ];

        $newLoanId = $loanModel->create($data);
        if ($newLoanId) {
            // Link the restructure to the new loan
            $stmt = $this->db->prepare("UPDATE loan_restructures SET new_loan_id = ? WHERE loan_id = ? AND status = 'approved' AND restructure_type = 'top_up'");
            $stmt->execute([$newLoanId, $loanId]);

            // Auto-disburse the new top-up loan
            $loanModel->disburse($newLoanId, $approvedBy);
        }
    }

    /**
     * Get outstanding principal (loan balance not including future interest)
     */
    private function getOutstandingPrincipal(int $loanId): float {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(principal), 0) - COALESCE(
                (SELECT SUM(paid_amount) FROM amortization_schedule WHERE loan_id = ? AND status IN ('paid', 'partial')), 0
             ) as outstanding
             FROM amortization_schedule WHERE loan_id = ?"
        );
        $stmt->execute([$loanId, $loanId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Get loan balance (total remaining including interest)
     */
    private function getLoanBalance(int $loanId): float {
        $loan = $this->getLoan($loanId);
        if (!$loan) return 0;
        
        $totalPaid = 0;
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE loan_id = ?");
        $stmt->execute([$loanId]);
        $totalPaid = (float)$stmt->fetchColumn();

        return (float)$loan['total_repayable'] - $totalPaid;
    }

    /**
     * Get loan details
     */
    private function getLoan(int $loanId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM loans WHERE id = ?");
        $stmt->execute([$loanId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get available restructure types for a loan
     */
    public function getAvailableTypes(int $loanId): array {
        $loan = $this->getLoan($loanId);
        if (!$loan) return [];

        $types = [];

        // Extension: only for disbursed loans
        if ($loan['status'] === 'disbursed') {
            $types[] = [
                'type' => 'extension',
                'label' => 'Loan Extension',
                'description' => 'Extend the loan repayment period',
                'icon' => 'ti ti-calendar-plus',
            ];
        }

        // Payment holiday: only for disbursed loans with pending installments
        if ($loan['status'] === 'disbursed') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ? AND status IN ('pending', 'overdue')"
            );
            $stmt->execute([$loanId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $types[] = [
                    'type' => 'payment_holiday',
                    'label' => 'Payment Holiday',
                    'description' => 'Postpone next installment by 30 days',
                    'icon' => 'ti ti-pause-circle',
                ];
            }
        }

        // Top-up: for disbursed loans with good repayment history
        if (in_array($loan['status'], ['disbursed', 'completed'])) {
            $types[] = [
                'type' => 'top_up',
                'label' => 'Loan Top-Up',
                'description' => 'Get additional funds on existing loan',
                'icon' => 'ti ti-cash-plus',
            ];
        }

        return $types;
    }

    /**
     * Check if loan has a pending restructure request
     */
    public function hasPendingRequest(int $loanId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM loan_restructures WHERE loan_id = ? AND status = 'pending'");
        $stmt->execute([$loanId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count pending requests (for admin badge)
     */
    public function countPendingRequests(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM loan_restructures WHERE status = 'pending'");
        return (int)$stmt->fetchColumn();
    }
}