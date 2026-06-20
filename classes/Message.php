<?php
// ============================================================
// VIKOBA - WhatsApp-style Messaging Class
// ============================================================

class Message {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Start a new conversation or get existing one between participants
     */
    public function startConversation(string $subject, array $participantIds): int {
        // Check if conversation already exists between these exact participants
        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->db->prepare("
            SELECT c.id, COUNT(cp.user_id) as cnt
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id
            GROUP BY c.id
            HAVING cnt = ? AND c.id IN (
                SELECT conversation_id FROM conversation_participants WHERE user_id IN ($placeholders)
            )
        ");
        $params = array_merge([count($participantIds)], $participantIds);
        $stmt->execute($params);
        $existing = $stmt->fetchAll();

        // Simple check: find a conversation that has all these participants
        foreach ($existing as $conv) {
            $stmt = $this->db->prepare("SELECT user_id FROM conversation_participants WHERE conversation_id = ?");
            $stmt->execute([$conv['id']]);
            $existingUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            sort($existingUsers);
            sort($participantIds);
            if ($existingUsers == $participantIds) {
                return (int)$conv['id'];
            }
        }

        // Create new conversation
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO conversations (subject) VALUES (?)");
            $stmt->execute([$subject]);
            $convId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?,?, NOW())");
            foreach ($participantIds as $pid) {
                $stmt->execute([$convId, $pid]);
            }

            $this->db->commit();
            return $convId;
        } catch (Exception $e) {
            $this->db->rollBack();
            return 0;
        }
    }

    /**
     * Send a message in a conversation
     */
    public function send(int $conversationId, int $senderId, string $body): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO conversation_messages (conversation_id, sender_id, body) VALUES (?,?,?)"
        );
        $result = $stmt->execute([$conversationId, $senderId, $body]);

        // Update last_read_at for sender
        $this->db->prepare("UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?")
                  ->execute([$conversationId, $senderId]);

        // Update conversation's updated_at
        $this->db->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")
                  ->execute([$conversationId]);

        return $result;
    }

    /**
     * Get all conversations for a user
     */
    public function getConversations(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   (SELECT cm.body FROM conversation_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                   (SELECT cm.created_at FROM conversation_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.created_at DESC LIMIT 1) as last_message_at,
                   (SELECT u.name FROM conversation_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.conversation_id = c.id ORDER BY cm.created_at DESC LIMIT 1) as last_sender_name,
                   (SELECT COUNT(*) FROM conversation_messages cm WHERE cm.conversation_id = c.id AND cm.created_at > COALESCE(cp.last_read_at, '1970-01-01')) as unread_count
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get messages in a conversation
     */
    public function getMessages(int $conversationId, int $userId, int $limit = 50): array {
        // Verify user is participant
        $stmt = $this->db->prepare("SELECT id FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        if (!$stmt->fetch()) return [];

        // Mark as read
        $this->db->prepare("UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?")
                  ->execute([$conversationId, $userId]);

        $stmt = $this->db->prepare("
            SELECT cm.*, u.name as sender_name, u.role as sender_role
            FROM conversation_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE cm.conversation_id = ?
            ORDER BY cm.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$conversationId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get conversation participants
     */
    public function getParticipants(int $conversationId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.role, cp.last_read_at
            FROM conversation_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.conversation_id = ?
        ");
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll();
    }

    /**
     * Get unread conversation count for a user
     */
    public function unreadCount(int $userId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
            WHERE (SELECT COUNT(*) FROM conversation_messages cm WHERE cm.conversation_id = c.id AND cm.created_at > COALESCE(cp.last_read_at, '1970-01-01')) > 0
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get conversation by ID if user is participant
     */
    public function getConversation(int $conversationId, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   (SELECT u.name FROM conversation_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.conversation_id = c.id ORDER BY cm.created_at DESC LIMIT 1) as last_sender_name
            FROM conversations c
            JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
            WHERE c.id = ?
        ");
        $stmt->execute([$userId, $conversationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get users available for messaging (for starting a conversation)
     */
    public function getAvailableUsers(int $currentUserId): array {
        $stmt = $this->db->prepare("
            SELECT id, name, role, email FROM users WHERE id != ? AND status = 'active' ORDER BY name ASC
        ");
        $stmt->execute([$currentUserId]);
        return $stmt->fetchAll();
    }
}