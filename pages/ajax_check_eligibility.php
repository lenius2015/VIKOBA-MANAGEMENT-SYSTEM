<?php
// AJAX endpoint to check loan eligibility in real-time
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$memberId = (int)($_GET['member_id'] ?? 0);
$amount = (float)($_GET['amount'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);

if (!$memberId || !$amount) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Verify the user owns this member (or is admin)
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? '';
$userMemberId = $_SESSION['member_id'] ?? 0;

if ($userRole !== 'admin' && $userRole !== 'treasurer' && $userMemberId != $memberId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get product if specified
$product = null;
if ($productId) {
    $productModel = new LoanProduct();
    $product = $productModel->getById($productId);
}

$loanModel = new Loan();
$eligibility = $loanModel->checkEligibility($memberId, $amount, $product);

// Add product info to response
$eligibility['has_product'] = $product ? true : false;

header('Content-Type: application/json');
echo json_encode($eligibility);
