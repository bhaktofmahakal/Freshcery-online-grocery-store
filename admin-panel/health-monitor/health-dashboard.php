<?php 
session_start();

if (!isset($_SESSION['adminname'])) {
    header("location: ../admins/login-admins.php");
    exit();
}

require "../../ai-system/health_monitor.php";

$monitor = new HealthMonitor();

// Run health check if requested
if (isset($_GET['check'])) {
    $healthResults = $monitor->runHealthCheck();
} else {
    // Get latest health check
    $history = $monitor->getHealthHistory(1);
    $healthResults = !empty($history) ? json_decode($history[0]['check_results'], true) : null;
}

// Get health statistics
$stats = $monitor->getHealthStats(7);
$history = $monitor->getHealthHistory(24);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Monitor - Freshcery Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../styles/style.css">
    <style>
        .health-card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .health-card:hover {
            transform: translateY(-5px);
        }
        
        .status-healthy { border-left: 5px solid #28a745; }
        .status-warning { border-left: 5px solid #ffc107; }
        .status-critical { border-left: 5px solid #dc3545; }
        
        .status-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .status-healthy .status-icon { color: #28a745; }
        .status-warning .status-icon { color: #ffc107; }
        .status-critical .status-icon { color: #dc3545; }
        
        .overall-status {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .alert-settings {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .health-timeline {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .timeline-item {
            border-left: 3px solid #dee2e6;
            padding-left: 15px;
            margin-bottom: 15px;
            position: relative;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dee2e6;
        }
        
        .timeline-item.healthy::before { background: #28a745; }
        .timeline-item.warning::before { background: #ffc107; }
        .timeline-item.critical::before { background: #dc3545; }
    </style>
</head>
<body>
    <?php include "../layouts/header.php"; ?>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-heartbeat"></i> System Health Monitor</h2>
                    <div>
                        <button class="btn btn-primary" onclick="runHealthCheck()">
                            <i class="fas fa-sync-alt"></i> Run Health Check
                        </button>
                        <button class="btn btn-info ml-2" onclick="toggleAutoRefresh()">
                            <i class="fas fa-clock"></i> <span id="autoRefreshText">Enable Auto-Refresh</span>
                        </button>
                    </div>
                </div>
                
                <?php if ($healthResults): ?>
                <!-- Overall Status -->
                <div class="overall-status">
                    <h1>
                        <?php if ($healthResults['overall_status'] === 'healthy'): ?>
                            <i class="fas fa-check-circle"></i> System Healthy
                        <?php elseif ($healthResults['overall_status'] === 'warning'): ?>
                            <i class="fas fa-exclamation-triangle"></i> System Warning
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i> System Critical
                        <?php endif; ?>
                    </h1>
                    <p>Last checked: <?= $healthResults['timestamp'] ?></p>
                </div>
                
                <!-- Component Status -->
                <div class="row">
                    <?php foreach ($healthResults['checks'] as $component => $check): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card health-card status-<?= $check['status'] ?>">
                                <div class="card-body text-center">
                                    <i class="fas fa-<?= getComponentIcon($component) ?> status-icon"></i>
                                    <h5><?= ucfirst(str_replace('_', ' ', $component)) ?></h5>
                                    <p><?= $check['message'] ?></p>
                                    <?php if (isset($check['details'])): ?>
                                        <small class="text-muted">
                                            <?php foreach ($check['details'] as $key => $value): ?>
                                                <?php if (is_array($value)): ?>
                                                    <?= ucfirst(str_replace('_', ' ', $key)) ?>:<br>
                                                    <?php foreach ($value as $subKey => $subValue): ?>
                                                        &nbsp;&nbsp;<?= ucfirst(str_replace('_', ' ', $subKey)) ?>: 
                                                        <?= is_numeric($subValue) ? number_format($subValue, 2) : $subValue ?><br>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <?= ucfirst(str_replace('_', ' ', $key)) ?>: 
                                                    <?= is_numeric($value) ? number_format($value, 2) : $value ?><br>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info text-center">
                    <h4><i class="fas fa-info-circle"></i> No Health Data Available</h4>
                    <p>Click "Run Health Check" to start monitoring system health.</p>
                    <button class="btn btn-primary" onclick="runHealthCheck()">
                        <i class="fas fa-play"></i> Run First Health Check
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Health History -->
                <?php if (!empty($history)): ?>
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="chart-container">
                            <h4><i class="fas fa-chart-line"></i> Health Trends (24 Hours)</h4>
                            <canvas id="healthChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="chart-container">
                            <h4><i class="fas fa-history"></i> Recent Events</h4>
                            <div class="health-timeline">
                                <?php foreach (array_slice($history, 0, 10) as $event): ?>
                                    <?php $eventData = json_decode($event['check_results'], true); ?>
                                    <div class="timeline-item <?= $event['overall_status'] ?>">
                                        <strong><?= ucfirst($event['overall_status']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= date('M j, H:i', strtotime($event['created_at'])) ?></small>
                                        <?php if ($event['overall_status'] !== 'healthy'): ?>
                                            <br>
                                            <small>
                                                <?php
                                                $issues = [];
                                                foreach ($eventData['checks'] as $comp => $check) {
                                                    if ($check['status'] !== 'healthy') {
                                                        $issues[] = ucfirst(str_replace('_', ' ', $comp));
                                                    }
                                                }
                                                echo implode(', ', $issues);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Alert Settings -->
                <div class="alert-settings">
                    <h4><i class="fas fa-bell"></i> Alert Configuration</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <form id="alertSettingsForm">
                                <div class="form-group">
                                    <label>Alert Email:</label>
                                    <input type="email" class="form-control" name="alert_email" value="utsavmishraa005@gmail.com">
                                </div>
                                <div class="form-group">
                                    <label>Max Response Time (seconds):</label>
                                    <input type="number" class="form-control" name="max_response_time" value="10" step="0.1">
                                </div>
                                <div class="form-group">
                                    <label>Max Error Rate (%):</label>
                                    <input type="number" class="form-control" name="max_error_rate" value="10">
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Min Cache Hit Rate (%):</label>
                                <input type="number" class="form-control" name="min_cache_hit_rate" value="30">
                            </div>
                            <div class="form-group">
                                <label>Disk Space Warning (%):</label>
                                <input type="number" class="form-control" name="disk_space_warning" value="85">
                            </div>
                            <button type="button" class="btn btn-success" onclick="saveAlertSettings()">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        let autoRefreshInterval = null;
        
        function runHealthCheck() {
            window.location.href = '?check=1';
        }
        
        function toggleAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                $('#autoRefreshText').text('Enable Auto-Refresh');
            } else {
                autoRefreshInterval = setInterval(() => {
                    runHealthCheck();
                }, 60000); // Every minute
                $('#autoRefreshText').text('Disable Auto-Refresh');
            }
        }
        
        function saveAlertSettings() {
            const formData = new FormData(document.getElementById('alertSettingsForm'));
            
            $.post('save-alert-settings.php', Object.fromEntries(formData), function(response) {
                if (response.success) {
                    alert('Alert settings saved successfully!');
                } else {
                    alert('Error saving settings: ' + response.message);
                }
            }, 'json').fail(function() {
                alert('Error saving alert settings');
            });
        }
        
        // Initialize health chart
        <?php if (!empty($history)): ?>
        const ctx = document.getElementById('healthChart').getContext('2d');
        const healthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [<?php 
                    $labels = array_reverse(array_slice($history, 0, 24));
                    echo implode(',', array_map(function($h) { 
                        return '"' . date('H:i', strtotime($h['created_at'])) . '"'; 
                    }, $labels));
                ?>],
                datasets: [{
                    label: 'System Health',
                    data: [<?php 
                        echo implode(',', array_map(function($h) { 
                            return $h['overall_status'] === 'healthy' ? 1 : ($h['overall_status'] === 'warning' ? 0.5 : 0); 
                        }, $labels));
                    ?>],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 1,
                        ticks: {
                            callback: function(value) {
                                return value === 1 ? 'Healthy' : (value === 0.5 ? 'Warning' : 'Critical');
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

<?php
function getComponentIcon($component) {
    $icons = [
        'database' => 'database',
        'ai_services' => 'brain',
        'cache' => 'bolt',
        'docker' => 'docker',
        'performance' => 'tachometer-alt',
        'disk_space' => 'hdd',
        'error_rates' => 'exclamation-triangle'
    ];
    return $icons[$component] ?? 'cog';
}
?>