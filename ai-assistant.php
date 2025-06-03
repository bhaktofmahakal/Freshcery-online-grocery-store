<?php 
// Start output buffering
ob_start();

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set error handler to prevent errors from being displayed
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require "config/config.php";
require "ai-system/ai_router.php";

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Clear any previous output
    ob_clean();
    
    // Set JSON header
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'ask_ai':
                    $prompt = trim($_POST['prompt'] ?? '');
                    if (empty($prompt)) {
                        echo json_encode(['error' => 'Please enter a question']);
                        exit;
                    }
                    
                    // Check if user is logged in for personalized responses
                    $userId = $_SESSION['user_id'] ?? null;
                    $username = $_SESSION['username'] ?? 'Guest';
                    
                    $userContext = [
                        'user_id' => $userId,
                        'username' => $username,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'session_id' => session_id(),
                        'is_authenticated' => isset($_SESSION['user_id'])
                    ];
                    
                    // Add user-specific context if logged in
                    if ($userId) {
                        try {
                            // Get user's recent orders for context
                            $stmt = $conn->prepare("
                                SELECT p.name, o.created_at 
                                FROM orders o 
                                JOIN products p ON o.product_id = p.id 
                                WHERE o.user_id = ? 
                                ORDER BY o.created_at DESC 
                                LIMIT 5
                            ");
                            $stmt->execute([$userId]);
                            $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (!empty($recentOrders)) {
                                $userContext['recent_orders'] = $recentOrders;
                                $userContext['context_note'] = "User has recent orders: " . 
                                    implode(', ', array_column($recentOrders, 'name'));
                            }
                        } catch (Exception $e) {
                            error_log("Error fetching user orders: " . $e->getMessage());
                        }
                    }
                    
                    $response = askAI($prompt, $userContext);
                    echo json_encode($response);
                    exit;
                    
                case 'get_status':
                    $status = getAIStatus();
                    echo json_encode($status);
                    exit;
                    
                case 'clear_cache':
                    $ai = new FreshceryAI();
                    $cleared = $ai->clearCache();
                    echo json_encode(['cleared' => $cleared, 'message' => "Cleared {$cleared} cache entries"]);
                    exit;
            }
        } catch (Exception $e) {
            error_log("AI System Error: " . $e->getMessage());
            echo json_encode(['error' => 'An error occurred while processing your request']);
            exit;
        }
    }
    
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Clear any output before including header
ob_clean();

// Include header for non-AJAX requests
require "includes/header.php";
?>

<style>
/* Fix navbar positioning and visibility */
#page-navigation {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background: rgba(255, 255, 255, 0.98) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 9999;
    transition: none !important;
}

/* Ensure navbar items are always visible */
#page-navigation .navbar-nav {
    display: flex !important;
    opacity: 1 !important;
    visibility: visible !important;
}

#page-navigation .nav-item {
    opacity: 1 !important;
    visibility: visible !important;
}

#page-navigation .nav-link {
    color: #333 !important;
    opacity: 1 !important;
    visibility: visible !important;
    transition: color 0.2s ease;
}

#page-navigation .nav-link:hover {
    color: #28a745 !important;
}

/* Fix dropdown menu visibility */
#page-navigation .dropdown-menu {
    display: none;
    opacity: 1 !important;
    visibility: visible !important;
    background: white !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

#page-navigation .dropdown:hover .dropdown-menu {
    display: block;
}

/* Adjust content spacing */
.ai-chat-container {
    max-width: 800px;
    margin: 100px auto 0;
    background: #fff;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
}

#page-content {
    padding-top: 0;
    position: relative;
    z-index: 1;
}

.page-header {
    position: relative;
    z-index: 9998;
}

.ai-chat-header {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    padding: 20px;
    text-align: center;
}

.ai-chat-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
}

.ai-chat-header p {
    margin: 5px 0 0 0;
    opacity: 0.9;
    font-size: 0.9rem;
}

.chat-messages {
    height: 400px;
    overflow-y: auto;
    padding: 20px;
    background: #f8f9fa;
}

.message {
    margin-bottom: 15px;
    display: flex;
    align-items: flex-start;
}

.message.user {
    justify-content: flex-end;
}

.message.ai {
    justify-content: flex-start;
}

.message-content {
    max-width: 70%;
    padding: 12px 16px;
    border-radius: 18px;
    font-size: 0.9rem;
    line-height: 1.4;
}

.message.user .message-content {
    background: #007bff;
    color: white;
    border-bottom-right-radius: 4px;
}

.message.ai .message-content {
    background: white;
    color: #333;
    border: 1px solid #e9ecef;
    border-bottom-left-radius: 4px;
}

.message-meta {
    font-size: 0.7rem;
    opacity: 0.7;
    margin-top: 4px;
}

.chat-input-container {
    padding: 20px;
    background: white;
    border-top: 1px solid #e9ecef;
}

.chat-input-form {
    display: flex;
    gap: 10px;
}

.chat-input {
    flex: 1;
    padding: 12px 16px;
    border: 2px solid #e9ecef;
    border-radius: 25px;
    font-size: 0.9rem;
    outline: none;
    transition: border-color 0.3s;
}

.chat-input:focus {
    border-color: #28a745;
}

.chat-send-btn {
    padding: 12px 24px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 25px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}

.chat-send-btn:hover {
    background: #218838;
}

.chat-send-btn:disabled {
    background: #6c757d;
    cursor: not-allowed;
}

.typing-indicator {
    display: none;
    padding: 10px 16px;
    color: #6c757d;
    font-style: italic;
    font-size: 0.8rem;
}

.status-panel {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    border-bottom: 1px solid #e9ecef;
}

.status-item:last-child {
    border-bottom: none;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-available {
    background: #d4edda;
    color: #155724;
}

.status-unavailable {
    background: #f8d7da;
    color: #721c24;
}

.status-cached {
    background: #d1ecf1;
    color: #0c5460;
}

.quick-questions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 15px;
}

.quick-question-btn {
    padding: 6px 12px;
    background: #e9ecef;
    border: 1px solid #dee2e6;
    border-radius: 15px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s;
}

.quick-question-btn:hover {
    background: #28a745;
    color: white;
    border-color: #28a745;
}

@media (max-width: 768px) {
    .ai-chat-container {
        margin: 10px;
        border-radius: 10px;
    }
    
    .message-content {
        max-width: 85%;
    }
    
    .chat-input-form {
        flex-direction: column;
    }
    
    .chat-send-btn {
        align-self: flex-end;
        width: auto;
    }
}
</style>

<div id="page-content" class="page-content">
    <div class="container py-5">
        <div class="ai-chat-container">
            <!-- Chat Header -->
            <div class="ai-chat-header">
                <h2>🤖 Freshcery AI Assistant</h2>
                <p>Ask me anything about our fresh groceries, delivery, or services!</p>
                
                <!-- User Status -->
                <div class="user-status-bar" style="background: #e3f2fd; padding: 8px 12px; border-radius: 6px; margin-top: 10px; font-size: 0.85rem;">
                    <?php if (isset($_SESSION['username'])): ?>
                        <span style="color: #1976d2;">
                            <i class="fas fa-user-check"></i> 
                            Welcome back, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>! 
                            I can provide personalized recommendations based on your order history.
                        </span>
                    <?php else: ?>
                        <span style="color: #666;">
                            <i class="fas fa-user"></i> 
                            You're browsing as a guest. 
                            <a href="auth/login.php" style="color: #1976d2;">Login</a> for personalized assistance!
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Chat Messages -->
            <div class="chat-messages" id="chat-messages">
                <div class="message ai">
                    <div class="message-content">
                        👋 Hello! I'm your Freshcery AI assistant. I'm here to help you with:
                        <br><br>
                        • Product information and availability<br>
                        • Delivery schedules and areas<br>
                        • Order process and payment<br>
                        • Quality guarantees<br>
                        • Farmer information<br>
                        • Store policies and more!
                        <br><br>
                        What would you like to know about Freshcery today?
                    </div>
                </div>
            </div>
            
            <!-- Typing Indicator -->
            <div class="typing-indicator" id="typing-indicator">
                🤖 AI is thinking...
            </div>
            
            <!-- Quick Questions -->
            <div class="px-3 pt-3">
                <div class="quick-questions">
                    <button class="quick-question-btn" onclick="askQuickQuestion('What are your store hours?')">Store Hours</button>
                    <button class="quick-question-btn" onclick="askQuickQuestion('How fresh are your products?')">Product Freshness</button>
                    <button class="quick-question-btn" onclick="askQuickQuestion('What areas do you deliver to?')">Delivery Areas</button>
                    <button class="quick-question-btn" onclick="askQuickQuestion('How do I track my order?')">Order Tracking</button>
                    <button class="quick-question-btn" onclick="askQuickQuestion('Tell me about your farmers')">Our Farmers</button>
                </div>
            </div>
            
            <!-- Chat Input -->
            <div class="chat-input-container">
                <form class="chat-input-form" onsubmit="sendMessage(event)">
                    <input 
                        type="text" 
                        class="chat-input" 
                        id="chat-input" 
                        placeholder="Ask me anything about Freshcery..." 
                        required
                        maxlength="500"
                    >
                    <button type="submit" class="chat-send-btn" id="send-btn">
                        Send
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Debug Controls -->
        <div class="text-center mt-3">
            <a href="shop.php" class="btn btn-sm btn-success">Browse Products</a>
        </div>
    </div>
</div>

<script>
let isWaiting = false;

function sendMessage(event) {
    event.preventDefault();
    
    if (isWaiting) return;
    
    const input = document.getElementById('chat-input');
    const message = input.value.trim();
    
    if (!message) return;
    
    // Add user message to chat
    addMessage(message, 'user');
    input.value = '';
    
    // Show typing indicator
    showTyping();
    
    // Send to AI
    askAI(message);
}

function askQuickQuestion(question) {
    if (isWaiting) return;
    
    addMessage(question, 'user');
    showTyping();
    askAI(question);
}

function askAI(prompt) {
    isWaiting = true;
    updateSendButton(true);
    
    fetch('ai-assistant.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=1&action=ask_ai&prompt=${encodeURIComponent(prompt)}`
    })
    .then(response => response.json())
    .then(data => {
        hideTyping();
        
        if (data.error) {
            addMessage('Sorry, there was an error: ' + data.error, 'ai', 'error');
        } else {
            const sourceInfo = getSourceInfo(data.source, data.metadata);
            addMessage(data.response, 'ai', data.source, sourceInfo);
        }
    })
    .catch(error => {
        hideTyping();
        addMessage('Sorry, I encountered a technical error. Please try again.', 'ai', 'error');
        console.error('AI Error:', error);
    })
    .finally(() => {
        isWaiting = false;
        updateSendButton(false);
    });
}

function addMessage(content, sender, source = '', meta = '') {
    const messagesContainer = document.getElementById('chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    const sourceIcon = getSourceIcon(source);
    const timestamp = new Date().toLocaleTimeString();
    
    messageDiv.innerHTML = `
        <div class="message-content">
            ${content}
            ${meta ? `<div class="message-meta">${sourceIcon} ${meta} • ${timestamp}</div>` : `<div class="message-meta">${timestamp}</div>`}
        </div>
    `;
    
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function getSourceIcon(source) {
    switch(source) {
        case 'gemini': return 'Freshcery AI';
        case 'ollama': return '🤖 Local AI';
        case 'cached': return 'Cached';
        case 'fallback': return '📋 Fallback';
        case 'error': return 'Error';
        default: return '🤖 AI';
    }
}

function getSourceInfo(source, metadata) {
    if (!metadata) return '';
    
    let info = [];
    if (metadata.processing_time) {
        info.push(`${metadata.processing_time}ms`);
    }
    if (source === 'cached' && metadata.cached_at) {
        const cachedDate = new Date(metadata.cached_at * 1000);
        info.push(`cached ${cachedDate.toLocaleTimeString()}`);
    }
    
    return info.join(' • ');
}

function showTyping() {
    document.getElementById('typing-indicator').style.display = 'block';
    const messagesContainer = document.getElementById('chat-messages');
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function hideTyping() {
    document.getElementById('typing-indicator').style.display = 'none';
}

function updateSendButton(disabled) {
    const btn = document.getElementById('send-btn');
    btn.disabled = disabled;
    btn.textContent = disabled ? 'Sending...' : 'Send';
}

function toggleStatus() {
    const panel = document.getElementById('status-panel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadSystemStatus();
    } else {
        panel.style.display = 'none';
    }
}

function loadSystemStatus() {
    fetch('ai-assistant.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'ajax=1&action=get_status'
    })
    .then(response => response.json())
    .then(data => {
        displaySystemStatus(data);
    })
    .catch(error => {
        document.getElementById('status-content').innerHTML = 'Error loading status';
    });
}

function displaySystemStatus(status) {
    let html = '';
    
    // Gemini Status
    html += `<div class="status-item">
        <span>Gemini API</span>
        <span class="status-badge ${status.gemini.status === 'available' ? 'status-available' : 'status-unavailable'}">
            ${status.gemini.status}
        </span>
    </div>`;
    
    // Ollama Status
    html += `<div class="status-item">
        <span>Ollama (Local AI)</span>
        <span class="status-badge ${status.ollama.status === 'available' ? 'status-available' : 'status-unavailable'}">
            ${status.ollama.status}
        </span>
    </div>`;
    
    // Redis Status
    html += `<div class="status-item">
        <span>Redis Cache</span>
        <span class="status-badge ${status.redis.status === 'connected' ? 'status-available' : 'status-unavailable'}">
            ${status.redis.status}
        </span>
    </div>`;
    
    // Cache Stats
    if (status.cache_stats) {
        html += `<div class="status-item">
            <span>Cache Stats</span>
            <span class="status-badge status-cached">
                ${status.cache_stats.total_keys} keys, ${status.cache_stats.hit_rate}% hit rate
            </span>
        </div>`;
    }
    
    document.getElementById('status-content').innerHTML = html;
}

function clearCache() {
    if (!confirm('Are you sure you want to clear the AI cache?')) return;
    
    fetch('ai-assistant.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'ajax=1&action=clear_cache'
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message || 'Cache cleared');
        loadSystemStatus();
    })
    .catch(error => {
        alert('Error clearing cache');
    });
}

// Auto-focus input on page load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('chat-input').focus();
});

// Handle Enter key in input
document.getElementById('chat-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!isWaiting) {
            sendMessage(e);
        }
    }
});
</script>

<?php require "includes/footer.php"; ?>