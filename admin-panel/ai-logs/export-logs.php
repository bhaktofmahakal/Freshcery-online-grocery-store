<?php 
session_start();

if (!isset($_SESSION['adminname'])) {
    header("location: ../admins/login-admins.php");
    exit();
}

require "../../config/config.php";

// Get the same filters as the main page
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sourceFilter = isset($_GET['source']) ? $_GET['source'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(prompt LIKE ? OR response LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($sourceFilter)) {
    $whereConditions[] = "source = ?";
    $params[] = $sourceFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(created_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get all logs matching the criteria
$query = "SELECT * FROM ai_logs $whereClause ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = 'ai_logs_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create file pointer
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, [
    'ID',
    'Prompt',
    'Response',
    'Source',
    'IP Address',
    'User ID',
    'Processing Time (seconds)',
    'Created At'
]);

// Add data rows
foreach ($logs as $log) {
    fputcsv($output, [
        $log['id'],
        $log['prompt'],
        $log['response'],
        $log['source'],
        $log['ip_address'],
        $log['user_id'] ?: 'Guest',
        $log['processing_time'],
        $log['created_at']
    ]);
}

fclose($output);
exit();
?>