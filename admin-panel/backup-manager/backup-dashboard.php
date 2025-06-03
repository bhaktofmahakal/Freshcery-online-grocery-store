<?php 
session_start();

if (!isset($_SESSION['adminname'])) {
    header("location: ../admins/login-admins.php");
    exit();
}

require "../../ai-system/backup_manager.php";

$backup = new BackupManager();

// Handle backup actions
$message = '';
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'full_backup':
            $result = $backup->createFullBackup();
            $message = $result['success'] ? 
                "✅ Full backup created successfully! File: " . basename($result['file']) : 
                "❌ Backup failed: " . $result['error'];
            break;
            
        case 'incremental_backup':
            $result = $backup->createIncrementalBackup();
            $message = $result['success'] ? 
                "✅ Incremental backup created successfully! File: " . basename($result['file']) : 
                "❌ Backup failed: " . $result['error'];
            break;
            
        case 'restore':
            if (isset($_POST['backup_file'])) {
                $result = $backup->restoreFromBackup($_POST['backup_file']);
                $message = $result['success'] ? 
                    "✅ Backup restored successfully!" : 
                    "❌ Restore failed: " . $result['error'];
            }
            break;
    }
}

// Get backup data
$history = $backup->getBackupHistory(20);
$stats = $backup->getBackupStats();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager - Freshcery Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../styles/style.css">
    <style>
        .backup-card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .backup-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
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
        
        .backup-actions {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .backup-history {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .backup-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        
        .backup-item:last-child {
            border-bottom: none;
        }
        
        .status-success { color: #28a745; }
        .status-failed { color: #dc3545; }
        
        .btn-backup {
            margin: 5px;
            border-radius: 25px;
            padding: 10px 20px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include "../layouts/header.php"; ?>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-archive"></i> Backup Manager</h2>
                    <div>
                        <button class="btn btn-info" onclick="refreshPage()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <?= $message ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="stats-header">
                    <h1><i class="fas fa-chart-bar"></i> Backup Statistics</h1>
                    <div class="row mt-4">
                        <div class="col-md-3">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['total_backups']) ?></span>
                                <span class="stat-label">Total Backups</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-item">
                                <span class="stat-number"><?= number_format($stats['total_size'] / (1024*1024), 1) ?>MB</span>
                                <span class="stat-label">Total Size</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-item">
                                <span class="stat-number"><?= $stats['available_files'] ?></span>
                                <span class="stat-label">Available Files</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-item">
                                <span class="stat-number">
                                    <?= $stats['last_backup'] ? date('M j', strtotime($stats['last_backup'])) : 'Never' ?>
                                </span>
                                <span class="stat-label">Last Backup</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Backup Actions -->
                <div class="backup-actions">
                    <h4><i class="fas fa-tools"></i> Backup Actions</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="full_backup">
                                <button type="submit" class="btn btn-primary btn-backup" onclick="return confirm('Create full backup? This may take a few minutes.')">
                                    <i class="fas fa-database"></i> Create Full Backup
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="incremental_backup">
                                <button type="submit" class="btn btn-success btn-backup">
                                    <i class="fas fa-plus"></i> Create Incremental Backup
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-warning btn-backup" onclick="scheduleBackup()">
                                <i class="fas fa-clock"></i> Schedule Auto Backup
                            </button>
                            
                            <button class="btn btn-info btn-backup" onclick="downloadBackup()">
                                <i class="fas fa-download"></i> Download Latest Backup
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h5>Restore from Backup</h5>
                        <form method="POST" class="form-inline">
                            <input type="hidden" name="action" value="restore">
                            <select name="backup_file" class="form-control mr-2" required>
                                <option value="">Select backup file...</option>
                                <?php foreach ($history as $backup): ?>
                                    <?php if ($backup['status'] === 'success'): ?>
                                        <option value="<?= $backup['file_path'] ?>">
                                            <?= basename($backup['file_path']) ?> 
                                            (<?= date('M j, Y H:i', strtotime($backup['created_at'])) ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Restore from backup? This will overwrite current data!')">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Backup History -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card backup-card">
                            <div class="card-header">
                                <h4><i class="fas fa-history"></i> Backup History</h4>
                            </div>
                            <div class="card-body">
                                <?php if (empty($history)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No backup history found. Create your first backup!</p>
                                    </div>
                                <?php else: ?>
                                    <div class="backup-history">
                                        <?php foreach ($history as $backup): ?>
                                            <div class="backup-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?= ucfirst($backup['backup_type']) ?> Backup</strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= basename($backup['file_path']) ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="status-<?= $backup['status'] ?>">
                                                            <i class="fas fa-<?= $backup['status'] === 'success' ? 'check-circle' : 'times-circle' ?>"></i>
                                                            <?= ucfirst($backup['status']) ?>
                                                        </span>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= date('M j, Y H:i', strtotime($backup['created_at'])) ?>
                                                        </small>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= number_format($backup['file_size'] / (1024*1024), 2) ?>MB
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Backup Configuration -->
                <div class="card backup-card">
                    <div class="card-header">
                        <h4><i class="fas fa-cog"></i> Backup Configuration</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Current Settings</h5>
                                <ul class="list-unstyled">
                                    <li><strong>Backup Directory:</strong> <?= $_ENV['BACKUP_DIR'] ?? 'Default' ?></li>
                                    <li><strong>Max Backups:</strong> <?= $_ENV['MAX_BACKUPS'] ?? '30' ?></li>
                                    <li><strong>Compression:</strong> <?= ($_ENV['BACKUP_COMPRESSION'] ?? true) ? 'Enabled' : 'Disabled' ?></li>
                                    <li><strong>Auto Backup:</strong> <?= ($_ENV['AUTO_BACKUP_ENABLED'] ?? true) ? 'Enabled' : 'Disabled' ?></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h5>Recommended Schedule</h5>
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-clock text-primary"></i> <strong>Full Backup:</strong> Daily at 2:00 AM</li>
                                    <li><i class="fas fa-clock text-success"></i> <strong>Incremental:</strong> Every 6 hours</li>
                                    <li><i class="fas fa-trash text-warning"></i> <strong>Cleanup:</strong> Keep 30 days</li>
                                    <li><i class="fas fa-compress text-info"></i> <strong>Compression:</strong> Enabled</li>
                                </ul>
                                
                                <div class="mt-3">
                                    <h6>Setup Cron Job (Linux/Mac):</h6>
                                    <code style="font-size: 0.8rem;">
                                        0 2 * * * php /path/to/freshcery/ai-system/backup_manager.php
                                    </code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        function refreshPage() {
            location.reload();
        }
        
        function scheduleBackup() {
            $.post('schedule-backup.php', function(response) {
                if (response.success) {
                    alert('✅ ' + response.message);
                    if (response.file) {
                        alert('Backup created: ' + response.file);
                    }
                    location.reload();
                } else {
                    alert('❌ Error: ' + response.error);
                }
            }, 'json').fail(function() {
                alert('Error scheduling backup');
            });
        }
        
        function downloadBackup() {
            <?php if (!empty($history)): ?>
                const latestBackup = '<?= basename($history[0]['file_path']) ?>';
                window.location.href = 'download-backup.php?file=' + encodeURIComponent(latestBackup);
            <?php else: ?>
                alert('No backups available for download');
            <?php endif; ?>
        }
    </script>
</body>
</html>