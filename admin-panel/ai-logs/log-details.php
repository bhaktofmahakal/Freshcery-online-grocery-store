<?php 
session_start();

if (!isset($_SESSION['adminname'])) {
    http_response_code(403);
    exit('Unauthorized');
}

require "../../config/config.php";

$logId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($logId <= 0) {
    http_response_code(400);
    exit('Invalid log ID');
}

$stmt = $conn->prepare("SELECT * FROM ai_logs WHERE id = ?");
$stmt->execute([$logId]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    http_response_code(404);
    exit('Log not found');
}
?>

<div class="row">
    <div class="col-md-6">
        <h6><i class="fas fa-question-circle text-primary"></i> User Prompt</h6>
        <div class="bg-light p-3 rounded mb-3" style="max-height: 200px; overflow-y: auto;">
            <?= nl2br(htmlspecialchars($log['prompt'])) ?>
        </div>
        
        <h6><i class="fas fa-info-circle text-info"></i> System Information</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Log ID:</strong></td>
                <td><?= $log['id'] ?></td>
            </tr>
            <tr>
                <td><strong>Source:</strong></td>
                <td>
                    <span class="badge badge-<?= $log['source'] === 'gemini' ? 'primary' : ($log['source'] === 'ollama' ? 'secondary' : ($log['source'] === 'cache' ? 'success' : 'warning')) ?>">
                        <?= ucfirst($log['source']) ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>IP Address:</strong></td>
                <td><?= $log['ip_address'] ?></td>
            </tr>
            <tr>
                <td><strong>User ID:</strong></td>
                <td><?= $log['user_id'] ? $log['user_id'] : 'Guest' ?></td>
            </tr>
            <tr>
                <td><strong>Processing Time:</strong></td>
                <td><?= number_format($log['processing_time'], 3) ?> seconds</td>
            </tr>
            <tr>
                <td><strong>Created At:</strong></td>
                <td><?= date('F j, Y \a\t g:i A', strtotime($log['created_at'])) ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h6><i class="fas fa-robot text-success"></i> AI Response</h6>
        <div class="bg-light p-3 rounded mb-3" style="max-height: 300px; overflow-y: auto;">
            <?= nl2br(htmlspecialchars($log['response'])) ?>
        </div>
        
        <h6><i class="fas fa-chart-line text-warning"></i> Analytics</h6>
        <div class="row">
            <div class="col-6">
                <div class="text-center p-2 bg-primary text-white rounded">
                    <div class="h4 mb-0"><?= strlen($log['prompt']) ?></div>
                    <small>Prompt Length</small>
                </div>
            </div>
            <div class="col-6">
                <div class="text-center p-2 bg-success text-white rounded">
                    <div class="h4 mb-0"><?= strlen($log['response']) ?></div>
                    <small>Response Length</small>
                </div>
            </div>
        </div>
        
        <?php if ($log['source'] === 'cache'): ?>
            <div class="alert alert-info mt-3">
                <i class="fas fa-bolt"></i> This response was served from cache for faster performance.
            </div>
        <?php elseif ($log['source'] === 'fallback'): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle"></i> This was a fallback response when AI systems were unavailable.
            </div>
        <?php endif; ?>
    </div>
</div>