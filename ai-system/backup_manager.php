<?php
/**
 * Freshcery AI System - Backup Manager
 * Automated backup system for AI logs and system data
 */

require_once __DIR__ . '/../config/config.php';

class BackupManager {
    private $pdo;
    private $backupDir;
    private $maxBackups;
    private $compressionEnabled;
    
    public function __construct() {
        $this->loadEnv();
        global $conn;
        $this->pdo = $conn;
        
        $this->backupDir = $_ENV['BACKUP_DIR'] ?? __DIR__ . '/backups';
        $this->maxBackups = $_ENV['MAX_BACKUPS'] ?? 30;
        $this->compressionEnabled = $_ENV['BACKUP_COMPRESSION'] ?? true;
        
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
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
     * Create full backup of AI logs
     */
    public function createFullBackup() {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $this->backupDir . "/ai_logs_full_backup_{$timestamp}";
        
        try {
            // Export AI logs
            $aiLogsFile = $backupFile . '_ai_logs.sql';
            $this->exportTable('ai_logs', $aiLogsFile);
            
            // Export health logs
            $healthLogsFile = $backupFile . '_health_logs.sql';
            $this->exportTable('health_logs', $healthLogsFile);
            
            // Create metadata file
            $metadataFile = $backupFile . '_metadata.json';
            $this->createMetadataFile($metadataFile, 'full');
            
            // Compress if enabled
            if ($this->compressionEnabled) {
                $zipFile = $backupFile . '.zip';
                $this->compressFiles($zipFile, [
                    $aiLogsFile,
                    $healthLogsFile,
                    $metadataFile
                ]);
                
                // Remove individual files
                unlink($aiLogsFile);
                unlink($healthLogsFile);
                unlink($metadataFile);
                
                $finalFile = $zipFile;
            } else {
                $finalFile = $backupFile . '_ai_logs.sql';
            }
            
            $this->logBackup('full', $finalFile, filesize($finalFile));
            $this->cleanupOldBackups();
            
            return [
                'success' => true,
                'file' => $finalFile,
                'size' => filesize($finalFile),
                'timestamp' => $timestamp
            ];
            
        } catch (Exception $e) {
            $this->logError("Full backup failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create incremental backup (only new logs since last backup)
     */
    public function createIncrementalBackup() {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $this->backupDir . "/ai_logs_incremental_backup_{$timestamp}";
        
        try {
            // Get last backup timestamp
            $lastBackupTime = $this->getLastBackupTime();
            
            // Export new AI logs
            $aiLogsFile = $backupFile . '_ai_logs.sql';
            $this->exportTableIncremental('ai_logs', $aiLogsFile, $lastBackupTime);
            
            // Export new health logs
            $healthLogsFile = $backupFile . '_health_logs.sql';
            $this->exportTableIncremental('health_logs', $healthLogsFile, $lastBackupTime);
            
            // Create metadata file
            $metadataFile = $backupFile . '_metadata.json';
            $this->createMetadataFile($metadataFile, 'incremental', $lastBackupTime);
            
            // Compress if enabled
            if ($this->compressionEnabled) {
                $zipFile = $backupFile . '.zip';
                $this->compressFiles($zipFile, [
                    $aiLogsFile,
                    $healthLogsFile,
                    $metadataFile
                ]);
                
                // Remove individual files
                unlink($aiLogsFile);
                unlink($healthLogsFile);
                unlink($metadataFile);
                
                $finalFile = $zipFile;
            } else {
                $finalFile = $backupFile . '_ai_logs.sql';
            }
            
            $this->logBackup('incremental', $finalFile, filesize($finalFile));
            
            return [
                'success' => true,
                'file' => $finalFile,
                'size' => filesize($finalFile),
                'timestamp' => $timestamp,
                'since' => $lastBackupTime
            ];
            
        } catch (Exception $e) {
            $this->logError("Incremental backup failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Export table to SQL file
     */
    private function exportTable($tableName, $outputFile) {
        $sql = "SELECT * FROM {$tableName}";
        $stmt = $this->pdo->query($sql);
        
        $output = "-- Freshcery AI System Backup\n";
        $output .= "-- Table: {$tableName}\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Get table structure
        $createStmt = $this->pdo->query("SHOW CREATE TABLE {$tableName}");
        $createTable = $createStmt->fetch(PDO::FETCH_ASSOC);
        $output .= $createTable['Create Table'] . ";\n\n";
        
        // Export data
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(function($value) {
                return $value === null ? 'NULL' : $this->pdo->quote($value);
            }, array_values($row));
            
            $output .= "INSERT INTO {$tableName} VALUES (" . implode(', ', $values) . ");\n";
        }
        
        file_put_contents($outputFile, $output);
    }
    
    /**
     * Export table incrementally (only new records)
     */
    private function exportTableIncremental($tableName, $outputFile, $since) {
        $sql = "SELECT * FROM {$tableName} WHERE created_at > ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$since]);
        
        $output = "-- Freshcery AI System Incremental Backup\n";
        $output .= "-- Table: {$tableName}\n";
        $output .= "-- Since: {$since}\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $recordCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(function($value) {
                return $value === null ? 'NULL' : $this->pdo->quote($value);
            }, array_values($row));
            
            $output .= "INSERT INTO {$tableName} VALUES (" . implode(', ', $values) . ");\n";
            $recordCount++;
        }
        
        $output .= "\n-- Records exported: {$recordCount}\n";
        file_put_contents($outputFile, $output);
    }
    
    /**
     * Create metadata file for backup
     */
    private function createMetadataFile($outputFile, $type, $since = null) {
        $metadata = [
            'backup_type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
            'since' => $since,
            'system_info' => [
                'php_version' => phpversion(),
                'server' => $_SERVER['SERVER_NAME'] ?? 'localhost',
                'backup_version' => '1.0'
            ],
            'tables' => []
        ];
        
        // Get table statistics
        $tables = ['ai_logs', 'health_logs'];
        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                $metadata['tables'][$table] = ['record_count' => $count];
            } catch (Exception $e) {
                $metadata['tables'][$table] = ['error' => $e->getMessage()];
            }
        }
        
        file_put_contents($outputFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }
    
    /**
     * Compress files into ZIP archive
     */
    private function compressFiles($zipFile, $files) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Cannot create ZIP file: {$zipFile}");
        }
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $zip->addFile($file, basename($file));
            }
        }
        
        $zip->close();
    }
    
    /**
     * Get timestamp of last backup
     */
    private function getLastBackupTime() {
        try {
            $stmt = $this->pdo->query("
                SELECT MAX(created_at) as last_backup 
                FROM backup_logs 
                WHERE status = 'success'
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['last_backup'] ?? '1970-01-01 00:00:00';
        } catch (Exception $e) {
            return '1970-01-01 00:00:00';
        }
    }
    
    /**
     * Log backup operation
     */
    private function logBackup($type, $file, $size) {
        try {
            // Create backup_logs table if it doesn't exist
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS backup_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    backup_type ENUM('full', 'incremental') NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    file_size BIGINT NOT NULL,
                    status ENUM('success', 'failed') DEFAULT 'success',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_type (backup_type),
                    INDEX idx_created_at (created_at)
                )
            ");
            
            $stmt = $this->pdo->prepare("
                INSERT INTO backup_logs (backup_type, file_path, file_size, status) 
                VALUES (?, ?, ?, 'success')
            ");
            $stmt->execute([$type, $file, $size]);
            
        } catch (Exception $e) {
            error_log("Backup logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old backup files
     */
    private function cleanupOldBackups() {
        try {
            // Get list of backup files
            $files = glob($this->backupDir . '/ai_logs_*');
            
            // Sort by modification time (newest first)
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            // Remove old files beyond max limit
            $filesToDelete = array_slice($files, $this->maxBackups);
            foreach ($filesToDelete as $file) {
                if (file_exists($file)) {
                    unlink($file);
                    $this->logInfo("Deleted old backup file: " . basename($file));
                }
            }
            
            // Clean up database logs
            $this->pdo->exec("
                DELETE FROM backup_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL {$this->maxBackups} DAY)
            ");
            
        } catch (Exception $e) {
            $this->logError("Backup cleanup failed: " . $e->getMessage());
        }
    }
    
    /**
     * Restore from backup file
     */
    public function restoreFromBackup($backupFile) {
        if (!file_exists($backupFile)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }
        
        try {
            $tempDir = sys_get_temp_dir() . '/restore_' . uniqid();
            mkdir($tempDir, 0755, true);
            
            // Extract if ZIP file
            if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($backupFile) === TRUE) {
                    $zip->extractTo($tempDir);
                    $zip->close();
                } else {
                    throw new Exception("Cannot extract ZIP file");
                }
            } else {
                copy($backupFile, $tempDir . '/' . basename($backupFile));
            }
            
            // Find SQL files and execute them
            $sqlFiles = glob($tempDir . '/*.sql');
            foreach ($sqlFiles as $sqlFile) {
                $sql = file_get_contents($sqlFile);
                $this->pdo->exec($sql);
            }
            
            // Cleanup temp directory
            array_map('unlink', glob($tempDir . '/*'));
            rmdir($tempDir);
            
            $this->logInfo("Restore completed from: " . basename($backupFile));
            
            return [
                'success' => true,
                'message' => 'Backup restored successfully',
                'file' => basename($backupFile)
            ];
            
        } catch (Exception $e) {
            $this->logError("Restore failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get backup history
     */
    public function getBackupHistory($limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM backup_logs 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get backup statistics
     */
    public function getBackupStats() {
        try {
            $stats = [];
            
            // Total backups
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM backup_logs");
            $stats['total_backups'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Backup size
            $stmt = $this->pdo->query("SELECT SUM(file_size) as total_size FROM backup_logs");
            $stats['total_size'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_size'];
            
            // Last backup
            $stmt = $this->pdo->query("SELECT MAX(created_at) as last_backup FROM backup_logs WHERE status = 'success'");
            $stats['last_backup'] = $stmt->fetch(PDO::FETCH_ASSOC)['last_backup'];
            
            // Available backup files
            $files = glob($this->backupDir . '/ai_logs_*');
            $stats['available_files'] = count($files);
            
            return $stats;
            
        } catch (Exception $e) {
            return [
                'total_backups' => 0,
                'total_size' => 0,
                'last_backup' => null,
                'available_files' => 0
            ];
        }
    }
    
    /**
     * Schedule automatic backups
     */
    public function scheduleBackups() {
        // This would typically be called by a cron job
        $lastFullBackup = $this->getLastFullBackupTime();
        $hoursSinceLastFull = (time() - strtotime($lastFullBackup)) / 3600;
        
        // Full backup every 24 hours
        if ($hoursSinceLastFull >= 24) {
            return $this->createFullBackup();
        }
        
        // Incremental backup every 6 hours
        $lastBackup = $this->getLastBackupTime();
        $hoursSinceLastBackup = (time() - strtotime($lastBackup)) / 3600;
        
        if ($hoursSinceLastBackup >= 6) {
            return $this->createIncrementalBackup();
        }
        
        return ['success' => true, 'message' => 'No backup needed at this time'];
    }
    
    private function getLastFullBackupTime() {
        try {
            $stmt = $this->pdo->query("
                SELECT MAX(created_at) as last_backup 
                FROM backup_logs 
                WHERE backup_type = 'full' AND status = 'success'
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['last_backup'] ?? '1970-01-01 00:00:00';
        } catch (Exception $e) {
            return '1970-01-01 00:00:00';
        }
    }
    
    private function logError($message) {
        error_log("[Freshcery Backup] ERROR: " . $message);
    }
    
    private function logInfo($message) {
        if ($_ENV['DEBUG_MODE'] ?? false) {
            error_log("[Freshcery Backup] INFO: " . $message);
        }
    }
}

// CLI usage for cron jobs
if (php_sapi_name() === 'cli') {
    $backup = new BackupManager();
    $result = $backup->scheduleBackups();
    
    echo "Backup Scheduler Result:\n";
    echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
    echo "Message: " . $result['message'] . "\n";
    
    if (isset($result['file'])) {
        echo "File: " . $result['file'] . "\n";
        echo "Size: " . number_format($result['size']) . " bytes\n";
    }
}
?>