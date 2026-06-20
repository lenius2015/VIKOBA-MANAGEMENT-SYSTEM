<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start();
$auth = new Auth();

// Redirect if already logged in — based on role
if ($auth->isLoggedIn()) {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'member') {
        redirect(APP_URL . '/pages/member_dashboard.php');
    } else {
        redirect(APP_URL . '/pages/dashboard.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($auth->login($email, $password)) {
        $role = $_SESSION['user_role'] ?? '';
        if ($role === 'member') {
            redirect(APP_URL . '/pages/member_dashboard.php');
        } else {
            redirect(APP_URL . '/pages/dashboard.php');
        }
    } else {
        $error = 'Invalid email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css"/>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css"/>
  <style>
    .login-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #185FA5 0%, #0C447C 50%, #092E54 100%);
      position: relative;
      overflow: hidden;
    }
    .login-page::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle at 30% 40%, rgba(255,255,255,0.05) 0%, transparent 50%),
                  radial-gradient(circle at 70% 60%, rgba(255,255,255,0.03) 0%, transparent 50%);
      animation: float 20s ease-in-out infinite;
    }
    @keyframes float {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      33% { transform: translate(30px, -30px) rotate(1deg); }
      66% { transform: translate(-20px, 20px) rotate(-1deg); }
    }
    .login-card {
      width: 420px;
      background: rgba(255,255,255,0.98);
      border-radius: 20px;
      padding: 45px 40px 35px;
      position: relative;
      z-index: 1;
      box-shadow: 0 25px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.1);
    }
    .login-logo { text-align: center; margin-bottom: 32px; }
    .login-logo .logo-icon {
      font-size: 52px;
      color: #185FA5;
      background: #E6F1FB;
      padding: 16px;
      border-radius: 16px;
      display: inline-block;
    }
    .login-logo h1 {
      font-size: 24px;
      font-weight: 700;
      color: #111;
      margin: 12px 0 4px;
      letter-spacing: -0.5px;
    }
    .login-logo p {
      font-size: 13px;
      color: #888;
      margin: 0;
    }
    .login-card .form-control {
      height: 48px;
      font-size: 14px;
      padding-left: 44px;
      border-radius: 12px;
      border: 2px solid #e8e8e0;
      background: #f8f8f6;
    }
    .login-card .form-control:focus {
      border-color: #185FA5;
      background: #fff;
      box-shadow: 0 0 0 4px rgba(24,95,165,0.1);
    }
    .login-card .input-group-text {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      z-index: 10;
      background: transparent;
      border: none;
      color: #aaa;
      font-size: 20px;
      pointer-events: none;
    }
    .login-card .form-group { position: relative; }
    .login-card .btn-primary {
      height: 48px;
      border-radius: 12px;
      font-size: 15px;
      font-weight: 600;
      background: linear-gradient(135deg, #185FA5, #0C447C);
      border: none;
      transition: all 0.2s;
    }
    .login-card .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(24,95,165,0.3);
    }
    .login-footer {
      text-align: center;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    .login-footer .role-badge {
      display: inline-block;
      font-size: 11px;
      padding: 4px 12px;
      border-radius: 20px;
      background: #E6F1FB;
      color: #185FA5;
      margin: 2px 4px;
    }
  </style>
</head>
<body class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <span class="logo-icon" aria-hidden="true">
        <svg width="52" height="52" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="0" y="0" width="64" height="64" rx="12" fill="#E6F1FB"/>
          <path d="M18 20h28v6H18z" fill="#185FA5"/>
          <path d="M12 32h40v6H12z" fill="#0C447C"/>
        </svg>
      </span>
      <h1>VIKOBA</h1>
      <p>Village Community Bank Management System</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" style="border-radius:12px;border:none;background:#FCEBEB;color:#A32D2D;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="me-1" xmlns="http://www.w3.org/2000/svg"><path d="M12 9v4" stroke="#A32D2D" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 17h.01" stroke="#A32D2D" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.73 3h16.9a2 2 0 0 0 1.73-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="#A32D2D" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span><?= escape($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group mb-3">
        <span class="input-group-text" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 4h16v8a8 8 0 0 1-16 0V4z" stroke="#999" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <input type="email" name="email" class="form-control" placeholder="Email address" required autofocus value="<?= escape($_POST['email'] ?? '') ?>"/>
      </div>
      <div class="form-group mb-4">
        <span class="input-group-text" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="11" width="18" height="10" rx="2" stroke="#999" stroke-width="1.2"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="#999" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <input type="password" name="password" class="form-control" placeholder="Password" required/>
      </div>
      <button type="submit" class="btn btn-primary w-100">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="me-2" xmlns="http://www.w3.org/2000/svg"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 17l5-5-5-5" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12H3" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Sign In
      </button>
    </form>

    <div class="login-footer">
      <p class="text-muted mb-2" style="font-size:12px;">Demo Accounts</p>
      <div>
        <span class="role-badge"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="me-1" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.5 6H22l-5 3.8L17 22l-5-3.2L7 22l1-10.2L3 8h7.5L12 2z" fill="#185FA5"/></svg>Admin: admin@vikoba.co.tz</span>
        <span class="role-badge" style="background:#EAF3DE;color:#3B6D11;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="me-1" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="6" fill="#3B6D11"/></svg>Treasurer: treasurer@vikoba.co.tz</span>
        <span class="role-badge" style="background:#FAEEDA;color:#854F0B;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="me-1" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0" fill="#854F0B"/></svg>Member: fatuma@vikoba.co.tz</span>
      </div>
      <p class="text-muted mt-2" style="font-size:11px;">Password: <strong>password</strong></p>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>