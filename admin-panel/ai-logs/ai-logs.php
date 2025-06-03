<?php 
session_start();

if (!isset($_SESSION['adminname'])) {
    header("location: ../admins/login-admins.php");
    exit();
}

require "../../config/config.php";

// Pagination settings
$recordsPerPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $recordsPerPage;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sourceFilter = isset($_GET['source']) ? $_GET['source'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$userFilter = isset($_GET['user']) ? trim($_GET['user']) : '';

// Build WHERE clause
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(l.prompt LIKE ? OR l.response LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($sourceFilter)) {
    $whereConditions[] = "l.source = ?";
    $params[] = $sourceFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(l.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(l.created_at) <= ?";
    $params[] = $dateTo;
}

if (!empty($userFilter)) {
    $whereConditions[] = "(u.name LIKE ? OR l.user_id = ?)";
    $params[] = "%$userFilter%";
    $params[] = $userFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total records for pagination
$countQuery = "SELECT COUNT(*) as total FROM ai_logs $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Get logs with pagination
$query = "SELECT l.*, u.fullname as user_name 
          FROM ai_logs l 
          LEFT JOIN users u ON l.user_id = u.id 
          $whereClause 
          ORDER BY l.created_at DESC 
          LIMIT $recordsPerPage OFFSET $offset";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsQuery = "SELECT 
    COUNT(*) as total_queries,
    COUNT(CASE WHEN l.source = 'gemini' THEN 1 END) as gemini_count,
    COUNT(CASE WHEN l.source = 'ollama' THEN 1 END) as ollama_count,
    COUNT(CASE WHEN l.source = 'cache' THEN 1 END) as cache_count,
    COUNT(CASE WHEN l.source = 'fallback' THEN 1 END) as fallback_count,
    AVG(l.processing_time) as avg_processing_time,
    COUNT(DISTINCT l.ip_address) as unique_ips,
    COUNT(DISTINCT l.user_id) as unique_users,
    COUNT(CASE WHEN l.user_id IS NOT NULL THEN 1 END) as logged_in_queries,
    COUNT(CASE WHEN l.user_id IS NULL THEN 1 END) as guest_queries
FROM ai_logs l 
WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Logs - Freshcery Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../styles/style.css">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            display: block;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .source-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .source-gemini { background: #e3f2fd; color: #1976d2; }
        .source-ollama { background: #f3e5f5; color: #7b1fa2; }
        .source-cache { background: #e8f5e8; color: #388e3c; }
        .source-fallback { background: #fff3e0; color: #f57c00; }
        
        .log-prompt {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .log-response {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .filters-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .btn-export {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: white;
        }
        
        .btn-clear {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            color: white;
        }
    </style>
</head>
<body>
    <?php include "../layouts/header.php"; ?>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-robot"></i> AI Interaction Logs</h2>
                    <div>
                        <button class="btn btn-export" onclick="exportLogs()">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                        <button class="btn btn-clear ml-2" onclick="clearOldLogs()">
                            <i class="fas fa-trash"></i> Clear Old Logs
                        </button>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-card">
                    <div class="row">
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['total_queries']) ?></span>
                                <span class="stat-label">Total Queries (24h)</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['gemini_count']) ?></span>
                                <span class="stat-label">Gemini Responses</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['ollama_count']) ?></span>
                                <span class="stat-label">Ollama Responses</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['cache_count']) ?></span>
                                <span class="stat-label">Cached Responses</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['avg_processing_time'], 3) ?>s</span>
                                <span class="stat-label">Avg Response Time</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['unique_users']) ?></span>
                                <span class="stat-label">Unique Users</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['logged_in_queries']) ?></span>
                                <span class="stat-label">Logged-in Queries</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['guest_queries']) ?></span>
                                <span class="stat-label">Guest Queries</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="filters-card">
                    <form method="GET" class="row">
                        <div class="col-md-3">
                            <label>Search in Prompt/Response:</label>
                            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
                        </div>
                        <div class="col-md-2">
                            <label>Source:</label>
                            <select name="source" class="form-control">
                                <option value="">All Sources</option>
                                <option value="gemini" <?= $sourceFilter === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                                <option value="ollama" <?= $sourceFilter === 'ollama' ? 'selected' : '' ?>>Ollama</option>
                                <option value="cache" <?= $sourceFilter === 'cache' ? 'selected' : '' ?>>Cache</option>
                                <option value="fallback" <?= $sourceFilter === 'fallback' ? 'selected' : '' ?>>Fallback</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>User:</label>
                            <input type="text" name="user" class="form-control" value="<?= htmlspecialchars($userFilter) ?>" placeholder="Search by user...">
                        </div>
                        <div class="col-md-2">
                            <label>Date From:</label>
                            <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Date To:</label>
                            <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
                        </div>
                        <div class="col-md-3">
                            <label>&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="ai-logs.php" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Logs Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th>ID</th>
                                <th>Prompt</th>
                                <th>Response</th>
                                <th>Source</th>
                                <th>User</th>
                                <th>IP Address</th>
                                <th>Processing Time</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No AI logs found matching your criteria.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= $log['id'] ?></td>
                                        <td>
                                            <div class="log-prompt" title="<?= htmlspecialchars($log['prompt']) ?>">
                                                <?= htmlspecialchars($log['prompt']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="log-response" title="<?= htmlspecialchars($log['response']) ?>">
                                                <?= htmlspecialchars($log['response']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="source-badge source-<?= $log['source'] ?>">
                                                <?= ucfirst($log['source']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['user_id']): ?>
                                                <span class="badge badge-info">
                                                    <?= htmlspecialchars($log['user_name'] ?? 'User #' . $log['user_id']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Guest</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $log['ip_address'] ?></td>
                                        <td><?= number_format($log['processing_time'], 3) ?>s</td>
                                        <td><?= date('M j, Y H:i', strtotime($log['created_at'])) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="viewLogDetails(<?= $log['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteLog(<?= $log['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Log Details Modal -->
    <div class="modal fade" id="logDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">AI Log Details</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="logDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        function viewLogDetails(logId) {
            $.get('log-details.php?id=' + logId, function(data) {
                $('#logDetailsContent').html(data);
                $('#logDetailsModal').modal('show');
            }).fail(function() {
                alert('Error loading log details');
            });
        }
        
        function deleteLog(logId) {
            if (confirm('Are you sure you want to delete this log entry?')) {
                $.post('delete-log.php', {id: logId}, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error deleting log: ' + response.message);
                    }
                }, 'json').fail(function() {
                    alert('Error deleting log');
                });
            }
        }
        
        function exportLogs() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = 'export-logs.php?' + params.toString();
        }
        
        function clearOldLogs() {
            if (confirm('This will delete all logs older than 30 days. Are you sure?')) {
                $.post('clear-old-logs.php', function(response) {
                    if (response.success) {
                        alert('Old logs cleared successfully. Deleted ' + response.deleted + ' entries.');
                        location.reload();
                    } else {
                        alert('Error clearing logs: ' + response.message);
                    }
                }, 'json').fail(function() {
                    alert('Error clearing logs');
                });
            }
        }
    </script>
</body>
</html>