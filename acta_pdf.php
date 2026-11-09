<?php
require_once 'config/session_setup.php';
require_once 'config/db.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$acta_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Fetch Acta Details
require_once 'config/middleware_tenant.php';
$tenant_filter_a = tenant_where('a');
$tenant_filter_u = tenant_where('u');
$tenant_filter_creator = tenant_where('creator');
$stmt = $pdo->prepare("SELECT a.*, u.name as user_name, u.email as user_email, u.position as user_position, creator.name as creator_name, ast.serial_number as asset_serial, ast.brand as asset_brand, ast.model as asset_model 
                       FROM actas a 
                       JOIN users u ON a.user_id = u.id 
                       JOIN users creator ON a.created_by = creator.id 
                       LEFT JOIN assets ast ON a.asset_id = ast.id 
                       WHERE a.id = ? AND $tenant_filter_a AND $tenant_filter_u AND $tenant_filter_creator");
$stmt->execute([$acta_id]);
$acta = $stmt->fetch();

if (!$acta) {
    header("Location: dashboard.php");
    exit();
}

// Check Permissions
if ($role == 'user' && $acta['user_id'] != $user_id) {
    header("Location: dashboard_user.php");
    exit();
}

$content = json_decode($acta['content'], true);

// HTML for PDF
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Acta #<?php echo $acta['id']; ?></title>
    <style>
        body { 
            font-family: 'Helvetica', Arial, sans-serif; 
            font-size: 12px; 
            color: #333; 
            line-height: 1.7;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 3px solid #4f46e5;
            background: linear-gradient(135deg, #f9fafb 0%, #eef2ff 100%);
            padding: 30px 20px;
            border-radius: 10px;
        }
        .header h1 { 
            color: #4f46e5; 
            margin: 0 0 10px 0; 
            font-size: 24px; 
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header .subtitle { 
            color: #64748b; 
            margin: 0 0 15px 0; 
            font-size: 14px; 
            font-weight: 500;
        }
        .header .id-date {
            background: #ffffff;
            display: inline-block;
            padding: 8px 25px;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            color: #475569;
            font-size: 10px;
        }
        .section-title { 
            color: #4f46e5; 
            font-size: 12px; 
            font-weight: bold; 
            border-left: 4px solid #4f46e5;
            padding: 5px 0 5px 15px;
            margin: 25px 0 18px 0;
            background: #f5f3ff;
            border-radius: 0 6px 6px 0;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-box { 
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .info-label { 
            font-weight: bold; 
            color: #64748b; 
            font-size: 9px; 
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .info-value { 
            font-size: 12px; 
            color: #1e293b; 
            font-weight: bold;
        }
        .details-box { 
            border: 1px solid #e2e8f0;
            padding: 25px;
            border-radius: 10px;
            background: #fafafa;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .details-box .details-text {
            font-size: 11px;
            color: #334155;
            line-height: 1.8;
        }
        .status-section {
            margin-top: 30px;
            text-align: center;
        }
        .status-badge { 
            display: inline-block; 
            padding: 10px 30px; 
            border-radius: 25px; 
            font-weight: bold; 
            text-transform: uppercase; 
            font-size: 11px;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .status-approved { background: #10b981; color: white; }
        .status-rejected { background: #ef4444; color: white; }
        .status-pending { background: #f59e0b; color: white; }
        .response-comment {
            margin-top: 20px;
            padding: 20px;
            background: #e0f2fe;
            border-left: 4px solid #0ea5e9;
            border-radius: 0 8px 8px 0;
        }
        .response-comment strong {
            color: #0369a1;
            display: block;
            margin-bottom: 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .signature-section {
            margin-top: 60px;
            display: flex;
            justify-content: space-around;
            page-break-inside: avoid;
        }
        .signature-line {
            text-align: center;
            width: 220px;
        }
        .signature-line hr {
            border: none;
            border-top: 1px solid #334155;
            margin-top: 70px;
        }
        .signature-name {
            font-family: 'Times New Roman', serif;
            font-style: italic;
            font-size: 15px;
            margin-top: 8px;
            color: #1e293b;
            font-weight: 500;
        }
        .signature-role {
            color: #64748b;
            font-size: 10px;
            margin-top: 3px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo htmlspecialchars($acta['title']); ?></h1>
        <p class="subtitle">DOCUMENTO OFICIAL DE <?php echo strtoupper($acta['type']); ?></p>
        <div class="id-date">
            <strong>ID:</strong> #<?php echo $acta['id']; ?> · <strong>Fecha de Emisión:</strong> <?php echo date('d/m/Y H:i', strtotime($acta['created_at'])); ?>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <div class="section-title" style="margin-top:0;">Información del Solicitante</div>
            <div class="info-label">Nombre Completo</div>
            <div class="info-value"><?php echo htmlspecialchars($acta['user_name']); ?></div>
            
            <div class="info-label" style="margin-top: 12px;">Cargo</div>
            <div class="info-value"><?php echo htmlspecialchars($acta['user_position'] ?? 'N/A'); ?></div>
            
            <div class="info-label" style="margin-top: 12px;">Correo Electrónico</div>
            <div class="info-value"><?php echo htmlspecialchars($acta['user_email']); ?></div>
        </div>
        
        <div class="info-box">
            <div class="section-title" style="margin-top:0;">Equipo / Activo</div>
            <?php if ($acta['asset_id']): ?>
                <div class="info-label">Equipo</div>
                <div class="info-value"><?php echo htmlspecialchars($acta['asset_brand']); ?> <?php echo htmlspecialchars($acta['asset_model']); ?></div>
                
                <div class="info-label" style="margin-top: 12px;">Número de Serie</div>
                <div class="info-value"><?php echo htmlspecialchars($acta['asset_serial']); ?></div>
            <?php else: ?>
                <div class="info-value" style="color: #94a3b8; font-style: italic; margin-top: 20px;">No se relacionó un activo específico.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section-title"><?php echo $acta['type'] == 'entrega' ? 'DETALLES DE LA ENTREGA' : 'DETALLES DEL MANTENIMIENTO'; ?></div>
    <div class="details-box">
        <div class="details-text">
            <?php echo nl2br(htmlspecialchars($content['details'])); ?>
        </div>
    </div>

    <div class="status-section">
        <span class="status-badge status-<?php echo $acta['status']; ?>">
            Estado: <?php echo strtoupper($acta['status']); ?>
        </span>
        <?php if ($acta['status'] != 'pending'): ?>
            <div style="margin-top: 15px; color: #64748b; font-size: 10px;">
                <strong>Fecha de Respuesta:</strong> <?php echo date('d/m/Y H:i', strtotime($acta['approved_at'])); ?>
            </div>
            <?php if ($acta['response_comment']): ?>
                <div class="response-comment">
                    <strong>Comentarios de la Respuesta</strong>
                    <span><?php echo htmlspecialchars($acta['response_comment']); ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="signature-section">
        <div class="signature-line">
            <hr>
            <div class="signature-name"><?php echo htmlspecialchars($acta['creator_name']); ?></div>
            <div class="signature-role">Generado por</div>
        </div>
        <div class="signature-line">
            <hr>
            <div class="signature-name"><?php echo htmlspecialchars($acta['user_name']); ?></div>
            <div class="signature-role">Recibido / Aprobado por</div>
        </div>
    </div>

    <div class="footer">
        <p>Documento generado electrónicamente - Desk Service · Este documento constituye una constancia válida de conformidad.</p>
        <p>ID de Documento: <?php echo $acta['id']; ?></p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// Generate PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$dompdf->stream('acta_' . $acta['id'] . '.pdf', ['Attachment' => true]);
exit;
