# -*- coding: utf-8 -*-
"""
ai_agent.py v2 — motor de recuperación y clasificación para la mesa de servicio.

Entrada (JSON por STDIN):
  mode: 'query' | 'email' | 'confirm'
  query / subject+body / kb[] / categories[] ...
Salida (JSON por STDOUT): ver generate_response / generate_email_reply.

Este motor NO inventa información: solo recupera artículos de la base de
conocimientos DEL TENANT (el PHP ya filtra por tenant antes de llamarlo) y
clasifica. La redacción natural la hace includes/ai_helper.php con un LLM
cuando hay ANTHROPIC_API_KEY; si no, se usan las plantillas de este archivo.
"""
import sys
import json
import re
import math
import unicodedata
from collections import Counter

# ----------------------------------------------------------------- texto
def strip_accents(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')


def norm(text):
    if not text:
        return ''
    text = strip_accents(str(text).lower())
    text = re.sub(r'[^a-z0-9ñ\s]', ' ', text)
    return re.sub(r'\s+', ' ', text).strip()


STOP = set(norm(w) for w in """
el la los las un una unos unas de del a al y e o u en con por para que si no mi tu su yo me te se lo le les
esta este esto estos estas como donde cuando quien cual hola buenas buenos dias tardes noches saludos hey
ayuda ayudame ayudar favor porfa problema problemas tengo tiene tienen hay pero mas muy tan cada alguna algun
algunos algunas desde hasta sobre entre ya hoy ayer aqui alli ahi ese esa eso son es ser esta estoy estan
fue era he ha han mis tus sus nos quiero necesito quisiera podria pueden puedo puede favor gracias oficina
solo tambien porque pues les nuestro nuestra equipo,
""".replace(',', ' ').split())
# 'equipo' es demasiado genérico para decidir relevancia
STOP.add('equipo')

LONG_SUFFIXES = ['amientos', 'imientos', 'aciones', 'uciones', 'amiento', 'imiento', 'adores', 'adoras',
                 'mente', 'acion', 'ucion', 'idad', 'ando', 'iendo', 'ados', 'adas', 'idos', 'idas',
                 'ante', 'ible', 'able', 'ado', 'ada', 'ido', 'ida', 'ar', 'er', 'ir']


def stem(w):
    if len(w) <= 3:
        return w
    for suf in LONG_SUFFIXES:
        if w.endswith(suf) and len(w) - len(suf) >= 3:
            w = w[:-len(suf)]
            break
    if w.endswith('es') and len(w) > 4:
        w = w[:-2]
    elif w.endswith('s') and len(w) > 3:
        w = w[:-1]
    if len(w) > 4 and w[-1] in 'aeo':
        w = w[:-1]
    return w


def tokens(text, drop_stop=True):
    out = []
    for w in norm(text).split():
        if drop_stop and (w in STOP or len(w) < 2):
            continue
        out.append(stem(w))
    return out


def clean_content(s):
    """El contenido a veces se guardó con '\\r\\n' literales; los convertimos a saltos reales."""
    if not s:
        return ''
    s = str(s).replace('\\r\\n', '\n').replace('\\n', '\n').replace('\\r', '\n').replace('\r\n', '\n').replace('\r', '\n')
    s = re.sub(r'\n{3,}', '\n\n', s).strip()
    s = re.sub(r'(?m)^\s*[-*]\s+', '• ', s)
    return s

# ----------------------------------------------------------------- conceptos (sinónimos)
# Cada concepto agrupa palabras que significan lo mismo en soporte TI.
# 'cats' son pistas para mapear el concepto al NOMBRE de una categoría del tenant.
CONCEPTS = {
    'red': {
        'terms': 'red redes internet wifi wi-fi inalambrica inalambrico conexion conectividad ethernet router modem switch lan señal navegar pagina paginas dns sin-internet desconecta desconectado',
        'cats': 'red redes conectividad internet network',
    },
    'cuenta': {
        'terms': 'contraseña clave password bloqueado bloqueada usuario cuenta restablecer restablecimiento olvide olvido login sesion autenticacion token verificacion codigo credenciales',
        'cats': 'seguridad cuentas accesos acceso usuarios permisos',
    },
    'permisos': {
        'terms': 'permiso permisos carpeta compartida compartido denegado autorizacion privilegios rol sin-acceso',
        'cats': 'permisos accesos acceso seguridad',
    },
    'impresora': {
        'terms': 'impresora impresoras imprimir imprime impresion toner tinta atasco atascada papel escaner escanear fotocopiadora cartucho cola-de-impresion',
        'cats': 'impresora impresion impresoras hardware perifericos',
    },
    'correo': {
        'terms': 'correo correos email mail outlook bandeja gmail buzon adjunto spam firma calendario rebota rebotado',
        'cats': 'correo email software comunicaciones',
    },
    'vpn': {
        'terms': 'vpn remoto remota teletrabajo casa tunel rdp anydesk escritorio-remoto',
        'cats': 'red vpn seguridad remoto',
    },
    'lentitud': {
        'terms': 'lento lenta lentitud demora tarda congela congelado cuelga colgado traba trabado rendimiento pesado',
        'cats': 'hardware software red rendimiento',
    },
    'encendido': {
        'terms': 'enciende prende apaga apagado reinicia reiniciar pantallazo arranca arranque bateria cargador carga energia',
        'cats': 'hardware equipos',
    },
    'pantalla': {
        'terms': 'pantalla monitor display proyector hdmi video resolucion',
        'cats': 'hardware perifericos',
    },
    'perifericos': {
        'terms': 'teclado mouse raton usb parlante parlantes altavoz auriculares webcam',
        'cats': 'hardware perifericos',
    },
    'reuniones': {
        'terms': 'teams zoom meet videollamada reunion camara microfono audio',
        'cats': 'software comunicaciones',
    },
    'software': {
        'terms': 'programa programas aplicacion software app instalar instalacion actualizar actualizacion licencia excel word office windows navegador chrome',
        'cats': 'software aplicaciones',
    },
    'hardware': {
        'terms': 'computador computadora portatil laptop pc disco ram memoria ventilador sobrecalentamiento caliente',
        'cats': 'hardware equipos',
    },
    'malware': {
        'terms': 'virus malware phishing sospechoso hackeo hackeado ransomware antivirus fraude suplantacion estafa',
        'cats': 'seguridad',
    },
    'telefonia': {
        'terms': 'telefono telefonos voip extension llamada llamadas diadema timbre tono central',
        'cats': 'telefonia telefono ip voip',
    },
    'crm': {
        'terms': 'crm erp sistema factura facturacion reporte modulo cliente clientes',
        'cats': 'crm erp sistemas aplicaciones',
    },
    'mantenimiento': {
        'terms': 'mantenimiento limpieza preventivo revision',
        'cats': 'mantenimiento',
    },
    'solicitud': {
        'terms': 'solicitud solicito requiero nuevo nueva alta baja creacion asignar asignacion entrega',
        'cats': 'solicitud solicitudes asignaciones requerimientos general otro otros',
    },
    'respaldo': {
        'terms': 'backup respaldo copia recuperar eliminado borrado papelera restaurar',
        'cats': 'software seguridad respaldo',
    },
    'servidor': {
        'terms': 'servidor server caido caida nube',
        'cats': 'red hardware servidores',
    },
}

TERM2CONCEPT = {}
CONCEPT_CATS = {}
for _name, _c in CONCEPTS.items():
    for _t in _c['terms'].split():
        for _piece in norm(_t.replace('-', ' ')).split():
            if _piece in STOP:
                continue
            TERM2CONCEPT.setdefault(stem(_piece), set()).add(_name)
    CONCEPT_CATS[_name] = set(stem(w) for w in norm(_c['cats']).split())


def concept_tokens(stems):
    out = set()
    for s in stems:
        for c in TERM2CONCEPT.get(s, ()):
            out.add('c:' + c)
    return out

# ----------------------------------------------------------------- sentimiento / prioridad / intención
def has_phrase(text_norm, phrase):
    return (' ' + norm(phrase) + ' ') in (' ' + text_norm + ' ')


NEG_STRONG = ['urgente', 'inaceptable', 'molesto', 'harto', 'cansado', 'pesimo', 'terrible', 'horrible',
              'desesperado', 'otra vez', 'nuevamente', 'de nuevo', 'nadie', 'todo el dia', 'desde hace dias',
              'parado', 'paralizado', 'no puedo trabajar', 'imposible', 'critico']
NEG = ['error', 'falla', 'fallo', 'lento', 'no funciona', 'no sirve', 'no carga', 'no abre', 'no puedo',
       'no enciende', 'caido', 'bloqueado', 'perdi', 'roto', 'dañado', 'danado', 'no responde', 'se congela']
POS = ['gracias', 'genial', 'excelente', 'perfecto', 'funciono', 'resuelto', 'solucionado', 'ya quedo',
       'muy amable', 'buen servicio']

HIGH_PRIORITY = ['urgente', 'todos', 'toda la oficina', 'no puedo trabajar', 'paralizado', 'servidor',
                 'sistema caido', 'cliente esperando', 'nadie puede', 'produccion', 'parado']
URGENT_PRIORITY = ['hackeado', 'hackeo', 'ransomware', 'virus', 'datos filtrados', 'fraude', 'robo', 'phishing']


def analyze_sentiment(text):
    t = norm(text)
    strong = sum(1 for p in NEG_STRONG if has_phrase(t, p))
    neg = sum(1 for p in NEG if has_phrase(t, p))
    pos = sum(1 for p in POS if has_phrase(t, p))
    score = strong * 2 + neg - pos * 2
    if score >= 2:
        return 'negativo'
    if pos > 0 and neg == 0 and strong == 0:
        return 'positivo'
    return 'neutral'


def suggest_priority(text):
    t = norm(text)
    if any(has_phrase(t, p) for p in URGENT_PRIORITY):
        return 'urgent'
    if any(has_phrase(t, p) for p in HIGH_PRIORITY):
        return 'high'
    return 'medium'


GREETING_WORDS = set(norm('hola buenos buenas dias tardes noches saludos hey que tal como estas buen dia ola holi').split())


def is_greeting(text):
    ws = norm(text).split()
    return bool(ws) and len(ws) <= 6 and all(w in GREETING_WORDS for w in ws)


def is_thanks(text):
    ws = norm(text).split()
    return bool(ws) and len(ws) <= 5 and 'gracias' in ws


HANDOFF = ['hablar con', 'agente', 'tecnico', 'persona', 'humano', 'asesor', 'operador', 'crear un ticket',
           'crea un ticket', 'abre un ticket', 'abrir un ticket', 'crear ticket', 'generar ticket', 'escalar', 'ticket']
YES_STRONG = ['ya funciona', 'ya funciono', 'ya quedo', 'ya no', 'resuelto', 'solucionado', 'se soluciono',
              'se arreglo', 'todo bien', 'ya sirve', 'ya pude', 'ya puedo']
NO_PHRASES = ['no', 'sigue', 'igual', 'persiste', 'todavia', 'aun', 'no funciono', 'no sirvio', 'no resolvio',
              'tampoco', 'nada', 'sigo', 'no ayudo', 'no sirve', 'no funciona', 'sigue igual']
YES_PHRASES = ['si', 'claro', 'perfecto', 'funciono', 'listo', 'gracias', 'excelente', 'ok', 'genial', 'efectivamente',
               'correcto', 'sirvio', 'ayudo', 'resolvio']
CLARIFY_STARTS = ['como', 'donde', 'cual', 'que significa', 'por que', 'a que', 'en que', 'cuanto']
CLARIFY_PHRASES = ['no entiendo', 'no encuentro', 'no se como', 'no me sale', 'no aparece', 'explica', 'explicame',
                   'mas detalle', 'paso']


def analyze_intent(text):
    t = norm(text)
    if not t:
        return 'unclear'
    if any(has_phrase(t, p) for p in HANDOFF):
        return 'handoff'
    if any(has_phrase(t, p) for p in YES_STRONG):
        return 'yes'
    if '?' in str(text) or any(t.startswith(p + ' ') or t == p for p in CLARIFY_STARTS) or any(has_phrase(t, p) for p in CLARIFY_PHRASES):
        return 'clarify'
    words = t.split()
    if any(has_phrase(t, p) for p in NO_PHRASES if p != 'no') or (words and words[0] == 'no'):
        return 'no'
    if any(has_phrase(t, p) for p in YES_PHRASES):
        return 'yes'
    return 'unclear'

# ----------------------------------------------------------------- clasificación
def classify_ticket(text, categories, best_kb=None):
    """Devuelve (nombre, id). Prioriza la categoría del artículo de KB que coincide."""
    cats = [c for c in categories if c.get('name')]
    if not cats:
        return 'General', None
    by_id = {str(c.get('id')): c for c in cats}

    if best_kb and best_kb.get('confidence', 0) >= 40 and best_kb.get('category_id') is not None:
        c = by_id.get(str(best_kb['category_id']))
        if c:
            return c['name'], c.get('id')

    q_stems = set(tokens(text))
    q_concepts = [c[2:] for c in concept_tokens(q_stems)]
    scores = Counter()
    for c in cats:
        name_stems = set(tokens(c['name'], drop_stop=False))
        score = 0.0
        score += 3.0 * len(name_stems & q_stems)
        for concept in q_concepts:
            hints = CONCEPT_CATS.get(concept, set())
            if name_stems & hints:
                score += 2.0
            # el nombre de la categoría es en sí un término del concepto (ej. 'Impresora')
            if any(concept in TERM2CONCEPT.get(s, ()) for s in name_stems):
                score += 2.0
        if score > 0:
            scores[c['name']] = score
    if scores:
        best = scores.most_common(1)[0][0]
        for c in cats:
            if c['name'] == best:
                return best, c.get('id')
    return 'General', None

# ----------------------------------------------------------------- recuperación (BM25)
FIELD_WEIGHTS = {'title': 3.0, 'keywords': 3.0, 'content': 1.0}
K1, B = 1.2, 0.75


def build_index(kb):
    docs = []
    for e in kb:
        tf = Counter()
        for field, w in FIELD_WEIGHTS.items():
            text = clean_content(e.get(field, '')) if field == 'content' else str(e.get(field, '') or '')
            toks = tokens(text)
            for t in toks:
                tf[t] += w
            for ct in concept_tokens(set(toks)):
                tf[ct] += w * 0.7
        docs.append({'entry': e, 'tf': tf, 'len': sum(tf.values()) or 1.0})
    avg = (sum(d['len'] for d in docs) / len(docs)) if docs else 1.0
    df = Counter()
    for d in docs:
        for t in d['tf']:
            df[t] += 1
    return docs, df, avg


def search_kb(query, kb, top_k=3):
    if not kb:
        return []
    docs, df, avg = build_index(kb)
    N = len(docs)
    q_stems = tokens(query)
    q_set = set(q_stems)
    q_tokens = list(q_set | concept_tokens(q_set))
    if not q_tokens:
        return []

    def idf(t):
        n = df.get(t, 0)
        return math.log(1 + (N - n + 0.5) / (n + 0.5))

    vocab = set(df.keys())
    recognized = [t for t in q_tokens if t in vocab]
    if not recognized:
        return []
    rec_idf_total = sum(idf(t) for t in recognized) or 1.0
    q_clean = norm(query)

    results = []
    for d in docs:
        bm25 = 0.0
        matched = []
        for t in q_tokens:
            f = d['tf'].get(t, 0)
            if f <= 0:
                continue
            matched.append(t)
            weight = 0.7 if t.startswith('c:') else 1.0
            bm25 += weight * idf(t) * (f * (K1 + 1)) / (f + K1 * (1 - B + B * d['len'] / avg))
        if not matched:
            continue
        coverage = sum(idf(t) for t in matched) / rec_idf_total
        recog_ratio = len(recognized) / len(q_tokens)
        confidence = 100.0 * min(1.0, coverage) * (0.6 + 0.4 * recog_ratio)
        exact = [t for t in matched if not t.startswith('c:')]
        if not exact:
            confidence *= 0.85
        title_clean = norm(d['entry'].get('title', ''))
        if title_clean and (title_clean in q_clean or (len(q_clean) > 6 and q_clean in title_clean)):
            confidence = min(100.0, confidence + 15)
        if len(q_set) < 2:
            confidence *= 0.8
        results.append({
            'id': d['entry'].get('id'),
            'title': str(d['entry'].get('title', '')).strip(),
            'content': clean_content(d['entry'].get('content', '')),
            'category_id': d['entry'].get('category_id'),
            'score': round(bm25, 3),
            'confidence': int(round(min(100.0, confidence))),
        })
    results.sort(key=lambda r: (r['confidence'], r['score']), reverse=True)
    return results[:top_k]

# ----------------------------------------------------------------- respuestas (plantillas de respaldo)
MIN_CHAT_CONFIDENCE = 40
MIN_EMAIL_CONFIDENCE = 35


def looks_english(content):
    c = ' ' + content.lower() + ' '
    return sum(1 for w in [' the ', ' and ', ' with ', ' for ', ' you ', ' your '] if w in c) >= 2


def _intro(sentiment):
    if sentiment == 'negativo':
        return 'Lamento el inconveniente, vamos a revisarlo juntos.'
    if sentiment == 'positivo':
        return '¡Qué bueno leerte!'
    return '¡Claro, con gusto te ayudo!'


def generate_response(query, kb, categories):
    sentiment = analyze_sentiment(query)
    priority = suggest_priority(query)

    if is_greeting(query):
        return {'reply': '¡Hola! 👋 Cuéntame qué está pasando y lo revisamos. Mientras más detalle me des (qué equipo, qué mensaje aparece, desde cuándo), mejor podré ayudarte.',
                'sentiment': sentiment, 'classification': 'General', 'category_id': None,
                'priority': 'medium', 'confidence': 0, 'intent': 'greeting', 'matches': []}
    if is_thanks(query):
        return {'reply': '¡Con gusto! Si necesitas algo más, aquí estoy. 😊',
                'sentiment': 'positivo', 'classification': 'General', 'category_id': None,
                'priority': 'low', 'confidence': 0, 'intent': 'thanks', 'matches': []}

    matches = search_kb(query, kb, top_k=3)
    best = matches[0] if matches else None
    cat_name, cat_id = classify_ticket(query, categories, best)

    result = {
        'sentiment': sentiment, 'classification': cat_name, 'category_id': cat_id,
        'priority': priority, 'confidence': best['confidence'] if best else 0,
        'intent': 'question', 'matches': matches,
    }

    if best and best['confidence'] >= MIN_CHAT_CONFIDENCE:
        reply = _intro(sentiment) + '\n\n'
        if cat_name != 'General':
            reply += 'Por lo que describes, parece un tema de **' + cat_name + '**.\n\n'
        if best['confidence'] < 60:
            reply += 'Encontré esto que podría ayudarte (no estoy completamente seguro de que sea tu caso):\n\n'
        else:
            reply += 'Esto es lo que suele resolverlo:\n\n'
        reply += '**' + best['title'] + '**\n' + best['content'] + '\n\n'
        if looks_english(best['content']):
            reply += '_(Nota: este artículo está en inglés.)_\n\n'
        reply += 'Pruébalo y cuéntame: ¿se solucionó? Si no, puedo crear un ticket para que un técnico te ayude.'
        result['reply'] = reply
        result['suggested_kb_id'] = best['id']
    else:
        reply = _intro(sentiment) + '\n\n'
        if cat_name != 'General':
            reply += 'Parece un tema de **' + cat_name + '**, '
            reply += 'pero todavía no tengo una guía exacta para tu caso. '
        else:
            reply += 'Todavía no tengo una guía exacta para tu caso. '
        reply += 'Puedo abrir un ticket para que un técnico lo revise personalmente. ¿Quieres que lo haga?'
        result['reply'] = reply
    return result


def generate_email_reply(from_name, subject, body, kb, categories):
    subject = subject or ''
    body = body or ''
    combined = (subject + ' ' + body).strip()
    sentiment = analyze_sentiment(combined)
    priority = suggest_priority(combined)

    matches = search_kb(combined, kb, top_k=3)
    best = matches[0] if matches else None
    cat_name, cat_id = classify_ticket(combined, categories, best)
    confidence = best['confidence'] if best else 0

    name = (from_name or '').strip()
    saludo = 'Hola ' + name + ',' if name else 'Hola,'
    if sentiment == 'negativo':
        saludo += ' lamentamos los inconvenientes que nos comentas.'
    intro = 'Gracias por escribirnos. Revisamos tu mensaje y lo clasificamos en la categoría de ' + cat_name + '.'

    if best and confidence >= MIN_EMAIL_CONFIDENCE:
        reply_body = (saludo + '\n\n' + intro + '\n\n'
                      'Encontramos una guía relacionada con tu consulta: "' + best['title'] + '"\n\n' +
                      best['content'] + '\n\n'
                      'Si esto resuelve tu caso, no necesitas hacer nada más. Si el problema continúa, '
                      'responde a este mismo correo y lo escalaremos con un técnico de nuestro equipo.')
        suggested = best['id']
    else:
        reply_body = (saludo + '\n\n' + intro + '\n\n'
                      'Registramos tu solicitud y un técnico la revisará para darte una respuesta personalizada en breve.')
        suggested = None

    s = subject.strip()
    reply_subject = ('Re: ' + s) if s and not s.lower().startswith('re:') else (s or 'Respuesta a tu solicitud de soporte')

    return {
        'reply_subject': reply_subject, 'reply_body': reply_body, 'sentiment': sentiment,
        'classification': cat_name, 'category_id': cat_id, 'priority': priority,
        'confidence': confidence, 'suggested_kb_id': suggested, 'matches': matches,
    }


# ----------------------------------------------------------------- main
if __name__ == '__main__':
    try:
        sys.stdin.reconfigure(encoding='utf-8')
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass
    try:
        raw = sys.stdin.read()
        if not raw:
            print(json.dumps({'status': 'error', 'message': 'No input data received'}))
            sys.exit(0)
        data = json.loads(raw)
        mode = data.get('mode', 'query')
        kb = data.get('kb', []) or []
        categories = data.get('categories', []) or []

        if mode == 'confirm':
            print(json.dumps({'status': 'success', 'intent': analyze_intent(data.get('query', '')),
                              'confirmation': {'yes': 'yes', 'no': 'no'}.get(analyze_intent(data.get('query', '')), 'unclear')},
                             ensure_ascii=False))
        elif mode == 'email':
            r = generate_email_reply(data.get('from_name', ''), data.get('subject', ''), data.get('body', ''), kb, categories)
            print(json.dumps({'status': 'success', **r}, ensure_ascii=False))
        else:
            r = generate_response(data.get('query', ''), kb, categories)
            print(json.dumps({'status': 'success', **r}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({'status': 'error', 'message': str(e)}, ensure_ascii=False))
