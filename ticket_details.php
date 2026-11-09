<?php
require_once 'includes/header.php';
checkAuth();
require_once 'config/db.php';
require_once 'includes/mailer.php';
require_once 'includes/email_templates.php';
require_once 'includes/csrf.php';
require_once 'includes/telegram_helper.php';

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}
$ticket_id = $_GET['id'];
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$tenant_id = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;


$stmt = $pdo->prepare("SELECT t.*, 
                              u.name as user_name, u.email as user_email, u.avatar as user_avatar,
                              c.name as category_name,
                              tech.name as tech_name, tech.email as tech_email
                       FROM tickets t 
                       JOIN users u ON t.user_id = u.id 
                       LEFT JOIN categories c ON t.category_id = c.id 
                       LEFT JOIN users tech ON t.assigned_tech_id = tech.id
                       WHERE t.id = ? AND t.tenant_id = ?");
$stmt->execute([$ticket_id, $tenant_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo "Ticket no encontrado.";
    exit;
}

// Check permissions (Admin/Tech can see all, User can only see theirs)
if ($role == 'user' && $ticket['user_id'] != $user_id) {
    echo "Acceso denegado.";
    exit;
}

try {
    $stt = $pdo->prepare("SELECT tech.id, tech.name, tech.email, tech.avatar FROM ticket_techs tt JOIN users tech ON tech.id = tt.tech_id WHERE tt.ticket_id = ? AND tt.active = 1 ORDER BY tech.name");
    $stt->execute([$ticket_id]);
    $assigned_techs = $stt->fetchAll();
} catch (Throwable $e) { $assigned_techs = []; }

// Obtener todos los solicitantes (múltiples)
$all_solicitors = [];
try {
    $stS = $pdo->prepare("SELECT u.id, u.name, u.email, u.avatar FROM ticket_solicitors ts JOIN users u ON ts.user_id = u.id WHERE ts.ticket_id = ? ORDER BY u.name");
    $stS->execute([$ticket_id]);
    $all_solicitors = $stS->fetchAll();
} catch (Throwable $e) {}
// Fallback: si no hay en la tabla nueva, usar el principal
if (empty($all_solicitors)) {
    $all_solicitors = [['id' => $ticket['user_id'], 'name' => $ticket['user_name'], 'email' => $ticket['user_email'], 'avatar' => $ticket['user_avatar']]];
}
function sanitize_html($html) {
    $allowed = '<p><br><b><strong><i><em><ul><ol><li><a><img><video><source>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/on\\w+=/i', '', $clean);
    $clean = preg_replace('/javascript:/i', '', $clean);
    return $clean;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message'])) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $message = sanitize_html($_POST['message']);
    $onlyWhitespace = trim(strip_tags($message)) === '';
    if ($onlyWhitespace) {
        $error = "Escribe un mensaje de texto antes de enviar archivos o imágenes.";
    } else {
    $stmt = $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$ticket_id, $user_id, $message]);
    $message_id = $pdo->lastInsertId();

    $emailAttachments = [];
    if (!empty($_FILES['attachments']['name'][0])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $maxSize = 20 * 1024 * 1024; // Límite de 20MB
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            $name = $_FILES['attachments']['name'][$i];
            $tmp  = $_FILES['attachments']['tmp_name'][$i];
            $type = $_FILES['attachments']['type'][$i];
            $size = $_FILES['attachments']['size'][$i] ?? 0;
            if (!is_uploaded_file($tmp)) continue;
            if ($size <= 0 || $size > $maxSize) {
                $_SESSION['error'] = "El archivo '$name' supera el límite de 20MB.";
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowedExt = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','7z','rar'];
            if (!in_array($ext, $allowedExt, true)) continue;
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            $destRel = 'uploads/' . $safeName;
            $destAbs = __DIR__ . '/' . $destRel;
            if (!move_uploaded_file($tmp, $destAbs)) continue;
            $ins = $pdo->prepare("INSERT INTO attachments (ticket_id, message_id, user_id, file_name, file_path, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$ticket_id, $message_id, $user_id, $name, $destRel, $type]);
            $emailAttachments[] = ['path' => $destAbs, 'name' => $name];
        }
    }

    $stmt = $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$ticket_id]);

    $display_id = $ticket['local_id'] ?: $ticket_id;
    $subj = "Actualización en Ticket #$display_id";
    $msgEmail = preg_replace(['#<p[^>]*>#i', '#</p>#i'], ['', '<br><br>'], $message);
    $authorName = '';
    try {
        $aq = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $aq->execute([$user_id]);
        $authorName = (string)$aq->fetchColumn();
    } catch (Throwable $e) {}
    $body = tpl_message_added($display_id, $msgEmail, $authorName);
    
    // Liberar el bloqueo de la sesión antes de enviar correos.
    // Esto permite que el usuario siga navegando por la web mientras se envían los correos.
    session_write_close();

    // Optimización: Recolectar todos los correos para enviar una sola vez
    $allRecipients = [];
    if (!empty($ticket['user_email'])) {
        $allRecipients[] = $ticket['user_email'];
    }
    if (!empty($assigned_techs)) {
        foreach ($assigned_techs as $t) {
            $tid = (int)$t['id'];
            $temail = $t['email'] ?? '';
            if ($tid === (int)$user_id || empty($temail)) continue;
            $allRecipients[] = $temail;
        }
    }
    
    if (!empty($allRecipients)) {
        @send_mail($allRecipients, $subj, $body, null, $emailAttachments, $tenant_id);
    }

    // Notify via Telegram
    try {
        telegram_notify_ticket_update($pdo, $ticket_id, "Se añadió un nuevo mensaje de: " . ($authorName ?: 'Staff'), 'info');
    } catch (Throwable $e) {}

    header("Location: ticket_details.php?id=$ticket_id");
    exit;
    }
}

// Fetch Categories for dropdown
try {
    $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ? ORDER BY name ASC");
    $catStmt->execute([$tenant_id]);
    $categories = $catStmt->fetchAll();
} catch (Throwable $e) { $categories = []; }

// Handle Category Change (Admin/Tech/Supervisor)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_category']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $new_category_id = (int)$_POST['category_id'];
    
    $old_cat = $ticket['category_name'];
    $stmtC = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmtC->execute([$new_category_id]);
    $new_cat = $stmtC->fetchColumn();

    if ($new_cat && $new_cat !== $old_cat) {
        $author_name = $_SESSION['name'] ?? 'Staff';
        $history_msg = "Categoría cambiada de <b>$old_cat</b> a <b>$new_cat</b> por <b>$author_name</b>";
        
        // Calcular nueva fecha de vencimiento según configuración de SLA
        $stmtSLA = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ?");
        $stmtSLA->execute([$new_category_id]);
        $sla = $stmtSLA->fetch();

        $val = $sla ? (int)$sla['resolution_time_hours'] : 48;
        $unit = $sla ? $sla['resolution_unit'] : 'hours';
        $hours = ($unit == 'days') ? ($val * 24) : $val;

        // Aplicar multiplicadores por prioridad (igual que en create_ticket.php)
        $priority = $ticket['priority'];
        if ($priority == 'critical') {
            $hours = max(1, round($hours * 0.25));
        } elseif ($priority == 'high') {
            $hours = max(2, round($hours * 0.5));
        } elseif ($priority == 'low') {
            $hours = round($hours * 1.5);
        }

        // Calcular nueva fecha de vencimiento BASADA EN LA FECHA DE CREACIÓN DEL TICKET
        $created_at = $ticket['created_at'];
        $new_due_date = date('Y-m-d H:i:s', strtotime($created_at . " +$hours hours"));
        $history_msg .= "<br>Vencimiento actualizado a: <b>" . date('d/m/Y H:i', strtotime($new_due_date)) . "</b>";
        
        $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)")->execute([$ticket_id, $user_id, $history_msg]);
        
        $stmt = $pdo->prepare("UPDATE tickets SET category_id = ?, due_date = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_category_id, $new_due_date, $ticket_id, $tenant_id]);
    }
    
    header("Location: ticket_details.php?id=$ticket_id");
    exit;
}

// Handle CSAT Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_csat']) && $role == 'user' && $ticket['status'] == 'closed') {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $rating = (int)$_POST['rating'];
    $feedback = trim($_POST['feedback']);
    if ($rating >= 1 && $rating <= 5) {
        $stmt = $pdo->prepare("UPDATE tickets SET csat_rating = ?, csat_feedback = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$rating, $feedback, $ticket_id]);
        
        // Registrar en historial del chat
        $author_name = $_SESSION['name'] ?? 'Usuario';
        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
        $msg = "<b>Encuesta de Satisfacción completada:</b><br>$stars ($rating/5)";
        if (!empty($feedback)) $msg .= "<br><i>\"" . htmlspecialchars($feedback) . "\"</i>";
        $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)")->execute([$ticket_id, $user_id, $msg]);
        
        header("Location: ticket_details.php?id=$ticket_id");
        exit;
    }
}

// Relacionar activo con el ticket (solo admin/tech)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_asset']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $asset_id = isset($_POST['asset_id']) && is_numeric($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    if ($asset_id) {
        $chk = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
        $chk->execute([$asset_id]);
        if ($chk->fetchColumn()) {
            // Check if already related
            $checkRel = $pdo->prepare("SELECT id FROM ticket_assets WHERE ticket_id = ? AND asset_id = ?");
            $checkRel->execute([$ticket_id, $asset_id]);
            if ($checkRel->fetchColumn()) {
                $_SESSION['asset_error'] = "Este equipo ya está relacionado con el ticket.";
            } else {
                $ins = $pdo->prepare("INSERT INTO ticket_assets (ticket_id, asset_id) VALUES (?, ?)");
                $ins->execute([$ticket_id, $asset_id]);
                // Para retrocompatibilidad (opcional, mantener asset_id principal si estaba vacío)
                if (empty($ticket['asset_id'])) {
                    $pdo->prepare("UPDATE tickets SET asset_id = ?, updated_at = NOW() WHERE id = ?")->execute([$asset_id, $ticket_id]);
                } else {
                    $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
                }
            }
            header("Location: ticket_details.php?id=$ticket_id");
            exit;
        } else {
            $_SESSION['asset_error'] = "Equipo no válido.";
        }
    }
}

// Eliminar relación de activo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_asset']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $asset_id = (int)$_POST['remove_asset_id'];
    $pdo->prepare("DELETE FROM ticket_assets WHERE ticket_id = ? AND asset_id = ?")->execute([$ticket_id, $asset_id]);
    
    // Si era el asset_id principal de la tabla tickets, limpiarlo o poner el siguiente disponible
    if ((int)$ticket['asset_id'] === $asset_id) {
        $next = $pdo->prepare("SELECT asset_id FROM ticket_assets WHERE ticket_id = ? LIMIT 1");
        $next->execute([$ticket_id]);
        $nextId = $next->fetchColumn() ?: null;
        $pdo->prepare("UPDATE tickets SET asset_id = ?, updated_at = NOW() WHERE id = ?")->execute([$nextId, $ticket_id]);
    } else {
        $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
    }
    header("Location: ticket_details.php?id=$ticket_id");
    exit;
}

// Handle Status Change (Admin/Tech only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $new_status = $_POST['status'];
    $new_priority = $_POST['priority'];
    
    // Trazabilidad: Registrar cambios en el chat
    $history_msgs = [];
    if ($new_status !== $ticket['status']) {
        $status_color = ($new_status === 'closed') ? 'status-closed' : (($new_status === 'pending') ? 'warning' : 'primary');
        $old_status_color = ($ticket['status'] === 'closed') ? 'status-closed' : (($ticket['status'] === 'pending') ? 'warning' : 'primary');
        $history_msgs[] = "Estado cambiado de <span class='badge bg-$old_status_color'>" . t_status($ticket['status']) . "</span> a <span class='badge bg-$status_color'>" . t_status($new_status) . "</span>";
    }
    if ($new_priority !== $ticket['priority']) {
        $history_msgs[] = "Prioridad cambiada de <span class='badge bg-" . p_color($ticket['priority']) . "'>" . t_priority($ticket['priority']) . "</span> a <span class='badge bg-" . p_color($new_priority) . "'>" . t_priority($new_priority) . "</span>";
    }

    if (!empty($history_msgs)) {
        $author_name = $_SESSION['name'] ?? 'Staff';
        $final_history_msg = implode("<br>", $history_msgs) . " por <b>$author_name</b>";
        
        // RECALCULAR VENCIMIENTO SI LA PRIORIDAD CAMBIÓ
        $new_due_date = $ticket['due_date'];
        if ($new_priority !== $ticket['priority']) {
            $stmtSLA = $pdo->prepare("SELECT * FROM sla_config WHERE category_id = ?");
            $stmtSLA->execute([$ticket['category_id']]);
            $sla = $stmtSLA->fetch();

            $val = $sla ? (int)$sla['resolution_time_hours'] : 48;
            $unit = $sla ? $sla['resolution_unit'] : 'hours';
            $hours = ($unit == 'days') ? ($val * 24) : $val;

            if ($new_priority == 'critical') {
                $hours = max(1, round($hours * 0.25));
            } elseif ($new_priority == 'high') {
                $hours = max(2, round($hours * 0.5));
            } elseif ($new_priority == 'low') {
                $hours = round($hours * 1.5);
            }

            $created_at = $ticket['created_at'];
            $new_due_date = date('Y-m-d H:i:s', strtotime($created_at . " +$hours hours"));
            $final_history_msg .= "<br>Vencimiento actualizado por cambio de prioridad a: <b>" . date('d/m/Y H:i', strtotime($new_due_date)) . "</b>";
        }

        $pdo->prepare("INSERT INTO messages (ticket_id, user_id, message) VALUES (?, ?, ?)")->execute([$ticket_id, $user_id, $final_history_msg]);
        
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, priority = ?, due_date = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_status, $new_priority, $new_due_date, $ticket_id, $tenant_id]);
    } else {
        // Solo actualizar status si no hay mensajes de historial (aunque esto no debería pasar si algo cambió)
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, priority = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$new_status, $new_priority, $ticket_id, $tenant_id]);
    }
    
    $display_id = $ticket['local_id'] ?: $ticket_id;
    if (!empty($ticket['user_email'])) {
        $subj = "Ticket #$display_id actualizado";
        $body = tpl_status_updated($display_id, $new_status, $new_priority);
        @send_mail($ticket['user_email'], $subj, $body, null, [], $tenant_id);
    }
    
    // Notify via Telegram (Status/Priority Change)
    try {
        $author_name = $_SESSION['name'] ?? 'Staff';
        $status_text = t_status($new_status);
        $priority_text = t_priority($new_priority);
        $telegram_msg = "Estado: *$status_text* | Prioridad: *$priority_text*\nActualizado por: *$author_name*";
        telegram_notify_ticket_update($pdo, $ticket_id, $telegram_msg, 'status');
    } catch (Throwable $e) {}

    try {
        $txt = "Estado: $new_status · Prioridad: $new_priority";
        notify_add($pdo, (int)$ticket['user_id'], 'ticket_status', "Ticket #$display_id actualizado", $txt, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
        if (!empty($ticket['assigned_tech_id']) && (int)$ticket['assigned_tech_id'] !== (int)$ticket['user_id'] && (int)$ticket['assigned_tech_id'] !== (int)$user_id) {
            notify_add($pdo, (int)$ticket['assigned_tech_id'], 'ticket_status', "Ticket #$display_id actualizado", $txt, "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
        }
    } catch (Throwable $e) {}
    
    header("Location: ticket_details.php?id=$ticket_id");
    exit;
}

// Asignar ticket a técnico (Admin/Tech)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_tech']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $tech_email = isset($_POST['tech_email']) ? trim($_POST['tech_email']) : '';
    if ($tech_email === '') {
        $error = "Ingresa el correo del técnico.";
    } else {
        $st = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND role IN ('tech', 'supervisor', 'admin')");
        $st->execute([$tech_email]);
        $tech = $st->fetch();
        if (!$tech) {
            $error = "No se encontró un técnico, supervisor o administrador con ese correo.";
        } else {
            $chk = $pdo->prepare("SELECT active FROM ticket_techs WHERE ticket_id = ? AND tech_id = ?");
            $chk->execute([$ticket_id, $tech['id']]);
            $row = $chk->fetch();
            if (!$row) {
                $ins = $pdo->prepare("INSERT INTO ticket_techs (ticket_id, tech_id, active) VALUES (?, ?, 1)");
                $ins->execute([$ticket_id, $tech['id']]);
            } else {
                if ((int)$row['active'] === 0) {
                    $up = $pdo->prepare("UPDATE ticket_techs SET active = 1 WHERE ticket_id = ? AND tech_id = ?");
                    $up->execute([$ticket_id, $tech['id']]);
                }
            }
            if (empty($ticket['assigned_tech_id'])) {
                $upd = $pdo->prepare("UPDATE tickets SET assigned_tech_id = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$tech['id'], $ticket_id]);
            } else {
                $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
            }
            // Notificar al técnico
            if (!empty($tech['email'])) {
                $display_id = $ticket['local_id'] ?: $ticket_id;
                $subj = "Ticket #$display_id asignado";
                $body = "Hola ".$tech['name'].",\n\nSe te ha asignado el ticket #".$display_id." (".$ticket['title'].").\n\nIngresa al sistema para gestionarlo.\n";
                @send_mail($tech['email'], $subj, nl2br(htmlspecialchars($body)), null, [], $tenant_id);
            }
            try {
                $display_id = $ticket['local_id'] ?: $ticket_id;
                notify_add($pdo, (int)$tech['id'], 'ticket_assigned', "Te asignaron el ticket #$display_id", $ticket['title'], "ticket_details.php?id=$ticket_id", 'ticket', $ticket_id);
            } catch (Throwable $e) {}
            header("Location: ticket_details.php?id=$ticket_id");
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['leave_case']) && ($role == 'tech' || $role == 'admin' || $role == 'supervisor')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    
    // Si es técnico, validamos que no sea el único asignado
    if ($role == 'tech') {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ticket_techs WHERE ticket_id = ? AND active = 1");
        $cntStmt->execute([$ticket_id]);
        $totalActive = (int)$cntStmt->fetchColumn();
        if ($totalActive <= 1) {
            $error = "No puedes dejar el caso porque eres el único técnico asignado.";
        }
    }

    if (!isset($error)) {
        // Desactivar relación en ticket_techs
        $pdo->prepare("UPDATE ticket_techs SET active = 0 WHERE ticket_id = ? AND tech_id = ?")->execute([$ticket_id, $user_id]);
        
        // Si era el técnico principal asignado, liberar el campo
        if (!empty($ticket['assigned_tech_id']) && (int)$ticket['assigned_tech_id'] === (int)$user_id) {
            $pdo->prepare("UPDATE tickets SET assigned_tech_id = NULL, updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
        } else {
            $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
        }
        header("Location: ticket_details.php?id=$ticket_id");
        exit;
    }
}

// Link to Problem
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['link_problem']) && ($role == 'admin' || $role == 'supervisor' || $role == 'tech')) {
    if (!csrf_check()) { http_response_code(403); exit('CSRF inválido'); }
    $prob_id = empty($_POST['problem_id']) ? null : (int)$_POST['problem_id'];
    $pdo->prepare("UPDATE tickets SET problem_id = ?, updated_at = NOW() WHERE id = ?")->execute([$prob_id, $ticket_id]);
    header("Location: ticket_details.php?id=$ticket_id");
    exit;
}

// Fetch Messages
$stmt = $pdo->prepare("SELECT m.*, u.name as user_name, u.role as user_role, u.avatar as user_avatar
                       FROM messages m 
                       JOIN users u ON m.user_id = u.id 
                       WHERE m.ticket_id = ? 
                       ORDER BY m.created_at ASC");
$stmt->execute([$ticket_id]);
$messages = $stmt->fetchAll();
$attachmentsByMessage = [];
if ($messages) {
    $ids = array_column($messages, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT * FROM attachments WHERE message_id IN ($in)");
    $q->execute($ids);
    while ($row = $q->fetch()) {
        $attachmentsByMessage[$row['message_id']][] = $row;
    }
}

// Check if Overdue
$is_overdue = (!empty($ticket['due_date']) && strtotime($ticket['due_date']) < time() && $ticket['status'] != 'closed');
?>

<?php $pageTitle = 'Detalle del ticket'; require_once 'includes/navbar.php'; ?>

<style>
    .chat-box {
        display: flex;
        flex-direction: column;
        gap: 1.0rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 12px;
        max-height: 600px;
        overflow-y: auto;
    }
    .ticket-details-scroll {
        max-height: 600px;
        overflow-y: auto;
    }
    .message-wrapper {
        display: flex;
        gap: 0.75rem;
        max-width: 85%;
    }
    .message-wrapper.self {
        align-self: flex-end;
        flex-direction: row-reverse;
    }
    .message-wrapper.other {
        align-self: flex-start;
    }
    .avatar-wrapper {
        flex-shrink: 0;
    }
    .avatar-img {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        object-fit: cover;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: white;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    .avatar-default {
        background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
        font-size: 0.9rem;
    }
    .message-bubble {
        padding: 0;
        border-radius: 8px;
        position: relative;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        min-width: 220px;
        background: white;
    }
    /* Punteros de la burbuja */
    .message-bubble::after {
        content: "";
        position: absolute;
        top: 12px;
        width: 0;
        height: 0;
        border: 8px solid transparent;
    }
    .other .message-bubble::after {
        left: -16px;
        border-right-color: inherit;
    }
    .self .message-bubble::after {
        right: -16px;
        border-left-color: inherit;
    }

    /* Colores por rol/tipo */
    .bubble-admin { background-color: #f0fdf4; border: 1px solid #dcfce7; border-left-color: #f0fdf4; border-right-color: #f0fdf4; }
    .bubble-tech { background-color: #f0fdf4; border: 1px solid #dcfce7; border-left-color: #f0fdf4; border-right-color: #f0fdf4; }
    .bubble-user { background-color: #f8fafc; border: 1px solid #e2e8f0; border-left-color: #f8fafc; border-right-color: #f8fafc; }
    .bubble-other { background-color: #fffbeb; border: 1px solid #fef3c7; border-left-color: #fffbeb; border-right-color: #fffbeb; }

    /* Ajuste para que el puntero use el background de la burbuja */
    .other .bubble-admin::after, .other .bubble-tech::after { border-right-color: #f0fdf4; }
    .other .bubble-user::after { border-right-color: #f8fafc; }
    .self .bubble-admin::after, .self .bubble-tech::after { border-left-color: #f0fdf4; }
    .self .bubble-user::after { border-left-color: #f8fafc; }

    .bubble-header {
        padding: 0.4rem 0.6rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        font-size: 0.65rem;
        background: rgba(0,0,0,0.02);
        border-radius: 8px 8px 0 0;
    }
    .header-tag {
        background: #e2e8f0;
        padding: 1px 6px;
        border-radius: 4px;
        color: #475569;
        font-weight: 700;
        text-transform: uppercase;
    }
    .header-user {
        color: #2563eb;
        font-weight: 700;
        text-decoration: none;
    }
    .bubble-content {
        padding: 0.5rem 0.7rem;
        color: #334155;
        font-size: 0.75rem;
        line-height: 1.3;
    }
    .bubble-footer {
        padding: 0.4rem 0.6rem;
        border-top: 1px solid rgba(0,0,0,0.03);
    }
    .attachment-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .attachment-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 2px 0;
        font-size: 0.7rem;
        color: #64748b;
    }
    .attachment-item a {
        text-decoration: none;
        color: #2563eb;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .attachment-item a:hover { text-decoration: underline; }
    .bubble-footer img {
        max-width: 240px;
        height: auto;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        display: block;
    }
</style>

<div class="container-fluid px-4">
    <nav aria-label="breadcrumb" class="my-2">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php" title="Inicio" aria-label="Inicio"><i class="fas fa-home"></i></a></li>
            <li class="breadcrumb-item"><a href="dashboard.php">Tickets</a></li>
            <li class="breadcrumb-item active" aria-current="page">Ticket #<?php echo (int)$ticket['local_id']; ?></li>
        </ol>
    </nav>
    <div class="row my-2">
        <!-- Ticket Info & Chat -->
        <div class="col-lg-9">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <h6 class="mb-0 fw-bold">
                        #<?php echo $ticket['local_id']; ?> - <?php echo htmlspecialchars($ticket['title']); ?>
                        <?php if($is_overdue): ?>
                            <span class="badge bg-danger ms-2" style="font-size: 0.65rem;">VENCIDO</span>
                        <?php endif; ?>
                    </h6>
                    <span class="badge bg-<?php echo ($ticket['status'] === 'closed') ? 'status-closed' : 'secondary'; ?>" style="font-size: 0.65rem;"><?php echo t_status($ticket['status']); ?></span>
                </div>
                <div class="card-body">
                    <div class="chat-box mb-3" id="chatBox">
                        <?php 
                        // Omitimos el mensaje inicial si ya existe un mensaje idéntico en el historial
                        // (esto sucede cuando el sistema guarda la descripción como el primer mensaje de la tabla 'messages')
                        $show_initial_desc = true;
                        if (!empty($messages)) {
                            foreach ($messages as $msg) {
                                // Comparamos el contenido plano para evitar duplicidad visual
                                if (trim(strip_tags($msg['message'])) === trim(strip_tags($ticket['description']))) {
                                    $show_initial_desc = false;
                                    break;
                                }
                            }
                        }
                        
                        if ($show_initial_desc): 
                        ?>
                        <!-- Mensaje Inicial (Descripción del Ticket) -->
                        <div class="message-wrapper other mb-4">
                            <div class="avatar-wrapper">
                                <?php 
                                    $avatar_path = $ticket['user_avatar'];
                                    $has_avatar = !empty($avatar_path) && file_exists(__DIR__ . '/' . $avatar_path);
                                ?>
                                <?php if ($has_avatar): ?>
                                    <img src="<?php echo htmlspecialchars($avatar_path); ?>" class="avatar-img" alt="Avatar">
                                <?php else: ?>
                                    <div class="avatar-img avatar-default" title="<?php echo htmlspecialchars($ticket['user_name']); ?>">
                                        <?php echo strtoupper(substr($ticket['user_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="message-bubble bubble-other">
                                <div class="bubble-header">
                                    <span class="header-tag bg-primary text-white">DESCRIPCIÓN INICIAL</span>
                                    <span class="text-muted">POR</span>
                                    <a href="#" class="header-user"><?php echo htmlspecialchars(strtoupper($ticket['user_name'])); ?></a>
                                    <span class="ms-auto text-muted"><?php echo date('H:i', strtotime($ticket['created_at'])); ?></span>
                                </div>
                                <div class="bubble-content">
                                    <h6 class="fw-bold mb-2"><?php echo htmlspecialchars($ticket['title']); ?></h6>
                                    <?php echo nl2br($ticket['description']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php foreach ($messages as $msg): ?>
                            <?php 
                                $is_me = ($msg['user_id'] == $user_id); 
                                $bubble_class = 'bubble-user';
                                if ($msg['user_role'] === 'admin') $bubble_class = 'bubble-admin';
                                elseif ($msg['user_role'] === 'tech') $bubble_class = 'bubble-tech';
                                
                                $avatar_path = $msg['user_avatar'];
                                $has_avatar = !empty($avatar_path) && file_exists(__DIR__ . '/' . $avatar_path);
                            ?>
                            <div class="message-wrapper <?php echo $is_me ? 'self' : 'other'; ?> mb-4">
                                <div class="avatar-wrapper">
                                    <?php if ($has_avatar): ?>
                                        <img src="<?php echo htmlspecialchars($avatar_path); ?>" class="avatar-img" alt="Avatar">
                                    <?php else: ?>
                                        <div class="avatar-img avatar-default" title="<?php echo htmlspecialchars($msg['user_name']); ?>">
                                            <?php echo strtoupper(substr($msg['user_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="message-bubble <?php echo $bubble_class; ?>">
                                    <div class="bubble-header">
                                        <span class="header-tag">CREADO: <?php echo date('M Y', strtotime($msg['created_at'])); ?></span>
                                        <span class="text-muted">POR</span>
                                        <a href="#" class="header-user"><?php echo htmlspecialchars(strtoupper($msg['user_name'])); ?></a>
                                        <span class="ms-auto text-muted"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                                    </div>
                                    <div class="bubble-content">
                                        <?php echo $msg['message']; ?>
                                    </div>
                                    <?php if (!empty($attachmentsByMessage[$msg['id']])): ?>
                                        <div class="bubble-footer">
                                            <ul class="attachment-list">
                                                <?php foreach ($attachmentsByMessage[$msg['id']] as $att): ?>
                                                    <?php 
                                                        $path = htmlspecialchars($att['file_path']);
                                                        $mime = $att['mime_type'];
                                                        $is_image = strpos($mime, 'image/') === 0;
                                                    ?>
                                                    <li class="attachment-item">
                                                        <?php 
                                                            $icon = 'far fa-file';
                                                            if (strpos($mime, 'image/') === 0) $icon = 'far fa-image text-primary';
                                                            elseif (strpos($mime, 'video/') === 0) $icon = 'far fa-file-video text-info';
                                                            elseif ($mime === 'application/pdf') $icon = 'far fa-file-pdf text-danger';
                                                            elseif (strpos($mime, 'word') !== false) $icon = 'far fa-file-word text-primary';
                                                            elseif (strpos($mime, 'excel') !== false || strpos($mime, 'spreadsheet') !== false) $icon = 'far fa-file-excel text-success';
                                                            elseif (strpos($mime, 'powerpoint') !== false || strpos($mime, 'presentation') !== false) $icon = 'far fa-file-powerpoint text-warning';
                                                            elseif (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false || strpos($mime, '7z') !== false) $icon = 'far fa-file-archive text-secondary';
                                                        ?>
                                                        <a href="<?php echo $path; ?>" target="_blank">
                                                            <i class="<?php echo $icon; ?>"></i>
                                                            <span><?php echo htmlspecialchars($att['file_name']); ?></span>
                                                        </a>
                                                        <span class="text-muted small">Descargar</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($ticket['status'] != 'closed' || ($role == 'admin' || $role == 'supervisor' || $role == 'tech')): ?>
                    <?php if (isset($error)): ?><div class="alert alert-warning mb-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <form id="chatForm" action="" method="POST" enctype="multipart/form-data">
                        <?php echo csrf_input(); ?>
                        <div class="mb-2">
                            <textarea class="form-control" id="editor" name="message" placeholder="Escribe una respuesta..." rows="5"></textarea>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <label for="attachments" class="form-label mb-0 me-2"></label>
                            <input id="attachments" class="form-control" type="file" name="attachments[]" multiple aria-label="Subir anexos"
                                   accept="image/*,video/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,text/plain,application/zip,application/x-7z-compressed,application/x-rar-compressed">
                            <button id="sendBtn" class="btn btn-primary" type="submit" title="Enviar"><i class="fas fa-paper-plane me-1"></i>Enviar</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-secondary text-center m-0">Este ticket está cerrado.</div>
                        
                        <?php if ($role == 'user' && empty($ticket['csat_rating'])): ?>
                            <div class="card mt-4 border-warning shadow-sm">
                                <div class="card-header bg-warning text-dark fw-bold">
                                    <i class="fas fa-star me-2"></i>Califica nuestro servicio
                                </div>
                                <div class="card-body text-center">
                                    <p>Por favor, califica tu nivel de satisfacción con la resolución de este ticket.</p>
                                    <form action="" method="POST" class="d-inline-block text-start w-100" style="max-width: 400px;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="submit_csat" value="1">
                                        
                                        <div class="rating-stars mb-3 text-center fs-3 text-warning">
                                            <input type="radio" name="rating" id="star5" value="5" class="d-none" required><label for="star5" class="far fa-star cursor-pointer"></label>
                                            <input type="radio" name="rating" id="star4" value="4" class="d-none"><label for="star4" class="far fa-star cursor-pointer"></label>
                                            <input type="radio" name="rating" id="star3" value="3" class="d-none"><label for="star3" class="far fa-star cursor-pointer"></label>
                                            <input type="radio" name="rating" id="star2" value="2" class="d-none"><label for="star2" class="far fa-star cursor-pointer"></label>
                                            <input type="radio" name="rating" id="star1" value="1" class="d-none"><label for="star1" class="far fa-star cursor-pointer"></label>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <textarea class="form-control" name="feedback" rows="2" placeholder="Déjanos un comentario (opcional)..."></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100">Enviar Calificación</button>
                                    </form>
                                </div>
                            </div>
                            <script>
                                // Simple star rating logic
                                document.addEventListener('DOMContentLoaded', () => {
                                    const stars = document.querySelectorAll('.rating-stars label');
                                    const inputs = document.querySelectorAll('.rating-stars input');
                                    
                                    function updateStars(val) {
                                        stars.forEach((lbl, idx) => {
                                            const starVal = 5 - idx; // because of DOM order
                                            if (starVal <= val) {
                                                lbl.classList.remove('far');
                                                lbl.classList.add('fas');
                                            } else {
                                                lbl.classList.remove('fas');
                                                lbl.classList.add('far');
                                            }
                                        });
                                    }
                                    
                                    inputs.forEach(input => {
                                        input.addEventListener('change', (e) => {
                                            updateStars(e.target.value);
                                        });
                                    });

                                    // Validar tamaño de archivos (20MB)
                                    const chatForm = document.getElementById('chatForm');
                                    if (chatForm) {
                                        chatForm.addEventListener('submit', function(e) {
                                            const fileInput = this.querySelector('input[type="file"]');
                                            if (fileInput && fileInput.files.length > 0) {
                                                const maxSize = 20 * 1024 * 1024; // 20MB
                                                for (let i = 0; i < fileInput.files.length; i++) {
                                                    if (fileInput.files[i].size > maxSize) {
                                                        alert('El archivo "' + fileInput.files[i].name + '" supera el límite de 20MB. Por favor, elige un archivo más pequeño.');
                                                        e.preventDefault();
                                                        return false;
                                                    }
                                                }
                                            }
                                        });
                                    }
                                });
                            </script>
                            <style>
                                .rating-stars { direction: rtl; unicode-bidi: bidi-override; }
                                .rating-stars label:hover, .rating-stars label:hover ~ label, .rating-stars input:checked ~ label {
                                    font-weight: 900;
                                }
                                .cursor-pointer { cursor: pointer; }
                            </style>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="col-lg-3">
            <div class="card border-0 rounded-4 mb-3 shadow-sm bg-white">
                <!-- Header -->
                <div class="p-2 d-flex align-items-center border-bottom">
                    <h5 class="mb-0 fw-bold text-dark" style="font-size: 0.95rem; color: #bfa15f !important;">Detalles del Ticket</h5>
                </div>
                
                <!-- Body -->
                <div class="p-2">
                    
                    <!-- Solicitante Card -->
                    <div class="card border-0 rounded-3 mb-2 bg-light">
                        <div class="card-body p-2">
                            <div class="text-uppercase small fw-bold mb-1 text-muted d-flex align-items-center" style="font-size: 0.65rem;">
                                <i class="far fa-user me-2"></i> SOLICITANTE
                            </div>
                            <?php foreach ($all_solicitors as $sol): ?>
                                <div class="d-flex align-items-center justify-content-between mb-1 bg-white p-1 rounded border">
                                    <div class="text-truncate pe-2">
                                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.8rem;"><?php echo htmlspecialchars($sol['name']); ?></div>
                                        <div class="text-muted text-truncate" style="font-size: 0.65rem;"><?php echo htmlspecialchars($sol['email']); ?></div>
                                    </div>
                                    <?php if (!empty($sol['avatar']) && file_exists(__DIR__ . '/' . $sol['avatar'])): ?>
                                        <img src="<?php echo htmlspecialchars($sol['avatar']); ?>" class="rounded-circle shadow-sm flex-shrink-0" style="width: 28px; height: 28px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm flex-shrink-0" style="width: 28px; height: 28px; background: #6c757d; font-size: 0.75rem;">
                                            <?php 
                                                $initials = '';
                                                $parts = explode(' ', $sol['name']);
                                                if(isset($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
                                                if(isset($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
                                                echo $initials ?: 'U';
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Details Card (Categoría, Creado) -->
                    <div class="card border-0 rounded-3 mb-2 bg-light">
                        <div class="list-group list-group-flush rounded-3">
                            <!-- Categoría -->
                            <div class="list-group-item bg-transparent p-2 d-flex flex-column justify-content-center">
                                <div class="d-flex align-items-center text-muted mb-1">
                                    <i class="fas fa-folder-open me-2" style="width: 14px;"></i>
                                    <span class="text-uppercase fw-bold" style="font-size: 0.65rem;">Categoría</span>
                                </div>
                                <?php if ($role == 'admin' || $role == 'supervisor' || $role == 'tech'): ?>
                                    <form action="" method="POST" id="categoryForm" class="m-0 p-0">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="update_category" value="1">
                                        <select name="category_id" class="form-select form-select-sm border rounded-pill bg-white fw-bold text-dark py-0 px-2" onchange="document.getElementById('categoryForm').submit();" style="font-size: 0.7rem; box-shadow: none; cursor: pointer; width: 100%; height: 26px;">
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" <?php echo $ticket['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <div class="fw-bold text-dark ms-4" style="font-size: 0.75rem;"><?php echo htmlspecialchars($ticket['category_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Creado -->
                            <div class="list-group-item bg-transparent p-2 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center text-muted">
                                    <i class="far fa-clock me-2" style="width: 14px;"></i>
                                    <span class="text-uppercase fw-bold" style="font-size: 0.6rem;">Creado</span>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-dark" style="font-size: 0.7rem;"><?php echo date('d/m/Y', strtotime($ticket['created_at'])); ?></div>
                                    <div class="text-muted" style="font-size: 0.6rem;"><?php echo date('h:i A', strtotime($ticket['created_at'])); ?></div>
                                </div>
                            </div>
                            
                            <!-- Vencimiento -->
                            <div class="list-group-item bg-transparent border-0 p-2 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center text-muted">
                                    <i class="fas fa-stopwatch me-2" style="width: 14px;"></i>
                                    <span class="text-uppercase fw-bold" style="font-size: 0.6rem;">Vencimiento</span>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-dark" style="font-size: 0.7rem;"><?php echo !empty($ticket['due_date']) ? date('d/m/Y', strtotime($ticket['due_date'])) : 'Sin fecha'; ?></div>
                                    <div class="text-muted" style="font-size: 0.6rem;"><?php echo !empty($ticket['due_date']) ? date('h:i A', strtotime($ticket['due_date'])) : '-'; ?></div>
                                </div>
                            </div>

                            <?php 
                            // Cálculos de tiempo transcurrido y restante
                            $nowTs = time();
                            $createdTs = strtotime($ticket['created_at']);
                            $dueTs = !empty($ticket['due_date']) ? strtotime($ticket['due_date']) : null;
                            $closedTs = ($ticket['status'] == 'closed') ? strtotime($ticket['updated_at']) : null;

                            function formatDiff($diff) {
                                $isNeg = $diff < 0;
                                $diff = abs($diff);
                                $d = floor($diff / 86400);
                                $h = floor(($diff % 86400) / 3600);
                                $m = floor(($diff % 3600) / 60);
                                $out = [];
                                if ($d > 0) $out[] = "{$d}d";
                                if ($h > 0) $out[] = "{$h}h";
                                $out[] = "{$m}m";
                                return ($isNeg ? "-" : "") . implode(" ", $out);
                            }

                            $elapsedTxt = formatDiff(($closedTs ?: $nowTs) - $createdTs);
                            $remainingTxt = "-";
                            $remainingClass = "text-dark";
                            if ($ticket['status'] !== 'closed' && $dueTs) {
                                $diff = $dueTs - $nowTs;
                                $remainingTxt = formatDiff($diff);
                                if ($diff < 0) $remainingClass = "text-danger fw-bold";
                                elseif ($diff < 7200) $remainingClass = "text-warning fw-bold";
                            }
                            ?>

                            <!-- Tiempo Transcurrido -->
                            <div class="list-group-item bg-transparent border-top p-2 d-flex align-items-center justify-content-between" style="background: rgba(0,0,0,0.02) !important;">
                                <div class="d-flex align-items-center text-muted">
                                    <i class="fas fa-hourglass-half me-2" style="width: 14px;"></i>
                                    <span class="text-uppercase fw-bold" style="font-size: 0.6rem;">Transcurrido</span>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-dark" style="font-size: 0.7rem;"><?php echo $elapsedTxt; ?></div>
                                </div>
                            </div>

                            <!-- Tiempo Restante -->
                            <?php if ($ticket['status'] !== 'closed'): ?>
                            <div class="list-group-item bg-transparent p-2 d-flex align-items-center justify-content-between" style="background: rgba(0,0,0,0.02) !important;">
                                <div class="d-flex align-items-center text-muted">
                                    <i class="fas fa-history me-2" style="width: 14px;"></i>
                                    <span class="text-uppercase fw-bold" style="font-size: 0.6rem;">Restante</span>
                                </div>
                                <div class="text-end">
                                    <div class="<?php echo $remainingClass; ?>" style="font-size: 0.7rem;"><?php echo $remainingTxt; ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php 
                    // Obtener todos los equipos relacionados con el ticket
                    $ticketAssets = [];
                    try {
                        $stA = $pdo->prepare("SELECT a.*, at.name as type_name, u.name as owner_name
                                             FROM ticket_assets ta
                                             JOIN assets a ON a.id = ta.asset_id
                                             LEFT JOIN asset_types at ON at.id = a.type_id
                                             LEFT JOIN users u ON u.id = a.user_id
                                             WHERE ta.ticket_id = ?
                                             ORDER BY ta.created_at ASC");
                        $stA->execute([$ticket_id]);
                        $ticketAssets = $stA->fetchAll();
                    } catch (Throwable $e) {}
                    
                    // Manejar errores de relación de activos
                    if (isset($_SESSION['asset_error'])) {
                        echo '<div class="alert alert-warning alert-dismissible fade show small p-2 mb-2" role="alert">';
                        echo htmlspecialchars($_SESSION['asset_error']);
                        echo '<button type="button" class="btn-close p-2" data-bs-dismiss="alert" aria-label="Close"></button>';
                        echo '</div>';
                        unset($_SESSION['asset_error']);
                    }
                    ?>

                    <!-- Equipos Relacionados -->
                    <div class="card border-0 rounded-3 mb-2 bg-light">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-center mb-1 text-muted">
                                <i class="fas fa-server me-2" style="width: 14px;"></i>
                                <div class="text-uppercase fw-bold" style="font-size: 0.65rem;">Equipos Relacionados (<?php echo count($ticketAssets); ?>)</div>
                            </div>

                            <?php if (empty($ticketAssets)): ?>
                                <div class="d-flex align-items-center justify-content-between p-1 rounded bg-white border">
                                    <span class="text-muted text-truncate" style="font-size: 0.7rem;">Sin equipo vinculado</span>
                                    <?php if ($role == 'admin' || $role == 'supervisor' || $role == 'tech'): ?>
                                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-2 py-0 ms-1 flex-shrink-0" style="font-size: 0.65rem; height: 22px;" onclick="document.getElementById('asset_search_detail').focus();">
                                            <i class="fas fa-plus"></i> Añadir
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-1 mb-1">
                                    <?php foreach ($ticketAssets as $asset): ?>
                                        <?php 
                                            $assetFullName = trim(($asset['serial_number'] ?: 'S/N') . ' - ' . ($asset['brand'] ?: '') . ' ' . ($asset['model'] ?: ''));
                                            $assetId = (int)$asset['id'];
                                        ?>
                                        <div class="border rounded p-1 position-relative bg-white">
                                            <?php if ($role == 'admin' || $role == 'supervisor' || $role == 'tech'): ?>
                                                <form action="" method="POST" class="position-absolute top-0 end-0 p-1" onsubmit="return confirm('¿Quitar este equipo?')">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="remove_asset" value="1">
                                                    <input type="hidden" name="remove_asset_id" value="<?php echo $assetId; ?>">
                                                    <button type="submit" class="btn btn-sm text-danger p-0 border-0" title="Quitar">
                                                        <i class="fas fa-times-circle" style="font-size: 0.7rem;"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <div class="fw-bold mb-0 pe-3 text-dark text-truncate" style="font-size: 0.75rem;">
                                                <i class="fas fa-box me-1 text-muted"></i><?php echo htmlspecialchars($assetFullName); ?>
                                            </div>
                                            
                                            <div class="text-muted" style="font-size: 0.65rem;">
                                                <div class="text-truncate"><strong>Tipo:</strong> <?php echo htmlspecialchars($asset['type_name'] ?: '—'); ?></div>
                                                <div class="text-truncate"><strong>Asignado:</strong> <?php echo htmlspecialchars($asset['owner_name'] ?: 'Disponible'); ?></div>
                                                <div class="text-truncate"><strong>Estado:</strong> <span class="text-capitalize"><?php echo htmlspecialchars($asset['status'] ?: '—'); ?></span></div>
                                            </div>
                                            
                                            <?php 
                                            $dyn = [];
                                            try {
                                                $stD = $pdo->prepare("SELECT f.field_label, v.field_value
                                                                     FROM asset_field_values v
                                                                     JOIN asset_fields f ON f.id = v.field_id
                                                                     WHERE v.asset_id = ?
                                                                     ORDER BY f.field_label");
                                                $stD->execute([$assetId]);
                                                $dyn = $stD->fetchAll() ?: [];
                                            } catch (Throwable $e) {}
                                            
                                            if (!empty($dyn)): ?>
                                                <div class="mt-1 pt-1 border-top" style="font-size: 0.65rem;">
                                                    <a class="text-decoration-none text-secondary" data-bs-toggle="collapse" href="#dyn_<?php echo $assetId; ?>">Ver detalles...</a>
                                                    <div class="collapse mt-1" id="dyn_<?php echo $assetId; ?>">
                                                        <?php foreach ($dyn as $d): ?>
                                                            <div class="d-flex justify-content-between text-truncate">
                                                                <span class="text-muted pe-1"><?php echo htmlspecialchars($d['field_label']); ?>:</span>
                                                                <span class="fw-semibold text-dark text-truncate"><?php echo htmlspecialchars($d['field_value']); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($role == 'admin' || $role == 'supervisor' || $role == 'tech'): ?>
                                <form action="" method="POST" class="mt-1 position-relative">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="set_asset" value="1">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white border-end-0 text-muted px-2"><i class="fas fa-search"></i></span>
                                        <input type="text" class="form-control border-start-0" id="asset_search_detail" placeholder="Buscar equipo..." autocomplete="off" style="font-size: 0.7rem; height: 26px;">
                                        <input type="hidden" name="asset_id" id="asset_id_detail">
                                    </div>
                                    <div id="asset-results-detail" class="list-group position-absolute w-100 mt-1 shadow-sm d-none bg-white" style="z-index: 1000; max-height: 150px; overflow-y:auto; font-size: 0.7rem;"></div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Status, Prioridad, y Técnicos -->
                    <div class="card border-0 rounded-3 bg-light">
                        <div class="card-body p-2">
                            <?php if ($role == 'admin' || $role == 'supervisor' || $role == 'tech'): ?>
                                <form action="" method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="update_status" value="1">
                                    
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center text-muted">
                                            <i class="fas fa-circle me-2 text-success" style="font-size: 0.4rem; width: 14px; text-align: center;"></i>
                                            <span class="text-uppercase fw-bold" style="font-size: 0.65rem;">Estado</span>
                                        </div>
                                        <select class="form-select form-select-sm border rounded-pill fw-bold text-dark py-0 px-2 ms-2 " name="status" style="font-size: 0.7rem; box-shadow: none; cursor: pointer; width: auto; min-width: 90px; height: 26px;">
                                            <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Abierto</option>
                                            <option value="pending" <?php echo $ticket['status'] == 'pending' ? 'selected' : ''; ?>>En Espera</option>
                                            <option value="closed" <?php echo $ticket['status'] == 'closed' ? 'selected' : ''; ?>>Cerrado</option>
                                        </select>
                                    </div>
                                    
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="d-flex align-items-center text-muted">
                                            <i class="fas fa-exclamation-triangle me-2 text-warning" style="font-size: 0.7rem; width: 14px; text-align: center;"></i>
                                            <span class="text-uppercase fw-bold" style="font-size: 0.65rem;">Prioridad</span>
                                        </div>
                                        <select class="form-select form-select-sm border rounded-pill fw-bold text-dark py-0 px-2 ms-2 " name="priority" style="font-size: 0.7rem; box-shadow: none; cursor: pointer; width: auto; min-width: 80px; height: 26px;">
                                            <option value="low" <?php echo $ticket['priority'] == 'low' ? 'selected' : ''; ?>>Baja</option>
                                            <option value="medium" <?php echo $ticket['priority'] == 'medium' ? 'selected' : ''; ?>>Media</option>
                                            <option value="high" <?php echo $ticket['priority'] == 'high' ? 'selected' : ''; ?>>Alta</option>
                                            <option value="critical" <?php echo $ticket['priority'] == 'critical' ? 'selected' : ''; ?>>Crítica</option>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100 rounded-pill py-1 fw-bold" style="font-size: 0.75rem;">Actualizar Ticket</button>
                                </form>
                                
                                <hr class="my-3 border-secondary opacity-25">
                                <div class="text-uppercase fw-bold mb-1 text-muted" style="font-size: 0.65rem;">Técnicos asignados</div>
                                <ul class="list-unstyled mb-1 ps-0 text-dark" style="font-size: 0.7rem;">
                                    <?php if (!empty($assigned_techs)): foreach ($assigned_techs as $t): ?>
                                        <li class="text-truncate d-flex justify-content-between align-items-center mb-1 p-1 bg-white rounded border">
                                            <div class="d-flex align-items-center text-truncate">
                                                <?php if (!empty($t['avatar']) && file_exists(__DIR__ . '/' . $t['avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($t['avatar']); ?>" class="rounded-circle me-2 shadow-sm" style="width: 20px; height: 20px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 shadow-sm" style="width: 20px; height: 20px; font-size: 0.6rem;">
                                                        <?php echo strtoupper(substr($t['name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="text-truncate"><?php echo htmlspecialchars($t['name']); ?></span>
                                            </div>
                                            <?php if ((int)$t['id'] === (int)$user_id): ?>
                                                <form action="" method="POST" class="m-0" onsubmit="return confirm('¿Estás seguro de que quieres abandonar este caso?');">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="leave_case" value="1">
                                                    <button type="submit" class="btn btn-link text-danger p-0 ms-2 text-decoration-none extra-small" title="Abandonar">
                                                        <i class="fas fa-sign-out-alt" style="font-size: 0.7rem;"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </li>  
                                    <?php endforeach; else: ?>
                                        <li class="text-muted small ps-2">Ninguno</li>
                                    <?php endif; ?>
                                </ul>
                                
                                <?php if ($role == 'tech' || $role == 'admin' || $role == 'supervisor'): 
                                    $isAssigned = false; 
                                    foreach ($assigned_techs as $t){ if ((int)$t['id'] === (int)$user_id) { $isAssigned = true; break; } } 
                                    $totalActive = count($assigned_techs);
                                    if ($isAssigned): ?>
                                        <div class="card border-0 rounded-3 mb-2 bg-white shadow-sm overflow-hidden">
                                            <div class="card-body p-2">
                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                    <span class="small fw-bold text-muted" style="font-size: 0.65rem;">ESTADO DE ASIGNACIÓN</span>
                                                    <span class="badge bg-success-soft text-success rounded-pill" style="font-size: 0.6rem;">ASIGNADO</span>
                                                </div>
                                                <?php if ($role != 'tech' || $totalActive > 1): ?>
                                                    <form action="" method="POST" class="m-0" onsubmit="return confirm('¿Estás seguro de que quieres abandonar este caso? Se notificará al resto del equipo.');">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="leave_case" value="1">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 rounded-pill py-1 d-flex align-items-center justify-content-center" style="font-size: 0.75rem;">
                                                            <i class="fas fa-sign-out-alt me-2"></i>Abandonar Caso
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="p-2 rounded bg-light border text-center text-muted" style="font-size: 0.7rem;">
                                                        <i class="fas fa-lock me-1"></i>Único técnico asignado
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                <?php endif; endif; ?>
                                
                                <form action="" method="POST" class="mt-2">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="assign_tech" value="1">
                                    <div class="mb-1 position-relative">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-user-plus"></i></span>
                                            <input type="email" class="form-control border-start-0" id="tech_email" name="tech_email" placeholder="Asignar técnico..." autocomplete="off" style="font-size: 0.75rem;">
                                        </div>
                                        <div id="tech-search-results" class="list-group position-absolute w-100 mt-1 shadow-sm d-none bg-white" style="z-index: 1000; max-height: 150px; overflow-y: auto; font-size: 0.75rem;">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-outline-secondary w-100 rounded-pill py-1" style="font-size: 0.75rem;">Asignar</button>
                                </form>
                            <?php else: ?>
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-circle me-2 text-success" style="font-size: 0.5rem; width: 16px; text-align: center;"></i>
                                    <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                        <div class=" fw-bold text-muted" style="font-size: 0.7rem;">Estado</div>
                                        <span class="badge bg-secondary" style="font-size: 0.7rem;"><?php echo t_status($ticket['status']); ?></span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-exclamation-triangle me-2 text-warning" style="font-size: 0.8rem; width: 16px; text-align: center;"></i>
                                    <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                        <div class=" fw-bold text-muted" style="font-size: 0.7rem;">Prioridad</div>
                                        <span class="badge bg-warning text-dark" style="font-size: 0.7rem;"><?php echo t_priority($ticket['priority']); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/config/env.php';
$tiny = app_env('TINYMCE_API_KEY', 'no-api-key'); 
?>
<script src="https://cdn.tiny.cloud/1/<?php echo htmlspecialchars($tiny); ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    tinymce.init({
        selector: '#editor',
        menubar: 'insert format',
        language: 'es',
        plugins: 'link lists image media autoresize paste',
        toolbar: 'undo redo | bold italic underline | bullist numlist | link image media | removeformat',
        toolbar_mode: 'sliding',
        toolbar_sticky: true,
        branding: false,
        statusbar: false,
        height: 220,
        autoresize_min_height: 180,
        autoresize_max_height: 320,
        paste_data_images: true,
        content_style: 'body { font-family: Nunito, sans-serif; font-size: 14px; }'
    });
    function hasContentForSubmit() {
        try {
            if (window.tinymce && tinymce.get('editor')) {
                var ed = tinymce.get('editor');
                var html = ed.getContent() || '';
                var text = ed.getContent({ format: 'text' }).trim();
                var hasMedia = /<(img|video)\b/i.test(html);
                var files = (document.getElementById('attachments')?.files || []).length;
                return text.length > 0 || hasMedia || files > 0;
            }
        } catch(e){}
        var ta = document.getElementById('editor');
        var files = (document.getElementById('attachments')?.files || []).length;
        return (ta && ta.value.trim().length > 0) || files > 0;
    }
    var sendBtn = document.getElementById('sendBtn');
    if (sendBtn) {
        sendBtn.addEventListener('click', function(e) {
            if (window.tinymce) { tinymce.triggerSave(); }
            if (!hasContentForSubmit()) {
                e.preventDefault();
                alert('Agrega un mensaje o adjunta un archivo.');
            }
        });
    }
    var form = document.getElementById('chatForm');
    if (form) form.noValidate = true;
    var chatBox = document.getElementById("chatBox");
    chatBox.scrollTop = chatBox.scrollHeight;

    // Autocompletar técnicos con búsqueda online
    document.addEventListener('DOMContentLoaded', function() {
        const techInput = document.getElementById('tech_email');
        const techResults = document.getElementById('tech-search-results');

        if (!techInput) return;

        techInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                techResults.classList.add('d-none');
                return;
            }

            fetch(`search_techs.php?q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    techResults.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(tech => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small">${tech.name}</div>
                                        <div class="text-muted small">${tech.email}</div>
                                    </div>
                                </div>
                            `;
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                techInput.value = tech.email;
                                techResults.classList.add('d-none');
                            });
                            techResults.appendChild(item);
                        });
                        techResults.classList.remove('d-none');
                    } else {
                        techResults.classList.add('d-none');
                    }
                }).catch(() => {});
        });

        document.addEventListener('click', function(e) {
            if (!techInput.contains(e.target) && !techResults.contains(e.target)) {
                techResults.classList.add('d-none');
            }
        });
    });

    // Autocompletar activos (detalle) - relaciona automáticamente al seleccionar
    (function() {
        const aInput = document.getElementById('asset_search_detail');
        const aResults = document.getElementById('asset-results-detail');
        const aHidden = document.getElementById('asset_id_detail');
        const aForm = aInput ? aInput.closest('form') : null;
        const userId = <?php echo (int)$ticket['user_id']; ?>;
        if (!aInput || !aResults || !aHidden || !aForm) return;

        // Evitar que Enter envíe el form (para no “limpiar” accidentalmente)
        aForm.addEventListener('submit', function(e) { e.preventDefault(); });
        aInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });

        let t = null;
        aInput.addEventListener('input', function() {
            const q = this.value.trim();
            aHidden.value = '';
            clearTimeout(t);

            if (q.length < 2) {
                aResults.classList.add('d-none');
                aResults.innerHTML = '';
                return;
            }

            aResults.classList.remove('d-none');
            aResults.innerHTML = '<div class="list-group-item small text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Buscando...</div>';

            t = setTimeout(function() {
                fetch(`search_assets.php?q=${encodeURIComponent(q)}&user_id=${userId}`)
                    .then(r => r.json())
                    .then(list => {
                        aResults.innerHTML = '';
                        if (Array.isArray(list) && list.length > 0) {
                            list.forEach(a => {
                                const item = document.createElement('a');
                                item.href = '#';
                                item.className = 'list-group-item list-group-item-action py-2';
                                item.innerHTML = `<div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold small">${a.serial_number || 'S/N'} - ${a.brand || ''}</div>
                                        <div class="text-muted small">${a.model || ''} ${a.owner_name ? '· ' + a.owner_name : ''}</div>
                                    </div>
                                    <i class="fas fa-link text-primary small"></i>
                                </div>`;
                                item.addEventListener('click', (e) => {
                                    e.preventDefault();
                                    aInput.value = `${a.serial_number || 'S/N'} - ${a.brand || ''} ${a.model || ''}`.trim();
                                    aHidden.value = a.id;
                                    aResults.classList.add('d-none');
                                    aForm.submit();
                                });
                                aResults.appendChild(item);
                            });
                            aResults.classList.remove('d-none');
                        } else {
                            aResults.innerHTML = '<div class="list-group-item small text-muted">Sin resultados</div>';
                            aResults.classList.remove('d-none');
                        }
                    }).catch(() => {
                        aResults.innerHTML = '<div class="list-group-item small text-muted">No se pudo buscar</div>';
                        aResults.classList.remove('d-none');
                    });
            }, 200);
        });

        document.addEventListener('click', function(e) {
            if (!aInput.contains(e.target) && !aResults.contains(e.target)) {
                aResults.classList.add('d-none');
            }
        });
    })();

    // Initialize Select2 for problem linking
    $(document).ready(function() {
        if ($('.select2-problem').length) {
            $('.select2-problem').select2({
                theme: 'bootstrap-5',
                placeholder: "-- Selecciona un Problema --",
                allowClear: true
            });
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>