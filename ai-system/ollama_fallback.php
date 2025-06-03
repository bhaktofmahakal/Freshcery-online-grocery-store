<?php
/**
 * Freshcery AI System - Ollama Local Fallback
 * Local AI model fallback when Gemini is unavailable
 */

class OllamaFallback {
    private $host;
    private $port;
    private $model;
    private $timeout = 60; // Longer timeout for local processing
    
    public function __construct() {
        $this->loadEnv();
        $this->host = $_ENV['OLLAMA_HOST'] ?? 'localhost';
        $this->port = $_ENV['OLLAMA_PORT'] ?? '11434';
        $this->model = $_ENV['OLLAMA_MODEL'] ?? 'phi3';
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
    
    /**
     * Ask Ollama local model
     */
    public function askOllama($prompt, $context = null) {
        if (!$this->isAvailable()) {
            $this->logError("Ollama service not available");
            return "I'm sorry, but the AI assistant is temporarily unavailable. Please try again later or contact support.";
        }
        
        // Add Freshcery context to the prompt
        $systemContext = $_ENV['AI_CONTEXT'] ?? 'You are a helpful AI assistant for Freshcery grocery platform.';
        $fullPrompt = $systemContext . "\n\nUser Question: " . $prompt;
        
        if ($context) {
            $fullPrompt .= "\n\nAdditional Context: " . $context;
        }
        
        $fullPrompt .= "\n\nPlease provide a helpful, concise response:";
        
        $data = [
            'model' => $this->model,
            'prompt' => $fullPrompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40
            ]
        ];
        
        $url = "http://{$this->host}:{$this->port}/api/generate";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Freshcery-AI/1.0'
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->logError("Ollama cURL error: " . $error);
            return "Local AI model is currently unavailable. Please try again later.";
        }
        
        if ($httpCode !== 200) {
            $this->logError("Ollama HTTP error: " . $httpCode);
            return "Local AI model encountered an error. Please try again later.";
        }
        
        if (!$response) {
            $this->logError("Empty response from Ollama");
            return "No response received from local AI model.";
        }
        
        // Handle streaming response format
        $lines = explode("\n", trim($response));
        $output = "";
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $chunk = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }
            
            if (isset($chunk['response'])) {
                $output .= $chunk['response'];
            }
            
            // Check if this is the final chunk
            if (isset($chunk['done']) && $chunk['done'] === true) {
                break;
            }
        }
        
        if (empty($output)) {
            $this->logError("No valid response content from Ollama");
            return "Unable to generate a response. Please try again.";
        }
        
        $this->logSuccess("Ollama response generated successfully");
        return trim($output);
    }
    
    /**
     * Check if Ollama is available
     */
    public function isAvailable() {
        $url = "http://{$this->host}:{$this->port}/api/tags";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200 && !empty($response);
    }
    
    /**
     * Get available models
     */
    public function getAvailableModels() {
        if (!$this->isAvailable()) {
            $this->logError("Ollama service not available when checking models");
            return [];
        }
        
        $url = "http://{$this->host}:{$this->port}/api/tags";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) {
            $this->logError("Empty response when checking available models");
            return [];
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Failed to parse models response: " . json_last_error_msg());
            return [];
        }
        
        $models = $data['models'] ?? [];
        $this->logSuccess("Available models: " . json_encode($models));
        return $models;
    }
    
    /**
     * Check if the configured model is available
     */
    public function isModelAvailable() {
        $models = $this->getAvailableModels();
        foreach ($models as $model) {
            // Check both with and without :latest suffix
            if ($model['name'] === $this->model || 
                $model['name'] === $this->model . ':latest' ||
                $model['name'] === str_replace(':latest', '', $model['name'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get service status
     */
    public function getStatus() {
        if (!$this->isAvailable()) {
            return [
                'status' => 'unavailable',
                'reason' => 'Ollama service not running',
                'suggestion' => 'Start Ollama service with: ollama serve'
            ];
        }
        
        if (!$this->isModelAvailable()) {
            return [
                'status' => 'model_missing',
                'reason' => "Model '{$this->model}' not found",
                'suggestion' => "Install model with: ollama pull {$this->model}"
            ];
        }
        
        return [
            'status' => 'available',
            'reason' => 'Ollama service running with model available',
            'model' => $this->model
        ];
    }
    
    private function logError($message) {
        if ($_ENV['LOG_ENABLED'] ?? false) {
            error_log("[Freshcery AI - Ollama] ERROR: " . $message);
        }
    }
    
    private function logSuccess($message) {
        if ($_ENV['DEBUG_MODE'] ?? false) {
            error_log("[Freshcery AI - Ollama] SUCCESS: " . $message);
        }
    }
}

/**
 * Simple function wrapper for backward compatibility
 */
function askOllama($prompt, $context = null) {
    $ollama = new OllamaFallback();
    return $ollama->askOllama($prompt, $context);
}
?>