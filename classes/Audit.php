<?php
require_once __DIR__ . '/Database.php';

class Audit
{
    protected $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Record a successful login
    public function recordLogin($userId = null, $username = null, $role = null, $sessionId = null)
    {
        $ip = $this->getIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $device = $this->parseDevice($ua);
        $browser = $this->parseBrowser($ua);
        $os = $this->parseOS($ua);

        $stmt = $this->db->prepare("INSERT INTO login_activity (user_id, username, role, ip, user_agent, device, browser, os, status, session_id) VALUES (?,?,?,?,?,?,?,?,? ,?)");
        $stmt->execute([$userId, $username, $role, $ip, $ua, $device, $browser, $os, 'success', $sessionId]);
        return $this->db->lastInsertId();
    }

    // Update logout_time for a session or record id
    public function recordLogout($loginActivityId = null, $sessionId = null)
    {
        if ($loginActivityId) {
            $stmt = $this->db->prepare("UPDATE login_activity SET logout_time = NOW() WHERE id = ?");
            return $stmt->execute([$loginActivityId]);
        }
        if ($sessionId) {
            $stmt = $this->db->prepare("UPDATE login_activity SET logout_time = NOW() WHERE session_id = ? AND logout_time IS NULL");
            return $stmt->execute([$sessionId]);
        }
        return false;
    }

    // Record a failed login attempt
    public function recordFailedLogin($attemptedUsername = null)
    {
        $ip = $this->getIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $this->db->prepare("INSERT INTO failed_logins (attempted_username, ip, user_agent) VALUES (?,?,?)");
        return $stmt->execute([$attemptedUsername, $ip, $ua]);
    }

    // Generic activity log
    public function logActivity($userId = null, $username = null, $role = null, $module = 'system', $action = 'action', $details = null)
    {
        $ip = $this->getIp();
        $stmt = $this->db->prepare("INSERT INTO activity_logs (user_id, username, role, module, action, details, ip) VALUES (?,?,?,?,?,?,?)");
        return $stmt->execute([$userId, $username, $role, $module, $action, $details, $ip]);
    }

    // Field-level audit trail: store before/after
    public function recordChange($userId = null, $username = null, $table = '', $recordId = '', $field = '', $old = null, $new = null)
    {
        $ip = $this->getIp();
        $stmt = $this->db->prepare("INSERT INTO audit_trail (user_id, username, table_name, record_id, field_name, old_value, new_value, ip) VALUES (?,?,?,?,?,?,?,?)");
        return $stmt->execute([$userId, $username, $table, (string)$recordId, $field, $old, $new, $ip]);
    }

    // Keep session monitor updated (create or touch)
    public function touchSession($sessionId, $userId = null, $username = null)
    {
        $ip = $this->getIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $this->db->prepare("SELECT id FROM sessions_monitor WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row) {
            $upd = $this->db->prepare("UPDATE sessions_monitor SET last_activity = NOW(), ip = ?, user_agent = ?, user_id = ?, username = ? WHERE session_id = ?");
            return $upd->execute([$ip, $ua, $userId, $username, $sessionId]);
        } else {
            $ins = $this->db->prepare("INSERT INTO sessions_monitor (session_id, user_id, username, ip, user_agent) VALUES (?,?,?,?,?)");
            return $ins->execute([$sessionId, $userId, $username, $ip, $ua]);
        }
    }

    // Helpers
    protected function getIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    protected function parseBrowser($ua)
    {
        if (!$ua) return null;
        if (strpos($ua, 'Firefox') !== false) return 'Firefox';
        if (strpos($ua, 'Chrome') !== false) return 'Chrome';
        if (strpos($ua, 'Safari') !== false && strpos($ua, 'Chrome') === false) return 'Safari';
        if (strpos($ua, 'Edge') !== false) return 'Edge';
        return null;
    }

    protected function parseOS($ua)
    {
        if (!$ua) return null;
        if (stripos($ua, 'Windows') !== false) return 'Windows';
        if (stripos($ua, 'Android') !== false) return 'Android';
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
        if (stripos($ua, 'Mac') !== false) return 'macOS';
        if (stripos($ua, 'Linux') !== false) return 'Linux';
        return null;
    }

    protected function parseDevice($ua)
    {
        if (!$ua) return null;
        if (stripos($ua, 'Mobile') !== false) return 'Mobile';
        return 'Desktop';
    }

    // ============================================================
    // ENHANCED SECURITY FEATURES
    // ============================================================

    /**
     * Record loan approval action for audit trail
     */
    public function recordLoanApproval($loanId, $loanNo, $memberId, $approvingOfficerId, $approvingOfficerName, $stage, $action, $statusBefore, $statusAfter, $comments = null)
    {
        $ip = $this->getIp();
        try {
            $stmt = $this->db->prepare("INSERT INTO loan_approval_logs (loan_id, loan_no, member_id, approving_officer_id, approving_officer_name, approval_stage, action, status_before, status_after, comments, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            return $stmt->execute([$loanId, $loanNo, $memberId, $approvingOfficerId, $approvingOfficerName, $stage, $action, $statusBefore, $statusAfter, $comments, $ip]);
        } catch (Throwable $e) {
            error_log("Error recording loan approval: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record data modification with before/after values
     */
    public function recordDataModification($userId, $username, $tableName, $recordId, $fieldName, $oldValue, $newValue, $description = null)
    {
        $ip = $this->getIp();
        try {
            $stmt = $this->db->prepare("INSERT INTO data_audit_trail (user_id, username, table_name, record_id, field_name, old_value, new_value, change_description, ip_address) VALUES (?,?,?,?,?,?,?,?,?)");
            return $stmt->execute([$userId, $username, $tableName, $recordId, $fieldName, $oldValue, $newValue, $description, $ip]);
        } catch (Throwable $e) {
            error_log("Error recording data modification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log module activity with entity tracking
     */
    public function logModuleActivity($userId, $username, $role, $module, $action, $entityType = null, $entityId = null, $entityName = null, $details = null)
    {
        $ip = $this->getIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        try {
            $stmt = $this->db->prepare("INSERT INTO module_activity_logs (user_id, username, role, module, action, entity_type, entity_id, entity_name, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            return $stmt->execute([$userId, $username, $role, $module, $action, $entityType, $entityId, $entityName, $details, $ip, $ua]);
        } catch (Throwable $e) {
            error_log("Error logging module activity: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record or update online user session
     */
    public function updateOnlineUser($sessionId, $userId, $username, $role, $browser = null, $device = null, $os = null)
    {
        $ip = $this->getIp();
        try {
            // Check if session exists
            $stmt = $this->db->prepare("SELECT id FROM online_users WHERE session_id = ?");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();

            if ($row) {
                // Update existing session
                $upd = $this->db->prepare("UPDATE online_users SET last_activity = NOW(), status = 'active', ip_address = ?, browser = ?, device = ?, os = ? WHERE session_id = ?");
                return $upd->execute([$ip, $browser, $device, $os, $sessionId]);
            } else {
                // Create new online user entry
                $ins = $this->db->prepare("INSERT INTO online_users (session_id, user_id, username, role, ip_address, browser, device, os, login_time) VALUES (?,?,?,?,?,?,?,?,NOW())");
                return $ins->execute([$sessionId, $userId, $username, $role, $ip, $browser, $device, $os]);
            }
        } catch (Throwable $e) {
            error_log("Error updating online user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record user logout (remove from online users)
     */
    public function recordUserOffline($sessionId)
    {
        try {
            $stmt = $this->db->prepare("UPDATE online_users SET status = 'offline', last_activity = NOW() WHERE session_id = ?");
            return $stmt->execute([$sessionId]);
        } catch (Throwable $e) {
            error_log("Error recording offline: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get currently active online users
     */
    public function getOnlineUsers($excludeMinutesIdle = 30)
    {
        try {
            $sql = "SELECT * FROM online_users 
                    WHERE status = 'active' 
                    AND TIMESTAMPDIFF(MINUTE, last_activity, NOW()) < ? 
                    ORDER BY last_activity DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$excludeMinutesIdle]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting online users: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect and record suspicious activity
     */
    public function recordSuspiciousActivity($userId, $username, $activityType, $severity, $details, $ipAddress = null, $deviceInfo = null)
    {
        $ip = $ipAddress ?? $this->getIp();
        try {
            $stmt = $this->db->prepare("INSERT INTO suspicious_activities (user_id, username, activity_type, severity, details, ip_address, device_info) VALUES (?,?,?,?,?,?,?)");
            return $stmt->execute([$userId, $username, $activityType, $severity, $details, $ip, $deviceInfo]);
        } catch (Throwable $e) {
            error_log("Error recording suspicious activity: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check for brute force attacks (excessive failed logins)
     */
    public function checkBruteForceAttempt($username, $threshold = 5, $minutesWindow = 15)
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM failed_logins WHERE attempted_username = ? AND attempt_time > (NOW() - INTERVAL ? MINUTE)");
            $stmt->execute([$username, $minutesWindow]);
            $row = $stmt->fetch();
            return (int)($row['count'] ?? 0) >= $threshold;
        } catch (Throwable $e) {
            error_log("Error checking brute force: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get failed login attempts for a user
     */
    public function getFailedLoginAttempts($username, $hours = 24)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM failed_logins WHERE attempted_username = ? AND attempt_time > (NOW() - INTERVAL ? HOUR) ORDER BY attempt_time DESC");
            $stmt->execute([$username, $hours]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting failed login attempts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create system alert
     */
    public function createAlert($level, $title, $message, $alertType = null, $relatedEntityId = null, $actionUrl = null)
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO system_alerts (level, title, message, alert_type, related_entity_id, action_url) VALUES (?,?,?,?,?,?)");
            return $stmt->execute([$level, $title, $message, $alertType, $relatedEntityId, $actionUrl]);
        } catch (Throwable $e) {
            error_log("Error creating alert: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to admin
     */
    public function notifyAdmin($adminId, $notificationType, $title, $message, $priority = 'medium', $entityType = null, $entityId = null, $actionUrl = null)
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO admin_notifications (admin_id, notification_type, title, message, priority, related_entity_type, related_entity_id, action_url) VALUES (?,?,?,?,?,?,?,?)");
            return $stmt->execute([$adminId, $notificationType, $title, $message, $priority, $entityType, $entityId, $actionUrl]);
        } catch (Throwable $e) {
            error_log("Error sending admin notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread notifications for admin
     */
    public function getAdminNotifications($adminId, $limit = 50)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM admin_notifications WHERE admin_id = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$adminId, $limit]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting admin notifications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead($notificationId, $adminId = null)
    {
        try {
            if ($adminId) {
                $stmt = $this->db->prepare("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND admin_id = ?");
                return $stmt->execute([$notificationId, $adminId]);
            } else {
                $stmt = $this->db->prepare("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
                return $stmt->execute([$notificationId]);
            }
        } catch (Throwable $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get suspicious activities summary
     */
    public function getSuspiciousActivitiesSummary($days = 7)
    {
        try {
            $stmt = $this->db->prepare("SELECT severity, activity_type, COUNT(*) as count FROM suspicious_activities WHERE detected_at > (NOW() - INTERVAL ? DAY) AND acknowledged = 0 GROUP BY severity, activity_type ORDER BY severity DESC");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("Error getting suspicious activities summary: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get activity summary statistics
     */
    public function getActivityStats($days = 30)
    {
        try {
            $stats = [];
            
            // Total logins
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM login_activity WHERE login_time > (NOW() - INTERVAL ? DAY) AND status = 'success'");
            $stmt->execute([$days]);
            $stats['total_logins'] = (int)($stmt->fetch()['count'] ?? 0);
            
            // Failed logins
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM failed_logins WHERE attempt_time > (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$days]);
            $stats['failed_logins'] = (int)($stmt->fetch()['count'] ?? 0);
            
            // Active users
            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM online_users WHERE last_activity > (NOW() - INTERVAL 1 HOUR)");
            $stmt->execute([]);
            $stats['active_users'] = (int)($stmt->fetch()['count'] ?? 0);
            
            // Activity log entries
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE created_at > (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$days]);
            $stats['activity_logs'] = (int)($stmt->fetch()['count'] ?? 0);
            
            // Data changes
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM data_audit_trail WHERE changed_at > (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$days]);
            $stats['data_changes'] = (int)($stmt->fetch()['count'] ?? 0);
            
            // Suspicious activities
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM suspicious_activities WHERE detected_at > (NOW() - INTERVAL ? DAY) AND acknowledged = 0");
            $stmt->execute([$days]);
            $stats['suspicious_activities'] = (int)($stmt->fetch()['count'] ?? 0);
            
            return $stats;
        } catch (Throwable $e) {
            error_log("Error getting activity stats: " . $e->getMessage());
            return [];
        }
    }
}
