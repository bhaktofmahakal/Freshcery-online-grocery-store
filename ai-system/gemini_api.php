<?php
/**
 * Freshcery AI System - Gemini API Integration
 * High-accuracy AI responses using Google's Gemini Pro
 */

class GeminiAPI {
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
    private $timeout = 30;
    
    public function __construct() {
        $this->loadEnv();
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
        
        if (!$this->apiKey) {
            error_log("Gemini API key not found in environment variables");
        }
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
     * Ask Gemini Pro a question with Freshcery context
     */
    public function askGemini($prompt, $context = null) {
        if (!$this->apiKey) {
            $this->logError("Gemini API key not configured");
            return false;
        }
        
        // Add Freshcery context to the prompt
        $systemContext = $_ENV['AI_CONTEXT'] ?? 'You are a helpful AI assistant for Freshcery grocery platform.';
        $fullPrompt = $systemContext . "\n\nUser Question: " . $prompt;
        
        if ($context) {
            $fullPrompt .= "\n\nAdditional Context: " . $context;
        }
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];
        
        $ch = curl_init($this->baseUrl . '?key=' . $this->apiKey);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Freshcery-AI/1.0'
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->logError("Gemini API cURL error: " . $error);
            return false;
        }
        
        if ($httpCode !== 200) {
            $this->logError("Gemini API HTTP error: " . $httpCode . " - " . $response);
            return false;
        }
        
        if (!$response) {
            $this->logError("Empty response from Gemini API");
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Invalid JSON response from Gemini API");
            return false;
        }
        
        // Extract the response text
        $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if (!$responseText) {
            $this->logError("No response text found in Gemini API response");
            return false;
        }
        
        $this->logSuccess("Gemini API response received successfully");
        return trim($responseText);
    }
    
    /**
     * Check if Gemini API is available
     */
    public function isAvailable() {
        return !empty($this->apiKey);
    }
    
    /**
     * Get API status
     */
    public function getStatus() {
        if (!$this->isAvailable()) {
            return ['status' => 'unavailable', 'reason' => 'API key not configured'];
        }
        
        // Simple test call
        $testResponse = $this->askGemini("Hello");
        if ($testResponse !== false) {
            return ['status' => 'available', 'reason' => 'API responding normally'];
        } else {
            return ['status' => 'error', 'reason' => 'API not responding'];
        }
    }
    
    private function logError($message) {
        if ($_ENV['LOG_ENABLED'] ?? false) {
            error_log("[Freshcery AI - Gemini] ERROR: " . $message);
        }
    }
    
    private function logSuccess($message) {
        if ($_ENV['DEBUG_MODE'] ?? false) {
            error_log("[Freshcery AI - Gemini] SUCCESS: " . $message);
        }
    }
}

/**
 * Simple function wrapper for backward compatibility
 */
function askGemini($prompt, $context = null) {
    $gemini = new GeminiAPI();
    return $gemini->askGemini($prompt, $context);
}
?>