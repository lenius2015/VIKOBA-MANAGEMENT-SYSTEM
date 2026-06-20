<?php
// ============================================================
// VIKOBA - M-Pesa Payment Integration (Safaricom API)
// ============================================================

class Mpesa {
    private PDO $db;
    private array $settings = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->loadSettings();
    }

    /**
     * Load M-Pesa settings
     */
    private function loadSettings(): void {
        try {
            $stmt = $this->db->query("SELECT * FROM mpesa_settings WHERE active = 1 LIMIT 1");
            $settings = $stmt->fetch();
            if ($settings) {
                $this->settings = $settings;
            }
        } catch (PDOException $e) {
            // Table might not exist yet (migration not run)
            $this->settings = [];
        }
    }

    /**
     * Check if M-Pesa service is active/configured
     */
    public function isActive(): bool {
        return !empty($this->settings['active'])
            && !empty($this->settings['consumer_key'])
            && !empty($this->settings['consumer_secret'])
            && !empty($this->settings['shortcode']);
    }

    /**
     * Get M-Pesa settings
     */
    public function getSettings(): array {
        return $this->settings;
    }

    /**
     * Update M-Pesa settings
     */
    public function updateSettings(array $data): bool {
        $stmt = $this->db->prepare(
            "UPDATE mpesa_settings SET 
                consumer_key = ?, consumer_secret = ?, passkey = ?, 
                shortcode = ?, environment = ?, callback_url = ?, active = ?
             WHERE id = 1"
        );
        return $stmt->execute([
            $data['consumer_key'] ?? '',
            $data['consumer_secret'] ?? '',
            $data['passkey'] ?? '',
            $data['shortcode'] ?? '174379',
            $data['environment'] ?? 'sandbox',
            $data['callback_url'] ?? '',
            $data['active'] ? 1 : 0
        ]);
    }

    /**
     * Get OAuth token from Safaricom
     */
    private function getToken(): ?string {
        $env = $this->settings['environment'] ?? 'sandbox';
        $url = $env === 'production' 
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $credentials = base64_encode($this->settings['consumer_key'] . ':' . $this->settings['consumer_secret']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials,
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['access_token'] ?? null;
        }

        return null;
    }

    /**
     * Initiate STK Push (Lipa Na M-Pesa Online)
     */
    public function stkPush(float $amount, string $phone, string $accountRef, string $transactionDesc = 'Loan Repayment'): array {
        if (!$this->isActive()) {
            return ['success' => false, 'error' => 'M-Pesa service not configured'];
        }

        $token = $this->getToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to get M-Pesa access token'];
        }

        $env = $this->settings['environment'] ?? 'sandbox';
        $url = $env === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

        $shortcode = $this->settings['shortcode'] ?? '174379';
        $passkey = $this->settings['passkey'] ?? '';
        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        // Normalize phone: ensure format is 254XXXXXXXXX
        $phone = $this->normalizePhone($phone);
        // Add leading 254 if needed
        if (strlen($phone) === 9) $phone = '254' . $phone;
        elseif (strlen($phone) === 10 && str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        elseif (str_starts_with($phone, '+')) $phone = substr($phone, 1);

        $callbackUrl = $this->settings['callback_url'] ?? (APP_URL . '/pages/api/mpesa_callback.php');

        $postData = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => intval(round($amount)),
            'PartyA'            => $phone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $accountRef,
            'TransactionDesc'   => $transactionDesc,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = json_decode($response, true) ?? [];

        if ($httpCode === 200 && !empty($result['MerchantRequestID'])) {
            // Log the transaction
            $this->logTransaction([
                'transaction_type' => 'stk_push',
                'transaction_id' => $result['CheckoutRequestID'] ?? uniqid('MP', true),
                'amount' => $amount,
                'phone_number' => $phone,
                'merchant_request_id' => $result['MerchantRequestID'],
                'checkout_request_id' => $result['CheckoutRequestID'],
                'status' => 'pending',
                'raw_response' => json_encode($result),
            ]);

            return [
                'success' => true,
                'merchant_request_id' => $result['MerchantRequestID'] ?? '',
                'checkout_request_id' => $result['CheckoutRequestID'] ?? '',
                'response' => $result,
            ];
        }

        return [
            'success' => false,
            'error' => $error ?: ($result['errorMessage'] ?? 'STK Push failed'),
            'response' => $result,
        ];
    }

    /**
     * Process M-Pesa callback (confirmation of payment)
     */
    public function processCallback(array $callbackData): array {
        if (empty($callbackData['Body']['stkCallback'])) {
            return ['success' => false, 'error' => 'Invalid callback data'];
        }

        $callback = $callbackData['Body']['stkCallback'];
        $resultCode = $callback['ResultCode'] ?? 1;
        $resultDesc = $callback['ResultDesc'] ?? '';
        $checkoutRequestId = $callback['CheckoutRequestID'] ?? '';
        $merchantRequestId = $callback['MerchantRequestID'] ?? '';

        // Find the transaction
        $stmt = $this->db->prepare(
            "SELECT * FROM mpesa_transactions WHERE checkout_request_id = ? OR merchant_request_id = ?"
        );
        $stmt->execute([$checkoutRequestId, $merchantRequestId]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            // Log unknown callback
            $this->logTransaction([
                'transaction_type' => 'callback',
                'transaction_id' => $checkoutRequestId ?: uniqid('CB', true),
                'amount' => 0,
                'phone_number' => '',
                'status' => 'failed',
                'raw_response' => json_encode($callbackData),
            ]);
            return ['success' => false, 'error' => 'Transaction not found'];
        }

        $status = ($resultCode === 0) ? 'completed' : 'failed';

        // Extract callback metadata
        $metadata = $callback['CallbackMetadata']['Item'] ?? [];
        $amount = 0;
        $receipt = '';
        $phone = '';
        $transactionDate = '';

        foreach ($metadata as $item) {
            if ($item['Name'] === 'Amount') $amount = (float)($item['Value'] ?? 0);
            if ($item['Name'] === 'MpesaReceiptNumber') $receipt = $item['Value'] ?? '';
            if ($item['Name'] === 'PhoneNumber') $phone = (string)($item['Value'] ?? '');
            if ($item['Name'] === 'TransactionDate') $transactionDate = $item['Value'] ?? '';
        }

        // Update transaction record
        $upd = $this->db->prepare(
            "UPDATE mpesa_transactions 
             SET status = ?, result_code = ?, result_desc = ?, callback_data = ?,
                 transaction_id = COALESCE(NULLIF(?, ''), transaction_id)
             WHERE id = ?"
        );
        $upd->execute([
            $status, (string)$resultCode, $resultDesc, json_encode($callbackData),
            $receipt ?: '',
            $transaction['id']
        ]);

        // If completed and linked to a loan, auto-record repayment
        if ($resultCode === 0 && $transaction['loan_id']) {
            $loan = $this->recordMpesaRepayment(
                $transaction['loan_id'],
                $transaction['member_id'] ?? null,
                $amount ?: (float)$transaction['amount'],
                $receipt ?: $transaction['transaction_id']
            );
        }

        return [
            'success' => $resultCode === 0,
            'amount' => $amount,
            'receipt' => $receipt,
            'phone' => $phone,
            'result_desc' => $resultDesc,
        ];
    }

    /**
     * Query STK Push status
     */
    public function queryStatus(string $checkoutRequestId): array {
        $token = $this->getToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to get M-Pesa access token'];
        }

        $env = $this->settings['environment'] ?? 'sandbox';
        $url = $env === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';

        $shortcode = $this->settings['shortcode'] ?? '174379';
        $passkey = $this->settings['passkey'] ?? '';
        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $postData = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    /**
     * Record a repayment that came through M-Pesa
     */
    private function recordMpesaRepayment(int $loanId, ?int $memberId, float $amount, string $reference): bool {
        try {
            $loanModel = new Loan();

            // Get loan to find member_id if not provided
            if (!$memberId) {
                $stmt = $this->db->prepare("SELECT member_id FROM loans WHERE id = ?");
                $stmt->execute([$loanId]);
                $loan = $stmt->fetch();
                if (!$loan) return false;
                $memberId = (int)$loan['member_id'];
            }

            $repaymentData = [
                'loan_id' => $loanId,
                'member_id' => $memberId,
                'amount' => $amount,
                'payment_method' => 'mobile_money',
                'reference' => 'M-PESA: ' . $reference,
                'date' => date('Y-m-d'),
                'recorded_by' => null, // Auto-recorded by system
                'notes' => 'M-Pesa automatic repayment',
            ];

            $loanModel->addRepayment($repaymentData);
            return true;
        } catch (Exception $e) {
            error_log("M-Pesa repayment recording failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get M-Pesa transactions for display
     */
    public function getTransactions(int $page = 1, int $perPage = 50, string $status = ''): array {
        $where = '';
        $params = [];
        if ($status && in_array($status, ['pending', 'completed', 'failed', 'cancelled'])) {
            $where = 'WHERE t.status = ?';
            $params[] = $status;
        }
        
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM mpesa_transactions t $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        $stmt = $this->db->prepare(
            "SELECT t.*, l.loan_no, m.name as member_name, m.member_no
             FROM mpesa_transactions t
             LEFT JOIN loans l ON t.loan_id = l.id
             LEFT JOIN members m ON t.member_id = m.id
             $where
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => ceil($total / $perPage),
        ];
    }

    /**
     * Get M-Pesa transactions for a loan
     */
    public function getLoanTransactions(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM mpesa_transactions WHERE loan_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Log M-Pesa transaction
     */
    private function logTransaction(array $data): int {
        // Generate unique transaction ID if not provided
        if (empty($data['transaction_id'])) {
            $data['transaction_id'] = 'MP' . time() . rand(100, 999);
        }

        // Ensure unique transaction_id
        $check = $this->db->prepare("SELECT id FROM mpesa_transactions WHERE transaction_id = ?");
        $check->execute([$data['transaction_id']]);
        if ($check->fetch()) {
            $data['transaction_id'] .= '-' . rand(10, 99);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO mpesa_transactions 
                (transaction_type, transaction_id, amount, phone_number, 
                 merchant_request_id, checkout_request_id, status, raw_response)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['transaction_type'] ?? 'stk_push',
            $data['transaction_id'],
            $data['amount'] ?? 0,
            $data['phone_number'] ?? '',
            $data['merchant_request_id'] ?? null,
            $data['checkout_request_id'] ?? null,
            $data['status'] ?? 'pending',
            $data['raw_response'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Normalize phone number
     */
    public function normalizePhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '254') && strlen($phone) === 9) {
            $phone = '254' . $phone;
        }
        return $phone;
    }

    /**
     * Format phone for display
     */
    public function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 12 && str_starts_with($phone, '254')) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }
}