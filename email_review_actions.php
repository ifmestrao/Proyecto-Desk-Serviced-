<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'includes/email_templates.php';
require_once 'includes/mailer.php';
require_once 'includes/ai_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);
$tenant_id = $_SESSION['tenant_id'];

if ($action === 'get_body' && $id) {
    $stmt = $pdo->prepare("SELECT subject, body, from_name, from_email, created_at FROM email_requests WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($req) {
        echo json_encode([
            'success' => true,
            'subject' => $req['subject'],
            'body' => strip_tags($req['body']),
            'from_name' => $req['from_name'],
            'from_email' => $req['from_email'],
            'date' => $req['created_at'] ? date('d/m/Y H:i', strtotime($req['created_at'])) : '',
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No encontrado']);
    }
    exit;
}

if ($action === 'approve' && $id) {
    try {
        // 1. Obtener la solicitud
        $stmt = $pdo->prepare("SELECT * FROM email_requests WHERE id = ? AND tenant_id = ? AND status = 'pending'");
        $stmt->execute([$id, $tenant_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) throw new Exception("Solicitud no encontrada.");

        // 2-5. Crear (o reutilizar) usuario + ticket + adjuntos (función compartida con la escalación automática)
        $result = create_ticket_from_email_request($pdo, $tenant_id, $req, 'low');
        $ticket_id = $result['ticket_id'];
        $next_local_id = $result['local_id'];

        // 6. Marcar solicitud como aprobada
        $pdo->prepare("UPDATE email_requests SET status = 'approved' WHERE id = ?")->execute([$id]);

        // 7. Notificar al usuario (Opcional, fuera de la transacción)
        try {
            $subj = "Ticket #$next_local_id creado vía Email: " . $req['subject'];
            $emailBody = tpl_ticket_created($req['from_name'], $next_local_id, $req['subject'], 'low', date('Y-m-d H:i:s', strtotime("+72 hours")));
            @send_mail($req['from_email'], $subj, $emailBody, null, [], $tenant_id);
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'ticket_id' => $ticket_id, 'local_id' => $next_local_id]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_ai_draft' && $id) {
    ensure_email_ai_columns($pdo);

    $stmt = $pdo->prepare("SELECT from_name, from_email, subject, body, ai_subject, ai_reply, ai_confidence, ai_sentiment, ai_classification, ai_status FROM email_requests WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'error' => 'No encontrado']);
        exit;
    }

    // Si aún no se ha generado un borrador (ej. correo llegó antes de activar esta función), generarlo ahora
    if (empty($req['ai_reply'])) {
        $ai = ai_analyze_email($pdo, $tenant_id, $req['from_name'], $req['subject'], $req['body']);
        if (($ai['status'] ?? '') === 'success') {
            save_ai_email_draft($pdo, $id, $ai);
            $req['ai_subject'] = $ai['reply_subject'];
            $req['ai_reply'] = $ai['reply_body'];
            $req['ai_confidence'] = $ai['confidence'];
            $req['ai_sentiment'] = $ai['sentiment'];
            $req['ai_classification'] = $ai['classification'];
            $req['ai_status'] = 'drafted';
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo generar la respuesta con IA: ' . ($ai['message'] ?? '')]);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'subject' => $req['ai_subject'],
        'body' => $req['ai_reply'],
        'confidence' => (int)($req['ai_confidence'] ?? 0),
        'sentiment' => $req['ai_sentiment'],
        'classification' => $req['ai_classification'],
        'status' => $req['ai_status'],
        'to_email' => $req['from_email'],
        'to_name' => $req['from_name'],
    ]);
    exit;
}

if ($action === 'send_ai_reply' && $id) {
    ensure_email_ai_columns($pdo);

    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($subject === '' || $body === '') {
        echo json_encode(['success' => false, 'error' => 'Asunto y cuerpo son obligatorios']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT from_email, from_name, ai_classification FROM email_requests WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'error' => 'Solicitud no encontrada']);
        exit;
    }

    $sent = send_ai_email_reply(
        $pdo, $id, $req['from_email'], $req['from_name'],
        $subject, $body, $req['ai_classification'] ?? 'Soporte', $tenant_id
    );

    echo $sent
        ? json_encode(['success' => true])
        : json_encode(['success' => false, 'error' => 'No se pudo enviar el correo. Verifica la configuración SMTP.']);
    exit;
}

if ($action === 'reject' && $id) {
    $pdo->prepare("UPDATE email_requests SET status = 'rejected' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);
?>
