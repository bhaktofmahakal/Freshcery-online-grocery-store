<?php
/**
 * Pure PHP Redis Client - No Extension Required
 * Works with socket connections to Redis server
 */

class PureRedisClient {
    private $socket;
    private $host;
    private $port;
    private $timeout;
    private $connected = false;
    
    public function __construct($host = 'localhost', $port = 6379, $timeout = 5) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    public function connect($host = null, $port = null, $timeout = null) {
        $host = $host ?? $this->host;
        $port = $port ?? $this->port;
        $timeout = $timeout ?? $this->timeout;
        
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        
        if (!$this->socket) {
            error_log("Pure Redis Client: Connection failed - {$errstr} ({$errno})");
            return false;
        }
        
        $this->connected = true;
        return true;
    }
    
    public function ping() {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('PING');
        return $response === 'PONG' ? '+PONG' : false;
    }
    
    public function get($key) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('GET', $key);
        return $response === null ? false : $response;
    }
    
    public function set($key, $value) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('SET', $key, $value);
        return $response === 'OK';
    }
    
    public function setex($key, $ttl, $value) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('SETEX', $key, $ttl, $value);
        return $response === 'OK';
    }
    
    public function del($key) {
        if (!$this->connected) return false;
        
        if (is_array($key)) {
            $args = array_merge(['DEL'], $key);
            $response = $this->sendCommand(...$args);
        } else {
            $response = $this->sendCommand('DEL', $key);
        }
        
        return (int)$response;
    }
    
    public function incr($key) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('INCR', $key);
        return (int)$response;
    }
    
    public function keys($pattern) {
        if (!$this->connected) return [];
        
        $response = $this->sendCommand('KEYS', $pattern);
        return is_array($response) ? $response : [];
    }
    
    public function select($database) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('SELECT', $database);
        return $response === 'OK';
    }
    
    public function auth($password) {
        if (!$this->connected) return false;
        
        $response = $this->sendCommand('AUTH', $password);
        return $response === 'OK';
    }
    
    public function info($section = null) {
        if (!$this->connected) return false;
        
        if ($section) {
            $response = $this->sendCommand('INFO', $section);
        } else {
            $response = $this->sendCommand('INFO');
        }
        
        if (!$response) return [];
        
        // Parse INFO response
        $info = [];
        $lines = explode("\r\n", $response);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $info[$key] = $value;
            }
        }
        
        return $info;
    }
    
    public function close() {
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }
    
    private function sendCommand(...$args) {
        if (!$this->connected || !$this->socket) {
            return false;
        }
        
        // Build Redis protocol command
        $command = "*" . count($args) . "\r\n";
        foreach ($args as $arg) {
            $command .= "$" . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        
        // Send command
        $written = fwrite($this->socket, $command);
        if ($written === false) {
            return false;
        }
        
        // Read response
        return $this->readResponse();
    }
    
    private function readResponse() {
        if (!$this->socket) return false;
        
        $line = fgets($this->socket);
        if ($line === false) return false;
        
        $line = rtrim($line, "\r\n");
        $type = $line[0];
        $data = substr($line, 1);
        
        switch ($type) {
            case '+': // Simple string
                return $data;
                
            case '-': // Error
                error_log("Redis Error: {$data}");
                return false;
                
            case ':': // Integer
                return (int)$data;
                
            case '$': // Bulk string
                $length = (int)$data;
                if ($length === -1) return null;
                if ($length === 0) return '';
                
                $data = fread($this->socket, $length + 2); // +2 for \r\n
                return substr($data, 0, -2); // Remove \r\n
                
            case '*': // Array
                $count = (int)$data;
                if ($count === -1) return null;
                
                $array = [];
                for ($i = 0; $i < $count; $i++) {
                    $array[] = $this->readResponse();
                }
                return $array;
                
            default:
                return false;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

// Create a Redis class that mimics the PHP Redis extension
class Redis {
    private $client;
    
    public function __construct() {
        $this->client = new PureRedisClient();
    }
    
    public function connect($host, $port = 6379, $timeout = 5) {
        return $this->client->connect($host, $port, $timeout);
    }
    
    public function ping() {
        return $this->client->ping();
    }
    
    public function get($key) {
        return $this->client->get($key);
    }
    
    public function set($key, $value) {
        return $this->client->set($key, $value);
    }
    
    public function setex($key, $ttl, $value) {
        return $this->client->setex($key, $ttl, $value);
    }
    
    public function del($key) {
        return $this->client->del($key);
    }
    
    public function incr($key) {
        return $this->client->incr($key);
    }
    
    public function keys($pattern) {
        return $this->client->keys($pattern);
    }
    
    public function select($database) {
        return $this->client->select($database);
    }
    
    public function auth($password) {
        return $this->client->auth($password);
    }
    
    public function info($section = null) {
        return $this->client->info($section);
    }
    
    public function close() {
        if ($this->client) {
            return $this->client->close();
        }
        return true;
    }
}
?>