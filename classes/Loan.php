<?php
// ============================================================
// VIKOBA - Loan Class with Eligibility, Scoring & Amortization
// ============================================================

class Loan {
    private PDO $db;

    public const INTEREST_RATE = 15; // default
    public const SHARE_VALUE   = 2500; // Tsh per share
    public const SHARE_MULTIPLIER = 3; // max loan = shares * value * 3
    public const MIN_SAVINGS_PCT = 20; // savings must be >= 20% of loan
    public const MIN_MEMBERSHIP_MONTHS = 3;
    public const MAX_ACTIVE_LOANS = 1;
    public const FINE_THRESHOLD = 50000;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(array $filters = []): array {
        $sql = "SELECT l.*, m.name as member_name, m.member_no,
                       COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
                FROM loans l
                JOIN members m ON l.member_id = m.id";
        $where = []; $params = [];
        if (!empty($filters['status']))    { $where[] = "l.status=?";    $params[] = $filters['status']; }
        if (!empty($filters['member_id'])) { $where[] = "l.member_id=?"; $params[] = $filters['member_id']; }
        if (!empty($filters['product_id'])) { $where[] = "l.product_id=?"; $params[] = $filters['product_id']; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY l.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT l.*, m.name as member_name, m.member_no, m.shares, m.join_date,
                    lp.name as product_name, lp.code as product_code,
                    COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
             FROM loans l
             JOIN members m ON l.member_id=m.id
             LEFT JOIN loan_products lp ON l.product_id = lp.id
             WHERE l.id=?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get member's complete loan profile for admin review
     */
    public function getMemberLoanProfile(int $memberId): array {
        // Total contributions
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM contributions WHERE member_id=?");
        $stmt->execute([$memberId]);
        $contributions = $stmt->fetch();

        // Active loans (disbursed or approved but not yet disbursed)
        $stmt = $this->db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM loans WHERE member_id=? AND status IN ('disbursed','approved')");
        $stmt->execute([$memberId]);
        $activeLoans = $stmt->fetch();

        // Completed loans
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM loans WHERE member_id=? AND status='completed'");
        $stmt->execute([$memberId]);
        $completedLoans = (int)$stmt->fetchColumn();

        // Defaulted loans
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM loans WHERE member_id=? AND status='defaulted'");
        $stmt->execute([$memberId]);
        $defaultedLoans = (int)$stmt->fetchColumn();

        // Late/overdue installments
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM amortization_schedule a
             JOIN loans l ON a.loan_id = l.id
             WHERE l.member_id = ? AND a.status = 'overdue'"
        );
        $stmt->execute([$memberId]);
        $overdueInstallments = (int)$stmt->fetchColumn();

        // Pending fines
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM fines WHERE member_id=? AND paid=0");
        $stmt->execute([$memberId]);
        $fines = $stmt->fetch();

        // Previous loan repayment performance
        $stmt = $this->db->prepare("
            SELECT l.*, COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
            FROM loans l WHERE l.member_id=? AND l.status IN ('completed','active') ORDER BY l.created_at DESC LIMIT 5
        ");
        $stmt->execute([$memberId]);
        $previousLoans = $stmt->fetchAll();

        return [
            'contributions'       => $contributions,
            'active_loans'        => $activeLoans,
            'completed_loans'     => $completedLoans,
            'defaulted_loans'     => $defaultedLoans,
            'overdue_installments'=> $overdueInstallments,
            'fines'               => $fines,
            'previous_loans'      => $previousLoans,
        ];
    }

    /**
     * Calculate weighted credit score (0-100)
     * Modern approach: weighted factors instead of simple pass/fail
     */
    public function calculateCreditScore(int $memberId, float $requestedAmount, ?array $product = null): array {
        $member = $this->getMember($memberId);
        if (!$member) {
            return ['score' => 0, 'max_score' => 100, 'rating' => 'unknown', 'breakdown' => [], 'eligible' => false];
        }

        $profile = $this->getMemberLoanProfile($memberId);
        $monthsActive = $this->monthsSince($member['join_date']);
        $totalContributions = (float)$profile['contributions']['total'];
        $activeLoanCount = (int)$profile['active_loans']['count'];
        $completedLoans = (int)$profile['completed_loans'];
        $defaultedCount = (int)$profile['defaulted_loans'];
        $overdueCount = (int)$profile['overdue_installments'];
        $pendingFines = (float)$profile['fines']['total'];

        // Use product rules if available, otherwise fallback to defaults
        $minSavingsPct = ($product['min_savings_pct'] ?? self::MIN_SAVINGS_PCT) / 100;
        $shareMultiplier = $product['share_multiplier'] ?? self::SHARE_MULTIPLIER;
        $maxLoanByShares = $member['shares'] * self::SHARE_VALUE * $shareMultiplier;

        $breakdown = [];
        $score = 0;
        $maxScore = 100;

        // 1. Membership Tenure (10 pts)
        $tenureScore = min(10, round(($monthsActive / 24) * 10));
        $breakdown[] = [
            'factor'  => 'Membership Tenure',
            'weight'  => 10,
            'score'   => $tenureScore,
            'detail'  => "$monthsActive months active (max 24+ months for full score)",
        ];
        $score += $tenureScore;

        // 2. Savings-to-Loan Ratio (20 pts)
        $savingsRatio = $requestedAmount > 0 ? $totalContributions / $requestedAmount : 0;
        $savingsScore = 0;
        if ($savingsRatio >= 0.5) $savingsScore = 20;
        elseif ($savingsRatio >= 0.4) $savingsScore = 16;
        elseif ($savingsRatio >= $minSavingsPct) $savingsScore = 12;
        elseif ($savingsRatio >= ($minSavingsPct / 2)) $savingsScore = 6;
        $breakdown[] = [
            'factor'  => 'Savings-to-Loan Ratio',
            'weight'  => 20,
            'score'   => $savingsScore,
            'detail'  => number_format($savingsRatio * 100, 1) . "% (target: " . ($minSavingsPct * 100) . "%+)",
        ];
        $score += $savingsScore;

        // 3. Repayment History (25 pts)
        $repaymentScore = 0;
        if ($completedLoans > 0) {
            $repaymentScore = min(25, 10 + ($completedLoans * 5));
        } else {
            // New member with no history gets partial
            $repaymentScore = 10;
        }
        // Penalty for overdue installments
        $repaymentScore = max(0, $repaymentScore - ($overdueCount * 5));
        $breakdown[] = [
            'factor'  => 'Repayment History',
            'weight'  => 25,
            'score'   => $repaymentScore,
            'detail'  => "$completedLoans completed loans, $overdueCount overdue installment(s)",
        ];
        $score += $repaymentScore;

        // 4. Share Collateral (15 pts)
        $collateralRatio = $requestedAmount > 0 ? min(1, $maxLoanByShares / $requestedAmount) : 0;
        $collateralScore = round($collateralRatio * 15);
        $breakdown[] = [
            'factor'  => 'Share Collateral',
            'weight'  => 15,
            'score'   => $collateralScore,
            'detail'  => "Max loan by shares: " . number_format($maxLoanByShares) . " (requested: " . number_format($requestedAmount) . ")",
        ];
        $score += $collateralScore;

        // 5. Active Loan Status (10 pts)
        $activeLoanScore = $activeLoanCount === 0 ? 10 : ($activeLoanCount === 1 ? 3 : 0);
        $breakdown[] = [
            'factor'  => 'Active Loans',
            'weight'  => 10,
            'score'   => $activeLoanScore,
            'detail'  => "$activeLoanCount active loan(s)",
        ];
        $score += $activeLoanScore;

        // 6. Default & Fine Record (10 pts)
        $defaultFineScore = 10;
        if ($defaultedCount > 0) $defaultFineScore -= 8;
        if ($pendingFines > self::FINE_THRESHOLD) $defaultFineScore -= 4;
        elseif ($pendingFines > 0) $defaultFineScore -= 2;
        $defaultFineScore = max(0, $defaultFineScore);
        $breakdown[] = [
            'factor'  => 'Default & Fine Record',
            'weight'  => 10,
            'score'   => $defaultFineScore,
            'detail'  => "$defaultedCount default(s), Tsh " . number_format($pendingFines) . " pending fines",
        ];
        $score += $defaultFineScore;

        // 7. Guarantor Strength (10 pts) - if applicable
        $guarantorScore = 5; // neutral baseline
        if (!empty($product['requires_guarantor']) && $product['requires_guarantor']) {
            $gStmt = $this->db->prepare("SELECT COUNT(*) FROM guarantors WHERE loan_id IN (SELECT id FROM loans WHERE member_id = ?) AND status = 'approved'");
            $gStmt->execute([$memberId]);
            $existingGuarantors = (int)$gStmt->fetchColumn();
            $guarantorScore = $existingGuarantors > 0 ? 10 : 2;
        }
        $breakdown[] = [
            'factor'  => 'Guarantor/Group Strength',
            'weight'  => 10,
            'score'   => $guarantorScore,
            'detail'  => "Guarantor history score: $guarantorScore/10",
        ];
        $score += $guarantorScore;

        // Determine rating
        $rating = 'high';
        if ($score >= 80) $rating = 'low';
        elseif ($score >= 60) $rating = 'medium';

        $eligible = $score >= 50; // minimum threshold

        return [
            'score'      => $score,
            'max_score'  => $maxScore,
            'rating'     => $rating,
            'eligible'   => $eligible,
            'breakdown'  => $breakdown,
        ];
    }

    /**
     * Check if a member is eligible for a loan
     */
    public function checkEligibility(int $memberId, float $requestedAmount, ?array $product = null): array {
        $member = $this->getMember($memberId);
        if (!$member) {
            return ['eligible' => false, 'checks' => [], 'error' => 'Member not found'];
        }

        $profile = $this->getMemberLoanProfile($memberId);
        $monthsActive = $this->monthsSince($member['join_date']);

        // Use product rules if available
        $minSavingsPct = ($product['min_savings_pct'] ?? self::MIN_SAVINGS_PCT) / 100;
        $shareMultiplier = $product['share_multiplier'] ?? self::SHARE_MULTIPLIER;
        $maxLoanByShares = $member['shares'] * self::SHARE_VALUE * $shareMultiplier;
        $minSavingsRequired = $requestedAmount * $minSavingsPct;
        $totalContributions = (float)$profile['contributions']['total'];
        $activeLoanCount = (int)$profile['active_loans']['count'];
        $defaultedCount = (int)$profile['defaulted_loans'];
        $pendingFines = (float)$profile['fines']['total'];

        // Get credit score
        $creditScore = $this->calculateCreditScore($memberId, $requestedAmount, $product);

        $checks = [];

        // 1. Membership tenure
        $tenureOk = $monthsActive >= self::MIN_MEMBERSHIP_MONTHS;
        $checks[] = [
            'name'    => 'Membership Period',
            'passed'  => $tenureOk,
            'message' => $tenureOk
                ? "Member for $monthsActive months (required: " . self::MIN_MEMBERSHIP_MONTHS . ")"
                : "Need " . self::MIN_MEMBERSHIP_MONTHS . "+ months (you have $monthsActive)"
        ];

        // 2. Savings requirement
        $savingsOk = $totalContributions >= $minSavingsRequired;
        $checks[] = [
            'name'    => 'Savings Requirement',
            'passed'  => $savingsOk,
            'message' => $savingsOk
                ? "Savings " . number_format($totalContributions) . " ≥ " . ($minSavingsPct * 100) . "% of loan (" . number_format($minSavingsRequired) . ")"
                : "Need at least " . number_format($minSavingsRequired) . " in savings (have " . number_format($totalContributions) . ")"
        ];

        // 3. Share collateral
        $sharesOk = $requestedAmount <= $maxLoanByShares;
        $checks[] = [
            'name'    => 'Share Collateral',
            'passed'  => $sharesOk,
            'message' => $sharesOk
                ? "$requestedAmount ≤ max loan of " . number_format($maxLoanByShares) . " (shares × " . self::SHARE_VALUE . " × " . $shareMultiplier . ")"
                : "Max loan based on your shares: " . number_format($maxLoanByShares)
        ];

        // 4. Active loan limit
        $activeOk = $activeLoanCount < self::MAX_ACTIVE_LOANS;
        $checks[] = [
            'name'    => 'Active Loans',
            'passed'  => $activeOk,
            'message' => $activeOk
                ? "No active loans"
                : "You have $activeLoanCount active loan(s) (max " . self::MAX_ACTIVE_LOANS . ")"
        ];

        // 5. No defaulted loans
        $defaultOk = $defaultedCount === 0;
        $checks[] = [
            'name'    => 'Default Status',
            'passed'  => $defaultOk,
            'message' => $defaultOk
                ? "No defaulted loans"
                : "You have $defaultedCount defaulted loan(s)"
        ];

        // 6. Pending fines threshold
        $finesOk = $pendingFines < self::FINE_THRESHOLD;
        $checks[] = [
            'name'    => 'Pending Fines',
            'passed'  => $finesOk,
            'message' => $finesOk
                ? "Pending fines (" . number_format($pendingFines) . ") under threshold (" . number_format(self::FINE_THRESHOLD) . ")"
                : "Pending fines " . number_format($pendingFines) . " exceed limit"
        ];

        // 7. Credit score eligibility
        $creditOk = $creditScore['eligible'];
        $checks[] = [
            'name'    => 'Credit Score',
            'passed'  => $creditOk,
            'message' => $creditOk
                ? "Credit score: {$creditScore['score']}/{$creditScore['max_score']} (Rating: {$creditScore['rating']})"
                : "Credit score: {$creditScore['score']}/{$creditScore['max_score']} - below minimum threshold (50)"
        ];

        $allPassed = !in_array(false, array_column($checks, 'passed'));

        return [
            'eligible'          => $allPassed,
            'checks'            => $checks,
            'member'            => $member,
            'profile'           => $profile,
            'months_active'     => $monthsActive,
            'total_savings'     => $totalContributions,
            'max_loan_by_shares'=> $maxLoanByShares,
            'min_savings_needed'=> $minSavingsRequired,
            'requested_amount'  => $requestedAmount,
            'credit_score'      => $creditScore,
        ];
    }

    /**
     * Calculate risk level for admin review
     */
    public function calculateRiskLevel(int $memberId, array $eligibility): string {
        // Use the weighted credit score
        if (!empty($eligibility['credit_score']['rating'])) {
            return $eligibility['credit_score']['rating'];
        }

        // Fallback to basic scoring
        $score = 0;
        $profile = $eligibility['profile'];

        foreach ($eligibility['checks'] as $check) {
            if ($check['passed']) $score++;
        }

        if ($profile['completed_loans'] > 0) $score++;
        if ($profile['completed_loans'] > 2) $score += 1;

        $totalSavings = (float)$eligibility['total_savings'];
        $requestedAmount = (float)($eligibility['requested_amount'] ?? 0);
        if ($totalSavings > 0 && $requestedAmount > 0) {
            $ratio = $requestedAmount / $totalSavings;
            if ($ratio <= 2) $score += 1;
            elseif ($ratio > 4) $score -= 1;
        }

        if ((int)$profile['active_loans']['count'] > 0) $score--;
        if ((float)$profile['fines']['total'] > 0) $score--;
        if ((int)$profile['defaulted_loans'] > 0) $score -= 2;

        if ($score >= 7) return 'low';
        if ($score >= 4) return 'medium';
        return 'high';
    }

    /**
     * Check if loan can be auto-approved based on product rules
     */
    public function canAutoApprove(array $product, array $eligibility): bool {
        if (empty($product['auto_approve_threshold']) && empty($product['auto_approve_min_score'])) {
            return false;
        }

        $amount = (float)($eligibility['requested_amount'] ?? 0);
        $score = (int)($eligibility['credit_score']['score'] ?? 0);

        // Check threshold-based auto-approve
        if (!empty($product['auto_approve_threshold']) && $amount <= (float)$product['auto_approve_threshold']) {
            if (!empty($product['auto_approve_min_score']) && $score >= (int)$product['auto_approve_min_score']) {
                return true;
            }
            if (empty($product['auto_approve_min_score'])) {
                return true;
            }
        }

        return false;
    }

    public function approveWithAmount(int $id, float $approvedAmount, int $approvedBy, ?string $reviewNotes = null, ?string $riskLevel = null): bool {
        $loan = $this->getById($id);
        if (!$loan) return false;

        $total = $approvedAmount * (1 + $loan['interest_rate'] / 100);
        $stmt = $this->db->prepare(
            "UPDATE loans SET amount=?, total_repayable=?, suggested_amount=?, review_notes=?, risk_level=?, approved_by=?, approved_date=NOW(), status='approved', updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([
            $approvedAmount, $total, $approvedAmount,
            $reviewNotes, $riskLevel, $approvedBy, $id
        ]);
    }

    /**
     * Approve loan with credit score and auto-approve tracking
     */
    public function approveWithScoring(int $id, int $approvedBy, ?string $reviewNotes = null, ?int $creditScore = null, ?string $scoreBreakdown = null, bool $autoApproved = false): bool {
        $loan = $this->getById($id);
        if (!$loan) return false;

        $total = $loan['amount'] * (1 + $loan['interest_rate'] / 100);

        $sql = "UPDATE loans SET total_repayable=?, review_notes=?, risk_level=?, credit_score=?, credit_score_breakdown=?, auto_approved=?, approved_by=?, approved_date=NOW(), status='approved', updated_at=NOW() WHERE id=?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $total, $reviewNotes,
            $creditScore && $creditScore >= 80 ? 'low' : ($creditScore && $creditScore >= 60 ? 'medium' : 'high'),
            $creditScore, $scoreBreakdown,
            $autoApproved ? 1 : 0,
            $approvedBy, $id
        ]);
    }

    public function requestChanges(int $id, int $reviewedBy, string $reviewNotes): bool {
        $stmt = $this->db->prepare(
            "UPDATE loans SET status='review_requested', review_notes=?, approved_by=?, updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([$reviewNotes, $reviewedBy, $id]);
    }

    public function reject(int $id, int $rejectedBy, ?string $reviewNotes = null): bool {
        $stmt = $this->db->prepare("UPDATE loans SET status='rejected', approved_by=?, review_notes=?, updated_at=NOW() WHERE id=?");
        return $stmt->execute([$rejectedBy, $reviewNotes, $id]);
    }

    public function create(array $data): bool {
        $no = $this->generateLoanNo();
        $total = $data['amount'] * (1 + $data['interest_rate'] / 100);

        // Calculate savings at time of application
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id=?");
        $stmt->execute([$data['member_id']]);
        $savings = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "INSERT INTO loans (loan_no, member_id, product_id, amount, interest_rate, total_repayable, savings_at_application, term_months, payment_frequency, purpose, application_date, due_date, status, current_approval_level, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"
        );
        $ok = $stmt->execute([
            $no, $data['member_id'], $data['product_id'] ?? null,
            $data['amount'], $data['interest_rate'] ?? self::INTEREST_RATE,
            $total, $savings,
            $data['term_months'] ?? null, $data['payment_frequency'] ?? 'monthly',
            $data['purpose'] ?? '', $data['application_date'],
            $data['due_date'] ?? null, 'submitted', 'officer'
        ]);
        if ($ok) return (int)$this->db->lastInsertId();
        return false;
    }

    public function approve(int $id, int $approvedBy): bool {
        $stmt = $this->db->prepare(
            "UPDATE loans SET status='approved', approved_by=?, approved_date=NOW(), updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([$approvedBy, $id]);
    }

    /**
     * Disburse loan: mark as disbursed, generate amortization schedule
     */
    public function disburse(int $id, int $disbursedBy): bool {
        $loan = $this->getById($id);
        if (!$loan) return false;

        $this->db->beginTransaction();
        try {
            // Update loan status
            $stmt = $this->db->prepare(
                "UPDATE loans SET status='disbursed', disbursement_date=NOW(), disbursed_by=?, updated_at=NOW() WHERE id=?"
            );
            $stmt->execute([$disbursedBy, $id]);

            // Generate amortization schedule
            $productModel = new LoanProduct();
            $termMonths = (int)($loan['term_months'] ?? 12);
            $frequency = $loan['payment_frequency'] ?? 'monthly';

            $amort = $productModel->generateAmortizationSchedule(
                (float)$loan['amount'],
                (float)$loan['interest_rate'],
                $termMonths > 0 ? $termMonths : 12,
                $frequency,
                date('Y-m-d')
            );

            // Insert schedule into database
            $stmt = $this->db->prepare(
                "INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal, interest, total_amount, status)
                 VALUES (?,?,?,?,?,?,'pending')"
            );
            foreach ($amort['schedule'] as $inst) {
                $stmt->execute([
                    $id, $inst['installment_no'], $inst['due_date'],
                    $inst['principal'], $inst['interest'], $inst['total_amount']
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function addRepayment(array $data): bool {
        $this->db->beginTransaction();
        try {
            $status = $data['status'] ?? 'approved';

            // Check if 'status' column exists in repayments table (backward compat)
            $hasStatusColumn = false;
            try {
                $colCheck = $this->db->query("SHOW COLUMNS FROM repayments LIKE 'status'");
                $hasStatusColumn = (bool)$colCheck->fetch();
            } catch (Exception $e) {}

            if ($hasStatusColumn) {
                $stmt = $this->db->prepare(
                    "INSERT INTO repayments (loan_id, member_id, amount, payment_method, reference, status, date, recorded_by, notes)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $data['loan_id'], $data['member_id'], $data['amount'],
                    $data['payment_method'] ?? 'cash', $data['reference'] ?? '',
                    $status,
                    $data['date'], $data['recorded_by'] ?? null, $data['notes'] ?? ''
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO repayments (loan_id, member_id, amount, payment_method, reference, date, recorded_by, notes)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $data['loan_id'], $data['member_id'], $data['amount'],
                    $data['payment_method'] ?? 'cash', $data['reference'] ?? '',
                    $data['date'], $data['recorded_by'] ?? null, $data['notes'] ?? ''
                ]);
            }

            // Only apply to loan amortization if approved (not pending)
            if ($status === 'approved') {
                // Update amortization schedule - apply payment to earliest pending/overdue installments
                $this->applyRepaymentToSchedule($data['loan_id'], (float)$data['amount'], $data['date']);

                // Check if loan is fully repaid
                $loan = $this->getById($data['loan_id']);
                if ($loan && ($loan['total_paid'] + $data['amount']) >= $loan['total_repayable']) {
                    $upd = $this->db->prepare("UPDATE loans SET status='completed', updated_at=NOW() WHERE id=?");
                    $upd->execute([$data['loan_id']]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Apply a repayment amount to the amortization schedule (public wrapper for admin approval)
     * This is used by pending_payments.php to apply approved payments to the loan schedule
     * without creating a duplicate repayment record.
     */
    public function applyManualRepayment(int $loanId, float $amount, string $date): bool {
        try {
            $this->applyRepaymentToSchedule($loanId, $amount, $date);

            // Check if loan is fully repaid
            $loan = $this->getById($loanId);
            if ($loan && ($loan['total_paid'] + $amount) >= $loan['total_repayable']) {
                $upd = $this->db->prepare("UPDATE loans SET status='completed', updated_at=NOW() WHERE id=?");
                $upd->execute([$loanId]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Apply a repayment to the amortization schedule (paid to earliest dues)
     */
    private function applyRepaymentToSchedule(int $loanId, float $amount, string $date): void {
        $stmt = $this->db->prepare(
            "SELECT * FROM amortization_schedule WHERE loan_id = ? AND status IN ('pending','overdue','partial') ORDER BY installment_no ASC LIMIT 1"
        );
        $stmt->execute([$loanId]);
        $installment = $stmt->fetch();

        if (!$installment) return;

        $remaining = $amount;
        $dueAmount = (float)$installment['total_amount'] - (float)$installment['paid_amount'];
        $lateFee = (float)$installment['late_fee'];

        // Pay late fee first if any
        if ($lateFee > 0 && $remaining > 0) {
            $feePaid = min($lateFee, $remaining);
            $remaining -= $feePaid;
            $lateFee -= $feePaid;
            $stmt = $this->db->prepare("UPDATE amortization_schedule SET late_fee = ? WHERE id = ?");
            $stmt->execute([max(0, $lateFee), $installment['id']]);
        }

        // Pay principal + interest
        if ($remaining > 0) {
            $newPaid = min($dueAmount, $remaining);
            $remaining -= $newPaid;
            $totalPaid = (float)$installment['paid_amount'] + $newPaid;

            $newStatus = 'pending';
            if ($totalPaid >= (float)$installment['total_amount']) {
                $newStatus = 'paid';
            } elseif ($totalPaid > 0) {
                $newStatus = 'partial';
            }

            $stmt = $this->db->prepare(
                "UPDATE amortization_schedule SET paid_amount = ?, paid_date = ?, status = ? WHERE id = ?"
            );
            $stmt->execute([$totalPaid, $date, $newStatus, $installment['id']]);
        }

        // If there's remaining and more installments, recurse
        if ($remaining > 0) {
            $this->applyRepaymentToSchedule($loanId, $remaining, $date);
        }
    }

    /**
     * Get amortization schedule for a loan
     */
    public function getAmortizationSchedule(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM amortization_schedule WHERE loan_id = ? ORDER BY installment_no ASC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Calculate late fees for overdue installments
     * Run this periodically (e.g., daily cron)
     */
    public function processLateFees(int $loanId, float $lateFeePct = 1.0, int $graceDays = 7): int {
        $stmt = $this->db->prepare(
            "SELECT * FROM amortization_schedule
             WHERE loan_id = ? AND status IN ('pending','partial')
             AND due_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)"
        );
        $stmt->execute([$loanId, $graceDays]);
        $overdue = $stmt->fetchAll();

        $count = 0;
        foreach ($overdue as $inst) {
            $daysOverdue = (new DateTime($inst['due_date']))->diff(new DateTime())->days;
            $fee = round((float)$inst['total_amount'] * ($lateFeePct / 100) * ceil($daysOverdue / 30), 2);

            $upd = $this->db->prepare(
                "UPDATE amortization_schedule SET status = 'overdue', late_fee = late_fee + ? WHERE id = ?"
            );
            if ($upd->execute([$fee, $inst['id']])) $count++;

            // Also accrue late fee on the loan record
            $upd2 = $this->db->prepare("UPDATE loans SET late_fee_accrued = late_fee_accrued + ? WHERE id = ?");
            $upd2->execute([$fee, $loanId]);
        }

        return $count;
    }

    /**
     * Process late fees for all active loans (cron job)
     */
    public function processAllLateFees(): array {
        $stmt = $this->db->query(
            "SELECT DISTINCT l.id FROM loans l
             JOIN amortization_schedule a ON l.id = a.loan_id
             WHERE l.status = 'disbursed'
             AND a.status IN ('pending','partial')
             AND a.due_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        );
        $loanIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($loanIds as $loanId) {
            $count = $this->processLateFees((int)$loanId);
            if ($count > 0) {
                $results[$loanId] = $count;
            }
        }
        return $results;
    }

    public function getRepayments(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT r.*, u.name as recorded_by_name, ru.name as reviewed_by_name, r.reviewed_at
             FROM repayments r
             LEFT JOIN users u ON r.recorded_by = u.id
             LEFT JOIN users ru ON r.reviewed_by = ru.id
             WHERE r.loan_id = ? ORDER BY r.date DESC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single repayment by ID
     */
    public function getRepaymentById(int $repaymentId): ?array {
        $stmt = $this->db->prepare("SELECT r.* FROM repayments r WHERE r.id=?");
        $stmt->execute([$repaymentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update a repayment record
     */
    public function updateRepayment(int $id, array $data): bool {
        $stmt = $this->db->prepare(
            "UPDATE repayments SET member_id=?, amount=?, payment_method=?, reference=?, date=?, notes=? WHERE id=?"
        );
        return $stmt->execute([
            $data['member_id'], $data['amount'], $data['payment_method'] ?? 'cash',
            $data['reference'] ?? '', $data['date'], $data['notes'] ?? '', $id
        ]);
    }

    /**
     * Delete a repayment record
     */
    public function deleteRepayment(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM repayments WHERE id=?");
        return $stmt->execute([$id]);
    }

    private function generateLoanNo(): string {
        $stmt = $this->db->query("SELECT MAX(CAST(SUBSTRING(loan_no,4) AS UNSIGNED)) as max_no FROM loans");
        $row = $stmt->fetch();
        $next = ($row['max_no'] ?? 0) + 1;
        return 'LN-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function getTotalDisbursed(): float {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM loans WHERE status IN ('disbursed','completed')");
        return (float)$stmt->fetchColumn();
    }

    public function getTotalRepaid(): float {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM repayments");
        return (float)$stmt->fetchColumn();
    }

    /**
     * Get portfolio at risk (PAR) statistics
     */
    public function getPortfolioStats(): array {
        // Total portfolio
        $stmt = $this->db->query(
            "SELECT COALESCE(SUM(amount),0) as total_disbursed,
                    COUNT(*) as total_loans
             FROM loans WHERE status IN ('disbursed')"
        );
        $portfolio = $stmt->fetch();

        // Overdue (PAR 30, 60, 90)
        $par = [];
        foreach ([30, 60, 90] as $days) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(a.total_amount - a.paid_amount), 0) as amount,
                        COUNT(DISTINCT a.loan_id) as count
                 FROM amortization_schedule a
                 JOIN loans l ON a.loan_id = l.id
                 WHERE a.status IN ('pending','partial','overdue')
                 AND a.due_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 AND l.status = 'disbursed'"
            );
            $stmt->execute([$days]);
            $row = $stmt->fetch();
            $par["par_{$days}"] = [
                'amount' => (float)$row['amount'],
                'count'  => (int)$row['count'],
            ];
        }

        // Repayment rate
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM repayments");
        $totalRepaid = (float)$stmt->fetchColumn();
        $stmt = $this->db->query("SELECT COALESCE(SUM(total_repayable),0) FROM loans WHERE status IN ('disbursed','completed')");
        $totalDue = (float)$stmt->fetchColumn();

        return [
            'total_disbursed' => (float)$portfolio['total_disbursed'],
            'total_loans'     => (int)$portfolio['total_loans'],
            'total_repaid'    => $totalRepaid,
            'total_due'       => $totalDue,
            'repayment_rate'  => $totalDue > 0 ? round(($totalRepaid / $totalDue) * 100, 1) : 0,
            'par'             => $par,
        ];
    }

    // ============================================================
    // APPROVAL WORKFLOW METHODS
    // ============================================================

    /**
     * Get the required approval level based on amount and risk
     */
    public function determineRequiredLevel(float $amount, string $riskLevel): string {
        $officerMax = (float)($this->getSetting('approval_officer_max_amount') ?: 500000);
        $treasurerMax = (float)($this->getSetting('approval_treasurer_max_amount') ?: 2000000);
        $highRiskRequiresAdmin = (bool)($this->getSetting('approval_high_risk_requires_admin') ?: true);

        if ($riskLevel === 'high' && $highRiskRequiresAdmin) return 'admin';
        if ($amount > $treasurerMax) return 'admin';
        if ($amount > $officerMax) return 'treasurer';
        return 'officer';
    }

    /**
     * Get loans pending approval at a specific level
     */
    public function getPendingApprovals(?string $level = null, array $filters = []): array {
        $sql = "SELECT l.*, m.name as member_name, m.member_no,
                       COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid,
                       DATEDIFF(CURDATE(), l.created_at) as days_pending
                FROM loans l
                JOIN members m ON l.member_id = m.id
                WHERE l.status IN ('submitted','under_review')";
        $params = [];

        if ($level) {
            $sql .= " AND l.current_approval_level = ?";
            $params[] = $level;
        }
        if (!empty($filters['risk_level'])) {
            $sql .= " AND l.risk_level = ?";
            $params[] = $filters['risk_level'];
        }
        if (!empty($filters['min_amount'])) {
            $sql .= " AND l.amount >= ?";
            $params[] = (float)$filters['min_amount'];
        }
        if (!empty($filters['max_amount'])) {
            $sql .= " AND l.amount <= ?";
            $params[] = (float)$filters['max_amount'];
        }
        if (!empty($filters['product_id'])) {
            $sql .= " AND l.product_id = ?";
            $params[] = (int)$filters['product_id'];
        }
        if (!empty($filters['days_pending'])) {
            $sql .= " AND DATEDIFF(CURDATE(), l.created_at) >= ?";
            $params[] = (int)$filters['days_pending'];
        }
        if (!empty($filters['member_id'])) {
            $sql .= " AND l.member_id = ?";
            $params[] = (int)$filters['member_id'];
        }

        $sql .= " ORDER BY 
            CASE l.risk_level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END ASC,
            l.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get pending counts grouped by approval level
     */
    public function getPendingCountsByLevel(): array {
        $stmt = $this->db->query("
            SELECT 
                COALESCE(current_approval_level, 'none') as approval_level,
                COUNT(*) as count,
                SUM(CASE WHEN risk_level = 'high' THEN 1 ELSE 0 END) as high_risk_count,
                SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) >= 7 THEN 1 ELSE 0 END) as aged_count
            FROM loans 
            WHERE status IN ('submitted','under_review')
            GROUP BY approval_level
        ");
        $result = ['officer' => 0, 'treasurer' => 0, 'admin' => 0, 'none' => 0];
        $highRisk = ['officer' => 0, 'treasurer' => 0, 'admin' => 0];
        $aged = ['officer' => 0, 'treasurer' => 0, 'admin' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $level = $row['approval_level'];
            $result[$level] = (int)$row['count'];
            if (isset($highRisk[$level])) $highRisk[$level] = (int)$row['high_risk_count'];
            if (isset($aged[$level])) $aged[$level] = (int)$row['aged_count'];
        }
        return ['counts' => $result, 'high_risk' => $highRisk, 'aged' => $aged];
    }

    /**
     * Add an approval action to the chain
     */
    public function addApprovalAction(int $loanId, int $approverId, string $level, string $action, ?string $notes = null): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO loan_approvals (loan_id, approver_id, approval_level, action, notes)
             VALUES (?,?,?,?,?)"
        );
        return $stmt->execute([$loanId, $approverId, $level, $action, $notes]);
    }

    /**
     * Get the full approval chain for a loan
     */
    public function getApprovalChain(int $loanId): array {
        $stmt = $this->db->prepare("
            SELECT a.*, u.name as approver_name, u.role as approver_role
            FROM loan_approvals a
            LEFT JOIN users u ON a.approver_id = u.id
            WHERE a.loan_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Advance a loan to the next approval level (or approve if all levels done)
     */
    public function advanceToNextLevel(int $loanId, int $userId): bool {
        $loan = $this->getById($loanId);
        if (!$loan) return false;

        $currentLevel = $loan['current_approval_level'] ?? 'officer';
        $nextLevel = match($currentLevel) {
            'officer' => 'treasurer',
            'treasurer' => 'admin',
            'admin' => 'none',
            'none' => 'none',
            default => 'treasurer'
        };

        if ($nextLevel === 'none') {
            // All approvals done - mark as fully approved
            $stmt = $this->db->prepare(
                "UPDATE loans SET status='approved', approved_by=?, approved_date=NOW(), current_approval_level='none', updated_at=NOW() WHERE id=?"
            );
            return $stmt->execute([$userId, $loanId]);
        }

        // Move to next level
        $stmt = $this->db->prepare(
            "UPDATE loans SET current_approval_level=?, status='under_review', updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([$nextLevel, $loanId]);
    }

    /**
     * Approve at current level and determine next step
     * All loans go through a mandatory chain: officer -> treasurer -> admin
     * The required level (based on amount/risk) determines if admin is the final level.
     * Officer and treasurer levels are ALWAYS required steps.
     */
    public function approveAtLevel(int $loanId, int $userId, string $level, ?string $notes = null): array {
        $loan = $this->getById($loanId);
        if (!$loan) return ['success' => false, 'message' => 'Loan not found'];

        $this->db->beginTransaction();
        try {
            // Record this approval action
            $this->addApprovalAction($loanId, $userId, $level, 'approved', $notes);

            // Determine the required level for this loan
            $riskLevel = $loan['risk_level'] ?? 'medium';
            $requiredLevel = $this->determineRequiredLevel((float)$loan['amount'], $riskLevel);

            // Mandatory chain: officer -> treasurer -> (admin if required)
            // Officer can only advance to treasurer (never fully approve)
            if ($level === 'officer') {
                // Always move to treasurer next
                $nextLevel = 'treasurer';
                $stmt = $this->db->prepare(
                    "UPDATE loans SET current_approval_level=?, status='under_review', updated_at=NOW() WHERE id=?"
                );
                $stmt->execute([$nextLevel, $loanId]);
                $this->db->commit();
                return ['success' => true, 'message' => "Approved at officer level, sent to treasurer for review", 'status' => 'under_review', 'final' => false];
            }

            // Treasurer can advance to admin (if required) or fully approve (if admin not needed)
            if ($level === 'treasurer') {
                $levelOrder = ['officer' => 1, 'treasurer' => 2, 'admin' => 3];
                $requiredOrder = $levelOrder[$requiredLevel] ?? 3;

                if ($requiredOrder >= 3) {
                    // Admin is required - advance to admin
                    $stmt = $this->db->prepare(
                        "UPDATE loans SET current_approval_level='admin', status='under_review', updated_at=NOW() WHERE id=?"
                    );
                    $stmt->execute([$loanId]);
                    $this->db->commit();
                    return ['success' => true, 'message' => "Approved at treasurer level, sent to admin for final approval", 'status' => 'under_review', 'final' => false];
                }

                // Admin not required - treasurer can fully approve
                $total = $loan['amount'] * (1 + $loan['interest_rate'] / 100);
                $stmt = $this->db->prepare(
                    "UPDATE loans SET status='approved', total_repayable=?, review_notes=COALESCE(CONCAT(review_notes, '\n', ?), ?), approved_by=?, approved_date=NOW(), current_approval_level='none', updated_at=NOW() WHERE id=?"
                );
                $stmt->execute([$total, $notes, $notes, $userId, $loanId]);
                $this->db->commit();
                return ['success' => true, 'message' => 'Loan fully approved by treasurer', 'status' => 'approved', 'final' => true];
            }

            // Admin level - can always fully approve
            $total = $loan['amount'] * (1 + $loan['interest_rate'] / 100);
            $stmt = $this->db->prepare(
                "UPDATE loans SET status='approved', total_repayable=?, review_notes=COALESCE(CONCAT(review_notes, '\n', ?), ?), approved_by=?, approved_date=NOW(), current_approval_level='none', updated_at=NOW() WHERE id=?"
            );
            $stmt->execute([$total, $notes, $notes, $userId, $loanId]);
            $this->db->commit();
            return ['success' => true, 'message' => 'Loan fully approved by admin', 'status' => 'approved', 'final' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Conditionally approve a loan
     */
    public function approveConditionally(int $loanId, int $userId, array $conditions, ?string $notes = null): bool {
        $loan = $this->getById($loanId);
        if (!$loan) return false;

        $this->db->beginTransaction();
        try {
            // Record conditional approval in chain
            $this->addApprovalAction($loanId, $userId, $loan['current_approval_level'] ?? 'officer', 'conditionally_approved', $notes);

            // Insert conditions
            $stmt = $this->db->prepare(
                "INSERT INTO loan_conditions (loan_id, condition_text, condition_type, created_by) VALUES (?,?,?,?)"
            );
            foreach ($conditions as $cond) {
                $type = $cond['type'] ?? 'other';
                $text = $cond['text'] ?? $cond;
                $stmt->execute([$loanId, $text, $type, $userId]);
            }

            // Set loan status to conditionally approved
            $upd = $this->db->prepare(
                "UPDATE loans SET status='approved', approval_notes=?, approved_by=?, approved_date=NOW(), current_approval_level='none', updated_at=NOW() WHERE id=?"
            );
            $upd->execute([$notes, $userId, $loanId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get conditions for a loan
     */
    public function getConditions(int $loanId): array {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name as created_by_name, mu.name as met_by_name
            FROM loan_conditions c
            LEFT JOIN users u ON c.created_by = u.id
            LEFT JOIN users mu ON c.met_by = mu.id
            WHERE c.loan_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a condition as met
     */
    public function markConditionMet(int $conditionId, int $userId): bool {
        $stmt = $this->db->prepare(
            "UPDATE loan_conditions SET is_met = 1, met_date = CURDATE(), met_by = ? WHERE id = ?"
        );
        return $stmt->execute([$userId, $conditionId]);
    }

    /**
     * Check if all conditions for a loan are met
     */
    public function areConditionsMet(int $loanId): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as total, SUM(is_met) as met FROM loan_conditions WHERE loan_id = ?"
        );
        $stmt->execute([$loanId]);
        $row = $stmt->fetch();
        return $row && $row['total'] > 0 && $row['total'] == $row['met'];
    }

    /**
     * Reject with specific reason communicated to member
     */
    public function rejectWithReason(int $loanId, int $userId, string $reason, ?string $internalNotes = null): bool {
        $notes = $internalNotes ? $reason . ' | ' . $internalNotes : $reason;
        $stmt = $this->db->prepare(
            "UPDATE loans SET status='rejected', rejection_reason=?, review_notes=?, approved_by=?, updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([$reason, $notes, $userId, $loanId]);
    }

    // ============================================================
    // BULK OPERATIONS
    // ============================================================

    /**
     * Bulk approve loans at a level
     */
    public function bulkApprove(array $loanIds, int $userId, string $level, ?string $notes = null): int {
        $count = 0;
        foreach ($loanIds as $id) {
            $result = $this->approveAtLevel((int)$id, $userId, $level, $notes);
            if ($result['success']) $count++;
        }
        return $count;
    }

    /**
     * Bulk reject loans
     */
    public function bulkReject(array $loanIds, int $userId, ?string $reason = null): int {
        $count = 0;
        foreach ($loanIds as $id) {
            if ($this->rejectWithReason((int)$id, $userId, $reason ?: 'Batch rejection')) {
                $this->addApprovalAction((int)$id, $userId, 'officer', 'rejected', $reason ?: 'Batch rejection');
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk request changes for loans
     */
    public function bulkRequestChanges(array $loanIds, int $userId, string $notes): int {
        $count = 0;
        foreach ($loanIds as $id) {
            if ($this->requestChanges((int)$id, $userId, $notes)) {
                $this->addApprovalAction((int)$id, $userId, 'officer', 'requested_changes', $notes);
                $count++;
            }
        }
        return $count;
    }

    // ============================================================
    // DISBURSEMENT AUTHORIZATION
    // ============================================================

    /**
     * Authorize a loan for disbursement (separate from approval)
     */
    public function authorizeDisbursement(int $loanId, int $userId): bool {
        $loan = $this->getById($loanId);
        if (!$loan || $loan['status'] !== 'approved') return false;

        // Check conditions if any
        if (!$this->areConditionsMet($loanId)) return false;

        $stmt = $this->db->prepare(
            "UPDATE loans SET disbursement_authorized_by=?, disbursement_authorized_date=NOW(), updated_at=NOW() WHERE id=?"
        );
        return $stmt->execute([$userId, $loanId]);
    }

    /**
     * Get loans awaiting disbursement authorization
     */
    public function getDisbursementQueue(): array {
        $stmt = $this->db->query("
            SELECT l.*, m.name as member_name, m.member_no,
                   COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
            FROM loans l
            JOIN members m ON l.member_id = m.id
            WHERE l.status = 'approved'
            ORDER BY l.approved_date ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Check if loan is ready for disbursement
     */
    public function isReadyForDisbursement(int $loanId): array {
        $loan = $this->getById($loanId);
        if (!$loan) return ['ready' => false, 'reason' => 'Loan not found'];
        if ($loan['status'] !== 'approved') return ['ready' => false, 'reason' => 'Loan not approved'];
        if (!$this->areConditionsMet($loanId)) {
            $conditions = $this->getConditions($loanId);
            $unmet = array_filter($conditions, fn($c) => !$c['is_met']);
            return ['ready' => false, 'reason' => 'Unmet conditions: ' . count($unmet), 'unmet_conditions' => $unmet];
        }
        return ['ready' => true, 'reason' => 'Ready for disbursement'];
    }

    /**
     * Get a system setting value
     */
    private function getSetting(string $key): ?string {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getMember(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function monthsSince(string $date): int {
        $now = new DateTime();
        $then = new DateTime($date);
        $diff = $now->diff($then);
        return ($diff->y * 12) + $diff->m;
    }
}