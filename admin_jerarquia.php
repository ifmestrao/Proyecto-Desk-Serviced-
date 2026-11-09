<?php
require_once 'includes/header.php';
checkAuth();
checkStaff();
require_once 'config/db.php';
require_once 'config/middleware_tenant.php';

$pageTitle = 'Jerarquía Organizacional';
$tenant_id = get_tenant_id();

// Obtener información del tenant
$tenant_stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
$tenant_stmt->execute([$tenant_id]);
$tenant_name = $tenant_stmt->fetchColumn() ?: 'Mi Instancia';

// Asegurar que las tablas y columnas existan
try {
    // Tabla de Entidades
    $pdo->exec("CREATE TABLE IF NOT EXISTS entities (
        id int NOT NULL AUTO_INCREMENT,
        tenant_id int NOT NULL,
        name varchar(190) NOT NULL,
        description text,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_entity_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Columna entity_id en groups
    $checkColumn = $pdo->query("SHOW COLUMNS FROM `groups` LIKE 'entity_id'");
    if (!$checkColumn->fetch()) {
        $pdo->exec("ALTER TABLE `groups` ADD COLUMN entity_id INT DEFAULT NULL AFTER description");
    }
} catch (Throwable $e) {}

// Fetch groups and users for the tree
$groups = $pdo->prepare("SELECT id, name FROM `groups` WHERE tenant_id = ? AND is_active = 1");
$groups->execute([$tenant_id]);
$groups = $groups->fetchAll(PDO::FETCH_ASSOC);
$group_ids = array_column($groups, 'id');

$users = $pdo->prepare("SELECT u.id, u.name, gu.group_id 
                       FROM users u 
                       JOIN group_users gu ON u.id = gu.user_id 
                       WHERE u.tenant_id = ? AND u.is_active = 1");
$users->execute([$tenant_id]);
$users = $users->fetchAll(PDO::FETCH_ASSOC);

function cleanMermaid($str) {
    // Eliminamos caracteres que rompen Mermaid y escapamos comillas
    $clean = str_replace(['"', '[', ']', '(', ')', '{', '}', ';', '#', ':', '>', '<', '&', '/', '\\', '|', '*', '+', '=', '~'], '', $str);
    return trim($clean);
}

require_once 'includes/navbar.php';
?>

<div class="container-fluid px-4" style="margin-bottom: 30px;">
    <div class="d-flex justify-content-between align-items-center my-4">
        <div>
            <p class="text-muted">Visualiza la relación entre grupos y técnicos de tu instancia.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-light btn-sm border" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Reintentar
            </button>
            <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Exportar PDF
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-project-diagram me-2 text-primary"></i>Mapa de Grupos y Personal</h6>
            <small class="text-muted" id="mermaid-status">Cargando diagrama...</small>
        </div>
        <div class="card-body p-4 overflow-auto bg-light bg-opacity-10" style="min-height: 600px;">
            <div class="mermaid text-center" id="hierarchy-diagram">
flowchart TD
    %% Estilos de Nodos Profesionales y Legibles
    classDef main fill:#4f46e5,stroke:#4338ca,stroke-width:2px,rx:12,ry:12,color:#ffffff,font-weight:600,font-size:16px,font-family:'Inter',sans-serif;
    classDef group fill:#10b981,stroke:#059669,stroke-width:2px,rx:12,ry:12,color:#ffffff,font-weight:500,font-size:14px,font-family:'Inter',sans-serif;
    classDef user fill:#f59e0b,stroke:#d97706,stroke-width:2px,rx:12,ry:12,color:#ffffff,font-weight:500,font-size:14px,font-family:'Inter',sans-serif;

    T["<?php echo cleanMermaid($tenant_name); ?>"]:::main

    <?php 
    $rendered_relations = [];
    foreach ($groups as $g): 
        $g_id = "G" . $g['id'];
        $g_label = cleanMermaid($g['name']);
        echo "    $g_id(\"$g_label\"):::group\n";
        echo "    T --> $g_id\n";
    endforeach; 

    foreach ($users as $u): 
        if (!empty($u['group_id']) && in_array($u['group_id'], $group_ids)):
            $u_id = "U" . $u['id'];
            $g_id = "G" . $u['group_id'];
            $u_label = cleanMermaid($u['name']);
            
            echo "    $u_id(\"$u_label\"):::user\n";
            
            $rel = "$g_id --> $u_id";
            if (!in_array($rel, $rendered_relations)):
                echo "    $rel\n";
                $rendered_relations[] = $rel;
            endif;
        endif;
    endforeach; 
    ?>
    
    %% Configuración de líneas profesionales
    linkStyle default stroke:#4f46e5,stroke-width:2px,fill:none,stroke-opacity:0.7;
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle-sm bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <span class="text-muted small fw-bold text-uppercase d-block" style="font-size: 0.65rem;">Grupos Activos</span>
                            <h4 class="mb-0 fw-bold"><?php echo count($groups); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle-sm bg-warning bg-opacity-10 text-warning me-3">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <span class="text-muted small fw-bold text-uppercase d-block" style="font-size: 0.65rem;">Personal Asignado</span>
                            <h4 class="mb-0 fw-bold"><?php echo count($users); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="module">
    import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs';
    
    const status = document.getElementById('mermaid-status');
    
    async function renderDiagram() {
        try {
            mermaid.initialize({ 
                startOnLoad: false,
                theme: 'neutral',
                themeVariables: {
                    fontFamily: 'Inter, sans-serif',
                    fontSize: '14px',
                    fontWeight: '500',
                    textColor: '#ffffff',
                    mainBkg: '#4f46e5',
                    nodeBorder: '#4338ca',
                    lineColor: '#4f46e5',
                    primaryColor: '#4f46e5',
                    primaryTextColor: '#ffffff',
                    primaryBorderColor: '#4338ca'
                },
                securityLevel: 'loose',
                flowchart: { 
                    useMaxWidth: true, 
                    htmlLabels: false,
                    curve: 'basis',
                    padding: 25
                }
            });
            await mermaid.run({
                nodes: document.querySelectorAll('.mermaid'),
            });
            status.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i>Mapa actualizado';
        } catch (e) {
            console.error("Mermaid error:", e);
            status.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-1"></i>Error en estructura de datos';
            
            // Reintento automático con configuración básica si falla
            setTimeout(async () => {
                try {
                    console.log("Reintentando con configuración básica...");
                    mermaid.initialize({ startOnLoad: false, theme: 'default' });
                    await mermaid.run({ nodes: document.querySelectorAll('.mermaid') });
                } catch (e2) {
                    const diagramDiv = document.getElementById('hierarchy-diagram');
                    if (diagramDiv) {
                        diagramDiv.innerHTML = '<div class="alert alert-warning m-4">No se pudo renderizar el mapa. Verifica que los nombres de grupos o usuarios no tengan caracteres muy extraños.</div>';
                    }
                }
            }, 1000);
        }
    }
    
    // Ejecutar después de que la página cargue completamente
    if (document.readyState === 'complete') {
        renderDiagram();
    } else {
        window.addEventListener('load', renderDiagram);
    }
</script>

<style>
    .mermaid {
        min-height: 500px;
    }
    .icon-circle-sm {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    @media print {
        .sidebar, .navbar, .btn, .card-header { display: none !important; }
        .mermaid { transform: scale(0.9); }
        .card { border: 1px solid #eee !important; box-shadow: none !important; }
    }
</style>

<?php require_once 'includes/footer.php'; ?>
