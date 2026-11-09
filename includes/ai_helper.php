<?php


require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ai_llm.php';

if (!function_exists('create_ticket_from_email_request')) {
    /**
     * Crea un ticket a partir de una fila de email_requests. Usado tanto por
     * la aprobación manual (email_review_actions.php) como por la escalación
     * automática cuando un usuario responde y su problema persiste.
     *
     * @param array $req      Fila de email_requests (from_email, from_name, subject, body, attachments)
     * @param string $priority 'low' | 'medium' | 'high' | 'urgent' (según tu esquema de tickets)
     * @return array ['ticket_id' => int, 'local_id' => int, 'user_id' => int]
     */
    function create_ticket_from_email_request($pdo, $tenant_id, $req, $priority = 'low') {
        $pdo->beginTransaction();
        try {
            // 1. Encontrar o crear usuario
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
            $stmt->execute([$req['from_email'], $tenant_id]);
            $user = $stmt->fetch();

            if (!$user) {
                $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM users WHERE tenant_id = ?");
                $st_local->execute([$tenant_id]);
                $next_user_local_id = (int)$st_local->fetchColumn();

                $pdo->prepare("INSERT INTO users (tenant_id, local_id, name, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, 'user', 1)")
                    ->execute([$tenant_id, $next_user_local_id, $req['from_name'], $req['from_email'], password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT)]);
                $user_id = $pdo->lastInsertId();
            } else {
                $user_id = $user['id'];
            }

            // 2. Crear ticket
            $description = "Creado desde Email por: " . $req['from_name'] . " <" . $req['from_email'] . ">\n\n" . $req['body'];

            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE tenant_id = ? LIMIT 1");
            $stmtCat->execute([$tenant_id]);
            $category_id = $stmtCat->fetchColumn() ?: 1;

            $st_local = $pdo->prepare("SELECT COALESCE(MAX(local_id), 0) + 1 FROM tickets WHERE tenant_id = ?");
            $st_local->execute([$tenant_id]);
            $next_local_id = (int)$st_local->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO tickets (tenant_id, local_id, source, user_id, category_id, title, description, priority, due_date, location, status) VALUES (?, ?, 'email', ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 72 HOUR), 'Email', 'open')");
            $stmt->execute([$tenant_id, $next_local_id, $user_id, $category_id, $req['subject'], $description, $priority]);
            $ticket_id = $pdo->lastInsertId();

            // 3. Agregar solicitante real
            $pdo->prepare("INSERT IGNORE INTO ticket_solicitors (ticket_id, user_id) VALUES (?, ?)")->execute([$ticket_id, $user_id]);

            // 4. Procesar adjuntos (si existen)
            $attachments = json_decode($req['attachments'] ?? '[]', true);
            if (!empty($attachments)) {
                foreach ($attachments as $att) {
                    // Solo extensiones permitidas y nombre saneado (evita subir .php u otros ejecutables)
                    $safe = safe_upload_name((string)($att['filename'] ?: $att['name']), 'mail');
                    if (!$safe) { continue; }
                    $filename = $safe[0];
                    $path = 'uploads/' . $filename;
                    if (!is_dir('uploads/')) mkdir('uploads/', 0755, true);
                    file_put_contents($path, $att['attachment']);

                    $pdo->prepare("INSERT INTO attachments (ticket_id, user_id, file_name, file_path, mime_type) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$ticket_id, $user_id, ($att['filename'] ?: $att['name']), $path, 'application/octet-stream']);
                }
            }

            $pdo->commit();

            return ['ticket_id' => $ticket_id, 'local_id' => $next_local_id, 'user_id' => $user_id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('ai_run_python')) {
    /** Ejecuta ai_agent.py (python3 o python) y devuelve el JSON decodificado. */
    function ai_run_python(array $input): array {
        $script = realpath(__DIR__ . '/../ai_agent.py');
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $candidates = array_values(array_filter([app_env('PYTHON_BIN', ''), 'python3', 'python']));
        $lastError = 'No se encontró Python en el servidor.';

        foreach ($candidates as $bin) {
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open([$bin, $script], $spec, $pipes); // forma de array: sin shell
            if (!is_resource($process)) continue;

            fwrite($pipes[0], $json);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $rc = proc_close($process);

            if ($rc === 0 && $output !== '' && $output !== false) {
                $decoded = json_decode($output, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
                $lastError = 'Respuesta de IA no válida.';
                error_log('AI decode error: ' . substr($output, 0, 200));
            } else {
                $lastError = "python falló ($bin, código $rc)";
                error_log("AI Agent Error ($bin, code $rc): $stderr");
            }
        }
        return ['status' => 'error', 'message' => 'El asistente no está disponible en este momento.'];
    }
}

if (!function_exists('ai_tenant_name')) {
    function ai_tenant_name($pdo, $tenant_id): string {
        static $cache = [];
        $tenant_id = (int)$tenant_id;
        if (isset($cache[$tenant_id])) return $cache[$tenant_id];
        try {
            $st = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
            $st->execute([$tenant_id]);
            $cache[$tenant_id] = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$tenant_id] = '';
        }
        return $cache[$tenant_id];
    }
}

if (!function_exists('call_ai_agent')) {
    /**
     * Punto único de IA.
     *  mode 'query'    : chat (recupera en la KB del tenant + redacta natural si hay LLM)
     *  mode 'followup' : el usuario hace una pregunta sobre el artículo ya mostrado
     *  mode 'email'    : borrador de respuesta por correo
     *  mode 'confirm'  : intención del usuario (sí / no / agente / duda)
     * Claves opcionales: tenant_name, history[], article{}.
     */
    function call_ai_agent($input_data) {
        $mode = $input_data['mode'] ?? 'query';
        $tenantName = (string)($input_data['tenant_name'] ?? '');
        $history = $input_data['history'] ?? [];
        $categories = $input_data['categories'] ?? [];

        if ($mode === 'followup') {
            $art = $input_data['article'] ?? null;
            if ($art && ai_llm_enabled()) {
                $ai = ai_llm_compose([
                    'tenant_name' => $tenantName, 'channel' => 'chat', 'message' => (string)($input_data['query'] ?? ''),
                    'history' => $history, 'matches' => [$art], 'categories' => $categories,
                ]);
                if ($ai) return ['status' => 'success', 'reply' => $ai['reply'], 'wants_human' => $ai['wants_human'], 'intent' => 'followup'];
            }
            $reply = "Claro, te comparto de nuevo la guía:\n\n";
            if ($art) $reply .= '**' . ($art['title'] ?? '') . "**\n" . ($art['content'] ?? '') . "\n\n";
            $reply .= 'Si sigues con dudas, puedo crear un ticket para que un técnico te ayude.';
            return ['status' => 'success', 'reply' => $reply, 'wants_human' => false, 'intent' => 'followup'];
        }

        $engine = ai_run_python($input_data);
        // Respaldo nativo PHP: en hosting donde Python/proc_open no está disponible,
        // el chatbot sigue consultando la KB filtrada por tenant en lugar de fallar por completo.
        if (($engine['status'] ?? '') !== 'success' && $mode !== 'confirm') {
            $engine = ai_php_kb_fallback($input_data);
        }
        if (($engine['status'] ?? '') !== 'success' || $mode === 'confirm') return $engine;

        if ($mode === 'query' && ai_llm_enabled() && !in_array($engine['intent'] ?? '', ['greeting', 'thanks'], true)) {
            $ai = ai_llm_compose([
                'tenant_name' => $tenantName, 'channel' => 'chat', 'message' => (string)($input_data['query'] ?? ''),
                'history' => $history, 'matches' => $engine['matches'] ?? [], 'categories' => $categories,
            ]);
            if ($ai) {
                $engine['reply'] = $ai['reply'];
                if ($ai['answers_question']) $engine['suggested_kb_id'] = $ai['kb_id'];
                else unset($engine['suggested_kb_id']);
                if ($ai['classification'] !== 'General') { $engine['classification'] = $ai['classification']; $engine['category_id'] = $ai['category_id']; }
                if ($ai['priority'])  $engine['priority']  = $ai['priority'];
                if ($ai['sentiment']) $engine['sentiment'] = $ai['sentiment'];
                $engine['wants_human'] = $ai['wants_human'];
                $engine['llm'] = true;
            }
        }

        if ($mode === 'email' && ai_llm_enabled()) {
            $ai = ai_llm_compose([
                'tenant_name' => $tenantName, 'channel' => 'email', 'message' => (string)($input_data['body'] ?? ''),
                'subject' => (string)($input_data['subject'] ?? ''), 'from_name' => (string)($input_data['from_name'] ?? ''),
                'history' => [], 'matches' => $engine['matches'] ?? [], 'categories' => $categories,
            ]);
            if ($ai) {
                $engine['reply_body'] = $ai['reply'];
                if ($ai['answers_question']) {
                    $engine['suggested_kb_id'] = $ai['kb_id'];
                } else {
                    // El modelo dice que la KB no responde: bajamos la confianza para que lo revise un humano
                    $engine['suggested_kb_id'] = null;
                    $engine['confidence'] = min((int)($engine['confidence'] ?? 0), 30);
                }
                if ($ai['classification'] !== 'General') { $engine['classification'] = $ai['classification']; $engine['category_id'] = $ai['category_id']; }
                if ($ai['sentiment']) $engine['sentiment'] = $ai['sentiment'];
                if ($ai['priority'])  $engine['priority']  = $ai['priority'];
                $engine['llm'] = true;
            }
        }
        return $engine;
    }
}


if (!function_exists('ai_php_kb_fallback')) {
    function ai_php_kb_fallback(array $input): array {
        $q = trim((string)($input['query'] ?? ''));
        $norm = static function(string $v): string {
            $v = mb_strtolower($v, 'UTF-8');
            if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v); if ($x !== false) $v = $x; }
            $v = preg_replace('/[^a-z0-9]+/i', ' ', $v) ?? $v;
            return trim(preg_replace('/\s+/', ' ', $v) ?? $v);
        };
        $clean = $norm($q);
        if ($clean === '') return ['status'=>'success','reply'=>'Cuéntame qué está pasando y te ayudaré a encontrar una solución.','intent'=>'question','matches'=>[],'confidence'=>0,'wants_human'=>false];
        $greetings = ['hola','buenos dias','buenas tardes','buenas noches','buenas','hey','saludos'];
        if (in_array($clean, $greetings, true)) return ['status'=>'success','reply'=>'¡Hola! 👋 Cuéntame qué está pasando, qué equipo o aplicación está afectado y qué mensaje de error aparece.','intent'=>'greeting','matches'=>[],'confidence'=>0,'wants_human'=>false];
        if (strpos($clean, 'gracias') !== false) return ['status'=>'success','reply'=>'¡Con gusto! 😊 Si necesitas algo más, aquí estoy.','intent'=>'thanks','matches'=>[],'confidence'=>0,'wants_human'=>false];
        $stop = array_flip(explode(' ', 'como donde cuando cual que para por con una uno unos unas los las del de la el y o en mi me se no tengo esta este esta esto problema necesito ayuda equipo'));
        $qt = array_values(array_filter(explode(' ', $clean), static fn($w) => strlen($w) > 2 && !isset($stop[$w])));
        $ranked = [];
        foreach (($input['kb'] ?? []) as $article) {
            $title = $norm((string)($article['title'] ?? ''));
            $keywords = $norm((string)($article['keywords'] ?? ''));
            $content = $norm((string)($article['content'] ?? ''));
            $score = 0; $matched = 0;
            foreach (array_unique($qt) as $w) {
                $hit = false;
                if ($title !== '' && strpos($title, $w) !== false) { $score += 4; $hit = true; }
                if ($keywords !== '' && strpos($keywords, $w) !== false) { $score += 3; $hit = true; }
                if ($content !== '' && strpos($content, $w) !== false) { $score += 1; $hit = true; }
                if ($hit) $matched++;
            }
            if ($score > 0) {
                $confidence = (int)min(95, round(100 * $matched / max(1, count(array_unique($qt)))));
                if ($title !== '' && (strpos($clean, $title) !== false || strpos($title, $clean) !== false)) $confidence = min(100, $confidence + 15);
                $ranked[] = ['id'=>(int)($article['id'] ?? 0),'title'=>(string)($article['title'] ?? ''),'content'=>(string)($article['content'] ?? ''),'category_id'=>$article['category_id'] ?? null,'confidence'=>$confidence,'score'=>$score];
            }
        }
        usort($ranked, static fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($b['confidence'] <=> $a['confidence']));
        $matches = array_slice($ranked, 0, 3); $best = $matches[0] ?? null;
        $categoryName = 'General'; $categoryId = null;
        if ($best && !empty($best['category_id'])) foreach (($input['categories'] ?? []) as $cat) if ((int)$cat['id'] === (int)$best['category_id']) { $categoryName = (string)$cat['name']; $categoryId = (int)$cat['id']; break; }
        $priority = preg_match('/hacke|ransomware|virus|phishing|datos filtrados/', $clean) ? 'urgent' : (preg_match('/urgente|todos|no puedo trabajar|servidor caido/', $clean) ? 'high' : 'medium');
        if ($best && $best['confidence'] >= 40) {
            $reply = "¡Claro, con gusto te ayudo!\n\n**" . $best['title'] . "**\n" . $best['content'] . "\n\n¿Estos pasos resolvieron el problema? Si no, puedo ayudarte a crear un ticket para soporte.";
            return ['status'=>'success','reply'=>$reply,'intent'=>'question','matches'=>$matches,'confidence'=>$best['confidence'],'suggested_kb_id'=>$best['id'],'classification'=>$categoryName,'category_id'=>$categoryId,'priority'=>$priority,'wants_human'=>false,'fallback'=>'php'];
        }
        return ['status'=>'success','reply'=>'No encontré una guía suficientemente precisa en la base de conocimientos para asegurar una solución. Puedo ayudarte a crear un ticket para que soporte revise el caso. Cuéntame qué equipo o aplicación está afectado y qué error aparece.','intent'=>'question','matches'=>$matches,'confidence'=>$best['confidence'] ?? 0,'classification'=>$categoryName,'category_id'=>$categoryId,'priority'=>$priority,'wants_human'=>true,'fallback'=>'php'];
    }
}

if (!function_exists('fetch_kb_for_tenant')) {
    function fetch_kb_for_tenant($pdo, $tenant_id) {
        $stmt = $pdo->prepare("SELECT id, category_id, title, content, keywords FROM knowledge_base WHERE tenant_id = ? ORDER BY id DESC LIMIT 800");
        $stmt->execute([$tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('fetch_categories_for_tenant')) {
    function fetch_categories_for_tenant($pdo, $tenant_id) {
        $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_tenant_setting')) {
    function get_tenant_setting($pdo, $tenant_id, $key, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
            $stmt->execute([$tenant_id, $key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('ensure_email_ai_columns')) {
    /**
     * Autorepara la tabla email_requests agregando las columnas necesarias
     * para guardar el análisis/borrador de respuesta generado por la IA.
     * Sigue el mismo patrón "self-healing" usado en el resto del proyecto
     * (ver multicanal.php / chatbot_handler.php con la columna `source`).
     */
    function ensure_email_ai_columns($pdo) {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $columns = [
            'ai_subject'        => "ADD COLUMN ai_subject VARCHAR(255) NULL AFTER attachments",
            'ai_reply'          => "ADD COLUMN ai_reply MEDIUMTEXT NULL AFTER ai_subject",
            'ai_confidence'     => "ADD COLUMN ai_confidence INT NULL AFTER ai_reply",
            'ai_sentiment'      => "ADD COLUMN ai_sentiment VARCHAR(20) NULL AFTER ai_confidence",
            'ai_classification' => "ADD COLUMN ai_classification VARCHAR(150) NULL AFTER ai_sentiment",
            'ai_kb_id'          => "ADD COLUMN ai_kb_id INT NULL AFTER ai_classification",
            'ai_status'         => "ADD COLUMN ai_status ENUM('none','drafted','sent','escalated') DEFAULT 'none' AFTER ai_kb_id",
            'ai_sent_at'        => "ADD COLUMN ai_sent_at DATETIME NULL AFTER ai_status",
        ];

        foreach ($columns as $col => $alterSql) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM `email_requests` LIKE '$col'");
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    $pdo->exec("ALTER TABLE `email_requests` $alterSql");
                } elseif ($col === 'ai_status' && strpos($existing['Type'], "'escalated'") === false) {
                    // Ampliar el enum si la columna ya existía sin el valor 'escalated'
                    $pdo->exec("ALTER TABLE `email_requests` MODIFY ai_status ENUM('none','drafted','sent','escalated') DEFAULT 'none'");
                }
            } catch (Throwable $e) {
                error_log("ensure_email_ai_columns error ($col): " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ai_analyze_email')) {
    /**
     * Analiza el correo con la IA (clasificación, sentimiento, búsqueda en KB)
     * y redacta una propuesta de respuesta. No envía nada por sí sola.
     */
    function ai_analyze_email($pdo, $tenant_id, $from_name, $subject, $body) {
        $kb = fetch_kb_for_tenant($pdo, $tenant_id);
        $categories = fetch_categories_for_tenant($pdo, $tenant_id);

        return call_ai_agent([
            'mode'       => 'email',
            'tenant_name'=> ai_tenant_name($pdo, $tenant_id),
            'from_name'  => $from_name,
            'subject'    => $subject,
            'body'       => strip_tags($body ?? ''),
            'kb'         => $kb,
            'categories' => $categories,
        ]);
    }
}

if (!function_exists('save_ai_email_draft')) {
    /**
     * Guarda el análisis/borrador generado por la IA en email_requests.
     */
    function save_ai_email_draft($pdo, $request_id, $ai_result) {
        ensure_email_ai_columns($pdo);
        try {
            $stmt = $pdo->prepare("UPDATE email_requests SET
                ai_subject = ?, ai_reply = ?, ai_confidence = ?, ai_sentiment = ?,
                ai_classification = ?, ai_kb_id = ?, ai_status = 'drafted'
                WHERE id = ?");
            $stmt->execute([
                $ai_result['reply_subject'] ?? null,
                $ai_result['reply_body'] ?? null,
                $ai_result['confidence'] ?? 0,
                $ai_result['sentiment'] ?? null,
                $ai_result['classification'] ?? null,
                $ai_result['suggested_kb_id'] ?? null,
                $request_id,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log("save_ai_email_draft error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_ai_email_reply')) {
    /**
     * Envía por correo la respuesta (generada por IA o editada por un agente)
     * y marca la solicitud como respondida.
     */
    function send_ai_email_reply($pdo, $request_id, $to_email, $to_name, $subject, $body_text, $classification, $tenant_id) {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/email_templates.php';
        ensure_email_ai_columns($pdo);

        $html = tpl_ai_reply($to_name ?: 'usuario', $body_text, $classification ?: 'Soporte');
        $sent = send_mail($to_email, $subject, $html, $body_text, [], $tenant_id);

        if ($sent) {
            try {
                $pdo->prepare("UPDATE email_requests SET ai_subject = ?, ai_reply = ?, ai_status = 'sent', ai_sent_at = NOW() WHERE id = ?")
                    ->execute([$subject, $body_text, $request_id]);
            } catch (Throwable $e) {
                error_log("send_ai_email_reply update error: " . $e->getMessage());
            }
        }

        return $sent;
    }
}
