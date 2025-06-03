<?php
/**
 * Freshcery AI System - Health Monitor
 * Monitors system health and sends alerts when issues are detected
 */

require_once 'ai_router.php';
require_once __DIR__ . '/../config/config.php';

class HealthMonitor {
    private $ai;
    private $pdo;
    private $alertsEnabled;
    private $alertEmail;
    private $thresholds;
    
    public function __construct() {
        $this->loadEnv();
        $this->ai = new FreshceryAI();
        global $conn;
        $this->pdo = $conn;
        
        $this->alertsEnabled = $_ENV['HEALTH_ALERTS_ENABLED'] ?? true;
        $this->alertEmail = $_ENV['ALERT_EMAIL'] ?? 'admin@freshcery.com';
        
        $this->thresholds = [
            'max_response_time' => $_ENV['MAX_RESPONSE_TIME'] ?? 10.0, // seconds
            'max_error_rate' => $_ENV['MAX_ERROR_RATE'] ?? 10, // percentage
            'min_cache_hit_rate' => $_ENV['MIN_CACHE_HIT_RATE'] ?? 30, // percentage
            'max_queue_size' => $_ENV['MAX_QUEUE_SIZE'] ?? 100,
            'disk_space_warning' => $_ENV['DISK_SPACE_WARNING'] ?? 85 // percentage
        ];
    }
    
    private function loadEnv() {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    /**
     * Run complete health check
     */
    public function runHealthCheck() {
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'healthy',
            'checks' => []
        ];
        
        // Check all components
        $results['checks']['database'] = $this->checkDatabase();
        $results['checks']['ai_services'] = $this->checkAIServices();
        $results['checks']['cache'] = $this->checkCache();
        $results['checks']['docker'] = $this->checkDockerServices();
        $results['checks']['performance'] = $this->checkPerformance();
        $results['checks']['disk_space'] = $this->checkDiskSpace();
        $results['checks']['error_rates'] = $this->checkErrorRates();
        
        // Determine overall status
        foreach ($results['checks'] as $check) {
            if ($check['status'] === 'critical') {
                $results['overall_status'] = 'critical';
                break;
            } elseif ($check['status'] === 'warning' && $results['overall_status'] === 'healthy') {
                $results['overall_status'] = 'warning';
            }
        }
        
        // Log health check
        $this->logHealthCheck($results);
        
        // Send alerts if needed
        if ($results['overall_status'] !== 'healthy') {
            $this->sendAlert($results);
        }
        
        return $results;
    }
    
    private function checkDatabase() {
        try {
            $start = microtime(true);
            $stmt = $this->pdo->query("SELECT 1");
            $responseTime = (microtime(true) - $start) * 1000;
            
            if ($responseTime > 1000) { // 1 second
                return [
                    'status' => 'warning',
                    'message' => 'Database response time is slow',
                    'details' => ['response_time_ms' => round($responseTime, 2)]
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Database connection is healthy',
                'details' => ['response_time_ms' => round($responseTime, 2)]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Database connection failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }
    
    private function checkAIServices() {
        $systemStatus = $this->ai->getSystemStatus();
        $issues = [];
        
        if ($systemStatus['gemini']['status'] !== 'available') {
            $issues[] = 'Gemini API unavailable';
        }
        
        if ($systemStatus['ollama']['status'] !== 'available') {
            $issues[] = 'Ollama service unavailable';
        }
        
        if (count($issues) === 2) {
            return [
                'status' => 'critical',
                'message' => 'All AI services are down',
                'details' => ['issues' => $issues]
            ];
        } elseif (count($issues) === 1) {
            return [
                'status' => 'warning',
                'message' => 'One AI service is down',
                'details' => ['issues' => $issues]
            ];
        }
        
        return [
            'status' => 'healthy',
            'message' => 'All AI services are operational',
            'details' => $systemStatus
        ];
    }
    
    private function checkCache() {
        try {
            $cache = new RedisCache();
            $stats = $cache->getCacheStats();
            
            if (!$cache->isAvailable()) {
                return [
                    'status' => 'warning',
                    'message' => 'Redis cache unavailable, using file fallback',
                    'details' => ['fallback_active' => true]
                ];
            }
            
            $hitRate = $stats['hit_rate'] ?? 0;
            if ($hitRate < $this->thresholds['min_cache_hit_rate']) {
                return [
                    'status' => 'warning',
                    'message' => 'Cache hit rate is low',
                    'details' => ['hit_rate' => $hitRate, 'threshold' => $this->thresholds['min_cache_hit_rate']]
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Cache is performing well',
                'details' => $stats
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Cache check failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }
    
    private function checkDockerServices() {
        $services = ['freshcery-redis', 'freshcery-ollama'];
        $issues = [];
        
        foreach ($services as $service) {
            $output = [];
            exec("docker ps --filter \"name=$service\" --format \"{{.Status}}\" 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0 || empty($output) || strpos($output[0], 'Up') === false) {
                $issues[] = "$service container not running";
            }
        }
        
        if (count($issues) > 0) {
            return [
                'status' => 'warning',
                'message' => 'Some Docker services are down',
                'details' => ['issues' => $issues]
            ];
        }
        
        return [
            'status' => 'healthy',
            'message' => 'All Docker services are running',
            'details' => ['services' => $services]
        ];
    }
    
    private function checkPerformance() {
        try {
            // Check average response time in last hour
            $stmt = $this->pdo->prepare("
                SELECT AVG(processing_time) as avg_time, COUNT(*) as total_requests
                FROM ai_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $avgTime = $result['avg_time'] ?? 0;
            $totalRequests = $result['total_requests'] ?? 0;
            
            if ($avgTime > $this->thresholds['max_response_time']) {
                return [
                    'status' => 'warning',
                    'message' => 'Average response time is high',
                    'details' => [
                        'avg_response_time' => round($avgTime, 3),
                        'threshold' => $this->thresholds['max_response_time'],
                        'requests_last_hour' => $totalRequests
                    ]
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Performance is within normal range',
                'details' => [
                    'avg_response_time' => round($avgTime, 3),
                    'requests_last_hour' => $totalRequests
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Performance check failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }
    
    private function checkDiskSpace() {
        $totalSpace = disk_total_space('.');
        $freeSpace = disk_free_space('.');
        $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;
        
        if ($usedPercentage > $this->thresholds['disk_space_warning']) {
            return [
                'status' => 'warning',
                'message' => 'Disk space is running low',
                'details' => [
                    'used_percentage' => round($usedPercentage, 2),
                    'free_space_gb' => round($freeSpace / (1024*1024*1024), 2),
                    'total_space_gb' => round($totalSpace / (1024*1024*1024), 2)
                ]
            ];
        }
        
        return [
            'status' => 'healthy',
            'message' => 'Disk space is adequate',
            'details' => [
                'used_percentage' => round($usedPercentage, 2),
                'free_space_gb' => round($freeSpace / (1024*1024*1024), 2)
            ]
        ];
    }
    
    private function checkErrorRates() {
        try {
            // Check error rate in last hour
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN source = 'fallback' THEN 1 END) as fallback_requests
                FROM ai_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $totalRequests = $result['total_requests'] ?? 0;
            $fallbackRequests = $result['fallback_requests'] ?? 0;
            
            if ($totalRequests === 0) {
                return [
                    'status' => 'healthy',
                    'message' => 'No requests in the last hour',
                    'details' => ['total_requests' => 0]
                ];
            }
            
            $errorRate = ($fallbackRequests / $totalRequests) * 100;
            
            if ($errorRate > $this->thresholds['max_error_rate']) {
                return [
                    'status' => 'warning',
                    'message' => 'Error rate is high',
                    'details' => [
                        'error_rate' => round($errorRate, 2),
                        'fallback_requests' => $fallbackRequests,
                        'total_requests' => $totalRequests
                    ]
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Error rate is within acceptable range',
                'details' => [
                    'error_rate' => round($errorRate, 2),
                    'total_requests' => $totalRequests
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Error rate check failed',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }
    
    private function logHealthCheck($results) {
        try {
            // Create health_logs table if it doesn't exist
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS health_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    overall_status ENUM('healthy', 'warning', 'critical') NOT NULL,
                    check_results JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_status (overall_status),
                    INDEX idx_created_at (created_at)
                )
            ");
            
            $stmt = $this->pdo->prepare("
                INSERT INTO health_logs (overall_status, check_results) 
                VALUES (?, ?)
            ");
            $stmt->execute([$results['overall_status'], json_encode($results)]);
            
        } catch (Exception $e) {
            error_log("Health check logging failed: " . $e->getMessage());
        }
    }
    
    private function sendAlert($results) {
        if (!$this->alertsEnabled) {
            return;
        }
        
        $subject = "🚨 Freshcery AI System Alert - " . ucfirst($results['overall_status']);
        $message = $this->formatAlertMessage($results);
        
        // Log alert
        error_log("HEALTH ALERT: " . $subject . "\n" . $message);
        
        // Send email alert (if configured)
        if ($this->alertEmail && function_exists('mail')) {
            $headers = "From: noreply@freshcery.com\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            mail($this->alertEmail, $subject, $message, $headers);
        }
        
        // Send webhook alert (if configured)
        $webhookUrl = $_ENV['ALERT_WEBHOOK_URL'] ?? null;
        if ($webhookUrl) {
            $this->sendWebhookAlert($webhookUrl, $results);
        }
    }
    
    private function formatAlertMessage($results) {
        $html = "<h2>🚨 Freshcery AI System Health Alert</h2>";
        $html .= "<p><strong>Overall Status:</strong> " . ucfirst($results['overall_status']) . "</p>";
        $html .= "<p><strong>Timestamp:</strong> " . $results['timestamp'] . "</p>";
        
        $html .= "<h3>Component Status:</h3>";
        $html .= "<table border='1' cellpadding='5' cellspacing='0'>";
        $html .= "<tr><th>Component</th><th>Status</th><th>Message</th></tr>";
        
        foreach ($results['checks'] as $component => $check) {
            $statusColor = $check['status'] === 'healthy' ? 'green' : ($check['status'] === 'warning' ? 'orange' : 'red');
            $html .= "<tr>";
            $html .= "<td>" . ucfirst(str_replace('_', ' ', $component)) . "</td>";
            $html .= "<td style='color: $statusColor'>" . ucfirst($check['status']) . "</td>";
            $html .= "<td>" . $check['message'] . "</td>";
            $html .= "</tr>";
        }
        
        $html .= "</table>";
        $html .= "<p><a href='http://localhost/freshcery/system-status.php'>View System Status</a></p>";
        
        return $html;
    }
    
    private function sendWebhookAlert($webhookUrl, $results) {
        $payload = [
            'text' => "🚨 Freshcery AI System Alert",
            'attachments' => [
                [
                    'color' => $results['overall_status'] === 'critical' ? 'danger' : 'warning',
                    'title' => 'System Health Status: ' . ucfirst($results['overall_status']),
                    'fields' => []
                ]
            ]
        ];
        
        foreach ($results['checks'] as $component => $check) {
            if ($check['status'] !== 'healthy') {
                $payload['attachments'][0]['fields'][] = [
                    'title' => ucfirst(str_replace('_', ' ', $component)),
                    'value' => $check['message'],
                    'short' => true
                ];
            }
        }
        
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Get health history
     */
    public function getHealthHistory($hours = 24) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM health_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY created_at DESC
            ");
            $stmt->execute([$hours]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get health statistics
     */
    public function getHealthStats($days = 7) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    overall_status,
                    COUNT(*) as count,
                    DATE(created_at) as date
                FROM health_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY overall_status, DATE(created_at)
                ORDER BY date DESC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    $monitor = new HealthMonitor();
    $results = $monitor->runHealthCheck();
    
    echo "Health Check Results:\n";
    echo "Overall Status: " . $results['overall_status'] . "\n";
    echo "Timestamp: " . $results['timestamp'] . "\n\n";
    
    foreach ($results['checks'] as $component => $check) {
        echo ucfirst(str_replace('_', ' ', $component)) . ": " . $check['status'] . " - " . $check['message'] . "\n";
    }
}
?>