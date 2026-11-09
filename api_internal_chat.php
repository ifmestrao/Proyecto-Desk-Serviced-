<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$tenant_id = $_SESSION['tenant_id'];
$current_user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Update current user's last seen
try {
    $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$current_user_id]);
} catch (Exception $e) {}

// Create tables if not exists and add missing columns
try {
    $pdo->beginTransaction();
    
    // Check internal_chat_rooms
    $checkRooms = $pdo->query("SHOW TABLES LIKE 'internal_chat_rooms'");
    if (!$checkRooms->fetch()) {
        $pdo->exec("
            CREATE TABLE `internal_chat_rooms` (
                `id` int NOT NULL AUTO_INCREMENT,
                `tenant_id` int NOT NULL,
                `user1_id` int NOT NULL,
                `user2_id` int NOT NULL,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_room_tenant` (`tenant_id`),
                KEY `idx_room_user1` (`user1_id`),
                KEY `idx_room_user2` (`user2_id`),
                UNIQUE KEY `unique_room` (`tenant_id`, `user1_id`, `user2_id`),
                CONSTRAINT `fk_room_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_room_user1` FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_room_user2` FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Check internal_chat_messages and add file columns if missing
    $checkMessages = $pdo->query("SHOW TABLES LIKE 'internal_chat_messages'");
    if (!$checkMessages->fetch()) {
        $pdo->exec("
            CREATE TABLE `internal_chat_messages` (
                `id` int NOT NULL AUTO_INCREMENT,
                `room_id` int NOT NULL,
                `sender_id` int NOT NULL,
                `message` text,
                `file_path` varchar(255) DEFAULT NULL,
                `file_name` varchar(255) DEFAULT NULL,
                `file_type` varchar(100) DEFAULT NULL,
                `file_size` bigint DEFAULT NULL,
                `is_read` tinyint(1) DEFAULT 0,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_msg_room` (`room_id`),
                KEY `idx_msg_sender` (`sender_id`),
                KEY `idx_msg_read` (`is_read`),
                CONSTRAINT `fk_msg_room` FOREIGN KEY (`room_id`) REFERENCES `internal_chat_rooms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Add missing columns to internal_chat_messages if needed
        $checkColumns = $pdo->query("SHOW COLUMNS FROM internal_chat_messages LIKE 'file_path'");
        if (!$checkColumns->fetch()) {
            $pdo->exec("ALTER TABLE internal_chat_messages ADD COLUMN file_path varchar(255) DEFAULT NULL AFTER message");
        }
        $checkColumns = $pdo->query("SHOW COLUMNS FROM internal_chat_messages LIKE 'file_name'");
        if (!$checkColumns->fetch()) {
            $pdo->exec("ALTER TABLE internal_chat_messages ADD COLUMN file_name varchar(255) DEFAULT NULL AFTER file_path");
        }
        $checkColumns = $pdo->query("SHOW COLUMNS FROM internal_chat_messages LIKE 'file_type'");
        if (!$checkColumns->fetch()) {
            $pdo->exec("ALTER TABLE internal_chat_messages ADD COLUMN file_type varchar(100) DEFAULT NULL AFTER file_name");
        }
        $checkColumns = $pdo->query("SHOW COLUMNS FROM internal_chat_messages LIKE 'file_size'");
        if (!$checkColumns->fetch()) {
            $pdo->exec("ALTER TABLE internal_chat_messages ADD COLUMN file_size bigint DEFAULT NULL AFTER file_type");
        }
        
        // Drop old image_path if exists and migrate
        $checkOldImage = $pdo->query("SHOW COLUMNS FROM internal_chat_messages LIKE 'image_path'");
        if ($checkOldImage->fetch()) {
            // Migrate existing images to new file columns
            $pdo->exec("UPDATE internal_chat_messages SET file_path = image_path, file_name = 'image.jpg', file_type = 'image/jpeg' WHERE image_path IS NOT NULL AND file_path IS NULL");
            $pdo->exec("ALTER TABLE internal_chat_messages DROP COLUMN image_path");
        }
    }
    
    // Add last_seen to users table
    $checkUsers = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen'");
    if (!$checkUsers->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_seen datetime DEFAULT NULL AFTER last_login");
    }
    
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // Ignore errors
}

if ($action === 'get_recent') {
    // Get recent chats, unread count, and other user's last seen
    $stmt = $pdo->prepare("
        SELECT 
            cr.id,
            CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END as other_user_id,
            u.name as other_user_name,
            u.avatar as other_user_avatar,
            u.last_seen as other_user_last_seen,
            (
                SELECT COUNT(*) FROM internal_chat_messages 
                WHERE room_id = cr.id 
                AND sender_id != ? 
                AND is_read = 0
            ) as unread_count,
            (
                SELECT message FROM internal_chat_messages 
                WHERE room_id = cr.id 
                ORDER BY created_at DESC 
                LIMIT 1
            ) as last_message,
            (
                SELECT file_path FROM internal_chat_messages 
                WHERE room_id = cr.id 
                ORDER BY created_at DESC 
                LIMIT 1
            ) as last_file,
            (
                SELECT file_name FROM internal_chat_messages 
                WHERE room_id = cr.id 
                ORDER BY created_at DESC 
                LIMIT 1
            ) as last_file_name,
            (
                SELECT file_type FROM internal_chat_messages 
                WHERE room_id = cr.id 
                ORDER BY created_at DESC 
                LIMIT 1
            ) as last_file_type,
            (
                SELECT created_at FROM internal_chat_messages 
                WHERE room_id = cr.id 
                ORDER BY created_at DESC 
                LIMIT 1
            ) as last_message_time
        FROM internal_chat_rooms cr
        LEFT JOIN users u ON u.id = CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END
        WHERE cr.tenant_id = ? 
        AND (cr.user1_id = ? OR cr.user2_id = ?)
        ORDER BY cr.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $tenant_id, $current_user_id, $current_user_id]);
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'chats' => $chats]);
    exit;
}

if ($action === 'get_messages' && isset($_GET['room_id'])) {
    $room_id = (int)$_GET['room_id'];
    
    // Verify user is in this room
    $check = $pdo->prepare("SELECT 1 FROM internal_chat_rooms WHERE id = ? AND tenant_id = ? AND (user1_id = ? OR user2_id = ?)");
    $check->execute([$room_id, $tenant_id, $current_user_id, $current_user_id]);
    if (!$check->fetch()) {
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    
    // Mark messages as read
    $stmtMark = $pdo->prepare("UPDATE internal_chat_messages SET is_read = 1 WHERE room_id = ? AND sender_id != ?");
    $stmtMark->execute([$room_id, $current_user_id]);
    
    // Get messages
    $stmtMsgs = $pdo->prepare("
        SELECT m.*, u.name as sender_name 
        FROM internal_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.room_id = ? 
        ORDER BY m.id ASC
    ");
    $stmtMsgs->execute([$room_id]);
    $messages = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);
    
    // Get room info
    $stmtRoom = $pdo->prepare("
        SELECT cr.*, 
            u1.name as user1_name, u1.avatar as user1_avatar, u1.last_seen as user1_last_seen,
            u2.name as user2_name, u2.avatar as user2_avatar, u2.last_seen as user2_last_seen
        FROM internal_chat_rooms cr
        LEFT JOIN users u1 ON cr.user1_id = u1.id
        LEFT JOIN users u2 ON cr.user2_id = u2.id
        WHERE cr.id = ? AND cr.tenant_id = ?
    ");
    $stmtRoom->execute([$room_id, $tenant_id]);
    $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
    $isU1 = ((int)$room['user1_id'] === (int)$current_user_id);
    $other_user_id = $isU1 ? $room['user2_id'] : $room['user1_id'];
    $other_user_name = $isU1 ? $room['user2_name'] : $room['user1_name'];
    $other_user_avatar = $isU1 ? $room['user2_avatar'] : $room['user1_avatar'];
    $other_user_last_seen = $isU1 ? $room['user2_last_seen'] : $room['user1_last_seen'];
    
    echo json_encode([
        'success' => true, 
        'messages' => $messages, 
        'other_user_id' => $other_user_id,
        'other_user_name' => $other_user_name,
        'other_user_avatar' => $other_user_avatar,
        'other_user_last_seen' => $other_user_last_seen
    ]);
    exit;
}

if ($action === 'send_message' && isset($_POST['room_id'])) {
    $room_id = (int)$_POST['room_id'];
    $message = trim($_POST['message'] ?? '');
    $filePath = null;
    $fileName = null;
    $fileType = null;
    $fileSize = null;
    
    // Verify user is in this room
    $check = $pdo->prepare("SELECT 1 FROM internal_chat_rooms WHERE id = ? AND tenant_id = ? AND (user1_id = ? OR user2_id = ?)");
    $check->execute([$room_id, $tenant_id, $current_user_id, $current_user_id]);
    if (!$check->fetch()) {
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    
    // Handle file upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat_files' . DIRECTORY_SEPARATOR;
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Allowed file types: images, pdf, word
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['file']['tmp_name']);
        $fileSize = $_FILES['file']['size'];
        
        if (in_array($mimeType, $allowedTypes) && $fileSize <= $maxFileSize) {
            // La extensión sale del tipo real detectado (nunca del nombre enviado): evita subir .php disfrazado de imagen
            $extMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'application/pdf' => 'pdf', 'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];
            $ext = $extMap[$mimeType];
            $fileName = mb_substr(preg_replace('/[\x00-\x1F<>"\\\/]+/u', '', basename((string)$_FILES['file']['name'])), 0, 150);
            if ($fileName === '') $fileName = 'archivo.' . $ext;
            $uniqueFileName = 'chat_' . $room_id . '_' . time() . '_' . uniqid() . '.' . $ext;
            $dest = $uploadDir . $uniqueFileName;
            $relativeDest = 'uploads/chat_files/' . $uniqueFileName;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $filePath = $relativeDest;
                $fileType = $mimeType;
            }
        }
    }
    
    // Save message with optional file
    $stmt = $pdo->prepare("INSERT INTO internal_chat_messages (room_id, sender_id, message, file_path, file_name, file_type, file_size, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
    $stmt->execute([$room_id, $current_user_id, $message, $filePath, $fileName, $fileType, $fileSize]);
    
    // Update room's updated_at
    $pdo->prepare("UPDATE internal_chat_rooms SET updated_at = NOW() WHERE id = ?")->execute([$room_id]);
    
    echo json_encode(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    exit;
}

if ($action === 'get_user_profile' && isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    
    // Verify user is in same tenant
    $stmt = $pdo->prepare("SELECT id, name, email, avatar, role, created_at, last_seen FROM users WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$user_id, $tenant_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }
    
    echo json_encode(['success' => true, 'user' => $user]);
    exit;
}

if ($action === 'start_chat' && isset($_POST['user_id'])) {
    $other_user_id = (int)$_POST['user_id'];
    
    if ($other_user_id === $current_user_id) {
        echo json_encode(['error' => 'No puedes chatear contigo mismo']);
        exit;
    }
    
    // Verify other user is in same tenant
    $checkUser = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ?");
    $checkUser->execute([$other_user_id, $tenant_id]);
    if (!$checkUser->fetch()) {
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }
    
    // Check if room already exists
    $checkRoom = $pdo->prepare("
        SELECT id FROM internal_chat_rooms 
        WHERE tenant_id = ? 
        AND (
            (user1_id = ? AND user2_id = ?) 
            OR (user1_id = ? AND user2_id = ?)
        )
    ");
    $checkRoom->execute([$tenant_id, $current_user_id, $other_user_id, $other_user_id, $current_user_id]);
    $existing = $checkRoom->fetch();
    
    if ($existing) {
        echo json_encode(['success' => true, 'room_id' => $existing['id']]);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO internal_chat_rooms (tenant_id, user1_id, user2_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->execute([$tenant_id, $current_user_id, $other_user_id]);
    
    echo json_encode(['success' => true, 'room_id' => $pdo->lastInsertId()]);
    exit;
}

echo json_encode(['error' => 'Acción inválida']);
exit;
?>