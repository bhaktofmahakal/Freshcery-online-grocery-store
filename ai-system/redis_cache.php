<?php
/**
 * Freshcery AI System - Redis Cache Management
 * Smart caching for AI responses to improve performance and reduce costs
 */

class RedisCache {
    private $redis;
    private $host;
    private $port;
    private $password;
    private $defaultTTL;
    private $connected = false;
    
    public function __construct() {
        $this->loadEnv();
        $this->host = $_ENV['REDIS_HOST'] ?? 'localhost';
        $this->port = $_ENV['REDIS_PORT'] ?? 6379;
        $this->password = $_ENV['REDIS_PASSWORD'] ?? '';
        $this->defaultTTL = $_ENV['CACHE_TTL'] ?? 3600;
        
        $this->connect();
    }
    
    private function loadEnv() {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    private function connect() {
        if (!extension_loaded('redis')) {
            // Use pure PHP Redis client as fallback
            require_once __DIR__ . '/pure_redis_client.php';
            $this->logError("Redis PHP extension not installed - using pure PHP Redis client");
        }
        
        try {
            $this->redis = new Redis();
            $this->connected = $this->redis->connect($this->host, $this->port, 5); // 5 second timeout
            
            if ($this->connected && !empty($this->password)) {
                $this->redis->auth($this->password);
            }
            
            if ($this->connected) {
                $this->redis->select(0); // Use database 0
                $this->logSuccess("Connected to Redis successfully");
            }
            
        } catch (Exception $e) {
            $this->logError("Redis connection failed: " . $e->getMessage());
            $this->connected = false;
        }
        
        return $this->connected;
    }
    
    /**
     * Get cached response
     */
    public function getFromCache($key) {
        if (!$this->connected) {
            return $this->getFromFileCache($key);
        }
        
        try {
            $cacheKey = $this->generateCacheKey($key);
            $cached = $this->redis->get($cacheKey);
            
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logSuccess("Cache hit for key: " . $cacheKey);
                    return $data;
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->logError("Cache get error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Save response to cache
     */
    public function saveToCache($key, $value, $ttl = null) {
        if (!$this->connected) {
            return $this->saveToFileCache($key, $value, $ttl);
        }
        
        try {
            $cacheKey = $this->generateCacheKey($key);
            $ttl = $ttl ?? $this->defaultTTL;
            
            $cacheData = [
                'response' => $value,
                'timestamp' => time(),
                'source' => 'freshcery_ai',
                'ttl' => $ttl
            ];
            
            $result = $this->redis->setex($cacheKey, $ttl, json_encode($cacheData));
            
            if ($result) {
                $this->logSuccess("Cached response for key: " . $cacheKey);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logError("Cache save error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate cache key from prompt
     */
    private function generateCacheKey($prompt) {
        // Normalize the prompt for consistent caching
        $normalized = strtolower(trim($prompt));
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize whitespace
        $normalized = preg_replace('/[^\w\s]/', '', $normalized); // Remove special chars
        
        return 'freshcery_ai:' . md5($normalized);
    }
    
    /**
     * Clear cache by pattern
     */
    public function clearCache($pattern = '*') {
        if (!$this->connected) {
            return false;
        }
        
        try {
            $keys = $this->redis->keys('freshcery_ai:' . $pattern);
            if (!empty($keys)) {
                $deleted = $this->redis->del($keys);
                $this->logSuccess("Cleared {$deleted} cache entries");
                return $deleted;
            }
            return 0;
            
        } catch (Exception $e) {
            $this->logError("Cache clear error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        if (!$this->connected) {
            return null;
        }
        
        try {
            $info = $this->redis->info();
            $keys = $this->redis->keys('freshcery_ai:*');
            
            return [
                'connected' => true,
                'total_keys' => count($keys),
                'memory_used' => $info['used_memory_human'] ?? 'Unknown',
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info)
            ];
            
        } catch (Exception $e) {
            $this->logError("Cache stats error: " . $e->getMessage());
            return null;
        }
    }
    
    private function calculateHitRate($info) {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        if ($total === 0) return 0;
        
        return round(($hits / $total) * 100, 2);
    }
    
    /**
     * Check if Redis is available
     */
    public function isAvailable() {
        return $this->connected;
    }
    
    /**
     * Test Redis connection
     */
    public function testConnection() {
        if (!$this->connected) {
            return ['status' => 'disconnected', 'message' => 'Not connected to Redis'];
        }
        
        try {
            $pong = $this->redis->ping();
            if ($pong === '+PONG' || $pong === 'PONG') {
                return ['status' => 'connected', 'message' => 'Redis connection is healthy'];
            } else {
                return ['status' => 'error', 'message' => 'Unexpected ping response: ' . $pong];
            }
            
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Ping failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Rate limiting functionality
     */
    public function checkRateLimit($identifier, $maxRequests = null, $timeWindow = 60) {
        $maxRequests = $maxRequests ?? ($_ENV['MAX_REQUESTS_PER_MINUTE'] ?? 10);
        $rateLimitEnabled = $_ENV['RATE_LIMIT_ENABLED'] ?? true;
        
        if (!$rateLimitEnabled) {
            return true;
        }
        
        if (!$this->connected) {
            return $this->checkRateLimitFile($identifier, $maxRequests, $timeWindow);
        }
        
        try {
            $key = "rate_limit:" . md5($identifier);
            $current = $this->redis->get($key);
            
            if ($current === false) {
                // First request
                $this->redis->setex($key, $timeWindow, 1);
                return true;
            }
            
            if ($current >= $maxRequests) {
                $this->logError("Rate limit exceeded for: " . $identifier);
                return false;
            }
            
            $this->redis->incr($key);
            return true;
            
        } catch (Exception $e) {
            $this->logError("Rate limit check error: " . $e->getMessage());
            return true; // Allow on error
        }
    }
    
    private function checkRateLimitFile($identifier, $maxRequests, $timeWindow) {
        $rateLimitFile = __DIR__ . '/cache/rate_limit_' . md5($identifier) . '.json';
        
        if (!file_exists($rateLimitFile)) {
            // First request
            $data = ['count' => 1, 'timestamp' => time()];
            file_put_contents($rateLimitFile, json_encode($data));
            return true;
        }
        
        $content = file_get_contents($rateLimitFile);
        $data = json_decode($content, true);
        
        if (!$data || (time() - $data['timestamp']) > $timeWindow) {
            // Reset counter if time window expired
            $data = ['count' => 1, 'timestamp' => time()];
            file_put_contents($rateLimitFile, json_encode($data));
            return true;
        }
        
        if ($data['count'] >= $maxRequests) {
            $this->logError("Rate limit exceeded for: " . $identifier);
            return false;
        }
        
        $data['count']++;
        file_put_contents($rateLimitFile, json_encode($data));
        return true;
    }
    
    /**
     * File-based cache fallback methods
     */
    private function getFromFileCache($key) {
        $cacheKey = $this->generateCacheKey($key);
        $cacheFile = $this->getCacheFilePath($cacheKey);
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        // Check if cache has expired
        $expiry = $data['timestamp'] + $data['ttl'];
        if (time() > $expiry) {
            unlink($cacheFile);
            return null;
        }
        
        $this->logSuccess("File cache hit for key: " . $cacheKey);
        return $data;
    }
    
    private function saveToFileCache($key, $value, $ttl = null) {
        $cacheKey = $this->generateCacheKey($key);
        $cacheFile = $this->getCacheFilePath($cacheKey);
        $ttl = $ttl ?? $this->defaultTTL;
        
        $cacheData = [
            'response' => $value,
            'timestamp' => time(),
            'source' => 'freshcery_ai',
            'ttl' => $ttl
        ];
        
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $result = file_put_contents($cacheFile, json_encode($cacheData));
        
        if ($result !== false) {
            $this->logSuccess("File cached response for key: " . $cacheKey);
            return true;
        }
        
        return false;
    }
    
    private function getCacheFilePath($cacheKey) {
        $cacheDir = __DIR__ . '/cache';
        return $cacheDir . '/' . $cacheKey . '.json';
    }
    
    private function logError($message) {
        if ($_ENV['LOG_ENABLED'] ?? false) {
            error_log("[Freshcery AI - Redis] ERROR: " . $message);
        }
    }
    
    private function logSuccess($message) {
        if ($_ENV['DEBUG_MODE'] ?? false) {
            error_log("[Freshcery AI - Redis] SUCCESS: " . $message);
        }
    }
    
    public function __destruct() {
        if ($this->connected && $this->redis) {
            $this->redis->close();
        }
    }
}

/**
 * Simple function wrappers for backward compatibility
 */
function getFromCache($key) {
    $cache = new RedisCache();
    $result = $cache->getFromCache($key);
    return $result ? $result['response'] : null;
}

function saveToCache($key, $value, $ttl = null) {
    $cache = new RedisCache();
    return $cache->saveToCache($key, $value, $ttl);
}
?>