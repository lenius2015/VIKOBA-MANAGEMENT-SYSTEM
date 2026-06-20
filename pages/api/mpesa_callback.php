<?php
// ============================================================
// VIKOBA - M-Pesa Payment Callback Endpoint
// This is called by Safaricom API after STK Push
// ============================================================

// Allow cross-origin requests from Safaricom
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST from Safaricom
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Method not allowed']);
    exit;
}

// Initialize minimal bootstrap (don't use session for callbacks)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Database.php';

try {
    // Get raw POST data
    $callbackData = json_decode(file_get_contents('php://input'), true);
    
    if (!$callbackData) {
        http_response_code(400);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
        exit;
    }

    // Process the callback
    $mpesa = new Mpesa();
    $result = $mpesa->processCallback($callbackData);

    // Respond to Safaricom with success acknowledgement
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Success',
    ]);

} catch (Exception $e) {
    error_log("M-Pesa callback error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Internal error']);
}