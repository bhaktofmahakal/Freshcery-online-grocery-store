<?php
/**
 * Freshcery AI System - Conversation Manager
 * Manages user conversation history and context
 */

require_once __DIR__ . '/../config/config.php';

class ConversationManager {
    private $pdo;
    private $maxHistoryLength;
    private $contextWindow;
    
    public function __construct() {
        $this->loadEnv();
        global $conn;
        $this->pdo = $conn;
        
        $this->maxHistoryLength = $_ENV['MAX_CONVERSATION_HISTORY'] ?? 50;
        $this->contextWindow = $_ENV['CONVERSATION_CONTEXT_WINDOW'] ?? 5;
        
        $this->initializeTables();
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
    
    private function initializeTables() {
        try {
            // Create conversations table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    session_id VARCHAR(255),
                    conversation_id VARCHAR(255) NOT NULL,
                    title VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    is_active BOOLEAN DEFAULT TRUE,
                    INDEX idx_user_id (user_id),
                    INDEX idx_session_id (session_id),
                    INDEX idx_conversation_id (conversation_id),
                    INDEX idx_created_at (created_at)
                )
            ");
            
            // Create conversation_messages table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conversation_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    conversation_id VARCHAR(255) NOT NULL,
                    message_type ENUM('user', 'ai') NOT NULL,
                    message_content TEXT NOT NULL,
                    ai_source ENUM('gemini', 'ollama', 'cache', 'fallback') NULL,
                    processing_time DECIMAL(10,3) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_conversation_id (conversation_id),
                    INDEX idx_created_at (created_at),
                    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE
                )
            ");
            
        } catch (Exception $e) {
            error_log("Conversation tables initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Start or get existing conversation
     */
    public function getOrCreateConversation($userId = null, $sessionId = null) {
        $conversationId = $this->generateConversationId($userId, $sessionId);
        
        try {
            // Check if conversation exists
            $stmt = $this->pdo->prepare("
                SELECT * FROM conversations 
                WHERE conversation_id = ? AND is_active = TRUE
            ");
            $stmt->execute([$conversationId]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$conversation) {
                // Create new conversation
                $title = $this->generateConversationTitle($userId);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO conversations (user_id, session_id, conversation_id, title) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $sessionId, $conversationId, $title]);
                
                $conversation = [
                    'id' => $this->pdo->lastInsertId(),
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'conversation_id' => $conversationId,
                    'title' => $title,
                    'created_at' => date('Y-m-d H:i:s'),
                    'is_active' => true
                ];
            }
            
            return $conversation;
            
        } catch (Exception $e) {
            error_log("Conversation creation failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Add message to conversation
     */
    public function addMessage($conversationId, $messageType, $content, $aiSource = null, $processingTime = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO conversation_messages 
                (conversation_id, message_type, message_content, ai_source, processing_time) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $conversationId,
                $messageType,
                $content,
                $aiSource,
                $processingTime
            ]);
            
            if ($result) {
                // Update conversation timestamp
                $this->updateConversationTimestamp($conversationId);
                
                // Clean up old messages if needed
                $this->cleanupOldMessages($conversationId);
                
                return $this->pdo->lastInsertId();
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Message addition failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get conversation history
     */
    public function getConversationHistory($conversationId, $limit = null) {
        $limit = $limit ?? $this->contextWindow;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM conversation_messages 
                WHERE conversation_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$conversationId, $limit]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Reverse to get chronological order
            return array_reverse($messages);
            
        } catch (Exception $e) {
            error_log("Conversation history retrieval failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get conversation context for AI
     */
    public function getConversationContext($conversationId) {
        $history = $this->getConversationHistory($conversationId, $this->contextWindow);
        
        if (empty($history)) {
            return '';
        }
        
        $context = "Previous conversation context:\n";
        foreach ($history as $message) {
            $role = $message['message_type'] === 'user' ? 'User' : 'Assistant';
            $context .= "{$role}: {$message['message_content']}\n";
        }
        
        return $context;
    }
    
    /**
     * Get user's conversation list
     */
    public function getUserConversations($userId, $limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT c.*, 
                       COUNT(cm.id) as message_count,
                       MAX(cm.created_at) as last_message_at
                FROM conversations c
                LEFT JOIN conversation_messages cm ON c.conversation_id = cm.conversation_id
                WHERE c.user_id = ? AND c.is_active = TRUE
                GROUP BY c.id
                ORDER BY c.updated_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("User conversations retrieval failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get session conversations (for guests)
     */
    public function getSessionConversations($sessionId, $limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT c.*, 
                       COUNT(cm.id) as message_count,
                       MAX(cm.created_at) as last_message_at
                FROM conversations c
                LEFT JOIN conversation_messages cm ON c.conversation_id = cm.conversation_id
                WHERE c.session_id = ? AND c.user_id IS NULL AND c.is_active = TRUE
                GROUP BY c.id
                ORDER BY c.updated_at DESC
                LIMIT ?
            ");
            $stmt->execute([$sessionId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Session conversations retrieval failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update conversation title based on first message
     */
    public function updateConversationTitle($conversationId, $firstMessage) {
        try {
            // Generate title from first message (first 50 characters)
            $title = substr($firstMessage, 0, 50);
            if (strlen($firstMessage) > 50) {
                $title .= '...';
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE conversations 
                SET title = ? 
                WHERE conversation_id = ?
            ");
            $stmt->execute([$title, $conversationId]);
            
        } catch (Exception $e) {
            error_log("Conversation title update failed: " . $e->getMessage());
        }
    }
    
    /**
     * Archive conversation
     */
    public function archiveConversation($conversationId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE conversations 
                SET is_active = FALSE 
                WHERE conversation_id = ?
            ");
            return $stmt->execute([$conversationId]);
            
        } catch (Exception $e) {
            error_log("Conversation archiving failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete conversation and all messages
     */
    public function deleteConversation($conversationId) {
        try {
            // Delete messages first (due to foreign key)
            $stmt = $this->pdo->prepare("DELETE FROM conversation_messages WHERE conversation_id = ?");
            $stmt->execute([$conversationId]);
            
            // Delete conversation
            $stmt = $this->pdo->prepare("DELETE FROM conversations WHERE conversation_id = ?");
            return $stmt->execute([$conversationId]);
            
        } catch (Exception $e) {
            error_log("Conversation deletion failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get conversation statistics
     */
    public function getConversationStats($userId = null) {
        try {
            $whereClause = $userId ? "WHERE c.user_id = ?" : "";
            $params = $userId ? [$userId] : [];
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT c.id) as total_conversations,
                    COUNT(cm.id) as total_messages,
                    AVG(cm.processing_time) as avg_processing_time,
                    COUNT(CASE WHEN cm.message_type = 'user' THEN 1 END) as user_messages,
                    COUNT(CASE WHEN cm.message_type = 'ai' THEN 1 END) as ai_messages
                FROM conversations c
                LEFT JOIN conversation_messages cm ON c.conversation_id = cm.conversation_id
                {$whereClause}
            ");
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Conversation stats retrieval failed: " . $e->getMessage());
            return [
                'total_conversations' => 0,
                'total_messages' => 0,
                'avg_processing_time' => 0,
                'user_messages' => 0,
                'ai_messages' => 0
            ];
        }
    }
    
    /**
     * Search conversations
     */
    public function searchConversations($query, $userId = null, $limit = 20) {
        try {
            $whereClause = "WHERE cm.message_content LIKE ?";
            $params = ["%{$query}%"];
            
            if ($userId) {
                $whereClause .= " AND c.user_id = ?";
                $params[] = $userId;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT c.*, cm.message_content as matching_message
                FROM conversations c
                JOIN conversation_messages cm ON c.conversation_id = cm.conversation_id
                {$whereClause}
                ORDER BY c.updated_at DESC
                LIMIT ?
            ");
            $params[] = $limit;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Conversation search failed: " . $e->getMessage());
            return [];
        }
    }
    
    private function generateConversationId($userId, $sessionId) {
        // For logged-in users, use user_id + current date
        if ($userId) {
            return "user_{$userId}_" . date('Y-m-d');
        }
        
        // For guests, use session_id + current date
        return "session_{$sessionId}_" . date('Y-m-d');
    }
    
    private function generateConversationTitle($userId) {
        $prefix = $userId ? "Chat" : "Guest Chat";
        return $prefix . " - " . date('M j, Y');
    }
    
    private function updateConversationTimestamp($conversationId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE conversations 
                SET updated_at = CURRENT_TIMESTAMP 
                WHERE conversation_id = ?
            ");
            $stmt->execute([$conversationId]);
        } catch (Exception $e) {
            error_log("Conversation timestamp update failed: " . $e->getMessage());
        }
    }
    
    private function cleanupOldMessages($conversationId) {
        try {
            // Keep only the most recent messages within the limit
            $stmt = $this->pdo->prepare("
                DELETE FROM conversation_messages 
                WHERE conversation_id = ? 
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM conversation_messages 
                        WHERE conversation_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT ?
                    ) as recent_messages
                )
            ");
            $stmt->execute([$conversationId, $conversationId, $this->maxHistoryLength]);
            
        } catch (Exception $e) {
            error_log("Message cleanup failed: " . $e->getMessage());
        }
    }
    
    /**
     * Export conversation to text format
     */
    public function exportConversation($conversationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT c.title, c.created_at as conversation_start
                FROM conversations c
                WHERE c.conversation_id = ?
            ");
            $stmt->execute([$conversationId]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$conversation) {
                return null;
            }
            
            $messages = $this->getConversationHistory($conversationId, 1000); // Get all messages
            
            $export = "Freshcery AI Conversation Export\n";
            $export .= "=====================================\n";
            $export .= "Title: {$conversation['title']}\n";
            $export .= "Started: {$conversation['conversation_start']}\n";
            $export .= "Exported: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($messages as $message) {
                $role = $message['message_type'] === 'user' ? 'You' : 'AI Assistant';
                $timestamp = date('H:i', strtotime($message['created_at']));
                $export .= "[{$timestamp}] {$role}: {$message['message_content']}\n\n";
            }
            
            return $export;
            
        } catch (Exception $e) {
            error_log("Conversation export failed: " . $e->getMessage());
            return null;
        }
    }
}
?>