<?php 
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['adminname'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require "../../config/config.php";

try {
    // Delete logs older than 30 days
    $stmt = $conn->prepare("DELETE FROM ai_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $result = $stmt->execute();
    
    $deletedCount = $stmt->rowCount();
    
    if ($result) {
        echo json_encode([
            'success' => true, 
            'message' => 'Old logs cleared successfully',
            'deleted' => $deletedCount
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to clear old logs']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>