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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

try {
    $db = getDB();

    // 1) Mensaje del paciente (POST JSON desde el front, o ?mensaje= para probar)
    $body = json_decode(file_get_contents('php://input'), true);
    $mensaje = $body['mensaje'] ?? ($_GET['mensaje'] ?? null);
    if (!$mensaje) {
        throw new RuntimeException('Falta el parámetro "mensaje".');
    }

    // 2) Catálogos desde la BD (no hardcode: si agregas filas, el agente se entera)
    $especialidades = $db->query("SELECT nombre FROM especialidades ORDER BY nombre")
                         ->fetchAll(PDO::FETCH_COLUMN);
    $planes = $db->query("SELECT id, aseguradora, nombre FROM planes ORDER BY id")
                 ->fetchAll();
    $planesEtiquetas = array_map(fn($p) => $p['aseguradora'] . ' - ' . $p['nombre'], $planes);

    // 3) La herramienta: esto es lo "agéntico". El modelo decide cuándo llamarla.
    //    El plan y la ciudad NO los pide la herramienta: ya los conocemos (sesión),
    //    y los inyectamos nosotros. Así el modelo solo decide la ESPECIALIDAD, lo que
    //    evita fallos de generación con function calling (error 400 de Groq).
    $tools = [[
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
                ],
                'required' => ['especialidad'],
            ],
        ],
    ]];

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
                "FLUJO OBLIGATORIO (síguelo al pie de la letra):\n" .
                "1) PRIMER mensaje del paciente con un síntoma: NO llames la herramienta NUNCA en este " .
                "turno, aunque el síntoma te parezca clarísimo. Responde con una frase corta de empatía " .
                "y UNA sola pregunta de seguimiento, la más útil (desde cuándo, qué tan intenso, o si " .
                "hubo un golpe). Siempre exactamente una pregunta, nunca cero, nunca dos.\n" .
                "2) SEGUNDO mensaje (el paciente ya respondió tu pregunta): NO hagas más preguntas. " .
                "Deduce la especialidad y llama INMEDIATAMENTE a la herramienta buscar_copago con esa " .
                "especialidad (el plan y la ciudad ya los conoce el sistema). Aunque la info sea " .
                "incompleta, igual llámala.\n" .
                "3) Al presentar el resultado: UNA frase corta indicando la especialidad sugerida. " .
                "NO repitas montos ni hospitales en el texto (se muestran en una tarjeta aparte). " .
                "Si el resultado de la herramienta trae requiere_autorizacion=true, agrega una segunda " .
                "frase MUY breve avisando que esta atención necesita autorización previa del seguro; " .
                "si es false, no menciones el tema.\n" .
                "4) Para los montos SIEMPRE usa la herramienta buscar_copago; nunca inventes precios.\n" .
                "5) Urgencias: si el síntoma es claramente grave (dolor de pecho intenso, dificultad " .
                "para respirar), dilo en una frase y recomienda emergencias, pero IGUAL llama la " .
                "herramienta para mostrar el copago.",
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
        $toolsArg  = $tools;
        $choiceArg = ['type' => 'function', 'function' => ['name' => 'buscar_copago']];
    } else {
        $toolsArg  = $tools;
        $choiceArg = 'auto';
    }

    // 6) Primera llamada al modelo (con reintento de seguridad ante fallos de function calling)
    try {
        $resp = openaiChat($_SESSION['messages'], $toolsArg, $choiceArg);
    } catch (Throwable $e) {
        // Si falló la llamada FORZADA a la herramienta (error típico de Groq),
        // reintenta dejando que el modelo la invoque en modo automático.
        if ($choiceArg !== 'auto') {
            $resp = openaiChat($_SESSION['messages'], $toolsArg, 'auto');
        } else {
            throw $e;
        }
    }
    $choice = $resp['choices'][0]['message'];
    $_SESSION['messages'][] = $choice;

    $datos = null;

    // 7) ¿El modelo usó la herramienta? (forzada en el turno 2)
    if (!empty($choice['tool_calls'])) {
        foreach ($choice['tool_calls'] as $tc) {
            $args      = json_decode($tc['function']['arguments'], true);
            $ciudadSel = $_SESSION['ciudad'] ?? 'Guayaquil';
            $planSel   = $_SESSION['plan_etiqueta'];   // el plan lo conocemos por la sesión
            $resultado = buscarCopago($db, $args['especialidad'] ?? '', $planSel, $planes, $ciudadSel);
            $datos     = $resultado;

            $_SESSION['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            ];
        }
        // 8) Segunda llamada: el modelo redacta la respuesta final con los datos reales
        $resp2  = openaiChat($_SESSION['messages'], $tools);
        $choice = $resp2['choices'][0]['message'];
        $_SESSION['messages'][] = $choice;
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
            $datos     = buscarCopago($db, $args['especialidad'], $planSel, $planes, $ciudadSel);
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

    echo json_encode([
        'ok'        => true,
        'respuesta' => $choice['content'] ?? '',
        'datos'     => $datos,   // null si el agente solo preguntó el plan
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// =====================================================================
//  Ejecuta la herramienta. AQUÍ la plata sale del SQL, no del modelo.
// =====================================================================
function buscarCopago(PDO $db, string $especialidad, string $planEtiqueta, array $planes, string $ciudad = 'Guayaquil'): array {
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
