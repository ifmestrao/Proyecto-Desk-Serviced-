<?php

if (defined('AI_DOCS_LOADED')) { return; }
define('AI_DOCS_LOADED', true);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/ai_llm.php';

define('AI_DOCS_MAX_BYTES', 20 * 1024 * 1024);   // 20 MB por archivo
define('AI_DOCS_MAX_CHARS', 3000000);            // texto máximo que se indexa por archivo
define('AI_DOCS_MAX_CHUNKS', 2500);              // fragmentos máximos por archivo
define('AI_DOCS_CHUNK_SIZE', 1000);              // caracteres por fragmento
define('AI_DOCS_CHUNK_OVERLAP', 150);            // solape entre fragmentos

/* ------------------------------------------------------------------ catálogo de formatos */

/** extensión => tipo (document | book | image | text) */
function ai_docs_types(): array {
    return [
        'pdf' => 'document', 'docx' => 'document', 'pptx' => 'document', 'xlsx' => 'document',
        'epub' => 'book',
        'txt' => 'text', 'md' => 'text', 'csv' => 'text', 'json' => 'text', 'html' => 'text', 'htm' => 'text', 'log' => 'text',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image',
    ];
}

function ai_docs_kind_label(string $kind): string {
    $m = ['document' => 'Documento', 'book' => 'Libro', 'image' => 'Imagen', 'text' => 'Texto'];
    return $m[$kind] ?? 'Archivo';
}

function ai_docs_mime_for(string $ext): string {
    $m = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'epub' => 'application/epub+zip', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'json' => 'application/json', 'html' => 'text/html', 'htm' => 'text/html', 'log' => 'text/plain',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ];
    return $m[$ext] ?? 'application/octet-stream';
}

function ai_docs_format_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function ai_docs_upload_error(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:   return 'El archivo supera el límite del servidor (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').';
        case UPLOAD_ERR_FORM_SIZE:  return 'El archivo supera el tamaño máximo del formulario.';
        case UPLOAD_ERR_PARTIAL:    return 'La subida se interrumpió a la mitad. Inténtalo de nuevo.';
        case UPLOAD_ERR_NO_FILE:    return 'No se recibió ningún archivo.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'El servidor no tiene carpeta temporal para subidas.';
        case UPLOAD_ERR_CANT_WRITE: return 'El servidor no pudo escribir el archivo en disco.';
        case UPLOAD_ERR_EXTENSION:  return 'Una extensión de PHP bloqueó la subida.';
    }
    return 'Error de subida desconocido (código ' . $code . ').';
}

/* ------------------------------------------------------------------ esquema */

function ai_docs_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        uploaded_by INT NULL,
        title VARCHAR(190) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        ext VARCHAR(10) NOT NULL,
        mime_type VARCHAR(100) NULL,
        kind VARCHAR(12) NOT NULL DEFAULT 'document',
        size_bytes BIGINT NOT NULL DEFAULT 0,
        sha1 CHAR(40) NULL,
        category_id INT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        status_message VARCHAR(500) NULL,
        extract_method VARCHAR(40) NULL,
        char_count INT NOT NULL DEFAULT 0,
        chunk_count INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        indexed_at DATETIME NULL,
        INDEX idx_aidoc_tenant (tenant_id),
        INDEX idx_aidoc_status (tenant_id, status),
        INDEX idx_aidoc_sha (tenant_id, sha1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_doc_chunks (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        document_id INT NOT NULL,
        chunk_index INT NOT NULL,
        content MEDIUMTEXT NOT NULL,
        terms MEDIUMTEXT NOT NULL,
        INDEX idx_aichunk_tenant_doc (tenant_id, document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* ------------------------------------------------------------------ texto: normalización y términos */

function ai_docs_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
    ]);
    $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

function ai_docs_stopwords(): array {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(explode(' ', 'el la los las un una unos unas de del al y e o u en con por para que si no mi tu su yo me te se lo le les esta este esto estos estas como donde cuando quien cual hola buenas buenos dias tardes noches ayuda favor problema problemas tengo tiene tienen hay pero mas muy tan cada alguna algun desde hasta sobre entre ya hoy ayer aqui alli ahi ese esa eso son es ser estoy estan fue era he ha han mis tus sus nos quiero necesito quisiera puedo puede gracias solo tambien porque pues equipo'));
    }
    return $stop;
}

function ai_docs_stem(string $w): string {
    if (strlen($w) <= 3) return $w;
    static $suffixes = ['amientos', 'imientos', 'aciones', 'uciones', 'amiento', 'imiento', 'adores', 'adoras', 'mente', 'acion', 'ucion', 'idad', 'ando', 'iendo', 'ados', 'adas', 'idos', 'idas', 'ante', 'ible', 'able', 'ado', 'ada', 'ido', 'ida', 'ar', 'er', 'ir'];
    foreach ($suffixes as $suf) {
        $l = strlen($suf);
        if (substr($w, -$l) === $suf && strlen($w) - $l >= 3) { $w = substr($w, 0, -$l); break; }
    }
    if (substr($w, -2) === 'es' && strlen($w) > 4) $w = substr($w, 0, -2);
    elseif (substr($w, -1) === 's' && strlen($w) > 3) $w = substr($w, 0, -1);
    if (strlen($w) > 4 && in_array(substr($w, -1), ['a', 'e', 'o'], true)) $w = substr($w, 0, -1);
    return $w;
}

/** Lista de raíces (stems) del texto, sin palabras vacías. */
function ai_docs_tokens(string $text, bool $dropStop = true): array {
    $stop = ai_docs_stopwords();
    $out = [];
    foreach (explode(' ', ai_docs_norm($text)) as $w) {
        if ($w === '' || strlen($w) < 2) continue;
        if ($dropStop && isset($stop[$w])) continue;
        $out[] = ai_docs_stem($w);
    }
    return $out;
}

/* ------------------------------------------------------------------ utilidades de extracción */

function ai_docs_html_to_text(string $html): string {
    $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr|/table)\s*/?>#i', "\n", $html);
    $html = preg_replace('#</t[dh]>#i', ' | ', $html);
    $t = strip_tags((string)$html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    $t = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t);
    return trim((string)$t);
}

function ai_docs_xml_to_text(string $xml): string {
    $xml = preg_replace('#<w:tab\s*/>#', "\t", $xml);
    $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
    $xml = preg_replace('#</w:p>#', "\n", $xml);
    $xml = preg_replace('#</w:tc>#', ' | ', $xml);
    $xml = preg_replace('#</w:tr>#', "\n", $xml);
    $xml = preg_replace('#</a:p>#', "\n", $xml);
    $t = strip_tags((string)$xml);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    return trim((string)preg_replace("/\n{3,}/", "\n\n", $t));
}

function ai_docs_to_utf8(string $s): string {
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

function ai_docs_zip_open(string $path): ZipArchive {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión PHP "zip" (ZipArchive), necesaria para leer DOCX/PPTX/XLSX/EPUB. Pídele a tu hosting que la active.');
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('El archivo está dañado o no es un ZIP/Office válido.');
    return $z;
}

function ai_docs_zip_read(ZipArchive $z, string $name): string {
    $st = $z->statName($name);
    if (!$st) return '';
    if ($st['size'] > 80 * 1024 * 1024) throw new RuntimeException('Un componente interno del archivo es demasiado grande.');
    $d = $z->getFromName($name);
    return $d === false ? '' : $d;
}

/** Resuelve binarios con rutas absolutas: el PATH de SSH puede diferir del PHP web/FPM. */
function ai_docs_resolve_bin(string $bin): string {
    if (strpos($bin, '/') !== false) return $bin;
    $known = [
        'pdftotext' => ['/usr/bin/pdftotext', '/bin/pdftotext', '/usr/local/bin/pdftotext'],
        'pdftoppm'  => ['/usr/bin/pdftoppm', '/bin/pdftoppm', '/usr/local/bin/pdftoppm'],
        'tesseract' => ['/usr/bin/tesseract', '/bin/tesseract', '/usr/local/bin/tesseract'],
    ];
    foreach (($known[$bin] ?? ['/usr/bin/' . $bin, '/bin/' . $bin, '/usr/local/bin/' . $bin]) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    // Como último recurso, conservar el nombre y permitir la resolución normal del entorno.
    return $bin;
}

/** Ejecuta un comando SIN shell. Devuelve [rc, stdout, stderr] o null si no se puede ejecutar procesos. */
function ai_docs_run(array $cmd, int $timeout = 120): ?array {
    if (!function_exists('proc_open')) return null;
    if (!empty($cmd[0]) && is_string($cmd[0])) $cmd[0] = ai_docs_resolve_bin($cmd[0]);
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = time(); $exit = null;
    while (true) {
        $st = proc_get_status($p);
        $r = [$pipes[1], $pipes[2]]; $w = null; $e = null;
        if (@stream_select($r, $w, $e, 0, 200000) > 0) {
            foreach ($r as $s) {
                $chunk = fread($s, 65536);
                if ($chunk !== false && $chunk !== '') { if ($s === $pipes[1]) $out .= $chunk; else $err .= $chunk; }
            }
        }
        if (!$st['running']) {
            if ($st['exitcode'] !== -1) $exit = $st['exitcode'];
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            break;
        }
        if (time() - $start > $timeout) { proc_terminate($p, 9); $err .= ' [tiempo agotado]'; break; }
        if (strlen($out) > 80 * 1024 * 1024) { proc_terminate($p, 9); break; }
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    if ($exit === null) $exit = $rc;
    return [$exit, $out, $err];
}

/** Comprueba que el binario exista y responde usando el argumento de versión correcto. */
function ai_docs_have_bin(string $bin): bool {
    static $cache = [];
    if (isset($cache[$bin])) return $cache[$bin];

    $path = ai_docs_resolve_bin($bin);
    if (strpos($path, '/') !== false && (!is_file($path) || !is_executable($path))) {
        return $cache[$bin] = false;
    }

    // Poppler usa -v; Tesseract usa --version.
    $versionArg = in_array($bin, ['pdftotext', 'pdftoppm'], true) ? '-v' : '--version';
    $r = ai_docs_run([$bin, $versionArg], 8);
    if ($r === null || $r[0] === 127) return $cache[$bin] = false;

    $output = (string)$r[1] . "\n" . (string)$r[2];
    $missing = stripos($output, 'command not found') !== false
        || stripos($output, 'executable not found') !== false
        || stripos($output, 'failed to execute') !== false;

    return $cache[$bin] = (!$missing && $r[0] === 0);
}

/** Calidad del texto extraído (0..1): proporción de caracteres "normales". */
function ai_docs_text_quality(string $t): float {
    $s = mb_substr($t, 0, 6000);
    $n = mb_strlen($s);
    if ($n === 0) return 0.0;
    $ok = preg_match_all('/[\p{L}\p{N}\s.,;:()¿?¡!\-\/%]/u', $s);
    return $ok / $n;
}

/* ------------------------------------------------------------------ extracción por formato */

function ai_docs_extract_docx(string $path): string {
    $z = ai_docs_zip_open($path);
    $parts = [];
    $main = ai_docs_zip_read($z, 'word/document.xml');
    if ($main === '') { $z->close(); throw new RuntimeException('El DOCX no contiene texto (word/document.xml vacío).'); }
    $parts[] = ai_docs_xml_to_text($main);
    foreach (['word/footnotes.xml', 'word/endnotes.xml'] as $extra) {
        $x = ai_docs_zip_read($z, $extra);
        if ($x !== '') $parts[] = ai_docs_xml_to_text($x);
    }
    $z->close();
    return trim(implode("\n\n", array_filter($parts)));
}

function ai_docs_extract_pptx(string $path): string {
    $z = ai_docs_zip_open($path);
    $slides = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $n, $m)) $slides[(int)$m[1]] = $n;
    }
    ksort($slides);
    $out = [];
    foreach ($slides as $num => $name) {
        $t = ai_docs_xml_to_text(ai_docs_zip_read($z, $name));
        if ($t !== '') $out[] = "Diapositiva $num:\n" . $t;
        $notes = ai_docs_zip_read($z, 'ppt/notesSlides/notesSlide' . $num . '.xml');
        if ($notes !== '') {
            $nt = ai_docs_xml_to_text($notes);
            if ($nt !== '') $out[] = "Notas de la diapositiva $num:\n" . $nt;
        }
    }
    $z->close();
    return trim(implode("\n\n", $out));
}

function ai_docs_extract_xlsx(string $path): string {
    $z = ai_docs_zip_open($path);
    $shared = [];
    $ss = ai_docs_zip_read($z, 'xl/sharedStrings.xml');
    if ($ss !== '' && preg_match_all('#<si[^>]*>(.*?)</si>#s', $ss, $m)) {
        foreach ($m[1] as $si) {
            preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $names = [];
    $wb = ai_docs_zip_read($z, 'xl/workbook.xml');
    if ($wb !== '' && preg_match_all('#<sheet [^>]*name="([^"]*)"#', $wb, $sm)) $names = $sm[1];
    $sheets = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $n, $m)) $sheets[(int)$m[1]] = $n;
    }
    ksort($sheets);
    $out = [];
    foreach ($sheets as $num => $name) {
        $xml = ai_docs_zip_read($z, $name);
        $lines = [];
        if (preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml, $rows)) {
            $count = 0;
            foreach ($rows[1] as $row) {
                if (++$count > 5000) { $lines[] = '(se omiten filas adicionales)'; break; }
                $cells = [];
                if (preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $c) {
                        $attrs = $c[1]; $inner = $c[2] ?? '';
                        $type = preg_match('#\bt="([^"]+)"#', $attrs, $tm) ? $tm[1] : '';
                        $val = '';
                        if ($type === 's' && preg_match('#<v>(\d+)</v>#', $inner, $vm)) $val = $shared[(int)$vm[1]] ?? '';
                        elseif ($type === 'inlineStr' && preg_match_all('#<t[^>]*>(.*?)</t>#s', $inner, $im)) $val = html_entity_decode(implode('', $im[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        elseif (preg_match('#<v>(.*?)</v>#s', $inner, $vm)) $val = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $val = trim($val);
                        if ($val !== '') $cells[] = $val;
                    }
                }
                if ($cells) $lines[] = implode(' | ', $cells);
            }
        }
        if ($lines) {
            $title = isset($names[$num - 1]) ? html_entity_decode($names[$num - 1], ENT_QUOTES | ENT_XML1, 'UTF-8') : "Hoja $num";
            $out[] = "Hoja: $title\n" . implode("\n", $lines);
        }
    }
    $z->close();
    return trim(implode("\n\n", $out));
}

function ai_docs_extract_epub(string $path): string {
    $z = ai_docs_zip_open($path);
    $order = [];
    $opfPath = '';
    $container = ai_docs_zip_read($z, 'META-INF/container.xml');
    if ($container !== '' && preg_match('#full-path="([^"]+)"#', $container, $m)) $opfPath = $m[1];
    $base = $opfPath !== '' && strpos($opfPath, '/') !== false ? dirname($opfPath) . '/' : '';
    $title = '';
    if ($opfPath !== '') {
        $opf = ai_docs_zip_read($z, $opfPath);
        if (preg_match('#<dc:title[^>]*>(.*?)</dc:title>#s', $opf, $tm)) $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $manifest = [];
        if (preg_match_all('#<item\b[^>]*>#', $opf, $items)) {
            foreach ($items[0] as $it) {
                if (preg_match('#\bid="([^"]+)"#', $it, $a) && preg_match('#\bhref="([^"]+)"#', $it, $b)) $manifest[$a[1]] = urldecode($b[1]);
            }
        }
        if (preg_match_all('#<itemref\b[^>]*idref="([^"]+)"#', $opf, $refs)) {
            foreach ($refs[1] as $id) if (isset($manifest[$id])) $order[] = $base . $manifest[$id];
        }
    }
    if (!$order) { // plan B: todos los html/xhtml en orden alfabético
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (preg_match('#\.(x?html?)$#i', $n)) $order[] = $n;
        }
        sort($order);
    }
    $out = [];
    if ($title !== '') $out[] = $title;
    $total = 0;
    foreach ($order as $name) {
        $name = preg_replace('#[^/]+/\.\./#', '', $name);
        $t = ai_docs_html_to_text(ai_docs_zip_read($z, $name));
        if ($t === '') continue;
        $out[] = $t;
        $total += mb_strlen($t);
        if ($total > AI_DOCS_MAX_CHARS) break;
    }
    $z->close();
    return trim(implode("\n\n", $out));
}

function ai_docs_extract_textfile(string $path, string $ext): string {
    $raw = (string)file_get_contents($path, false, null, 0, AI_DOCS_MAX_CHARS * 2);
    $t = ai_docs_to_utf8($raw);
    if ($ext === 'html' || $ext === 'htm') return ai_docs_html_to_text($t);
    if ($ext === 'json') {
        $j = json_decode($t, true);
        if (is_array($j)) $t = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    return trim($t);
}

/** Lector de PDF mínimo en PHP (para PDFs simples cuando no hay pdftotext). */
function ai_docs_pdf_basic(string $data): string {
    if (!preg_match_all('/stream[\r\n]+(.*?)[\r\n]*endstream/s', $data, $streams)) return '';
    $text = '';
    foreach ($streams[1] as $s) {
        $d = @gzuncompress($s);
        if ($d === false) $d = @gzinflate(substr($s, 2));
        if ($d === false) $d = @gzinflate($s);
        if ($d === false) $d = $s;
        if (strpos($d, 'BT') === false || strpos($d, 'ET') === false) continue;
        if (!preg_match_all('/BT(.*?)ET/s', $d, $bt)) continue;
        foreach ($bt[1] as $blk) {
            if (!preg_match_all('/\[((?:\\\\.|[^\]\\\\])*)\]\s*TJ|\(((?:\\\\.|[^)\\\\])*)\)\s*Tj|\(((?:\\\\.|[^)\\\\])*)\)\s*[\'"]|(T\*)/s', $blk, $mm, PREG_SET_ORDER)) continue;
            $line = '';
            foreach ($mm as $m) {
                if (!empty($m[4])) { $line .= "\n"; continue; }
                if (isset($m[1]) && $m[1] !== '') {
                    if (preg_match_all('/\(((?:\\\\.|[^)\\\\])*)\)|(-?\d+(?:\.\d+)?)/s', $m[1], $parts, PREG_SET_ORDER)) {
                        foreach ($parts as $p) {
                            if (isset($p[1]) && $p[1] !== '') $line .= ai_docs_pdf_unescape($p[1]);
                            elseif (isset($p[2]) && $p[2] !== '' && (float)$p[2] < -200) $line .= ' ';
                        }
                    }
                } elseif (isset($m[2]) && $m[2] !== '') $line .= ai_docs_pdf_unescape($m[2]);
                elseif (isset($m[3]) && $m[3] !== '') $line .= ai_docs_pdf_unescape($m[3]);
            }
            $text .= trim($line) . "\n";
        }
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", ai_docs_to_utf8($text)));
}

function ai_docs_pdf_unescape(string $s): string {
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) { return chr(octdec($m[1]) & 0xFF); }, $s);
    return strtr($s, ['\\n' => "\n", '\\r' => '', '\\t' => ' ', '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
}

/** OCR de una imagen con tesseract (si existe). Devuelve texto o ''. */
function ai_docs_tesseract(string $imgPath): string {
    if (!ai_docs_have_bin('tesseract')) return '';
    foreach ([['-l', 'spa+eng'], ['-l', 'eng'], []] as $lang) {
        $r = ai_docs_run(array_merge(['tesseract', $imgPath, 'stdout'], $lang), 90);
        if ($r !== null && $r[0] === 0 && trim($r[1]) !== '') return trim($r[1]);
    }
    return '';
}

/** PDF escaneado: pdftoppm + tesseract (hasta 40 páginas). */
function ai_docs_pdf_ocr(string $path): string {
    if (!ai_docs_have_bin('pdftoppm') || !ai_docs_have_bin('tesseract')) return '';
    $tmp = sys_get_temp_dir() . '/aiocr_' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0700, true)) return '';
    $text = '';
    try {
        $r = ai_docs_run(['pdftoppm', '-r', '150', '-l', '40', '-png', $path, $tmp . '/p'], 240);
        if ($r === null || $r[0] !== 0) return '';
        $files = glob($tmp . '/p*.png') ?: [];
        sort($files);
        foreach ($files as $i => $f) {
            $t = ai_docs_tesseract($f);
            if ($t !== '') $text .= $t . "\n\n";
            if (mb_strlen($text) > AI_DOCS_MAX_CHARS) break;
        }
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    }
    return trim($text);
}

/**
 * Llama a Claude con una imagen o un PDF (base64) para transcribir/describir.
 * Lanza RuntimeException con el motivo real si falla.
 */
function ai_docs_llm_media(string $bytes, string $mediaType, string $instruction, int $maxTokens = 4000): string {
    $key = app_env('ANTHROPIC_API_KEY', '');
    if ($key === '' || !function_exists('curl_init')) throw new RuntimeException('La IA con visión no está configurada (falta ANTHROPIC_API_KEY o la extensión curl).');
    $isPdf = ($mediaType === 'application/pdf');
    $block = [
        'type' => $isPdf ? 'document' : 'image',
        'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode($bytes)],
    ];
    $payload = [
        'model' => app_env('AI_VISION_MODEL', app_env('AI_MODEL', 'claude-haiku-5-5')),
        'max_tokens' => $maxTokens,
        'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => $instruction]]]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('No se pudo contactar a la IA: ' . $err);
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) ? ($data['error']['message'] ?? '') : '';
        throw new RuntimeException('La IA respondió con error HTTP ' . $code . ($msg ? ': ' . mb_substr($msg, 0, 200) : '.'));
    }
    $text = '';
    foreach (($data['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    return trim($text);
}

/** Reduce una imagen grande (GD) para poder enviarla a la IA. Devuelve [bytes, mediaType]. */
function ai_docs_prepare_image(string $path, string $ext): array {
    $bytes = (string)file_get_contents($path);
    $media = ai_docs_mime_for($ext);
    $info = @getimagesizefromstring($bytes);
    $tooBig = strlen($bytes) > 4500000 || ($info && max($info[0], $info[1]) > 2400);
    if ($tooBig && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $img = @imagecreatefromstring($bytes);
        if ($img) {
            $w = imagesx($img); $h = imagesy($img);
            $scale = min(1, 1800 / max($w, $h));
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagejpeg($dst, null, 82); $bytes = (string)ob_get_clean();
            imagedestroy($img); imagedestroy($dst);
            $media = 'image/jpeg';
        }
    }
    return [$bytes, $media];
}

/**
 * Extrae el texto de un archivo. Devuelve ['text' => ..., 'method' => ..., 'note' => ...].
 * Lanza RuntimeException con un motivo claro si no se puede leer.
 */
function ai_docs_extract(string $path, string $ext): array {
    if (!is_file($path)) throw new RuntimeException('El archivo ya no existe en el servidor (vuelve a subirlo).');
    $note = '';
    switch ($ext) {
        case 'txt': case 'md': case 'csv': case 'json': case 'html': case 'htm': case 'log':
            return ['text' => ai_docs_extract_textfile($path, $ext), 'method' => 'texto', 'note' => ''];
        case 'docx': return ['text' => ai_docs_extract_docx($path), 'method' => 'docx', 'note' => ''];
        case 'pptx': return ['text' => ai_docs_extract_pptx($path), 'method' => 'pptx', 'note' => ''];
        case 'xlsx': return ['text' => ai_docs_extract_xlsx($path), 'method' => 'xlsx', 'note' => ''];
        case 'epub': return ['text' => ai_docs_extract_epub($path), 'method' => 'epub', 'note' => ''];

        case 'pdf':
            $tried = [];
            $pdfDiagnostics = [];
            if (ai_docs_have_bin('pdftotext')) {
                $r = ai_docs_run(['pdftotext', '-enc', 'UTF-8', '-nopgbrk', $path, '-'], 180);
                if ($r !== null && $r[0] === 0 && mb_strlen(trim($r[1])) >= 30 && ai_docs_text_quality($r[1]) > 0.75) {
                    return ['text' => trim($r[1]), 'method' => 'pdftotext', 'note' => ''];
                }
                $tried[] = 'pdftotext';
                if ($r === null) $pdfDiagnostics[] = 'pdftotext no pudo ejecutarse desde PHP (proc_open/permisos o entorno web)';
                elseif ($r[0] !== 0) $pdfDiagnostics[] = 'pdftotext terminó con código ' . $r[0] . (trim($r[2]) !== '' ? ': ' . trim($r[2]) : '');
                elseif (mb_strlen(trim($r[1])) < 30) $pdfDiagnostics[] = 'pdftotext no encontró suficiente texto; puede ser un PDF escaneado';
                else $pdfDiagnostics[] = 'pdftotext devolvió texto con calidad insuficiente';
            } else {
                $pdfDiagnostics[] = 'pdftotext no está accesible desde el proceso PHP web';
            }
            $basic = ai_docs_pdf_basic((string)file_get_contents($path));
            if (mb_strlen($basic) >= 30 && ai_docs_text_quality($basic) > 0.85) {
                return ['text' => $basic, 'method' => 'lector-php', 'note' => ''];
            }
            $ocr = ai_docs_pdf_ocr($path);
            if (mb_strlen($ocr) >= 80) return ['text' => $ocr, 'method' => 'ocr-pdf', 'note' => 'PDF escaneado: se usó OCR (hasta 40 páginas).'];
            $pdfDiagnostics[] = 'OCR local no devolvió suficiente texto (comprueba acceso web a pdftoppm/tesseract y el idioma OCR)';
            if (ai_llm_enabled() && filesize($path) <= 12 * 1024 * 1024) {
                $t = ai_docs_llm_media((string)file_get_contents($path), 'application/pdf',
                    'Transcribe fielmente TODO el texto de este documento en español, conservando títulos, listas y pasos numerados. No resumas ni añadas comentarios.', 12000);
                if (mb_strlen($t) >= 80) return ['text' => $t, 'method' => 'ia-pdf', 'note' => 'Extraído con IA; en PDFs muy largos puede quedar parcial.'];
            }
            throw new RuntimeException('No se pudo extraer texto del PDF. ' . implode('; ', $pdfDiagnostics) . '. Si es un PDF escaneado, revisa el OCR y los permisos del proceso PHP web.');

        case 'jpg': case 'jpeg': case 'png': case 'webp':
            $parts = [];
            $method = [];
            $ocr = ai_docs_tesseract($path);
            if ($ocr !== '') { $parts[] = "Texto en la imagen:\n" . $ocr; $method[] = 'ocr'; }
            if (ai_llm_enabled()) {
                try {
                    [$bytes, $media] = ai_docs_prepare_image($path, $ext);
                    $d = ai_docs_llm_media($bytes, $media,
                        'Describe esta imagen para una base de conocimientos de soporte técnico (TI): qué equipo, pantalla o aplicación muestra, transcribe literalmente cualquier mensaje de error, código o etiqueta visible, y si es un diagrama o manual explica sus pasos. Responde en español, sin introducciones.', 1500);
                    if ($d !== '') { $parts[] = "Descripción de la imagen:\n" . $d; $method[] = 'ia-vision'; }
                } catch (RuntimeException $e) {
                    if (!$parts) throw $e;
                    $note = 'La descripción con IA falló (' . $e->getMessage() . '); se guardó solo el OCR.';
                }
            }
            if (!$parts) throw new RuntimeException('No hay OCR disponible para leer la imagen. Instala tesseract en el servidor o configura ANTHROPIC_API_KEY (visión).');
            return ['text' => implode("\n\n", $parts), 'method' => implode('+', $method), 'note' => $note];
    }
    throw new RuntimeException('Formato no soportado: .' . $ext);
}

/* ------------------------------------------------------------------ fragmentos */

function ai_docs_chunk(string $text): array {
    $size = AI_DOCS_CHUNK_SIZE; $overlap = AI_DOCS_CHUNK_OVERLAP;
    $text = preg_replace("/[ \t]+/", ' ', str_replace(["\r\n", "\r"], "\n", $text));
    $text = trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
    if ($text === '') return [];

    // 1) unidades de tamaño razonable
    $units = [];
    foreach (preg_split('/\n{2,}/', $text) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (mb_strlen($para) <= $size) { $units[] = $para; continue; }
        $sentences = preg_split('/(?<=[\.\!\?;:])\s+|\n/u', $para) ?: [$para];
        $buf = '';
        foreach ($sentences as $s) {
            while (mb_strlen($s) > $size) {          // frase gigante: corte duro
                if ($buf !== '') { $units[] = trim($buf); $buf = ''; }
                $units[] = mb_substr($s, 0, $size);
                $s = mb_substr($s, $size);
            }
            if (mb_strlen($buf) + mb_strlen($s) + 1 > $size) { if ($buf !== '') $units[] = trim($buf); $buf = $s; }
            else $buf .= ($buf === '' ? '' : ' ') . $s;
        }
        if (trim($buf) !== '') $units[] = trim($buf);
    }

    // 2) empaquetar con solape
    $chunks = []; $cur = '';
    foreach ($units as $u) {
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($u) + 2 > $size) {
            $chunks[] = $cur;
            $tail = mb_substr($cur, -$overlap);
            $sp = mb_strpos($tail, ' ');
            $cur = ($sp !== false ? trim(mb_substr($tail, $sp)) : '') . "\n" . $u;
            $cur = trim($cur);
        } else {
            $cur .= ($cur === '' ? '' : "\n\n") . $u;
        }
        if (count($chunks) >= AI_DOCS_MAX_CHUNKS) break;
    }
    if ($cur !== '' && count($chunks) < AI_DOCS_MAX_CHUNKS) $chunks[] = $cur;
    return $chunks;
}

/* ------------------------------------------------------------------ almacenamiento y subida */

function ai_docs_storage_dir(int $tenantId): string {
    $base = dirname(__DIR__) . '/uploads/kb';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    if (is_dir($base)) {
        if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        if (!is_file($base . '/index.html')) @file_put_contents($base . '/index.html', '');
    }
    $dir = $base . '/' . $tenantId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/';
}

/**
 * Valida y guarda UN archivo subido. Devuelve ['ok'=>bool, 'id'=>?int, 'name'=>string, 'message'=>string].
 * No indexa: llama después a ai_docs_process().
 */
function ai_docs_store_upload(PDO $pdo, int $tenantId, int $userId, array $f, ?int $categoryId = null, ?string $title = null): array {
    ai_docs_ensure_schema($pdo);
    $name = (string)($f['name'] ?? 'archivo');
    $fail = function (string $m) use ($name) { return ['ok' => false, 'id' => null, 'name' => $name, 'message' => $m]; };

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return $fail(ai_docs_upload_error((int)$f['error']));
    $tmp = (string)($f['tmp_name'] ?? '');
    if (!is_file($tmp)) return $fail('El servidor no recibió el archivo temporal.');
    $size = (int)($f['size'] ?? filesize($tmp));
    if ($size <= 0) return $fail('El archivo está vacío.');
    if ($size > AI_DOCS_MAX_BYTES) return $fail('Supera el máximo de ' . ai_docs_format_size(AI_DOCS_MAX_BYTES) . ' (pesa ' . ai_docs_format_size($size) . ').');

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = ai_docs_types();
    if (!isset($types[$ext])) return $fail('Formato .' . ($ext ?: '?') . ' no permitido. Usa: ' . implode(', ', array_keys($types)) . '.');

    // Contenido real vs extensión
    $head = (string)file_get_contents($tmp, false, null, 0, 4096);
    if ($ext === 'pdf' && strpos($head, '%PDF-') === false) return $fail('No es un PDF válido.');
    if (in_array($ext, ['docx', 'pptx', 'xlsx', 'epub'], true) && substr($head, 0, 2) !== 'PK') return $fail('El archivo no es un .' . $ext . ' válido (puede estar dañado o ser un formato antiguo).');
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && !@getimagesize($tmp)) return $fail('No es una imagen válida.');
    if ($types[$ext] === 'text' && (strpos($head, "\0") !== false || preg_match('/<\?php|<\?=/i', $head))) return $fail('El archivo de texto contiene datos binarios o código no permitido.');

    $sha = sha1_file($tmp);
    $dup = $pdo->prepare('SELECT id, title FROM ai_documents WHERE tenant_id = ? AND sha1 = ? LIMIT 1');
    $dup->execute([$tenantId, $sha]);
    if ($d = $dup->fetch(PDO::FETCH_ASSOC)) return $fail('Ya existe en tu centro de conocimiento como «' . $d['title'] . '».');

    $dir = ai_docs_storage_dir($tenantId);
    if (!is_dir($dir) || !is_writable($dir)) return $fail('El servidor no puede escribir en uploads/kb. Revisa los permisos de la carpeta.');
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . $stored) : (PHP_SAPI === 'cli' ? copy($tmp, $dir . $stored) : false);
    if (!$ok) return $fail('No se pudo guardar el archivo en el servidor.');
    @chmod($dir . $stored, 0640);

    $t = trim((string)$title) !== '' ? trim((string)$title) : trim(preg_replace('/[_-]+/', ' ', pathinfo($name, PATHINFO_FILENAME)));
    $st = $pdo->prepare('INSERT INTO ai_documents (tenant_id, uploaded_by, title, original_name, stored_name, ext, mime_type, kind, size_bytes, sha1, category_id, status, status_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$tenantId, $userId ?: null, mb_substr($t, 0, 190), mb_substr($name, 0, 255), $stored, $ext, ai_docs_mime_for($ext), $types[$ext], $size, $sha, $categoryId, 'pending', 'Subido. Pendiente de procesar.']);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'message' => 'Subido'];
}

/* ------------------------------------------------------------------ indexado */

/** Extrae, fragmenta e indexa un documento del tenant. Devuelve ['ok'=>bool,'message'=>string]. */
function ai_docs_process(PDO $pdo, int $docId, int $tenantId): array {
    ai_docs_ensure_schema($pdo);
    @set_time_limit(300);
    $st = $pdo->prepare('SELECT * FROM ai_documents WHERE id = ? AND tenant_id = ?');
    $st->execute([$docId, $tenantId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return ['ok' => false, 'message' => 'Documento no encontrado.'];

    $pdo->prepare("UPDATE ai_documents SET status = 'processing', status_message = 'Procesando…' WHERE id = ? AND tenant_id = ?")->execute([$docId, $tenantId]);
    try {
        $path = ai_docs_storage_dir($tenantId) . $doc['stored_name'];
        $res = ai_docs_extract($path, $doc['ext']);
        $text = trim($res['text']);
        if (mb_strlen($text) < 20) throw new RuntimeException('No se encontró texto legible en el archivo.');
        if (mb_strlen($text) > AI_DOCS_MAX_CHARS) $text = mb_substr($text, 0, AI_DOCS_MAX_CHARS);

        $chunks = ai_docs_chunk($text);
        if (!$chunks) throw new RuntimeException('El texto extraído quedó vacío tras limpiarlo.');

        $titleTokens = array_unique(ai_docs_tokens($doc['title'] . ' ' . pathinfo($doc['original_name'], PATHINFO_FILENAME)));
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM ai_doc_chunks WHERE document_id = ? AND tenant_id = ?')->execute([$docId, $tenantId]);
        $ins = $pdo->prepare('INSERT INTO ai_doc_chunks (tenant_id, document_id, chunk_index, content, terms) VALUES (?,?,?,?,?)');
        foreach ($chunks as $i => $c) {
            $terms = array_unique(array_merge(ai_docs_tokens($c), $titleTokens));
            $ins->execute([$tenantId, $docId, $i, $c, ' ' . implode(' ', $terms) . ' ']);
        }
        $msg = 'Indexado: ' . count($chunks) . ' fragmentos, ' . number_format(mb_strlen($text), 0, ',', '.') . ' caracteres.' . ($res['note'] ? ' ' . $res['note'] : '');
        $pdo->prepare("UPDATE ai_documents SET status = 'indexed', status_message = ?, extract_method = ?, char_count = ?, chunk_count = ?, indexed_at = NOW() WHERE id = ? AND tenant_id = ?")
            ->execute([mb_substr($msg, 0, 490), $res['method'], mb_strlen($text), count($chunks), $docId, $tenantId]);
        $pdo->commit();
        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ai_docs_process #' . $docId . ': ' . $e->getMessage());
        $m = mb_substr($e->getMessage(), 0, 480);
        $pdo->prepare("UPDATE ai_documents SET status = 'error', status_message = ?, chunk_count = 0 WHERE id = ? AND tenant_id = ?")->execute([$m, $docId, $tenantId]);
        try { $pdo->prepare('DELETE FROM ai_doc_chunks WHERE document_id = ? AND tenant_id = ?')->execute([$docId, $tenantId]); } catch (Throwable $e2) {}
        return ['ok' => false, 'message' => $m];
    }
}

function ai_docs_delete(PDO $pdo, int $tenantId, int $docId): bool {
    ai_docs_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT stored_name FROM ai_documents WHERE id = ? AND tenant_id = ?');
    $st->execute([$docId, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $pdo->prepare('DELETE FROM ai_doc_chunks WHERE document_id = ? AND tenant_id = ?')->execute([$docId, $tenantId]);
    $pdo->prepare('DELETE FROM ai_documents WHERE id = ? AND tenant_id = ?')->execute([$docId, $tenantId]);
    $f = ai_docs_storage_dir($tenantId) . $row['stored_name'];
    if (is_file($f)) @unlink($f);
    return true;
}

/* ------------------------------------------------------------------ búsqueda (solo del tenant) */

/**
 * Devuelve los fragmentos más relevantes: [['document_id','title','name','kind','snippet','score','confidence'], ...]
 * confidence = % de términos de la consulta presentes en el fragmento.
 */
function ai_docs_search(PDO $pdo, int $tenantId, string $query, int $limit = 4): array {
    try {
        ai_docs_ensure_schema($pdo);
        $q = array_values(array_unique(ai_docs_tokens($query)));
        $q = array_slice($q, 0, 10);
        if (!$q) return [];
        $likes = []; $params = [$tenantId];
        foreach ($q as $t) { $likes[] = 'c.terms LIKE ?'; $params[] = '% ' . $t . ' %'; }
        $sql = "SELECT c.id, c.document_id, c.content, c.terms, d.title, d.original_name, d.kind
                FROM ai_doc_chunks c
                JOIN ai_documents d ON d.id = c.document_id AND d.tenant_id = c.tenant_id
                WHERE c.tenant_id = ? AND d.is_active = 1 AND d.status = 'indexed' AND (" . implode(' OR ', $likes) . ")
                LIMIT 600";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $N = count($rows); $df = [];
        $sets = [];
        foreach ($rows as $i => $r) {
            $sets[$i] = array_flip(explode(' ', trim($r['terms'])));
            foreach ($q as $t) if (isset($sets[$i][$t])) $df[$t] = ($df[$t] ?? 0) + 1;
        }
        $qNorm = ai_docs_norm($query);
        $scored = [];
        foreach ($rows as $i => $r) {
            $matched = 0; $score = 0.0;
            foreach ($q as $t) {
                if (!isset($sets[$i][$t])) continue;
                $matched++;
                $score += log(1 + $N / max(1, $df[$t])) * (strlen($t) > 5 ? 1.3 : 1.0);
            }
            if ($matched === 0) continue;
            $coverage = min(1.0, $matched / min(count($q), 4));   // los casos largos tienen muchos términos: con 4 aciertos ya es relevante
            if (count($q) >= 3 && $matched < 2) continue;                  // una sola coincidencia suelta no basta
            $score *= (0.5 + $coverage);
            $cn = ai_docs_norm($r['content']);
            if ($qNorm !== '' && mb_strlen($qNorm) > 8 && strpos($cn, $qNorm) !== false) $score += 3;   // frase completa
            $scored[] = ['row' => $r, 'score' => $score, 'confidence' => (int)round(100 * $coverage)];
        }
        usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });

        $out = []; $perDoc = [];
        foreach ($scored as $s) {
            $r = $s['row'];
            $perDoc[$r['document_id']] = ($perDoc[$r['document_id']] ?? 0) + 1;
            if ($perDoc[$r['document_id']] > 2) continue;
            $out[] = [
                'document_id' => (int)$r['document_id'], 'title' => $r['title'], 'name' => $r['original_name'], 'kind' => $r['kind'],
                'snippet' => mb_substr(trim($r['content']), 0, 1100), 'score' => round($s['score'], 2), 'confidence' => $s['confidence'],
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('ai_docs_search: ' . $e->getMessage());
        return [];
    }
}

/** Contadores para el panel. */
function ai_docs_stats(PDO $pdo, int $tenantId): array {
    ai_docs_ensure_schema($pdo);
    $s = ['total' => 0, 'indexed' => 0, 'pending' => 0, 'error' => 0, 'document' => 0, 'book' => 0, 'image' => 0, 'text' => 0, 'chunks' => 0];
    $st = $pdo->prepare('SELECT status, kind, COUNT(*) n, COALESCE(SUM(chunk_count),0) ch FROM ai_documents WHERE tenant_id = ? GROUP BY status, kind');
    $st->execute([$tenantId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s['total'] += (int)$r['n'];
        if ($r['status'] === 'indexed') $s['indexed'] += (int)$r['n'];
        elseif ($r['status'] === 'error') $s['error'] += (int)$r['n'];
        else $s['pending'] += (int)$r['n'];
        if (isset($s[$r['kind']])) $s[$r['kind']] += (int)$r['n'];
        $s['chunks'] += (int)$r['ch'];
    }
    return $s;
}
