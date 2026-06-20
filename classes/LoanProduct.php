<?php
// ============================================================
// VIKOBA - Loan Product Catalog & Configuration
// ============================================================

class LoanProduct {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(bool $activeOnly = true): array {
        $sql = "SELECT * FROM loan_products";
        if ($activeOnly) $sql .= " WHERE status = 'active'";
        $sql .= " ORDER BY name ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM loan_products WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getByCode(string $code): ?array {
        $stmt = $this->db->prepare("SELECT * FROM loan_products WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            "INSERT INTO loan_products (name, code, description, min_amount, max_amount,
             default_interest_rate, min_term_months, max_term_months, min_savings_pct,
             share_multiplier, late_fee_pct, late_fee_grace_days, requires_guarantor,
             min_guarantors, requires_collateral, auto_approve_threshold,
             auto_approve_min_score, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $data['name'], $data['code'], $data['description'] ?? '',
            $data['min_amount'], $data['max_amount'],
            $data['default_interest_rate'] ?? 15.00,
            $data['min_term_months'] ?? 1, $data['max_term_months'] ?? 12,
            $data['min_savings_pct'] ?? 20.00,
            $data['share_multiplier'] ?? 3.00,
            $data['late_fee_pct'] ?? 1.00,
            $data['late_fee_grace_days'] ?? 7,
            $data['requires_guarantor'] ?? 0, $data['min_guarantors'] ?? 0,
            $data['requires_collateral'] ?? 0,
            $data['auto_approve_threshold'] ?? null,
            $data['auto_approve_min_score'] ?? null,
            $data['status'] ?? 'active'
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $allowed = ['name','code','description','min_amount','max_amount','default_interest_rate',
                    'min_term_months','max_term_months','min_savings_pct','share_multiplier',
                    'late_fee_pct','late_fee_grace_days','requires_guarantor','min_guarantors',
                    'requires_collateral','auto_approve_threshold','auto_approve_min_score','status'];
        $sets = []; $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($sets)) return false;
        $params[] = $id;
        $sql = "UPDATE loan_products SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Calculate maximum loan amount for a member based on product rules
     */
    public function calculateMaxLoan(int $memberId, array $product): float {
        $stmt = $this->db->prepare("SELECT shares FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member) return 0;

        $byShares = ($member['shares'] ?? 0) * 2500 * ($product['share_multiplier'] ?? 3);
        $byProduct = (float)$product['max_amount'];
        return min($byShares, $byProduct);
    }

    /**
     * Calculate minimum savings required for a loan amount under this product
     */
    public function calculateMinSavings(float $amount, array $product): float {
        return $amount * (($product['min_savings_pct'] ?? 20) / 100);
    }

    /**
     * Generate amortization schedule for a loan
     * @param float $amount Loan principal
     * @param float $interestRate Annual interest rate (%)
     * @param int $termMonths Loan term in months
     * @param string $frequency Payment frequency
     * @param string $startDate Disbursement/applicable start date
     * @return array Array of installments
     */
    /**
     * Delete a loan product (only if no loans reference it)
     */
    public function delete(int $id): bool {
        // Check if any loans reference this product
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM loans WHERE product_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            throw new Exception("Cannot delete product: $count loan(s) are using this product. Deactivate it instead.");
        }
        $stmt = $this->db->prepare("DELETE FROM loan_products WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function generateAmortizationSchedule(
        float $amount,
        float $interestRate,
        int $termMonths,
        string $frequency = 'monthly',
        string $startDate = ''
    ): array {
        if (empty($startDate)) $startDate = date('Y-m-d');
        $start = new DateTime($startDate);

        // Determine number of installments based on frequency
        $installments = $termMonths; // default monthly
        if ($frequency === 'biweekly') {
            $installments = $termMonths * 2;
        } elseif ($frequency === 'weekly') {
            $installments = $termMonths * 4;
        } elseif ($frequency === 'lump_sum') {
            $installments = 1;
        }

        // Calculate periodic interest rate
        $monthlyRate = ($interestRate / 100) / 12;
        $periodRate = $monthlyRate; // default monthly
        if ($frequency === 'biweekly') {
            $periodRate = $monthlyRate / 2;
        } elseif ($frequency === 'weekly') {
            $periodRate = $monthlyRate / 4;
        } elseif ($frequency === 'lump_sum') {
            $periodRate = $monthlyRate * $termMonths;
        }

        // EMI calculation: P * r * (1+r)^n / ((1+r)^n - 1)
        $emi = 0;
        if ($periodRate > 0 && $installments > 1) {
            $factor = pow(1 + $periodRate, $installments);
            $emi = $amount * $periodRate * $factor / ($factor - 1);
        } elseif ($installments === 1) {
            $emi = $amount * (1 + $periodRate);
        } else {
            $emi = $amount / $installments;
        }

        // Determine the interval for date increments
        $intervalDays = 30; // default monthly
        if ($frequency === 'biweekly') {
            $intervalDays = 14;
        } elseif ($frequency === 'weekly') {
            $intervalDays = 7;
        } elseif ($frequency === 'lump_sum') {
            $intervalDays = $termMonths * 30;
        }

        // Generate schedule
        $schedule = [];
        $balance = $amount;
        $totalInterest = 0;

        for ($i = 1; $i <= $installments; $i++) {
            $date = clone $start;
            $daysOffset = $i * $intervalDays;
            $date->modify("+{$daysOffset} days");

            $interestPart = $balance * $periodRate;
            $principalPart = $emi - $interestPart;

            if ($i === $installments) {
                $principalPart = $balance;
                $interestPart = $emi - $principalPart;
                if ($interestPart < 0) $interestPart = 0;
                $emi = $principalPart + $interestPart;
            }

            $totalInterest += $interestPart;
            $balance -= $principalPart;
            if ($balance < 0) $balance = 0;

            $schedule[] = [
                'installment_no' => $i,
                'due_date'       => $date->format('Y-m-d'),
                'principal'      => round($principalPart, 2),
                'interest'       => round($interestPart, 2),
                'total_amount'   => round($emi, 2),
                'balance_after'  => round($balance, 2),
                'status'         => 'pending',
                'paid_amount'    => 0,
                'late_fee'       => 0,
            ];
        }

        return [
            'schedule'       => $schedule,
            'installments'   => $installments,
            'total_interest' => round($totalInterest, 2),
            'total_repayable'=> round($amount + $totalInterest, 2),
            'emi'            => round($emi, 2),
        ];
    }
}