<?php
// ============================================================
// VIKOBA SECURITY MONITORING - Integration Helper
// ============================================================
// This file provides functions to integrate security monitoring
// throughout the application. Include this file in key locations.

require_once __DIR__ . '/Audit.php';

class SecurityMonitoring {
    protected $audit;
    protected $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->audit = new Audit();
    }

    /**
     * Track member record changes
     */
    public function trackMemberChange($userId, $username, $memberId, $memberName, $fieldName, $oldValue, $newValue) {
        try {
            $this->audit->recordDataModification(
                $userId, $username, 'members', $memberId, $fieldName, $oldValue, $newValue,
                "Member record updated: $fieldName changed for $memberName"
            );
            $this->audit->logModuleActivity($userId, $username, '', 'members', 'update', 'member', $memberId, $memberName);
        } catch (Throwable $e) {
            error_log("Error tracking member change: " . $e->getMessage());
        }
    }

    /**
     * Track contribution recording
     */
    public function trackContributionRecorded($userId, $username, $role, $memberId, $memberName, $amount, $method) {
        try {
            $this->audit->logModuleActivity(
                $userId, $username, $role, 'contributions', 'create', 'contribution', $memberId,
                "$memberName - Amount: $amount",
                "Contribution recorded: $amount via $method for $memberName"
            );
        } catch (Throwable $e) {
            error_log("Error tracking contribution: " . $e->getMessage());
        }
    }

    /**
     * Track loan application
     */
    public function trackLoanApplication($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, $amount) {
        try {
            $this->audit->logModuleActivity(
                $userId, $username, $role, 'loans', 'create', 'loan', $loanId,
                "$memberName - Amount: $amount - Loan #$loanNo",
                "New loan application submitted"
            );
        } catch (Throwable $e) {
            error_log("Error tracking loan application: " . $e->getMessage());
        }
    }

    /**
     * Track loan approval
     */
    public function trackLoanApproval($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, $statusBefore, $statusAfter, $comments = null) {
        try {
            $this->audit->recordLoanApproval(
                $loanId, $loanNo, $memberId, $userId, $username, 'primary_approval', 'approve',
                $statusBefore, $statusAfter, $comments
            );
            
            $this->audit->logModuleActivity(
                $userId, $username, $role, 'loans', 'approve', 'loan', $loanId,
                "$memberName - Loan #$loanNo - Status: $statusAfter",
                "Loan approved"
            );

            // Create admin notification
            $this->notifyAdmins('loan_approval', "Loan $loanNo Approved", 
                "Loan #$loanNo for $memberName has been approved by $username", 'high', 'loan', $loanId);
        } catch (Throwable $e) {
            error_log("Error tracking loan approval: " . $e->getMessage());
        }
    }

    /**
     * Track loan rejection
     */
    public function trackLoanRejection($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, $reason = null) {
        try {
            $this->audit->recordLoanApproval(
                $loanId, $loanNo, $memberId, $userId, $username, 'primary_review', 'reject',
                'submitted', 'rejected', $reason
            );

            $this->audit->logModuleActivity(
                $userId, $username, $role, 'loans', 'reject', 'loan', $loanId,
                "$memberName - Loan #$loanNo - Rejected",
                "Loan rejected: $reason"
            );

            $this->notifyAdmins('loan_rejection', "Loan $loanNo Rejected",
                "Loan #$loanNo for $memberName has been rejected", 'medium', 'loan', $loanId);
        } catch (Throwable $e) {
            error_log("Error tracking loan rejection: " . $e->getMessage());
        }
    }

    /**
     * Track fine issuance
     */
    public function trackFineIssued($userId, $username, $role, $fineId, $memberId, $memberName, $amount, $reason) {
        try {
            $this->audit->logModuleActivity(
                $userId, $username, $role, 'fines', 'create', 'fine', $fineId,
                "$memberName - Amount: $amount - Reason: $reason",
                "Fine issued"
            );
        } catch (Throwable $e) {
            error_log("Error tracking fine: " . $e->getMessage());
        }
    }

    /**
     * Track member registration
     */
    public function trackMemberRegistration($userId, $username, $role, $memberId, $memberName, $memberNo) {
        try {
            $this->audit->logModuleActivity(
                $userId, $username, $role, 'members', 'create', 'member', $memberId,
                "$memberName - Member #$memberNo",
                "New member registered"
            );

            $this->notifyAdmins('member_registration', "New Member Registered",
                "New member $memberName (#$memberNo) has been registered", 'medium', 'member', $memberId);
        } catch (Throwable $e) {
            error_log("Error tracking member registration: " . $e->getMessage());
        }
    }

    /**
     * Track user profile update
     */
    public function trackUserProfileUpdate($userId, $username, $fieldName, $oldValue, $newValue) {
        try {
            $this->audit->recordDataModification(
                $userId, $username, 'users', $userId, $fieldName, $oldValue, $newValue,
                "User profile updated: $fieldName changed"
            );
        } catch (Throwable $e) {
            error_log("Error tracking profile update: " . $e->getMessage());
        }
    }

    /**
     * Detect suspicious activities
     */
    public function detectSuspiciousActivity($userId, $username) {
        try {
            // Check for brute force attempts
            if ($this->audit->checkBruteForceAttempt($username, 5, 15)) {
                $this->audit->recordSuspiciousActivity(
                    $userId, $username, 'brute_force', 'high',
                    'Excessive failed login attempts detected',
                    null, null
                );
                $this->notifyAdmins('suspicious_activity', 'Brute Force Attack Detected',
                    "Multiple failed login attempts detected for user $username", 'critical');
            }

            // Check for unusual locations (would require GeoIP in production)
            // This is a placeholder for future GeoIP integration
        } catch (Throwable $e) {
            error_log("Error detecting suspicious activity: " . $e->getMessage());
        }
    }

    /**
     * Notify all admins about important events
     */
    public function notifyAdmins($notificationType, $title, $message, $priority = 'medium', $entityType = null, $entityId = null) {
        try {
            // Create a system alert for global security dashboard visibility
            $alertLevel = match ($priority) {
                'critical', 'urgent' => 'critical',
                'high', 'medium' => 'warning',
                default => 'info',
            };
            $this->audit->createAlert($alertLevel, $title, $message, $notificationType, $entityId, null);

            // Notify admin users individually
            $stmt = $this->db->query("SELECT id FROM users WHERE role = 'admin'");
            $admins = $stmt->fetchAll();

            foreach ($admins as $admin) {
                $this->audit->notifyAdmin(
                    (int)$admin['id'], $notificationType, $title, $message, $priority, $entityType, $entityId
                );
            }
        } catch (Throwable $e) {
            error_log("Error notifying admins: " . $e->getMessage());
        }
    }

    /**
     * Get security summary for dashboard
     */
    public function getSecuritySummary($days = 30) {
        try {
            return $this->audit->getActivityStats($days);
        } catch (Throwable $e) {
            error_log("Error getting security summary: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get suspicious activities list
     */
    public function getSuspiciousActivities($days = 7) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM suspicious_activities 
                WHERE detected_at > (NOW() - INTERVAL ? DAY) 
                ORDER BY severity DESC, detected_at DESC
                LIMIT 100
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting suspicious activities: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get failed login attempts
     */
    public function getFailedLoginAttempts($hours = 24) {
        try {
            $stmt = $this->db->prepare("
                SELECT attempted_username, ip, COUNT(*) as attempt_count, MAX(attempt_time) as last_attempt
                FROM failed_logins
                WHERE attempt_time > (NOW() - INTERVAL ? HOUR)
                GROUP BY attempted_username, ip
                ORDER BY last_attempt DESC
                LIMIT 50
            ");
            $stmt->execute([$hours]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting failed login attempts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get loan approval history
     */
    public function getLoanApprovalHistory($loanId = null, $limit = 50) {
        try {
            if ($loanId) {
                $stmt = $this->db->prepare("
                    SELECT * FROM loan_approval_logs
                    WHERE loan_id = ?
                    ORDER BY approval_date DESC
                ");
                $stmt->execute([$loanId]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT * FROM loan_approval_logs
                    ORDER BY approval_date DESC
                    LIMIT ?
                ");
                $stmt->execute([$limit]);
            }
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting loan approval history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Export audit trail to CSV
     */
    public function exportAuditTrailToCSV($startDate = null, $endDate = null) {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-d', strtotime('-30 days'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'User', 'Role', 'Module', 'Action', 'Details', 'IP Address']);

            $stmt = $this->db->prepare("
                SELECT created_at, username, role, module, action, details, ip
                FROM activity_logs
                WHERE DATE(created_at) BETWEEN ? AND ?
                ORDER BY created_at DESC
                LIMIT 5000
            ");
            $stmt->execute([$startDate, $endDate]);

            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['created_at'],
                    $row['username'],
                    $row['role'],
                    $row['module'],
                    $row['action'],
                    $row['details'],
                    $row['ip']
                ]);
            }

            fclose($output);
            exit;
        } catch (Throwable $e) {
            error_log("Error exporting audit trail: " . $e->getMessage());
        }
    }
}
