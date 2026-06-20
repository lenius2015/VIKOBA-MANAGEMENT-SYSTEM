<?php
// Redirect to the enhanced loan_approve.php page
require_once __DIR__ . '/../includes/bootstrap.php';
session_start();

$loanId = (int)($_GET['id'] ?? 0);
if ($loanId > 0) {
    redirect(APP_URL . '/pages/loan_approve.php?id=' . $loanId);
} else {
    redirect(APP_URL . '/pages/loans.php');
}