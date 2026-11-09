<?php
/**
 * Script de alerta de vencimientos próximos para tickets
 * Se encarga de notificar a los técnicos asignados cuando un ticket está a menos de 4 horas de vencer.
 */
require_once __DIR__ . '/config/db.php';

function check_overdue_alerts(PDO $pdo) {
    // Definimos el umbral de "próximo a vencer" (ej: 4 horas)
    $threshold_hours = 4;
    
    // Consultar tickets abiertos o en espera que vencen pronto y no han sido notificados hoy por este motivo
    // Usamos una subconsulta para evitar notificaciones duplicadas en un periodo corto (ej: cada 12 horas)
    $sql = "SELECT t.id, t.tenant_id, t.local_id, t.title, t.due_date, t.assigned_tech_id 
            FROM tickets t
            WHERE t.status IN ('open', 'pending')
              AND t.due_date > NOW() 
              AND t.due_date <= DATE_ADD(NOW(), INTERVAL ? HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM notifications n 
                  WHERE n.entity_id = t.id 
                    AND n.tenant_id = t.tenant_id
                    AND n.entity_type = 'ticket' 
                    AND n.type = 'ticket_near_overdue'
                    AND n.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
              )";
              
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$threshold_hours]);
    $near_overdue_tickets = $stmt->fetchAll();

    foreach ($near_overdue_tickets as $ticket) {
        $ticket_id = (int)$ticket['id'];
        $display_id = $ticket['local_id'] ?: $ticket_id;
        $title = $ticket['title'];
        $due_date = $ticket['due_date'];
        
        // Obtener todos los técnicos asignados (principal + secundarios)
        $techs = [];
        if ($ticket['assigned_tech_id']) {
            $techs[] = (int)$ticket['assigned_tech_id'];
        }
        
        $stSecondary = $pdo->prepare("SELECT tt.tech_id FROM ticket_techs tt JOIN users u ON u.id = tt.tech_id AND u.tenant_id = ? WHERE tt.ticket_id = ? AND tt.active = 1");
        $stSecondary->execute([(int)$ticket['tenant_id'], $ticket_id]);
        $secondary_techs = $stSecondary->fetchAll(PDO::FETCH_COLUMN);
        
        $all_techs = array_unique(array_merge($techs, array_map('intval', $secondary_techs)));

        foreach ($all_techs as $tech_id) {
            if ($tech_id > 0) {
                $time_left = round((strtotime($due_date) - time()) / 60); // minutos
                $body = "El ticket #$display_id está próximo a vencer (vence a las " . date('H:i', strtotime($due_date)) . "). Quedan aproximadamente $time_left minutos.";
                
                notify_add(
                    $pdo, 
                    $tech_id, 
                    'ticket_near_overdue', 
                    "⚠️ Ticket próximo a vencer", 
                    $body, 
                    "ticket_details.php?id=$ticket_id", 
                    'ticket', 
                    $ticket_id,
                    (int)$ticket['tenant_id']
                );
            }
        }
    }
}

// Ejecutar la revisión
try {
    check_overdue_alerts($pdo);
} catch (Throwable $e) {
    // Silencioso en producción, o loguear error
    error_log("Error en cron_overdue_alerts: " . $e->getMessage());
}
