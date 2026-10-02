<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';

require_login();
check_role(1);

if (!isset($_GET['leave_id'])) {
    die('Invalid request.');
}

$leave_id = intval($_GET['leave_id']);

$stmt = $conn->prepare("SELECT signature_path FROM leave_requests WHERE leave_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $leave_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $leave = $result->fetch_assoc();
    $stmt->close();

    if ($leave && !empty($leave['signature_path']) && file_exists($leave['signature_path'])) {
        $file_path = $leave['signature_path'];
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));
        
        readfile($file_path);
        exit;
    }
}

die('Signature file not found.');
?>