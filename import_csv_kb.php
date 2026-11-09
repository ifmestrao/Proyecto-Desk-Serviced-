<?php
require_once 'config/db.php';

// Solo por consola: php import_csv_kb.php archivo.csv ID_TENANT
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
$csvFile = $argv[1] ?? 'it-service-ticket-classification.csv';
$tenant_id = isset($argv[2]) ? (int)$argv[2] : 0;
if ($tenant_id <= 0) { die("Uso: php import_csv_kb.php archivo.csv ID_TENANT\n"); }

if (!file_exists($csvFile)) {
    die("Error: No se encontró el archivo $csvFile.\nUso: php import_csv_kb.php [nombre_del_archivo.csv]\n");
}

echo "Iniciando importación desde $csvFile...\n";

// Obtener categorías existentes
$catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ?");
$catStmt->execute([$tenant_id]);
$categories = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$categories = array_change_key_case(array_flip($categories), CASE_LOWER);

// Obtener un usuario administrador para 'created_by'
$userStmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? ORDER BY role = 'admin' DESC, id ASC LIMIT 1");
$userStmt->execute([$tenant_id]);
$user = $userStmt->fetch();
$creator_id = $user ? $user['id'] : 1;

$handle = fopen($csvFile, "r");
if (!$handle) {
    die("Error: No se pudo abrir el archivo $csvFile.\n");
}

// Detectar delimitador
$firstLine = fgets($handle);
$delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
rewind($handle);

$headers = fgetcsv($handle, 0, $delimiter);

if (!$headers) {
    die("Error: El archivo CSV está vacío o no tiene encabezados.\n");
}

// Limpiar encabezados de espacios o caracteres raros
$headers = array_map('trim', $headers);

$count = 0;
while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
    if (count($headers) !== count($row)) {
        echo "Error en fila " . ($count + 2) . ": El número de columnas (" . count($row) . ") no coincide con los encabezados (" . count($headers) . ").\n";
        continue;
    }
    $data = array_combine($headers, $row);
    
    // Mapeo flexible de columnas (Inglés y Español)
    $title = $data['asunto_del_ticket'] ?? $data['ticket_subject'] ?? $data['subject'] ?? 'Ticket sin asunto';
    $description = $data['descripción_del_ticket'] ?? $data['ticket_description'] ?? $data['description'] ?? '';
    $resolution = $data['notas_de_resolución'] ?? $data['resolution_notes'] ?? $data['resolution'] ?? '';
    $content = $description . ($resolution ? "\n\n**Solución:**\n" . $resolution : "");
    
    // Construir palabras clave dinámicamente
    $kw_fields = [
        'categoría', 'subcategoría', 'etiquetas', 'departamento', 'prioridad', 'ubicación', 'tipo_de_dispositivo',
        'category', 'subcategory', 'tags', 'department', 'priority', 'location', 'device_type'
    ];
    $kw_parts = [];
    foreach ($kw_fields as $field) {
        if (!empty($data[$field])) $kw_parts[] = trim($data[$field]);
    }
    $keywords = implode(', ', array_unique($kw_parts));
    
    // Mapear categoría ID basada en el nombre
    $cat_name = strtolower(trim($data['categoría'] ?? $data['category'] ?? ''));
    $category_id = null; // sin categoría si no hay coincidencia
    
    foreach ($categories as $name => $id) {
        if ($cat_name && (strpos($cat_name, strtolower($name)) !== false || strpos(strtolower($name), $cat_name) !== false)) {
            $category_id = $id;
            break;
        }
    }
    
    $created_at_raw = $data['fecha_de_creación'] ?? $data['created_datetime'] ?? date('Y-m-d H:i:s');
    $created_at = date('Y-m-d H:i:s', strtotime($created_at_raw));

    try {
        $stmt = $pdo->prepare("INSERT INTO knowledge_base (tenant_id, title, content, keywords, category_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $dup = $pdo->prepare('SELECT 1 FROM knowledge_base WHERE tenant_id = ? AND title = ? LIMIT 1');
        $dup->execute([$tenant_id, $title]);
        if ($dup->fetchColumn()) { continue; }
        $stmt->execute([$tenant_id, $title, $content, $keywords, $category_id, $creator_id, $created_at]);
        $count++;
    } catch (Exception $e) {
        // Si hay error de encoding, intentar limpiar
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            $keywords = mb_convert_encoding($keywords, 'UTF-8', 'UTF-8');
            try {
                $stmt->execute([$tenant_id, $title, $content, $keywords, $category_id, $creator_id, $created_at]);
                $count++;
                continue;
            } catch (Exception $e2) {}
        }
        echo "Error al importar fila " . ($count + 1) . ": " . $e->getMessage() . "\n";
    }
}

fclose($handle);
echo "Importación completada. Se añadieron $count registros a la base de conocimientos.\n";
?>
