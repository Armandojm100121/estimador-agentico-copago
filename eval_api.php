<?php
// eval_api.php  -  Ejecuta UN caso de prueba con UN modelo y devuelve las métricas.
//   GET ?i=<indice_del_caso>&model=<slug_del_modelo>
//
// Aísla la decisión central del agente: dado un síntoma, ¿a qué especialidad lo
// enruta? Fuerza la herramienta buscar_copago (igual que el turno 2 real) y lee
// la especialidad elegida. Mide latencia y tokens (costo). No calcula el copago:
// aquí solo interesa la PRECISIÓN de clasificación del modelo.

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/guardrails.php';
requiereLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $modelos = require __DIR__ . '/eval_modelos.php';
    $casos   = require __DIR__ . '/casos_evaluacion.php';

    $model = (string) ($_GET['model'] ?? '');
    if (!isset($modelos[$model])) {
        throw new RuntimeException('Modelo no válido.');
    }
    $i = (int) ($_GET['i'] ?? -1);
    if ($i < 0 || $i >= count($casos)) {
        throw new RuntimeException('Índice de caso fuera de rango.');
    }
    $caso = $casos[$i];

    // Catálogo de especialidades (para el enum de la herramienta y canonalizar).
    $especialidades = getDB()->query("SELECT nombre FROM especialidades ORDER BY nombre")
                             ->fetchAll(PDO::FETCH_COLUMN);

    // Herramienta idéntica a la del agente real (solo especialidad es obligatoria).
    $tools = [[
        'type' => 'function',
        'function' => [
            'name' => 'buscar_copago',
            'description' => 'Registra la especialidad médica adecuada para el síntoma del paciente.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'especialidad' => [
                        'type' => 'string',
                        'enum' => $especialidades,
                        'description' => 'Especialidad médica más adecuada para el síntoma.',
                    ],
                    'nivel_urgencia' => [
                        'type' => 'string',
                        'enum' => ['verde', 'amarillo', 'rojo'],
                        'description' => 'Triaje del síntoma (opcional).',
                    ],
                ],
                'required' => ['especialidad'],
            ],
        ],
    ]];

    $listaEsp = implode(', ', $especialidades);
    $messages = [
        ['role' => 'system', 'content' =>
            "Eres el clasificador clínico de un asistente de salud. Dada la molestia del " .
            "paciente, elige la ESPECIALIDAD más adecuada de esta lista y llama a la herramienta " .
            "buscar_copago con ella: $listaEsp. Elige siempre exactamente una."],
        ['role' => 'user', 'content' => $caso['sintoma']],
    ];
    $forzar = ['type' => 'function', 'function' => ['name' => 'buscar_copago']];

    // --- Llamada al modelo, midiendo la latencia ---
    $t0 = microtime(true);
    try {
        $resp = openaiChat($messages, $tools, $forzar, $model);
    } catch (Throwable $e) {
        // Mismo reintento que en producción: si falla la llamada forzada, va en auto.
        $resp = openaiChat($messages, $tools, 'auto', $model);
    }
    $latenciaMs = (microtime(true) - $t0) * 1000;

    // --- Extraer la especialidad elegida por el modelo ---
    $msg = $resp['choices'][0]['message'] ?? [];
    $obtenidaRaw = null;
    $nivel = null;
    if (!empty($msg['tool_calls'][0]['function']['arguments'])) {
        $args = json_decode($msg['tool_calls'][0]['function']['arguments'], true) ?: [];
        $obtenidaRaw = $args['especialidad'] ?? null;
        $nivel = $args['nivel_urgencia'] ?? null;
    }
    // Canonalizar (mismo guardrail que la app) para una comparación justa.
    $obtenida = $obtenidaRaw ? (gr_validar_especialidad($obtenidaRaw, $especialidades) ?? $obtenidaRaw) : null;
    $correcto = ($obtenida !== null && $obtenida === $caso['esperada']);

    // --- Costo estimado a partir de los tokens usados ---
    $tin  = (int) ($resp['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($resp['usage']['completion_tokens'] ?? 0);
    $costo = $tin / 1e6 * $modelos[$model]['in'] + $tout / 1e6 * $modelos[$model]['out'];

    echo json_encode([
        'ok'             => true,
        'i'              => $i,
        'sintoma'        => $caso['sintoma'],
        'esperada'       => $caso['esperada'],
        'obtenida'       => $obtenida,
        'correcto'       => $correcto,
        'nivel_urgencia' => $nivel,
        'latencia_ms'    => round($latenciaMs, 1),
        'tokens_in'      => $tin,
        'tokens_out'     => $tout,
        'costo'          => $costo,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
