<?php 
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['adminname'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require "../../config/config.php";

$logId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($logId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
    exit();
}

try {
    $stmt = $conn->prepare("DELETE FROM ai_logs WHERE id = ?");
    $result = $stmt->execute([$logId]);
    
    if ($result && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Log deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Log not found or already deleted']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>