<?php
/**
 * Freshcery AI System - Main AI Router
 * Orchestrates AI responses with intelligent fallback and caching
 */

require_once 'gemini_api.php';
require_once 'ollama_fallback.php';
require_once 'redis_cache.php';
require_once 'conversation_manager.php';
require_once __DIR__ . '/../config/config.php';

class FreshceryAI {
    private $gemini;
    private $ollama;
    private $cache;
    private $conversation;
    private $config;
    private $pdo;
    
    public function __construct() {
        $this->loadEnv();
        $this->gemini = new GeminiAPI();
        $this->ollama = new OllamaFallback();
        $this->cache = new RedisCache();
        $this->conversation = new ConversationManager();
        $this->initDatabase();
        
        $this->config = [
            'system_name' => $_ENV['AI_SYSTEM_NAME'] ?? 'Freshcery AI Assistant',
            'debug_mode' => $_ENV['DEBUG_MODE'] ?? false,
            'log_enabled' => $_ENV['LOG_ENABLED'] ?? true
        ];
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
    
    private function initDatabase() {
        try {
            global $conn;
            $this->pdo = $conn;
            $this->logInfo("Database connection initialized for AI logging");
        } catch (Exception $e) {
            $this->logError("Database connection failed: " . $e->getMessage());
            $this->pdo = null;
        }
    }
    
    /**
     * Main AI query function with intelligent routing
     */
    public function askAI($prompt, $userContext = []) {
        $startTime = microtime(true);
        
        // Input validation
        if (empty(trim($prompt))) {
            return $this->formatResponse("Please ask me a question about Freshcery!", 'error');
        }
        
        // Rate limiting check
        $userIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!$this->cache->checkRateLimit($userIP)) {
            return $this->formatResponse(
                "You've reached the maximum number of questions per minute. Please wait a moment before asking again.",
                'rate_limited'
            );
        }
        
        // Get or create conversation
        $userId = $userContext['user_id'] ?? null;
        $sessionId = $userContext['session_id'] ?? session_id();
        $conversation = $this->conversation->getOrCreateConversation($userId, $sessionId);
        
        if (!$conversation) {
            $this->logError("Failed to create conversation for user");
        }
        
        // Add user message to conversation
        if ($conversation) {
            $this->conversation->addMessage($conversation['conversation_id'], 'user', $prompt);
            
            // Update conversation title if this is the first message
            $history = $this->conversation->getConversationHistory($conversation['conversation_id'], 1);
            if (count($history) === 1) {
                $this->conversation->updateConversationTitle($conversation['conversation_id'], $prompt);
            }
        }
        
        // Check cache first
        $cached = $this->cache->getFromCache($prompt);
        if ($cached && isset($cached['response'])) {
            $this->logInfo("Serving cached response for prompt");
            return $this->formatResponse($cached['response'], 'cached', [
                'cached_at' => $cached['timestamp'] ?? null,
                'processing_time' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
        
        // Get conversation context
        $conversationContext = '';
        if ($conversation) {
            $conversationContext = $this->conversation->getConversationContext($conversation['conversation_id']);
        }
        
        // Enhance prompt with Freshcery context and conversation history
        $enhancedPrompt = $this->enhancePromptWithContext($prompt, $userContext, $conversationContext);
        
        // Try Gemini first
        $response = null;
        $source = null;
        
        if ($this->gemini->isAvailable()) {
            $this->logInfo("Attempting Gemini API request");
            $response = $this->gemini->askGemini($enhancedPrompt);
            if ($response !== false) {
                $source = 'gemini';
                $this->logInfo("Gemini API responded successfully");
            } else {
                $this->logInfo("Gemini API failed, trying fallback");
            }
        }
        
        // Fallback to Ollama if Gemini failed
        if ($response === null || $response === false) {
            $this->logInfo("Using Ollama fallback");
            $response = $this->ollama->askOllama($enhancedPrompt);
            $source = 'ollama';
        }
        
        // Final fallback if both AI systems fail
        if (empty($response) || $response === false) {
            $response = $this->getFallbackResponse($prompt);
            $source = 'fallback';
        }
        
        // Cache the response
        if ($source !== 'fallback') {
            $this->cache->saveToCache($prompt, $response);
        }
        
        $processingTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Log to database
        $this->logToDatabase($prompt, $response, $source, $userContext, $processingTime);
        
        // Add AI response to conversation
        if ($conversation && $response) {
            $this->conversation->addMessage(
                $conversation['conversation_id'], 
                'ai', 
                $response, 
                $source, 
                $processingTime / 1000
            );
        }
        
        return $this->formatResponse($response, $source, [
            'processing_time' => $processingTime,
            'prompt_length' => strlen($prompt),
            'response_length' => strlen($response)
        ]);
    }
    
    /**
     * Enhance prompt with Freshcery-specific context
     */
    private function enhancePromptWithContext($prompt, $userContext = [], $conversationContext = '') {
        $freshceryContext = $this->getFreshceryContext();
        
        $enhancedPrompt = $freshceryContext . "\n\n";
        
        // Add conversation context if available
        if (!empty($conversationContext)) {
            $enhancedPrompt .= $conversationContext . "\n";
        }
        
        // Add user context if provided
        if (!empty($userContext)) {
            $enhancedPrompt .= "User Context:\n";
            foreach ($userContext as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $enhancedPrompt .= "- {$key}: {$value}\n";
                }
            }
            $enhancedPrompt .= "\n";
        }
        
        $enhancedPrompt .= "Current User Question: " . $prompt . "\n\n";
        $enhancedPrompt .= "Please provide a helpful, accurate, and friendly response about Freshcery. ";
        $enhancedPrompt .= "Consider the conversation history to provide contextual responses. ";
        $enhancedPrompt .= "If the question is not related to Freshcery, politely redirect the conversation back to our grocery platform.";
        
        return $enhancedPrompt;
    }
    
    /**
     * Get Freshcery-specific context information
     */
    private function getFreshceryContext() {
        return "You are the AI assistant for Freshcery, a premium fresh grocery delivery platform. Here's what you need to know:

ABOUT FRESHCERY:
- We deliver fresh, farm-to-table groceries within 24 hours of harvest
- We connect customers directly with local farmers
- Our mission is to provide the freshest produce while supporting farmers
- We offer vegetables, fruits, dairy, and other fresh products

KEY FEATURES:
- Farm-to-table delivery within 24 hours
- Direct connection with local farmers
- Quality guarantee on all fresh produce
- Easy online ordering system
- Reliable delivery service

CUSTOMER BENEFITS:
- Always fresh, just-harvested produce
- Support for local farming communities
- Convenient online shopping
- Quality assurance
- Transparent farmer profiles

COMMON TOPICS TO HELP WITH:
- Product information and availability
- Delivery schedules and areas
- Order process and payment
- Quality guarantees
- Farmer information
- Store policies
- Account management
- General shopping assistance";
    }
    
    /**
     * Get fallback response when AI systems are unavailable
     */
    private function getFallbackResponse($prompt) {
        $fallbackResponses = [
            'general' => "Thank you for your question about Freshcery! I'm currently experiencing technical difficulties, but I'd be happy to help you in other ways:\n\n• Browse our fresh products in the Shop section\n• Contact our customer service team\n• Check our FAQ page for common questions\n• Visit our About page to learn more about our farm-to-table mission\n\nWe appreciate your patience and look forward to serving you!",
            
            'products' => "I'd love to help you find the perfect fresh products! While our AI assistant is temporarily unavailable, you can:\n\n• Browse our complete product catalog in the Shop section\n• Use our category filters to find specific items\n• Check product details and farmer information\n• Add items to your cart for quick checkout\n\nAll our products are farm-fresh and delivered within 24 hours of harvest!",
            
            'delivery' => "For delivery information, please:\n\n• Check our delivery areas on the contact page\n• Review our delivery schedule in your account\n• Contact customer service for specific delivery questions\n• Visit our FAQ for common delivery policies\n\nWe deliver fresh groceries within 24 hours of harvest to ensure maximum freshness!",
            
            'support' => "We're here to help! While our AI assistant is temporarily down:\n\n• Visit our Contact page for customer service\n• Check our FAQ section for quick answers\n• Browse our About page for company information\n• Use our easy online ordering system\n\nThank you for choosing Freshcery for your fresh grocery needs!"
        ];
        
        // Simple keyword matching for better fallback responses
        $prompt_lower = strtolower($prompt);
        
        if (strpos($prompt_lower, 'product') !== false || strpos($prompt_lower, 'vegetable') !== false || strpos($prompt_lower, 'fruit') !== false) {
            return $fallbackResponses['products'];
        } elseif (strpos($prompt_lower, 'delivery') !== false || strpos($prompt_lower, 'shipping') !== false) {
            return $fallbackResponses['delivery'];
        } elseif (strpos($prompt_lower, 'help') !== false || strpos($prompt_lower, 'support') !== false || strpos($prompt_lower, 'contact') !== false) {
            return $fallbackResponses['support'];
        }
        
        return $fallbackResponses['general'];
    }
    
    /**
     * Format AI response with metadata
     */
    private function formatResponse($response, $source, $metadata = []) {
        return [
            'response' => $response,
            'source' => $source,
            'timestamp' => time(),
            'system' => $this->config['system_name'],
            'metadata' => $metadata
        ];
    }
    
    /**
     * Get system status
     */
    public function getSystemStatus() {
        return [
            'gemini' => $this->gemini->getStatus(),
            'ollama' => $this->ollama->getStatus(),
            'redis' => $this->cache->testConnection(),
            'cache_stats' => $this->cache->getCacheStats(),
            'system_info' => [
                'name' => $this->config['system_name'],
                'version' => '1.0.0',
                'uptime' => $this->getUptime()
            ]
        ];
    }
    
    /**
     * Clear AI cache
     */
    public function clearCache($pattern = '*') {
        return $this->cache->clearCache($pattern);
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        return $this->cache->getCacheStats();
    }
    
    private function getUptime() {
        // Simple uptime calculation (you might want to store start time in a file)
        return "System operational";
    }
    
    private function logInfo($message) {
        if ($this->config['log_enabled']) {
            error_log("[Freshcery AI Router] INFO: " . $message);
        }
    }
    
    /**
     * Log AI interaction to database
     */
    private function logToDatabase($prompt, $response, $source, $userContext, $processingTime) {
        if (!$this->pdo || !$this->config['log_enabled']) {
            return false;
        }
        
        try {
            $userIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userId = $_SESSION['user_id'] ?? null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_logs (prompt, response, source, ip_address, user_id, processing_time, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $prompt,
                $response,
                $source,
                $userIP,
                $userId,
                $processingTime / 1000 // Convert to seconds
            ]);
            
            if ($result) {
                $this->logInfo("AI interaction logged to database successfully");
                return true;
            } else {
                $this->logError("Failed to log AI interaction to database");
                return false;
            }
            
        } catch (Exception $e) {
            $this->logError("Database logging error: " . $e->getMessage());
            return false;
        }
    }
    
    private function logError($message) {
        if ($this->config['log_enabled']) {
            error_log("[Freshcery AI Router] ERROR: " . $message);
        }
    }
}

/**
 * Simple function wrapper for easy integration
 */
function askAI($prompt, $userContext = []) {
    $ai = new FreshceryAI();
    return $ai->askAI($prompt, $userContext);
}

/**
 * Get AI system status
 */
function getAIStatus() {
    $ai = new FreshceryAI();
    return $ai->getSystemStatus();
}
?>