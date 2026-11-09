<?php
require_once __DIR__ . '/config/session_setup.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor'], true)) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(403);
    exit('CSRF inválido');
}

$role = $_SESSION['role'];
$currentUserId = (int)$_SESSION['user_id'];
$tid = (int)($_SESSION['tenant_id'] ?? 0);   // TODOS los datos exportados se filtran por este tenant
if ($tid <= 0) { http_response_code(403); exit('Forbidden'); }
$dataset = isset($_POST['dataset']) ? (string)$_POST['dataset'] : '';
$format = isset($_POST['format']) ? (string)$_POST['format'] : '';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalize_excel_cell($v): string {
    if ($v === null) return '';
    $s = (string)$v;
    $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
    return $s;
}

function table_has_column(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function send_excel_xls(string $filenameBase, string $title, array $headers, array $rows): void {
    if (headers_sent()) {
        http_response_code(500);
        exit('Headers already sent');
    }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\" cellpadding=\"4\" cellspacing=\"0\">";
    echo "<tr><th colspan=\"" . count($headers) . "\" style=\"font-size:14px;\">" . h($title) . "</th></tr>";
    echo "<tr>";
    foreach ($headers as $col) {
        echo "<th style=\"background:#f1f5f9;\">" . h($col) . "</th>";
    }
    echo "</tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $cell) {
            echo "<td>" . h(normalize_excel_cell($cell)) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
}

function send_pdf(string $filenameBase, string $title, array $headers, array $rows, string $orientation = 'portrait'): void {
    if (headers_sent()) {
        http_response_code(500);
        exit('Headers already sent');
    }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    require_once __DIR__ . '/vendor/autoload.php';
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf\Dompdf($options);
    $style = "
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
            h1 { font-size: 14px; margin: 0 0 10px 0; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #e5e7eb; padding: 6px; vertical-align: top; }
            th { background: #f8fafc; font-weight: bold; }
            .meta { margin: 0 0 10px 0; color: #6b7280; font-size: 9px; }
        </style>
    ";
    $html = "<html><head><meta charset=\"UTF-8\">$style</head><body>";
    $html .= "<h1>" . h($title) . "</h1>";
    $html .= "<div class=\"meta\">Generado: " . h(date('d/m/Y H:i')) . "</div>";
    $html .= "<table><thead><tr>";
    foreach ($headers as $col) {
        $html .= "<th>" . h($col) . "</th>";
    }
    $html .= "</tr></thead><tbody>";
    foreach ($rows as $r) {
        $html .= "<tr>";
        foreach ($r as $cell) {
            $html .= "<td>" . h($cell) . "</td>";
        }
        $html .= "</tr>";
    }
    $html .= "</tbody></table></body></html>";

    $paper = 'A4';
    $dompdf->setPaper($paper, $orientation === 'landscape' ? 'landscape' : 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
    echo $dompdf->output();
    exit;
}

try {
    if ($dataset === 'tickets_closed') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_techs (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, tech_id INT NOT NULL, active TINYINT(1) DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_ticket_tech (ticket_id, tech_id))");
        } catch (Throwable $e) {}

        $hasUpdatedAt = table_has_column($pdo, 'tickets', 'updated_at');
        $updatedSel = $hasUpdatedAt ? 't.updated_at' : 'NULL AS updated_at';
        $orderBy = $hasUpdatedAt ? 'ORDER BY COALESCE(t.updated_at, t.created_at) DESC' : 'ORDER BY t.created_at DESC';

        $sql = "SELECT t.id, t.title, u.name AS requester_name, u.email AS requester_email, c.name AS category_name,
                       t.priority, t.status, t.created_at, $updatedSel, t.due_date,
                       (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ')
                          FROM ticket_techs tt2
                          JOIN users u2 ON tt2.tech_id = u2.id
                         WHERE tt2.ticket_id = t.id AND tt2.active = 1) AS techs
                FROM tickets t
                JOIN users u ON t.user_id = u.id
                JOIN categories c ON t.category_id = c.id
                WHERE t.tenant_id = ? AND t.status = 'closed'";
        $params = [$tid];
        $sql .= " $orderBy";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rowsDb = $st->fetchAll() ?: [];

        $headers = ['#', 'Título', 'Solicitante', 'Correo', 'Categoría', 'Prioridad', 'Estado', 'Creado', 'Actualizado', 'Vence', 'Técnico(s)'];
        $rows = [];
        foreach ($rowsDb as $r) {
            $rows[] = [
                $r['id'],
                $r['title'],
                $r['requester_name'],
                $r['requester_email'],
                $r['category_name'],
                $r['priority'],
                $r['status'],
                $r['created_at'],
                $r['updated_at'],
                $r['due_date'],
                $r['techs'] ?? ''
            ];
        }
        $base = 'casos_cerrados_' . date('Ymd_His');
        $title = 'Casos cerrados';
        if ($format === 'pdf') {
            send_pdf($base, $title, $headers, $rows, 'landscape');
        }
        send_excel_xls($base, $title, $headers, $rows);
    }

    if ($dataset === 'tech_stats') {
        if ($role !== 'supervisor') {
            http_response_code(403);
            exit('Forbidden');
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_techs (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, tech_id INT NOT NULL, active TINYINT(1) DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_ticket_tech (ticket_id, tech_id))");
        } catch (Throwable $e) {}

        $techId = null;
        if (!empty($_POST['tech_id']) && ctype_digit((string)$_POST['tech_id'])) {
            $techId = (int)$_POST['tech_id'];
        }

        $title = 'Estadísticas del técnico';
        $subtitle = '';
        if ($techId) {
            $stU = $pdo->prepare("SELECT name, email FROM users WHERE id = ? AND role = 'tech' AND tenant_id = ?");
            $stU->execute([$techId, $tid]);
            $u = $stU->fetch();
            if ($u) {
                $subtitle = $u['name'] . ' (' . $u['email'] . ')';
            }
        } else {
            $subtitle = 'Todos';
        }

        if ($techId) {
            $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN ticket_techs tt ON tt.ticket_id = t.id AND tt.tech_id = ? AND tt.active = 1 WHERE t.tenant_id = ?");
            $st->execute([$techId, $tid]);
            $total = (int)$st->fetchColumn();

            $counts = ['open' => 0, 'pending' => 0, 'closed' => 0];
            $st = $pdo->prepare("SELECT t.status, COUNT(DISTINCT t.id) c FROM tickets t JOIN ticket_techs tt ON tt.ticket_id = t.id AND tt.tech_id = ? AND tt.active = 1 WHERE t.tenant_id = ? GROUP BY t.status");
            $st->execute([$techId, $tid]);
            while ($r = $st->fetch()) {
                if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
            }
            $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN ticket_techs tt ON tt.ticket_id = t.id AND tt.tech_id = ? AND tt.active = 1 WHERE t.tenant_id = ? AND t.due_date < NOW() AND t.status != 'closed'");
            $st->execute([$techId, $tid]);
            $overdue = (int)$st->fetchColumn();
        } else {
            $st = $pdo->query("SELECT COUNT(*) FROM tickets WHERE tenant_id = $tid");
            $total = (int)$st->fetchColumn();
            $counts = ['open' => 0, 'pending' => 0, 'closed' => 0];
            $st = $pdo->query("SELECT status, COUNT(*) c FROM tickets WHERE tenant_id = $tid GROUP BY status");
            while ($r = $st->fetch()) {
                if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
            }
            $st = $pdo->query("SELECT COUNT(*) FROM tickets WHERE tenant_id = $tid AND due_date < NOW() AND status != 'closed'");
            $overdue = (int)$st->fetchColumn();
        }

        $headers = ['Indicador', 'Valor'];
        $rows = [
            ['Técnico', $subtitle],
            ['Total', $total],
            ['Abiertos', $counts['open']],
            ['En espera', $counts['pending']],
            ['Cerrados', $counts['closed']],
            ['Vencidos', $overdue]
        ];

        $base = 'estadisticas_tecnico_' . date('Ymd_His');
        $fullTitle = $title . ($subtitle ? ' - ' . $subtitle : '');
        if ($format === 'pdf') {
            send_pdf($base, $fullTitle, $headers, $rows, 'portrait');
        }
        send_excel_xls($base, $fullTitle, $headers, $rows);
    }

    if ($dataset === 'cronograma_completed') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cronograma_assignees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cronograma_id INT NOT NULL,
                tech_id INT NOT NULL,
                UNIQUE KEY uniq_ca (cronograma_id, tech_id),
                INDEX idx_ca_tech (tech_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}

        $sql = "SELECT c.id, c.title, c.description, c.category, c.status, c.start_date, c.end_date, c.created_at,
                       creator.name AS creator_name,
                       (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ')
                          FROM cronograma_assignees ca
                          JOIN users u2 ON ca.tech_id = u2.id
                         WHERE ca.cronograma_id = c.id) AS assignees
                FROM cronograma c
                JOIN users creator ON c.created_by = creator.id
                WHERE c.tenant_id = ? AND c.status = 'completed'";
        $params = [$tid];
        $sql .= " ORDER BY COALESCE(c.end_date, c.created_at) DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rowsDb = $st->fetchAll() ?: [];

        $headers = ['#', 'Título', 'Descripción', 'Categoría', 'Estado', 'Inicio', 'Fin', 'Creado', 'Creado por', 'Asignado a'];
        $rows = [];
        foreach ($rowsDb as $r) {
            $rows[] = [
                $r['id'],
                $r['title'],
                $r['description'],
                $r['category'],
                $r['status'],
                $r['start_date'],
                $r['end_date'],
                $r['created_at'],
                $r['creator_name'],
                $r['assignees'] ?? ''
            ];
        }

        $base = 'cronogramas_completados_' . date('Ymd_His');
        $title = 'Cronogramas completados';
        if ($format === 'pdf') {
            send_pdf($base, $title, $headers, $rows, 'landscape');
        }
        send_excel_xls($base, $title, $headers, $rows);
    }

    if ($dataset === 'inventario') {
        $sql = "SELECT a.id, t.name AS type_name, a.serial_number, a.brand, a.model, a.status, u.name AS assigned_user_name, a.created_at
                FROM assets a
                JOIN asset_types t ON a.type_id = t.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.tenant_id = ?
                ORDER BY a.created_at DESC";
        $stInv = $pdo->prepare($sql);
        $stInv->execute([$tid]);
        $rowsDb = $stInv->fetchAll() ?: [];

        $dynMap = [];
        try {
            $st = $pdo->query("SELECT v.asset_id, f.field_label, v.field_value
                               FROM asset_field_values v
                               JOIN asset_fields f ON v.field_id = f.id
                               JOIN assets aa ON aa.id = v.asset_id AND aa.tenant_id = $tid
                               ORDER BY v.asset_id ASC, f.field_label ASC");
            while ($r = $st->fetch()) {
                $aid = (int)$r['asset_id'];
                if (!isset($dynMap[$aid])) $dynMap[$aid] = [];
                $dynMap[$aid][] = ($r['field_label'] . ': ' . $r['field_value']);
            }
        } catch (Throwable $e) {}

        $headers = ['#', 'Tipo', 'Serial', 'Marca', 'Modelo', 'Estado', 'Asignado a', 'Creado', 'Detalles'];
        $rows = [];
        foreach ($rowsDb as $r) {
            $aid = (int)$r['id'];
            $details = '';
            if (!empty($dynMap[$aid])) {
                $details = implode(' | ', $dynMap[$aid]);
            }
            $rows[] = [
                $r['id'],
                $r['type_name'],
                $r['serial_number'],
                $r['brand'],
                $r['model'],
                $r['status'],
                $r['assigned_user_name'] ?: 'Disponible',
                $r['created_at'],
                $details
            ];
        }

        $base = 'inventario_' . date('Ymd_His');
        $title = 'Inventario de activos';
        if ($format === 'pdf') {
            send_pdf($base, $title, $headers, $rows, 'landscape');
        }
        send_excel_xls($base, $title, $headers, $rows);
    }

    http_response_code(400);
    exit('Dataset no válido');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error al exportar');
}
