<?php
// ============================================================
// VIKOBA - SMS/Email Integration (Africa's Talking API)
// ============================================================

class SmsEmail {
    private PDO $db;
    private array $settings = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->loadSettings();
    }

    /**
     * Load Africa's Talking settings
     */
    private function loadSettings(): void {
        $stmt = $this->db->query("SELECT * FROM africas_talking_settings WHERE active = 1 LIMIT 1");
        $settings = $stmt->fetch();
        if ($settings) {
            $this->settings = $settings;
        }
    }

    /**
     * Check if SMS service is active/configured
     */
    public function isActive(): bool {
        return !empty($this->settings['active']) 
            && !empty($this->settings['api_key']) 
            && !empty($this->settings['username']);
    }

    /**
     * Send SMS via Africa's Talking API
     */
    public function sendSms(string $phone, string $message, string $recipientName = ''): array {
        if (!$this->isActive()) {
            return ['success' => false, 'error' => 'SMS service not configured'];
        }

        // Format phone number (ensure it starts with country code)
        $phone = $this->normalizePhone($phone);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.africastalking.com/version1/messaging');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'username' => $this->settings['username'],
            'to'       => $phone,
            'message'  => $message,
            'from'     => $this->settings['sender_id'] ?? 'VIKOBA',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . $this->settings['api_key'],
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = json_decode($response, true) ?? [];
        $success = $httpCode === 200 && empty($error);

        // Log the communication
        $this->logCommunication('sms', $phone, $recipientName, 'SMS Alert', $message, 
            $success ? 'sent' : 'failed', $response);

        return [
            'success' => $success,
            'response' => $result,
            'error' => $error ?: ($result['errorMessage'] ?? null),
        ];
    }

    /**
     * Send email using PHP mail() or SMTP settings
     */
    public function sendEmail(string $to, string $subject, string $message, string $recipientName = ''): array {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        // Get SMTP settings
        $smtpHost = $this->getSetting('smtp_host');
        $smtpFromEmail = $this->getSetting('smtp_from_email') ?: 'noreply@vikoba.co.tz';
        $smtpFromName = $this->getSetting('smtp_from_name') ?: 'Vikoba System';
        
        $headers .= "From: $smtpFromName <$smtpFromEmail>\r\n";
        $headers .= "Reply-To: $smtpFromEmail\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Build HTML email template
        $htmlBody = $this->buildEmailTemplate($subject, $message);

        $success = mail($to, $subject, $htmlBody, $headers);

        // Log the communication
        $this->logCommunication('email', $to, $recipientName, $subject, $message, 
            $success ? 'sent' : 'failed');

        return [
            'success' => $success,
            'error' => $success ? null : 'Failed to send email',
        ];
    }

    /**
     * Send repayment reminder SMS to a member
     */
    public function sendRepaymentReminder(int $loanId, int $memberId): array {
        // Get loan and member info
        $stmt = $this->db->prepare(
            "SELECT l.*, m.name as member_name, m.phone,
                    COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
             FROM loans l
             JOIN members m ON l.member_id = m.id
             WHERE l.id = ? AND l.member_id = ?"
        );
        $stmt->execute([$loanId, $memberId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            return ['success' => false, 'error' => 'Loan not found'];
        }

        $balance = (float)$loan['total_repayable'] - (float)$loan['total_paid'];
        $message = "Dear {$loan['member_name']}, this is a reminder for your loan ({$loan['loan_no']}) repayment. "
                 . "Outstanding balance: Tsh " . number_format($balance, 2) 
                 . ". Please make your payment on time to avoid penalties. - VIKOBA";

        return $this->sendSms($loan['phone'], $message, $loan['member_name']);
    }

    /**
     * Send bulk SMS reminders to all members with due loans
     */
    public function sendBulkReminders(): array {
        $daysBeforeDue = (int)($this->getSetting('reminder_days_before_due') ?: 3);
        
        // Find loans due within the reminder window
        $stmt = $this->db->prepare(
            "SELECT l.id as loan_id, l.loan_no, l.total_repayable, l.member_id,
                    m.name as member_name, m.phone, m.email,
                    COALESCE((SELECT SUM(r.amount) FROM repayments r WHERE r.loan_id=l.id),0) as total_paid
             FROM loans l
             JOIN members m ON l.member_id = m.id
             WHERE l.status = 'disbursed'
             AND l.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             AND (l.due_date > CURDATE() OR l.due_date = CURDATE())"
        );
        $stmt->execute([$daysBeforeDue]);
        $loansDue = $stmt->fetchAll();

        $results = ['sms' => 0, 'email' => 0, 'failed' => 0];
        
        foreach ($loansDue as $loan) {
            $balance = (float)$loan['total_repayable'] - (float)$loan['total_paid'];
            $message = "Dear {$loan['member_name']}, your loan ({$loan['loan_no']}) of Tsh " 
                     . number_format($balance, 2) . " is due soon. Please prepare your repayment. - VIKOBA";
            $emailMessage = "<p>Dear {$loan['member_name']},</p><p>Your loan (<strong>{$loan['loan_no']}</strong>) outstanding balance of <strong>Tsh " 
                          . number_format($balance, 2) . "</strong> is due for repayment. Please make your payment on time.</p><p>Thank you,<br>Vikoba Management</p>";

            // Send SMS
            if ($loan['phone']) {
                $smsResult = $this->sendSms($loan['phone'], $message, $loan['member_name']);
                if ($smsResult['success']) $results['sms']++; else $results['failed']++;
            }

            // Send Email
            if ($loan['email']) {
                $emailResult = $this->sendEmail($loan['email'], 'Loan Repayment Reminder - ' . $loan['loan_no'], $emailMessage, $loan['member_name']);
                if ($emailResult['success']) $results['email']++; else $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Schedule a reminder for later sending
     */
    public function scheduleReminder(int $loanId, string $type = 'sms', string $scheduledDate = ''): int {
        if (empty($scheduledDate)) {
            $scheduledDate = date('Y-m-d H:i:s', strtotime('+1 day'));
        }

        $stmt = $this->db->prepare(
            "SELECT l.*, m.name as member_name 
             FROM loans l JOIN members m ON l.member_id = m.id WHERE l.id = ?"
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan) return 0;

        $message = "Reminder: Your loan ({$loan['loan_no']}) repayment is due. Please pay on time. - VIKOBA";
        
        $stmt = $this->db->prepare(
            "INSERT INTO scheduled_reminders (reminder_type, recipient_type, recipient_id, loan_id, title, message, scheduled_date, created_by)
             VALUES (?, 'member', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $type, $loan['member_id'], $loanId,
            'Loan Repayment Reminder', $message, $scheduledDate,
            $_SESSION['user_id'] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Process pending scheduled reminders
     */
    public function processScheduledReminders(): array {
        $stmt = $this->db->prepare(
            "SELECT r.*, l.member_id, m.name as member_name, m.phone, m.email
             FROM scheduled_reminders r
             JOIN loans l ON r.loan_id = l.id
             JOIN members m ON l.member_id = m.id
             WHERE r.status = 'pending' AND r.scheduled_date <= NOW()
             LIMIT 50"
        );
        $stmt->execute();
        $reminders = $stmt->fetchAll();

        $processed = ['sent' => 0, 'failed' => 0];

        foreach ($reminders as $reminder) {
            $success = false;
            
            if ($reminder['reminder_type'] === 'sms' && $reminder['phone']) {
                $result = $this->sendSms($reminder['phone'], $reminder['message'], $reminder['member_name']);
                $success = $result['success'];
            } elseif ($reminder['reminder_type'] === 'email' && $reminder['email']) {
                $result = $this->sendEmail($reminder['email'], $reminder['title'], $reminder['message'], $reminder['member_name']);
                $success = $result['success'];
            }

            // Update reminder status
            $upd = $this->db->prepare(
                "UPDATE scheduled_reminders SET status = ?, sent_date = NOW() WHERE id = ?"
            );
            $upd->execute([$success ? 'sent' : 'failed', $reminder['id']]);

            if ($success) $processed['sent']++; else $processed['failed']++;
        }

        return $processed;
    }

    /**
     * Get Africa's Talking settings
     */
    public function getSettings(): array {
        return $this->settings;
    }

    /**
     * Update Africa's Talking settings
     */
    public function updateSettings(array $data): bool {
        $stmt = $this->db->prepare(
            "UPDATE africas_talking_settings SET api_key = ?, username = ?, sender_id = ?, active = ? WHERE id = 1"
        );
        return $stmt->execute([
            $data['api_key'] ?? '',
            $data['username'] ?? '',
            $data['sender_id'] ?? 'VIKOBA',
            $data['active'] ? 1 : 0
        ]);
    }

    /**
     * Normalize phone number to international format
     */
    private function normalizePhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If starts with 0, replace with 255 (Tanzania country code)
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            $phone = '255' . substr($phone, 1);
        }
        // If starts with +255, remove the +
        elseif (str_starts_with($phone, '255')) {
            // Already in correct format
        }
        // If neither, add 255
        elseif (!str_starts_with($phone, '255')) {
            $phone = '255' . $phone;
        }
        
        return $phone;
    }

    /**
     * Log sent communication
     */
    private function logCommunication(string $type, string $recipient, string $recipientName, string $subject, string $message, string $status, string $providerResponse = null): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO sent_communications (communication_type, recipient, recipient_name, subject, message, status, provider_response)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$type, $recipient, $recipientName, $subject, $message, $status, $providerResponse]);
        } catch (Exception $e) {
            // Silently fail - logging should not break functionality
        }
    }

    /**
     * Build HTML email template
     */
    private function buildEmailTemplate(string $subject, string $body): string {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($subject) . '</title></head>'
             . '<body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">'
             . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">'
             . '<div style="background:#185FA5;padding:20px;text-align:center;">'
             . '<h2 style="color:#fff;margin:0;font-size:20px;">VIKOBA Management System</h2>'
             . '</div>'
             . '<div style="padding:30px;">'
             . $body
             . '</div>'
             . '<div style="background:#f8f8f6;padding:15px;text-align:center;font-size:12px;color:#888;">'
             . '<p style="margin:0;">This is an automated message from VIKOBA Management System. Please do not reply.</p>'
             . '</div>'
             . '</div></body></html>';
    }

    /**
     * Get a system setting value
     */
    private function getSetting(string $key): string {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            return (string)$stmt->fetchColumn();
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get sent communications log with pagination
     */
    public function getSentLog(int $page = 1, int $perPage = 50, string $type = ''): array {
        $where = '';
        $params = [];
        if ($type && in_array($type, ['sms', 'email'])) {
            $where = 'WHERE communication_type = ?';
            $params[] = $type;
        }
        
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM sent_communications $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // Get records
        $stmt = $this->db->prepare(
            "SELECT * FROM sent_communications $where ORDER BY created_at DESC LIMIT ? OFFSET ?"
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
}