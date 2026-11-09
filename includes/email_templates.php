<?php

function ds_palette(string $color): array {
    $palettes = [
        'blue'   => ['bg' => '#1d4ed8', 'text' => '#ffffff', 'light' => '#dbeafe', 'light_text' => '#1e3a8a'],
        'teal'   => ['bg' => '#059669', 'text' => '#ffffff', 'light' => '#d1fae5', 'light_text' => '#064e3b'],
        'purple' => ['bg' => '#7c3aed', 'text' => '#ffffff', 'light' => '#ede9fe', 'light_text' => '#4c1d95'],
    ];
    return $palettes[$color] ?? $palettes['blue'];
}

function ds_badge_status(string $status): string {
    $map = [
        'open'    => ['Abierto',   '#dbeafe', '#1e3a8a'],
        'pending' => ['En espera', '#dbeafe', '#1e3a8a'],
        'closed'  => ['Resuelto',  '#d1fae5', '#064e3b'],
    ];
    [$label, $bg, $color] = $map[$status] ?? [ucfirst($status), '#f1f5f9', '#334155'];
    return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:'.$bg.';color:'.$color.';font-weight:700;font-size:13px;border:1.5px solid '.$color.'40">'.$label.'</span>';
}


function ds_badge_priority(string $priority): string {
    $map = [
        'low'      => ['Baja',    '#d1fae5', '#065f46'],
        'medium'   => ['Media',   '#fef3c7', '#92400e'],
        'high'     => ['Alta',    '#fee2e2', '#991b1b'],
        'critical' => ['Crítica', '#fee2e2', '#991b1b'],
    ];
    [$label, $bg, $color] = $map[$priority] ?? [ucfirst($priority), '#f1f5f9', '#334155'];
    return '<span style="display:inline-block;padding:5px 14px;border-radius:999px;background:'.$bg.';color:'.$color.';font-weight:700;font-size:13px;border:1.5px solid '.$color.'40">'.$label.'</span>';
}

// ── SVG íconos inline───
function ds_icon(string $name, string $stroke = '#ffffff', int $size = 20): string {
    $paths = [
        'bell'    => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'ticket'  => '<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>',
        'mail'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,4 12,13 2,4"/>',
        'check'   => '<polyline points="20 6 9 17 4 12"/>',
        'chat'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'headset' => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/><path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'help'    => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'email'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,4 12,13 2,4"/>',
    ];
    $d = $paths[$name] ?? $paths['help'];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="'.htmlspecialchars($stroke).'" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle">'.$d.'</svg>';
}

// ── Divisor ───────────────────────────────────────────────────
function ds_divider(): string {
    return '<div style="height:1px;background:#f1f5f9;margin:14px 0"></div>';
}

// ── Subtítulo ─────────────────────────────────────────────────
function ds_subtitle(string $html): string {
    return '<p style="margin:0 0 0 0;font-size:14px;color:#475569;line-height:1.6">'.$html.'</p>';
}

// ── Limpia el mensaje antes de mostrarlo ───────────
function ds_clean_message(string $message): string {
    $msg = preg_replace('/<br\s*\/?>/ i', "\n", $message);
    $msg = strip_tags($msg);
    $msg = html_entity_decode($msg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $msg = preg_replace('/[ \t]+/', ' ', $msg);
    $msg = trim($msg);
    $msg = preg_replace('/\n{3,}/', "\n\n", $msg);
    return nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
}

// ── Pie de nota ───────────────────────────────────────────────
function ds_hint(string $text): string {
    return '<table cellpadding="0" cellspacing="0" role="presentation">
      <tr>
        <td style="padding-right:7px;vertical-align:middle">'.ds_icon('chat','#94a3b8',14).'</td>
        <td style="font-size:12px;color:#94a3b8;line-height:1.5">'.$text.'</td>
      </tr>
    </table>';
}

// ═══════════════════════════════════════════════════════════════
//  PERSONALIZACIÓN POR ORGANIZACIÓN (marca + textos)
//  Se guarda en tenant_settings (clave: email_design).
//  Sin configuración se usan los textos originales de siempre.
// ═══════════════════════════════════════════════════════════════
foreach ([__DIR__ . '/tenant_settings.php', __DIR__ . '/includes/tenant_settings.php'] as $__ts) {
    if (!function_exists('ts_get') && file_exists($__ts)) { require_once $__ts; }
}
unset($__ts);

function ds_default_config(): array {
    return [
        'brand' => [
            'name'        => 'Desk Service',
            'logo_url'    => 'https://deskserviced.alwaysdata.net/assets/img/deskser.png',
            'site_url'    => 'https://deskserviced.alwaysdata.net',
            'footer_text' => 'Este es un mensaje automático de Desk Service.',
            'color'       => '',   // vacío = colores propios de cada plantilla
            'show_footer' => 1,
        ],
        'templates' => [
            'ticket_created' => [
                'title'     => 'Ticket creado #{ticket}',
                'intro'     => 'Hola {nombre}, se creó tu ticket.',
                'hint'      => 'Responde a este correo para contactar al soporte.',
                'cta_title' => 'Estamos para ayudarte',
                'cta_text'  => 'Nuestro equipo revisará tu solicitud y te responderá pronto.',
                'btn_label' => 'Ir al ticket',
            ],
            'message_added' => [
                'title'     => 'Actualización del ticket #{ticket}',
                'intro'     => 'Nuevo mensaje en el ticket #{ticket}{por_autor}.',
                'hint'      => 'Inicia sesión para ver más detalles.',
                'cta_title' => 'Mantente al tanto',
                'cta_text'  => 'Inicia sesión para ver más detalles y responder al ticket.',
                'btn_label' => 'Ir al ticket',
            ],
            'status_updated' => [
                'title'     => 'Ticket #{ticket} actualizado',
                'intro'     => 'Tu ticket #{ticket} fue actualizado.',
                'hint'      => 'Inicia sesión para ver más detalles.',
                'cta_title' => '¿Necesitas más ayuda?',
                'cta_text'  => 'Inicia sesión para ver más detalles o responder al ticket.',
                'btn_label' => 'Ver ticket',
            ],
            'ticket_closed' => [
                'title'     => 'Ticket #{ticket} resuelto',
                'intro'     => 'Tu caso ya se resolvió. ¡Gracias por usar {empresa}!',
                'hint'      => 'Gracias por usar {empresa}.',
                'cta_title' => '¿Necesitas más ayuda?',
                'cta_text'  => 'Puedes abrir un nuevo ticket cuando lo necesites.',
                'btn_label' => 'Ver ticket',
            ],
            'escalated' => [
                'title'     => 'Caso Escalado a Soporte',
                'intro'     => 'Hola {nombre}, vimos que tu problema sigue sin resolverse.',
                'body'      => 'Ya escalamos tu caso "{asunto}" con un técnico de nuestro equipo, quien lo revisará personalmente.',
                'hint'      => 'Puedes seguir el avance de tu caso con la referencia del ticket #{ticket}.',
                'cta_title' => 'Ticket #{ticket}',
                'cta_text'  => 'Un técnico se pondrá en contacto contigo lo antes posible.',
                'btn_label' => 'Ver mi ticket',
            ],
            'ai_reply' => [
                'title'     => 'Respuesta Automática',
                'intro'     => 'Hola {nombre}, esta es una respuesta generada por nuestro asistente de soporte a partir de tu correo.',
                'hint'      => '¿No se resolvió tu inquietud? Responde a este mismo correo y lo derivaremos con un técnico.',
                'cta_title' => 'Categoría: {categoria}',
                'cta_text'  => 'Nuestro asistente analizó tu mensaje. Si necesitas más ayuda, contáctanos.',
                'btn_label' => 'Ir a mi cuenta',
            ],
        ],
    ];
}

/** Metadatos para el editor: nombre visible, variables disponibles y campos editables. */
function ds_template_meta(): array {
    $labels = [
        'title' => 'Título del encabezado', 'intro' => 'Saludo / introducción', 'body' => 'Mensaje principal',
        'hint' => 'Nota al pie', 'cta_title' => 'Título del panel lateral', 'cta_text' => 'Texto del panel lateral',
        'btn_label' => 'Texto del botón',
    ];
    return [
        'ticket_created' => ['label' => 'Ticket creado',            'vars' => ['nombre','ticket','empresa'],            'fields' => ['title','intro','hint','cta_title','cta_text','btn_label']],
        'message_added'  => ['label' => 'Nuevo mensaje en ticket',  'vars' => ['ticket','por_autor','empresa'],         'fields' => ['title','intro','hint','cta_title','cta_text','btn_label']],
        'status_updated' => ['label' => 'Ticket actualizado',       'vars' => ['ticket','empresa'],                     'fields' => ['title','intro','hint','cta_title','cta_text','btn_label']],
        'ticket_closed'  => ['label' => 'Ticket resuelto',          'vars' => ['ticket','empresa'],                     'fields' => ['title','intro','hint','cta_title','cta_text','btn_label']],
        'escalated'      => ['label' => 'Caso escalado a técnico',  'vars' => ['nombre','ticket','asunto','empresa'],   'fields' => ['title','intro','body','hint','cta_title','cta_text','btn_label']],
        'ai_reply'       => ['label' => 'Respuesta automática IA',  'vars' => ['nombre','categoria','empresa'],         'fields' => ['title','intro','hint','cta_title','cta_text','btn_label']],
        '_labels'        => $labels,
    ];
}

/** Limpia y valida una configuración (de POST o de la base de datos). Solo conserva claves conocidas. */
function ds_sanitize_config(array $in): array {
    $def = ds_default_config();
    $out = $def;
    $clean = function ($v, int $max = 400): string {
        $v = (string)$v;
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return mb_substr(trim($v), 0, $max);
    };
    $b = is_array($in['brand'] ?? null) ? $in['brand'] : [];
    if (isset($b['name']) && $clean($b['name'], 60) !== '') $out['brand']['name'] = $clean($b['name'], 60);
    if (isset($b['footer_text'])) $out['brand']['footer_text'] = $clean($b['footer_text'], 200);
    if (isset($b['site_url'])) {
        $u = $clean($b['site_url'], 255);
        if ($u !== '' && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) $out['brand']['site_url'] = $u;
    }
    if (isset($b['logo_url'])) {
        $u = $clean($b['logo_url'], 500);
        if ($u === '') { $out['brand']['logo_url'] = ''; }
        elseif (preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) { $out['brand']['logo_url'] = $u; }
    }
    if (isset($b['color'])) {
        $c = $clean($b['color'], 7);
        $out['brand']['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '';
    }
    $out['brand']['show_footer'] = !empty($b['show_footer']) ? 1 : 0;
    // Si el formulario no trae la marca (p. ej. datos guardados antiguos), respetar el valor por defecto de show_footer
    if (!isset($in['brand']) ) $out['brand']['show_footer'] = $def['brand']['show_footer'];

    $t = is_array($in['templates'] ?? null) ? $in['templates'] : (is_array($in['tpl'] ?? null) ? $in['tpl'] : []);
    foreach ($def['templates'] as $key => $fields) {
        foreach ($fields as $f => $default) {
            $val = isset($t[$key][$f]) ? $clean($t[$key][$f], $f === 'body' || $f === 'intro' ? 500 : 200) : '';
            $out['templates'][$key][$f] = $val !== '' ? $val : $default;   // vacío => texto predeterminado
        }
    }
    return $out;
}

/** Permite a quien llama (webhooks, cron, IMAP) indicar la organización para la que se generan los correos. */
function ds_use_tenant(?PDO $pdo, int $tenant_id): void {
    $GLOBALS['ds_pdo'] = $pdo;
    $GLOBALS['ds_tenant_id'] = $tenant_id;
}

/** Configuración activa: vista previa (override) > configuración del tenant > predeterminada. */
function ds_cfg(): array {
    if (!empty($GLOBALS['ds_cfg_override']) && is_array($GLOBALS['ds_cfg_override'])) return $GLOBALS['ds_cfg_override'];
    $tid = (int)($GLOBALS['ds_tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
    if (isset($GLOBALS['ds_cfg_cache'][$tid])) return $GLOBALS['ds_cfg_cache'][$tid];
    $cfg = ds_default_config();
    if ($tid > 0 && function_exists('ts_get')) {
        $pdo = $GLOBALS['ds_pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) {
            $stored = ts_get($pdo, $tid, 'email_design', []);
            if ($stored) $cfg = ds_sanitize_config($stored);
        }
    }
    return $GLOBALS['ds_cfg_cache'][$tid] = $cfg;
}

/** Reemplaza {variables}. Texto plano (el wrapper se encarga de escapar). */
function ds_fill_plain(string $text, array $vars): string {
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $text);
}

/** Reemplaza {variables} produciendo HTML seguro. nombre/ticket salen en negrita (si $bold). $raw = HTML ya seguro. */
function ds_fill_html(string $text, array $vars, array $raw = [], bool $bold = true): string {
    $out = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return preg_replace_callback('/(#?)\{(\w+)\}/', function ($m) use ($vars, $raw, $bold) {
        $k = $m[2];
        if (array_key_exists($k, $raw)) return $m[1] . $raw[$k];
        if (!array_key_exists($k, $vars)) return $m[0];
        $v = htmlspecialchars((string)$vars[$k], ENT_QUOTES, 'UTF-8');
        if ($bold && in_array($k, ['nombre', 'ticket'], true)) {
            return '<strong style="color:#0f172a;font-weight:700">' . $m[1] . $v . '</strong>';
        }
        return $m[1] . $v;
    }, $out);
}

function ds_text(string $tpl, string $field, array $vars, bool $html = false, array $raw = [], bool $bold = true): string {
    $cfg = ds_cfg();
    $def = ds_default_config();
    $text = $cfg['templates'][$tpl][$field] ?? '';
    if ($text === '') $text = $def['templates'][$tpl][$field] ?? '';
    if (!isset($vars['empresa'])) $vars['empresa'] = $cfg['brand']['name'];
    return $html ? ds_fill_html($text, $vars, $raw, $bold) : ds_fill_plain($text, $vars);
}

// ═══════════════════════════════════════════════════════════════
//  WRAPPER PRINCIPAL — estructura HTML completa del email
// ═══════════════════════════════════════════════════════════════
function ds_email_wrap(
    string $color,
    string $hdr_icon_name,
    string $hdr_title,
    string $body_html,
    string $cta_icon_name,
    string $cta_title,
    string $cta_text,
    string $btn_label,
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $p = ds_palette($color);
    $cfg = ds_cfg();
    $brand = $cfg['brand'];
    if (!empty($brand['color']) && preg_match('/^#[0-9a-f]{6}$/i', $brand['color'])) $p['bg'] = $brand['color'];

    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $brand_name = $e($brand['name']);
    $site_url   = $e($brand['site_url']);
    $site_label = $e(preg_replace('#^https?://#i', '', rtrim($brand['site_url'], '/')));

    $logo_cell = '';
    if (!empty($brand['logo_url'])) {
        $logo_cell = '<td style="width:28px;height:28px;background:rgba(255,255,255,0.18);border-radius:7px;text-align:center;line-height:28px;vertical-align:middle">
                    <img src="'.$e($brand['logo_url']).'" alt="'.$brand_name.'" height="20" style="display:block;margin:4px auto;border:0;max-height:20px;max-width:60px;width:auto">
                  </td>';
    }

    $footer_html = '';
    if (!empty($brand['show_footer'])) {
        $footer_html = '
  <!-- Espacio -->
  <table class="wrap" width="620" align="center" cellpadding="0" cellspacing="0">
    <tr><td style="height:10px"></td></tr>
  </table>

  <!-- Footer global -->
  <table class="wrap" width="620" align="center" cellpadding="0" cellspacing="0" role="presentation"
    style="background:#111827;border-radius:10px">
    <tr>
      <td style="padding:14px 20px">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
          <tr>
            <td style="vertical-align:middle">
              <table cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  <td style="vertical-align:middle;padding-right:5px">'.ds_icon('email','#6b7280',13).'</td>
                  <td style="vertical-align:middle;padding-right:5px">'.ds_icon('globe','#6b7280',13).'</td>
                  <td style="padding-right:16px"><a href="'.$site_url.'" style="font-size:12px;color:#9ca3af;text-decoration:none">'.$site_label.'</a></td>
                </tr>
              </table>
            </td>
            <td style="text-align:right;font-size:11px;color:#6b7280">'.$e($brand['footer_text']).'</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>';
    }

    return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>'.$e($hdr_title).' — '.$brand_name.'</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
body,table,td,p,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
body{margin:0;padding:0;background:#f1f5f9;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}
a{text-decoration:none}
@media only screen and (max-width:600px){
  .wrap{width:100%!important}
  .cta-td{display:block!important;width:100%!important;border-left:none!important;border-top:1px solid #f1f5f9!important;padding:16px 20px!important}
  .btn{width:100%!important;box-sizing:border-box!important}
}
</style>
</head>
<body>
<div style="display:none;max-height:0;overflow:hidden;font-size:1px;color:#f1f5f9">'.$brand_name.' &mdash; '.$e($hdr_title).'&#8204;&nbsp;</div>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f1f5f9">
<tr><td style="padding:28px 16px">

  <!-- Tarjeta -->
  <table class="wrap" width="620" align="center" cellpadding="0" cellspacing="0" role="presentation"
    style="background:#ffffff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden">

    <!-- ── Header de color ── -->
    <tr>
      <td colspan="2" style="background:'.$p['bg'].';padding:14px 22px">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
          <tr>
            <!-- Logo -->
            <td style="vertical-align:middle">
              <table cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  '.$logo_cell.'
                  <td style="'.($logo_cell !== '' ? 'padding-left:8px;' : '').'font-size:13px;font-weight:700;color:rgba(255,255,255,0.92);letter-spacing:0.03em">'.$brand_name.'</td>
                </tr>
              </table>
            </td>
            <!-- Título -->
            <td style="text-align:right;vertical-align:middle">
              <table cellpadding="0" cellspacing="0" role="presentation" align="right">
                <tr>
                  <td style="padding-right:8px">'.ds_icon($hdr_icon_name,'rgba(255,255,255,0.85)',19).'</td>
                  <td style="font-size:17px;font-weight:800;color:#ffffff;letter-spacing:-0.01em">'.$e($hdr_title).'</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- ── Cuerpo + CTA ── -->
    <tr>
      <!-- Contenido -->
      <td style="padding:22px 24px;vertical-align:top">
        '.$body_html.'
      </td>

      <!-- CTA -->
      <td class="cta-td" style="width:190px;min-width:190px;vertical-align:top;padding:22px 20px;border-left:1px solid #f1f5f9">
        <div style="margin-bottom:10px">'.ds_icon($cta_icon_name,'#64748b',20).'</div>
        <p style="margin:0 0 5px 0;font-size:14px;font-weight:700;color:#0f172a;line-height:1.3">'.$e($cta_title).'</p>
        <p style="margin:0 0 16px 0;font-size:13px;color:#64748b;line-height:1.55">'.$e($cta_text).'</p>
        <a href="'.$e($btn_url).'" class="btn"
          style="display:block;padding:11px 16px;border-radius:9px;background:'.$p['bg'].';color:#ffffff;font-weight:700;font-size:14px;text-align:center;text-decoration:none">
          '.$e($btn_label).'
        </a>
      </td>
    </tr>

  </table>
'.$footer_html.'
</td></tr>
</table>
</body>
</html>';
}

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE — Caso escalado a técnico (el problema persistió tras la
//  respuesta automática de la IA)
// ═══════════════════════════════════════════════════════════════
function tpl_escalated_to_tech(
    string $name,
    string $ticket_id,
    string $subject,
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $v = ['nombre' => $name, 'ticket' => $ticket_id, 'asunto' => $subject];
    $body =
        ds_subtitle(ds_text('escalated', 'intro', $v, true))
        .ds_divider()
        .'<div style="font-size:14px;color:#334155;line-height:1.7">'
        .ds_text('escalated', 'body', $v, true)
        .'</div>'
        .ds_divider()
        .ds_hint(ds_text('escalated', 'hint', $v, true, [], false));

    return ds_email_wrap(
        'purple', 'ticket',
        ds_text('escalated', 'title', $v),
        $body,
        'headset', ds_text('escalated', 'cta_title', $v),
        ds_text('escalated', 'cta_text', $v),
        ds_text('escalated', 'btn_label', $v), $btn_url
    );
}

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE — Respuesta automática de IA (canal Email)
// ═══════════════════════════════════════════════════════════════
function tpl_ai_reply(
    string $name,
    string $reply_text,
    string $classification,
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $v = ['nombre' => $name, 'categoria' => $classification];
    $body =
        ds_subtitle(ds_text('ai_reply', 'intro', $v, true))
        .ds_divider()
        .'<div style="font-size:14px;color:#334155;line-height:1.7;white-space:pre-line">'.nl2br(htmlspecialchars($reply_text, ENT_QUOTES, 'UTF-8')).'</div>'
        .ds_divider()
        .ds_hint(ds_text('ai_reply', 'hint', $v, true, [], false));

    return ds_email_wrap(
        'purple', 'chat',
        ds_text('ai_reply', 'title', $v),
        $body,
        'headset', ds_text('ai_reply', 'cta_title', $v),
        ds_text('ai_reply', 'cta_text', $v),
        ds_text('ai_reply', 'btn_label', $v), $btn_url
    );
}

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE 1 — Ticket creado
// ═══════════════════════════════════════════════════════════════
function tpl_ticket_created(
    string $name,
    string $ticket_id,
    string $title,
    string $priority,
    string $due_date,
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $v = ['nombre' => $name, 'ticket' => $ticket_id];

    $body =
        ds_subtitle(ds_text('ticket_created', 'intro', $v, true))
        .ds_divider()
        .'<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
              <td style="font-size:13px;color:#94a3b8;padding:6px 0;width:85px">Ticket</td>
              <td style="font-size:13px;color:#0f172a;font-weight:600;padding:6px 14px 6px 0">#'.htmlspecialchars($ticket_id).'</td>
              <td style="font-size:13px;color:#94a3b8;padding:6px 0;width:55px">Título</td>
              <td style="font-size:13px;color:#0f172a;font-weight:500;padding:6px 0">'.htmlspecialchars($title).'</td>
            </tr>
            <tr>
              <td style="font-size:13px;color:#94a3b8;padding:6px 0">Prioridad</td>
              <td style="padding:6px 14px 6px 0">'.ds_badge_priority($priority).'</td>
              <td style="font-size:13px;color:#94a3b8;padding:6px 0">Vencimiento</td>
              <td style="font-size:13px;color:#0f172a;font-weight:500;padding:6px 0">'.htmlspecialchars($due_date).'</td>
            </tr>
          </table>'
        .ds_divider()
        .ds_hint(ds_text('ticket_created', 'hint', $v, true, [], false));

    return ds_email_wrap(
        'teal', 'ticket',
        ds_text('ticket_created', 'title', $v),
        $body,
        'headset', ds_text('ticket_created', 'cta_title', $v),
        ds_text('ticket_created', 'cta_text', $v),
        ds_text('ticket_created', 'btn_label', $v), $btn_url
    );
}

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE 2 — Mensaje nuevo en ticket
// ═══════════════════════════════════════════════════════════════
function tpl_message_added(
    string $ticket_id,
    string $message,
    string $author = '',
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $v = ['ticket' => $ticket_id, 'autor' => $author];
    $raw = ['por_autor' => $author !== ''
        ? ' por <strong style="color:#0f172a;font-weight:700">'.htmlspecialchars($author, ENT_QUOTES, 'UTF-8').'</strong>'
        : ''];

    $body =
        ds_subtitle(ds_text('message_added', 'intro', $v, true, $raw))
        .ds_divider()
        .'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px">
            <table cellpadding="0" cellspacing="0" role="presentation">
              <tr>
                <td style="padding-right:10px;vertical-align:top;padding-top:2px">'.ds_icon('chat','#7c3aed',15).'</td>
                <td style="font-size:13px;color:#334155;line-height:1.6">'.ds_clean_message($message).'</td>
              </tr>
            </table>
          </div>'
        .ds_divider()
        .ds_hint(ds_text('message_added', 'hint', $v, true, [], false));

    return ds_email_wrap(
        'purple', 'mail',
        ds_text('message_added', 'title', $v),
        $body,
        'user', ds_text('message_added', 'cta_title', $v),
        ds_text('message_added', 'cta_text', $v),
        ds_text('message_added', 'btn_label', $v), $btn_url
    );
}

// ═══════════════════════════════════════════════════════════════
//  TEMPLATE 3 — Estado actualizado
// ═══════════════════════════════════════════════════════════════
function tpl_status_updated(
    string $ticket_id,
    string $status,
    string $priority,
    string $btn_url = 'https://deskserviced.alwaysdata.net/login.php'
): string {
    $is_closed = $status === 'closed';
    $key = $is_closed ? 'ticket_closed' : 'status_updated';
    $v = ['ticket' => $ticket_id];

    $body =
        ds_subtitle(ds_text($key, 'intro', $v, true))
        .ds_divider()
        .'<div style="padding:4px 0">'.ds_badge_status($status).'&nbsp;&nbsp;'.ds_badge_priority($priority).'</div>'
        .ds_divider()
        .ds_hint(ds_text($key, 'hint', $v, true));

    return ds_email_wrap(
        $is_closed ? 'teal' : 'blue',
        $is_closed ? 'check' : 'bell',
        ds_text($key, 'title', $v),
        $body,
        'help', ds_text($key, 'cta_title', $v),
        ds_text($key, 'cta_text', $v),
        ds_text($key, 'btn_label', $v), $btn_url
    );
}

// ═══════════════════════════════════════════════════════════════
//  VISTA PREVIA — genera cada plantilla con datos de ejemplo
// ═══════════════════════════════════════════════════════════════
function ds_render_sample(string $key): string {
    $due = date('d/m/Y H:i', time() + 2 * 86400);
    switch ($key) {
        case 'message_added':  return tpl_message_added('1024', "Hola, ya revisamos tu caso.\nNecesitamos que nos confirmes si el problema continúa.", 'Soporte TI');
        case 'status_updated': return tpl_status_updated('1024', 'open', 'medium');
        case 'ticket_closed':  return tpl_status_updated('1024', 'closed', 'medium');
        case 'escalated':      return tpl_escalated_to_tech('María Pérez', '1024', 'No puedo conectarme a la VPN');
        case 'ai_reply':       return tpl_ai_reply('María Pérez', "Para restablecer tu contraseña ingresa a la opción «Olvidé mi contraseña» y sigue los pasos.\n\nSi el problema continúa, responde a este correo.", 'Accesos');
        case 'ticket_created':
        default:               return tpl_ticket_created('María Pérez', '1024', 'No puedo acceder a mi correo', 'high', $due);
    }
}