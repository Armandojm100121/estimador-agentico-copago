<?php
// chat.php  -  PASO 3: agente conversacional AGÉNTICO con OpenAI (function calling).
// Flujo: el paciente escribe un síntoma -> el modelo deduce la especialidad y,
// cuando conoce el plan, DECIDE llamar a la herramienta buscar_copago().
// La PLATA siempre sale del SQL; el modelo nunca inventa montos.
//
// Probar en el navegador (la sesión recuerda el contexto entre llamadas):
//   http://localhost/proyectoaiworks/chat.php?mensaje=me duele el pecho
//   http://localhost/proyectoaiworks/chat.php?mensaje=tengo el Plan Total de Salud S.A.
//   http://localhost/proyectoaiworks/chat.php?reset=1   (reinicia la conversación)

session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/guardrails.php';
require __DIR__ . '/triaje.php';

// Acumula qué guardrails se activaron en esta petición (se devuelve al front).
$guardrailsActivos = [];

if (isset($_GET['reset'])) {
    // Solo reinicia la conversación; conserva el login y el plan del usuario.
    unset($_SESSION['messages']);
    echo json_encode(['ok' => true, 'msg' => 'Conversación reiniciada']);
    exit;
}

// Seleccionar plan desde la pantalla de bienvenida. Reinicia la conversación
// y deja el plan persistido en la sesión PHP para todas las llamadas siguientes.
if (isset($_GET['set_plan'])) {
    try {
        $stmt = getDB()->prepare("SELECT id, aseguradora, nombre FROM planes WHERE id = ?");
        $stmt->execute([(int) $_GET['set_plan']]);
        $plan = $stmt->fetch();
        if (!$plan) {
            throw new RuntimeException('Plan no encontrado.');
        }
        $_SESSION = [];   // empezar limpio con el nuevo plan
        $_SESSION['plan_id']       = (int) $plan['id'];
        $_SESSION['plan_etiqueta'] = $plan['aseguradora'] . ' - ' . $plan['nombre'];
        echo json_encode([
            'ok'   => true,
            'plan' => [
                'id'       => $_SESSION['plan_id'],
                'etiqueta' => $_SESSION['plan_etiqueta'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        error_log('[chat.php set_plan] ' . $e->getMessage());
        http_response_code(($e instanceof RuntimeException) ? 400 : 503);
        $msg = ($e instanceof RuntimeException) ? $e->getMessage()
             : 'No se pudo seleccionar el plan. Intenta de nuevo.';
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}

// Seleccionar la ciudad de atención. Afecta qué hospitales se comparan.
// Reinicia la conversación para que el copago se recalcule con la nueva ciudad.
if (isset($_GET['set_ciudad'])) {
    try {
        $ciudad  = trim((string) $_GET['set_ciudad']);
        $validas = getDB()->query("SELECT DISTINCT ciudad FROM hospitales")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($ciudad, $validas, true)) {
            throw new RuntimeException('Ciudad no válida.');
        }
        $_SESSION['ciudad'] = $ciudad;
        unset($_SESSION['messages']);
        echo json_encode(['ok' => true, 'ciudad' => $ciudad], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        error_log('[chat.php set_ciudad] ' . $e->getMessage());
        http_response_code(($e instanceof RuntimeException) ? 400 : 503);
        $msg = ($e instanceof RuntimeException) ? $e->getMessage()
             : 'No se pudo cambiar la ciudad. Intenta de nuevo.';
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}

try {
    $db = getDB();

    // #16 · Rate limiting del endpoint de IA: protege contra abuso y controla el
    // costo de Groq. Ventana deslizante por sesión: máx. RL_MAX peticiones cada
    // RL_VENTANA segundos. Si se supera, respondemos 429 con mensaje amigable.
    $RL_MAX     = 15;   // peticiones permitidas...
    $RL_VENTANA = 60;   // ...por cada 60 segundos
    $ahora = time();
    $hits  = $_SESSION['chat_hits'] ?? [];
    // Conserva solo las marcas de tiempo dentro de la ventana actual.
    $hits  = array_values(array_filter($hits, fn($t) => $t > $ahora - $RL_VENTANA));
    if (count($hits) >= $RL_MAX) {
        $espera = $RL_VENTANA - ($ahora - $hits[0]);
        http_response_code(429);
        echo json_encode([
            'ok'    => false,
            'error' => "Estás enviando muchas consultas muy rápido. Espera unos $espera segundos e intenta de nuevo.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hits[] = $ahora;
    $_SESSION['chat_hits'] = $hits;

    // 1) Mensaje del paciente (POST JSON desde el front, o ?mensaje= para probar)
    $body = json_decode(file_get_contents('php://input'), true);
    $mensaje = $body['mensaje'] ?? ($_GET['mensaje'] ?? null);
    // G1 · Guardrail de entrada: rechaza vacío y recorta mensajes gigantes.
    $mensaje = gr_validar_mensaje($mensaje);

    // 2) Catálogos desde la BD (no hardcode: si agregas filas, el agente se entera)
    $especialidades = $db->query("SELECT nombre FROM especialidades ORDER BY nombre")
                         ->fetchAll(PDO::FETCH_COLUMN);
    $planes = $db->query("SELECT id, aseguradora, nombre FROM planes ORDER BY id")
                 ->fetchAll();
    $planesEtiquetas = array_map(fn($p) => $p['aseguradora'] . ' - ' . $p['nombre'], $planes);
    $ciudades = $db->query("SELECT DISTINCT ciudad FROM hospitales ORDER BY ciudad")
                   ->fetchAll(PDO::FETCH_COLUMN);

    // 3) Las herramientas del agente (function calling). Esto es lo "agéntico":
    //    el modelo DECIDE cuál llamar según lo que pide el paciente.
    //    - buscar_copago       : estima el copago (plan y ciudad ya conocidos por la sesión).
    //    - buscar_por_ciudad   : estima en OTRA ciudad sin cambiar la seleccionada.
    //    - comparar_planes     : compara TODOS los planes -> cuál conviene contratar.
    //    - explicar_cobertura  : explica de dónde sale el copago (transparencia).
    //    El plan y la ciudad por defecto NO se piden como argumento: los conocemos
    //    por la sesión y los inyectamos nosotros (evita fallos de function calling).
    $tools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'buscar_copago',
                'description' => 'Calcula el copago del paciente y devuelve los hospitales de la red ordenados del más económico al más caro, según la especialidad médica. El plan de seguro y la ciudad ya son conocidos por el sistema.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'especialidad' => [
                            'type' => 'string',
                            'enum' => $especialidades,
                            'description' => 'Especialidad médica adecuada para el síntoma.',
                        ],
                        'nivel_urgencia' => [
                            'type' => 'string',
                            'enum' => ['verde', 'amarillo', 'rojo'],
                            'description' => 'Triaje del síntoma (opcional): "rojo" si es una posible '
                                . 'emergencia (dolor de pecho, dificultad para respirar, desmayo, sangrado '
                                . 'abundante, señales de infarto/derrame); "amarillo" si necesita atención '
                                . 'pronta (fiebre alta, fractura, dolor intenso); "verde" si es de rutina.',
                        ],
                    ],
                    // Solo 'especialidad' es obligatorio: mantiene estable la llamada FORZADA
                    // (Groq/Llama falla si se le exigen varios campos a la vez). El nivel_urgencia
                    // es best-effort; si el modelo no lo manda, la red de seguridad del triaje decide.
                    'required' => ['especialidad'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'buscar_por_ciudad',
                'description' => 'Estima el copago en una CIUDAD específica (por ejemplo si el paciente pregunta "¿y en Quito cuánto sería?"), sin cambiar la ciudad seleccionada por el paciente. Usa el plan de seguro que ya conoce el sistema.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'especialidad' => [
                            'type' => 'string',
                            'enum' => $especialidades,
                            'description' => 'Especialidad médica a estimar.',
                        ],
                        'ciudad' => [
                            'type' => 'string',
                            'enum' => $ciudades,
                            'description' => 'Ciudad donde el paciente quiere atenderse.',
                        ],
                    ],
                    'required' => ['especialidad', 'ciudad'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'comparar_planes',
                'description' => 'Compara TODOS los planes de seguro disponibles para una especialidad y ciudad, y los ordena del copago más bajo al más alto. Úsala cuando el paciente pregunta qué plan le conviene contratar o cuál es más económico. NO usa el plan actual: compara todos.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'especialidad' => [
                            'type' => 'string',
                            'enum' => $especialidades,
                            'description' => 'Especialidad médica sobre la que comparar los planes.',
                        ],
                        'ciudad' => [
                            'type' => 'string',
                            'enum' => $ciudades,
                            'description' => 'Ciudad de atención para la comparación (opcional; por defecto la del paciente).',
                        ],
                    ],
                    'required' => ['especialidad'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'explicar_cobertura',
                'description' => 'Explica en detalle cómo funciona la cobertura del plan actual del paciente: el porcentaje que cubre el seguro, el deducible y de dónde sale el copago. Úsala cuando el paciente pregunta por qué paga ese valor, cómo se calcula o qué cubre su plan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'especialidad' => [
                            'type' => 'string',
                            'enum' => $especialidades,
                            'description' => 'Especialidad para dar un ejemplo concreto del cálculo (opcional).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
        [
            // Guardrail de ALCANCE: el agente declara que el mensaje NO es de salud
            // en vez de inventar una especialidad ante sinsentidos o temas ajenos.
            'type' => 'function',
            'function' => [
                'name' => 'fuera_de_tema',
                'description' => 'Úsala cuando el mensaje del paciente NO trata de una molestia, dolor o síntoma de salud: por ejemplo insultos, texto sin sentido, bromas o temas ajenos a la salud. En esos casos NO inventes una especialidad: llama a esta herramienta.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'motivo' => [
                            'type' => 'string',
                            'description' => 'Motivo breve por el que el mensaje no es una consulta de salud (opcional).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ],
    ];

    // 4) Validar que el paciente eligió su plan en la pantalla de bienvenida
    if (empty($_SESSION['plan_etiqueta'])) {
        throw new RuntimeException('Debes seleccionar tu plan antes de iniciar la conversación.');
    }

    // 5) Historial en sesión (primera vez: instrucciones del sistema con el plan YA conocido)
    if (empty($_SESSION['messages'])) {
        $listaEsp   = implode(', ', $especialidades);
        $planActual = $_SESSION['plan_etiqueta'];
        $_SESSION['messages'] = [[
            'role' => 'system',
            'content' =>
                "Eres Clara, la asistente de salud de SaludClara. Hablas con calidez, en español, " .
                "y MUY breve: máximo 2 frases por respuesta. Nunca uses listas ni suenes a formulario.\n" .
                "Ayudas al paciente, ANTES de atenderse, a saber qué especialidad necesita, cuánto " .
                "pagará de copago y qué hospital de su red le conviene más.\n" .
                "EL PLAN YA ESTÁ IDENTIFICADO: \"$planActual\". NUNCA preguntes por el plan.\n" .
                "Especialidades disponibles: $listaEsp.\n" .
                "ALCANCE (MUY IMPORTANTE): solo ayudas con molestias, dolores o síntomas de " .
                "salud. Si el mensaje del paciente NO trata de salud (insultos, texto sin " .
                "sentido, bromas o temas ajenos a la salud), NO preguntes ni inventes una " .
                "especialidad: en un turno con herramientas llama a la herramienta " .
                "fuera_de_tema; si no tienes herramientas disponibles, responde en UNA frase " .
                "amable que solo puedes ayudar con síntomas de salud e invítalo a contarte " .
                "qué siente.\n" .
                "FLUJO OBLIGATORIO (síguelo al pie de la letra):\n" .
                "1) PRIMER mensaje del paciente con un síntoma: NO llames la herramienta NUNCA en este " .
                "turno, aunque el síntoma te parezca clarísimo. Responde con una frase corta de empatía " .
                "y UNA sola pregunta de seguimiento, la más útil (desde cuándo, qué tan intenso, o si " .
                "hubo un golpe). Siempre exactamente una pregunta, nunca cero, nunca dos.\n" .
                "2) SEGUNDO mensaje (el paciente ya respondió tu pregunta): NO hagas más preguntas. " .
                "Deduce la especialidad y llama INMEDIATAMENTE a la herramienta buscar_copago con esa " .
                "especialidad (el plan y la ciudad ya los conoce el sistema). Aunque la info sea " .
                "incompleta, igual llámala. Excepción: si ese mensaje NO es un síntoma de salud, " .
                "llama a fuera_de_tema en lugar de buscar_copago.\n" .
                "3) Al presentar el resultado: UNA frase corta indicando la especialidad sugerida. " .
                "NO repitas montos ni hospitales en el texto (se muestran en una tarjeta aparte). " .
                "Si el resultado de la herramienta trae requiere_autorizacion=true, agrega una segunda " .
                "frase MUY breve avisando que esta atención necesita autorización previa del seguro; " .
                "si es false, no menciones el tema.\n" .
                "4) Para los montos SIEMPRE usa la herramienta buscar_copago; nunca inventes precios.\n" .
                "5) Urgencias y TRIAJE: al llamar buscar_copago, clasifica SIEMPRE el nivel_urgencia " .
                "del síntoma en 'verde' (rutina), 'amarillo' (atención pronta) o 'rojo' (posible " .
                "emergencia: dolor de pecho, dificultad para respirar, desmayo, sangrado abundante, " .
                "señales de infarto o derrame). Si es 'rojo', además dilo en una frase y recomienda " .
                "acudir a emergencias, pero IGUAL llama la herramienta para mostrar el copago.\n" .
                "6) OTRAS HERRAMIENTAS (úsalas cuando el paciente lo pida, después de la estimación):\n" .
                "   • Si pregunta qué plan le conviene contratar o cuál es más barato -> llama comparar_planes.\n" .
                "   • Si pregunta por qué paga ese valor, cómo se calcula o qué cubre su plan -> llama explicar_cobertura.\n" .
                "   • Si pregunta cuánto sería en OTRA ciudad -> llama buscar_por_ciudad con esa ciudad.\n" .
                "   Tras cualquiera de estas, responde con UNA frase breve; los detalles se muestran en una tarjeta aparte. " .
                "Nunca inventes montos: úsalos solo desde las herramientas.",
        ]];
    }
    $_SESSION['messages'][] = ['role' => 'user', 'content' => $mensaje];

    // 5) Control del flujo según el turno (cuántos mensajes ha enviado el paciente):
    //    Turno 1  -> SIN herramientas: el modelo se ve obligado a hacer UNA pregunta.
    //    Turno 2  -> herramienta FORZADA: muestra el copago de inmediato.
    //    Turno 3+ -> herramienta automática: conversación libre.
    $userTurns = 0;
    foreach ($_SESSION['messages'] as $m) {
        if (($m['role'] ?? '') === 'user') {
            $userTurns++;
        }
    }
    // Guardar el primer síntoma (motivo de consulta) para el historial.
    if ($userTurns === 1) {
        $_SESSION['sintoma_inicial'] = $mensaje;
    }
    if ($userTurns <= 1) {
        $toolsArg  = [];
        $choiceArg = 'auto';
    } elseif ($userTurns === 2) {
        // El modelo DEBE elegir una herramienta, pero solo entre estimar (buscar_copago)
        // o declarar que el mensaje no es de salud (fuera_de_tema). Así no inventa una
        // especialidad ante mensajes sin sentido o ajenos a la salud.
        $toolsArg  = array_values(array_filter($tools, fn($t) =>
            in_array($t['function']['name'], ['buscar_copago', 'fuera_de_tema'], true)));
        $choiceArg = 'required';
    } else {
        $toolsArg  = $tools;
        $choiceArg = 'auto';
    }

    // 6) Primera llamada al modelo, con reintentos escalonados ante el error 400 de
    //    function calling de Groq ("Failed to call a function"):
    //      1º intento: como corresponda al turno (forzado o auto).
    //      2º intento: en modo 'auto' (por si falló la llamada FORZADA).
    //      3º intento: SIN herramientas, para que Clara responda al menos en texto
    //                  y el paciente nunca vea un error crudo.
    try {
        $resp = openaiChat($_SESSION['messages'], $toolsArg, $choiceArg);
    } catch (GroqRateLimitException $e) {
        // Límite por minuto: reintentar gastaría MÁS tokens y empeoraría. Propaga
        // directo para avisar al paciente que espere unos segundos.
        throw $e;
    } catch (Throwable $e) {
        try {
            if ($choiceArg !== 'auto') {
                $resp = openaiChat($_SESSION['messages'], $toolsArg, 'auto');
            } else {
                throw $e;
            }
        } catch (GroqRateLimitException $e2) {
            throw $e2;
        } catch (Throwable $e2) {
            // Último recurso: sin herramientas. El modelo no podrá calcular el copago
            // en este turno, pero mantiene la conversación viva sin romperse.
            $resp = openaiChat($_SESSION['messages'], [], 'auto');
        }
    }
    $choice = $resp['choices'][0]['message'];
    $_SESSION['messages'][] = $choice;

    $datos = null;
    $fueraDeTema = false;   // se activa si el paciente escribió algo que no es de salud

    // 7) ¿El modelo usó alguna herramienta? El agente decide CUÁL según lo que pidió
    //    el paciente. Aquí despachamos cada llamada al manejador correspondiente.
    // Texto que alimenta la red de seguridad del triaje (#6). IMPORTANTE: solo los
    // ÚLTIMOS 2 mensajes del paciente (la consulta actual), NO todo el historial.
    // Así una molestia vieja (p. ej. "dolor en el pecho" preguntado antes) no
    // contamina ni escala por error una consulta nueva y distinta (p. ej. fiebre).
    $userMsgs = [];
    foreach ($_SESSION['messages'] as $m) {
        if (($m['role'] ?? '') === 'user') { $userMsgs[] = (string) ($m['content'] ?? ''); }
    }
    $sintomasTexto = implode(' ', array_slice($userMsgs, -2));

    if (!empty($choice['tool_calls'])) {
        foreach ($choice['tool_calls'] as $tc) {
            $fname        = $tc['function']['name'] ?? '';
            $args         = json_decode($tc['function']['arguments'], true) ?: [];
            $ciudadSesion = $_SESSION['ciudad'] ?? 'Guayaquil';
            $planSel      = $_SESSION['plan_etiqueta'];   // el plan lo conocemos por la sesión

            switch ($fname) {
                case 'buscar_por_ciudad':
                    // Estima en la ciudad que pidió el paciente (sin cambiar la de la sesión).
                    $resultado = buscarCopago($db, $args['especialidad'] ?? '', $planSel, $planes,
                                              $args['ciudad'] ?? $ciudadSesion, $especialidades);
                    break;

                case 'comparar_planes':
                    $resultado = compararPlanes($db, $args['especialidad'] ?? '',
                                                $args['ciudad'] ?? $ciudadSesion, $especialidades);
                    break;

                case 'explicar_cobertura':
                    $resultado = explicarCobertura($db, $planSel, $planes,
                                                   $args['especialidad'] ?? null, $especialidades);
                    break;

                case 'fuera_de_tema':
                    // El agente detectó que el mensaje no es una consulta de salud.
                    $resultado    = ['tipo' => 'fuera_de_tema'];
                    $fueraDeTema  = true;
                    break;

                case 'buscar_copago':
                default:
                    $resultado = buscarCopago($db, $args['especialidad'] ?? '', $planSel, $planes,
                                              $ciudadSesion, $especialidades);
                    break;
            }

            if (!empty($resultado['especialidad_invalida'])) { $guardrailsActivos[] = 'G2_especialidad_invalida'; }

            // #6 · TRIAJE: para las estimaciones, combina el nivel del modelo con la
            // red de seguridad (que solo escala hacia arriba). Se adjunta al resultado.
            if (($resultado['tipo'] ?? '') === 'estimacion') {
                $resultado['triaje'] = triaje_evaluar($args['nivel_urgencia'] ?? null, $sintomasTexto);
                if (!empty($resultado['triaje']['escalado_por_seguridad'])) {
                    $guardrailsActivos[] = 'T_triaje_escalado_por_seguridad';
                }
            }

            $datos = $resultado;

            $_SESSION['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            ];
        }
        // 8) Respuesta final.
        if ($fueraDeTema) {
            // El mensaje no era de salud: respondemos directo y amable (sin gastar otra
            // llamada al modelo) y descartamos cualquier "estimación".
            $choice = ['role' => 'assistant', 'content' =>
                'Puedo ayudarte solo con molestias o síntomas de salud, para estimar tu copago '
              . 'y orientarte sobre a qué especialista acudir. Cuéntame qué sientes 🙂'];
            $_SESSION['messages'][] = $choice;
            $datos = null;
        } else {
            // Segunda llamada: el modelo redacta la respuesta final con los datos reales.
            $resp2  = openaiChat($_SESSION['messages'], $tools);
            $choice = $resp2['choices'][0]['message'];
            $_SESSION['messages'][] = $choice;
        }
    }

    // 7b) FALLBACK robusto: a veces Llama NO usa el campo tool_calls y escribe la llamada
    //     como texto dentro de la respuesta, p.ej.:
    //       "...acude a emergencias. <function=buscar_copago>{"especialidad":"Cardiología"}</function>"
    //     Lo detectamos, ejecutamos la herramienta igual y limpiamos el texto que se muestra.
    if ($datos === null && empty($choice['tool_calls']) && !empty($choice['content'])
        && preg_match('/<function\s*=\s*buscar_copago\b[^>]*>?\s*(\{.*?\})\s*(?:<\/function>)?/s',
                      $choice['content'], $m)) {
        $args = json_decode($m[1], true) ?: [];
        if (!empty($args['especialidad'])) {
            $ciudadSel = $_SESSION['ciudad'] ?? 'Guayaquil';
            $planSel   = $_SESSION['plan_etiqueta'];
            $datos     = buscarCopago($db, $args['especialidad'], $planSel, $planes, $ciudadSel, $especialidades);
            if (!empty($datos['especialidad_invalida'])) { $guardrailsActivos[] = 'G2_especialidad_invalida'; }
            // #6 · Triaje también en el camino de respaldo (aquí solo lo aporta la red de seguridad).
            if (($datos['tipo'] ?? '') === 'estimacion') {
                $datos['triaje'] = triaje_evaluar($args['nivel_urgencia'] ?? null, $sintomasTexto);
                if (!empty($datos['triaje']['escalado_por_seguridad'])) {
                    $guardrailsActivos[] = 'T_triaje_escalado_por_seguridad';
                }
            }
        }
        // Quitar la etiqueta de función del texto que ve el usuario.
        $limpio = trim(preg_replace('#<function\b.*?</function>|<function\b.*$#s', '', $choice['content']));
        if ($limpio === '') {
            $limpio = 'Te comparto la especialidad sugerida y tu copago estimado.';
        }
        $choice['content'] = $limpio;
        $_SESSION['messages'][count($_SESSION['messages']) - 1]['content'] = $limpio;
    }

    // Guardar en el historial: solo si hay un usuario logueado y un resultado cubierto.
    if ($datos && !empty($datos['recomendado']) && !empty($_SESSION['user_id'])) {
        try {
            $best = $datos['recomendado'];
            $ins  = $db->prepare(
                "INSERT INTO consultas
                   (usuario_id, sintoma, especialidad, ciudad, hospital, red, copago,
                    porcentaje_cobertura, requiere_autorizacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                (int) $_SESSION['user_id'],
                mb_substr($_SESSION['sintoma_inicial'] ?? $mensaje, 0, 500),
                $datos['especialidad'] ?? null,
                $datos['ciudad'] ?? null,
                $best['nombre'] ?? null,
                $best['red'] ?? null,
                $best['copago'] ?? null,
                $best['porcentaje_cobertura'] ?? null,
                !empty($datos['requiere_autorizacion']) ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            // No romper la respuesta al usuario si falla el guardado del historial.
        }
    }

    // G3 · Anti-alucinación de montos: aunque el modelo escriba un precio en su
    // texto, se neutraliza. El ÚNICO número que ve el paciente sale del SQL.
    $textoFinal = $choice['content'] ?? '';
    [$textoFinal, $huboMonto] = gr_neutralizar_montos($textoFinal);
    if ($huboMonto) { $guardrailsActivos[] = 'G3_monto_alucinado_neutralizado'; }

    echo json_encode([
        'ok'         => true,
        'respuesta'  => $textoFinal,
        'datos'      => $datos,   // null si el agente solo preguntó el plan
        'guardrails' => $guardrailsActivos,   // defensas activadas en esta petición
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // #17 · Manejo de errores amigable: el detalle técnico va al log del servidor,
    // NUNCA al paciente. Según el origen del fallo mostramos un mensaje claro.
    error_log('[chat.php] ' . get_class($e) . ': ' . $e->getMessage());

    if ($e instanceof GroqRateLimitException) {
        // Límite gratis de Groq (tokens por minuto). Mensaje claro, no alarmante.
        $codigo  = 429;
        $seg     = $e->esperaSegundos > 0 ? (int) ceil($e->esperaSegundos) : 20;
        $amigable = 'Estoy recibiendo muchas consultas seguidas y el plan gratis de la IA '
                  . 'tiene un límite por minuto. Espera ~' . $seg . ' segundos y vuelve a intentar. 🙏';
    } elseif ($e instanceof GroqException) {
        $codigo  = 503;
        $amigable = 'La asistente de IA no está disponible en este momento. Intenta de nuevo en unos segundos.';
    } elseif ($e instanceof PDOException) {
        $codigo  = 503;
        $amigable = 'No pudimos conectar con la base de datos. Intenta de nuevo en un momento.';
    } elseif ($e instanceof RuntimeException) {
        // Errores de validación esperados (mensaje vacío, plan no elegido, etc.):
        // su mensaje ya es apto para el usuario.
        $codigo  = 400;
        $amigable = $e->getMessage();
    } else {
        $codigo  = 500;
        $amigable = 'Ocurrió un problema inesperado. Intenta de nuevo.';
    }

    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $amigable],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// =====================================================================
//  Ejecuta la herramienta. AQUÍ la plata sale del SQL, no del modelo.
// =====================================================================
function buscarCopago(PDO $db, string $especialidad, string $planEtiqueta, array $planes, string $ciudad = 'Guayaquil', array $catalogoEsp = []): array {
    // G2 · Guardrail anti-alucinación de especialidad: si el modelo propone una
    // especialidad que NO existe en la lista blanca de la BD, se rechaza AQUÍ,
    // antes de tocar el SQL. Si difiere solo en acentos/mayúsculas, se canonaliza.
    if ($catalogoEsp) {
        $canon = gr_validar_especialidad($especialidad, $catalogoEsp);
        if ($canon === null) {
            return [
                'error'                 => 'La especialidad "' . $especialidad . '" no existe en la red. El modelo no puede inventar especialidades.',
                'especialidad_invalida' => true,
                'cubierto'              => false,
                'recomendado'           => null,
                'opciones'              => [],
            ];
        }
        $especialidad = $canon;   // usar siempre el nombre canónico de la BD
    }

    // Resolver plan_id desde la etiqueta "Aseguradora - Plan"
    $plan_id = null;
    foreach ($planes as $p) {
        if (($p['aseguradora'] . ' - ' . $p['nombre']) === $planEtiqueta) {
            $plan_id = (int) $p['id'];
            break;
        }
    }

    // Resolver la especialidad (id, costo de referencia y si requiere autorización previa)
    $stmt = $db->prepare("SELECT id, costo_referencia, requiere_autorizacion
                          FROM especialidades WHERE nombre = ?");
    $stmt->execute([$especialidad]);
    $esp = $stmt->fetch();

    if (!$plan_id || !$esp) {
        return ['error' => 'No se encontró el plan o la especialidad indicada.'];
    }
    $especialidad_id = (int) $esp['id'];

    // Filtro por la ciudad elegida por el paciente. Se aplica en la consulta
    // (no borrando filas) para que sobreviva si se reimporta la base de datos.
    $sql = "SELECT h.nombre, h.red, c.copago, c.porcentaje_cobertura
            FROM coberturas c
            JOIN hospitales h ON h.id = c.hospital_id
            WHERE c.plan_id = ? AND c.especialidad_id = ? AND h.ciudad = ?
            ORDER BY c.copago ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$plan_id, $especialidad_id, $ciudad]);
    $hospitales = $stmt->fetchAll();

    foreach ($hospitales as &$h) {
        $h['copago'] = (float) $h['copago'];
    }
    unset($h);

    return [
        'tipo'                  => 'estimacion',
        'especialidad'          => $especialidad,
        'plan'                  => $planEtiqueta,
        'ciudad'                => $ciudad,
        'costo_referencia'      => (float) $esp['costo_referencia'],
        'requiere_autorizacion' => (bool) $esp['requiere_autorizacion'],
        'cubierto'              => !empty($hospitales),
        'recomendado'           => $hospitales[0] ?? null,
        'opciones'              => $hospitales,
    ];
}

// =====================================================================
//  comparar_planes  ·  ¿Qué plan conviene contratar?
//  Para una especialidad y ciudad, recorre TODOS los planes y calcula el mejor
//  (más barato) copago de cada uno. Los ordena de menor a mayor. La plata sale
//  del SQL. Ayuda al paciente a decidir qué seguro comprar.
// =====================================================================
function compararPlanes(PDO $db, string $especialidad, string $ciudad, array $catalogoEsp = []): array {
    // G2 · misma validación anti-alucinación de especialidad.
    if ($catalogoEsp) {
        $canon = gr_validar_especialidad($especialidad, $catalogoEsp);
        if ($canon === null) {
            return [
                'tipo'                  => 'comparacion_planes',
                'error'                 => 'La especialidad "' . $especialidad . '" no existe en la red.',
                'especialidad_invalida' => true,
                'planes'                => [],
            ];
        }
        $especialidad = $canon;
    }

    $stmt = $db->prepare("SELECT id, costo_referencia FROM especialidades WHERE nombre = ?");
    $stmt->execute([$especialidad]);
    $esp = $stmt->fetch();
    if (!$esp) {
        return ['tipo' => 'comparacion_planes', 'error' => 'Especialidad no encontrada.', 'planes' => []];
    }

    // Mejor copago por plan en esa ciudad y especialidad (más barato disponible).
    $sql = "SELECT p.aseguradora, p.nombre, p.deducible, p.porcentaje,
                   MIN(c.copago) AS mejor_copago
            FROM planes p
            JOIN coberturas c  ON c.plan_id = p.id
            JOIN hospitales h  ON h.id = c.hospital_id
            WHERE c.especialidad_id = ? AND h.ciudad = ?
            GROUP BY p.id, p.aseguradora, p.nombre, p.deducible, p.porcentaje
            ORDER BY mejor_copago ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([(int) $esp['id'], $ciudad]);
    $filas = $stmt->fetchAll();

    $planes = array_map(fn($f) => [
        'plan'         => $f['aseguradora'] . ' - ' . $f['nombre'],
        'aseguradora'  => $f['aseguradora'],
        'porcentaje'   => (int) $f['porcentaje'],
        'deducible'    => (float) $f['deducible'],
        'mejor_copago' => (float) $f['mejor_copago'],
    ], $filas);

    return [
        'tipo'             => 'comparacion_planes',
        'especialidad'     => $especialidad,
        'ciudad'           => $ciudad,
        'costo_referencia' => (float) $esp['costo_referencia'],
        'planes'           => $planes,
        'recomendado'      => $planes[0] ?? null,   // el de copago más bajo
    ];
}

// =====================================================================
//  explicar_cobertura  ·  Transparencia: de dónde sale el copago.
//  Devuelve los parámetros reales de la póliza (deducible, % de cobertura) y,
//  si se indica una especialidad, un ejemplo numérico del cálculo. La plata
//  sale del SQL: el modelo solo redacta la explicación en palabras.
// =====================================================================
function explicarCobertura(PDO $db, string $planEtiqueta, array $planes, ?string $especialidad = null, array $catalogoEsp = []): array {
    // Resolver el plan del paciente por su etiqueta "Aseguradora - Plan".
    $plan_id = null;
    foreach ($planes as $p) {
        if (($p['aseguradora'] . ' - ' . $p['nombre']) === $planEtiqueta) {
            $plan_id = (int) $p['id'];
            break;
        }
    }
    if (!$plan_id) {
        return ['tipo' => 'explicacion_cobertura', 'error' => 'No se encontró el plan del paciente.'];
    }

    $stmt = $db->prepare("SELECT aseguradora, nombre, deducible, factor_copago, porcentaje
                          FROM planes WHERE id = ?");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch();

    $out = [
        'tipo'          => 'explicacion_cobertura',
        'plan'          => $planEtiqueta,
        'aseguradora'   => $plan['aseguradora'],
        'deducible'     => (float) $plan['deducible'],
        'factor_copago' => (float) $plan['factor_copago'],
        'porcentaje'    => (int) $plan['porcentaje'],
    ];

    // Ejemplo concreto si el paciente mencionó una especialidad.
    if ($especialidad) {
        $canon = $catalogoEsp ? gr_validar_especialidad($especialidad, $catalogoEsp) : $especialidad;
        if ($canon) {
            $stmt = $db->prepare("SELECT costo_referencia FROM especialidades WHERE nombre = ?");
            $stmt->execute([$canon]);
            $esp = $stmt->fetch();
            if ($esp) {
                $tarifa = (float) $esp['costo_referencia'];
                $cubre  = round($tarifa * ((int) $plan['porcentaje']) / 100, 2);
                $out['especialidad'] = $canon;
                $out['ejemplo'] = [
                    'tarifa'        => $tarifa,
                    'cubre_seguro'  => $cubre,
                    'base_paciente' => round($tarifa - $cubre, 2),
                ];
            }
        }
    }

    return $out;
}
