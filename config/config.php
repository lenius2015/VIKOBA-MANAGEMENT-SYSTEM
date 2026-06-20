<?php
// ============================================================
// VIKOBA MANAGEMENT SYSTEM - Configuration
// ============================================================

define('APP_NAME', 'Vikoba Management System');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/vikoba');

// Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'vikoba_db');

// Session
define('SESSION_LIFETIME', 3600); // 1 hour

// Timezone
date_default_timezone_set('Africa/Dar_es_Salaam');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Currency
define('CURRENCY', 'Tsh');
define('INTEREST_RATE_DEFAULT', 15);
