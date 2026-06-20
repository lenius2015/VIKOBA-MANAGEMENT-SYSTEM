<?php
// ============================================================
// VIKOBA - Authentication Class
// ============================================================

class Auth {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function login(string $email, string $password): bool {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // check temporary lockout
        if ($user && !empty($user['locked_until'])) {
            $lockedUntil = strtotime($user['locked_until']);
            if ($lockedUntil > time()) {
                // still locked
                try {
                    require_once __DIR__ . '/Audit.php';
                    $audit = new Audit();
                    $audit->recordFailedLogin($email);
                } catch (Throwable $e) { /* ignore */ }
                return false;
            } else {
                // clear expired lock
                $upd = $this->db->prepare("UPDATE users SET locked_until = NULL WHERE id = ?");
                $upd->execute([$user['id']]);
            }
        }

        // failed credential path
        if (!$user || !password_verify($password, $user['password'])) {
            // record failed attempt
            try {
                require_once __DIR__ . '/Audit.php';
                $audit = new Audit();
                $audit->recordFailedLogin($email);

                // check threshold for lockout (5 attempts in 15 minutes)
                $threshold = 5;
                $minutes = 15;
                $countStmt = $this->db->prepare("SELECT COUNT(*) as c FROM failed_logins WHERE attempted_username = ? AND attempt_time > (NOW() - INTERVAL ? MINUTE)");
                $countStmt->execute([$email, $minutes]);
                $row = $countStmt->fetch();
                if ($row && (int)$row['c'] >= $threshold && $user) {
                    // set temporary lockout
                    $lockStmt = $this->db->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
                    $lockStmt->execute([$minutes, $user['id']]);
                }
            } catch (Throwable $e) { /* ignore */ }
            return false;
        }

        // successful login
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email']= $user['email'];
        $_SESSION['member_id'] = $user['member_id'] ?? null;
        $_SESSION['user_profile_picture'] = $user['profile_picture'] ?? null;
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time']= time();

        // record login activity via Audit
        try {
            require_once __DIR__ . '/Audit.php';
            $audit = new Audit();
            $loginId = $audit->recordLogin($user['id'], $user['email'], $user['role'], session_id());
            if ($loginId) $_SESSION['login_activity_id'] = $loginId;
            // create or touch session monitor
            $audit->touchSession(session_id(), $user['id'], $user['name']);
        } catch (Throwable $e) { /* ignore */ }

        // legacy activity log
        $this->log('Login', 'auth', 'User logged in');
        return true;
    }

    public function logout(): void {
        // record logout in audit table if available
        try {
            require_once __DIR__ . '/Audit.php';
            $audit = new Audit();
            if (!empty($_SESSION['login_activity_id'])) {
                $audit->recordLogout((int)$_SESSION['login_activity_id']);
            } else {
                $audit->recordLogout(null, session_id());
            }
        } catch (Throwable $e) { /* ignore */ }

        $this->log('Logout', 'auth', 'User logged out');
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }

    public function isLoggedIn(): bool {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            header('Location: ' . APP_URL . '/index.php');
            exit;
        }
        // Session timeout
        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            $this->logout();
        }
        $_SESSION['login_time'] = time();
    }

    public function requireRole(array $roles): void {
        $this->requireLogin();
        if (!in_array($_SESSION['user_role'], $roles)) {
            header('Location: ' . APP_URL . '/pages/dashboard.php?error=unauthorized');
            exit;
        }
    }

    public function getUser(): array {
        return [
            'id'        => $_SESSION['user_id']   ?? 0,
            'name'      => $_SESSION['user_name'] ?? '',
            'role'      => $_SESSION['user_role'] ?? '',
            'email'     => $_SESSION['user_email']?? '',
            'member_id' => $_SESSION['member_id'] ?? null,
            'profile_picture' => $_SESSION['user_profile_picture'] ?? null,
        ];
    }

    public function log(string $action, string $module, string $details = ''): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO activity_logs (user_id, username, role, module, action, details, ip) VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                $_SESSION['user_name'] ?? null,
                $_SESSION['user_role'] ?? null,
                $module, $action, $details,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) { /* silent fail */ }
    }
}
