<?php
// llm.php  -  Capa de modelo CONMUTABLE: Claude (Anthropic) o Groq (compat. OpenAI).
//
// La función pública openaiChat() mantiene el MISMO formato de entrada y salida
// (estilo OpenAI/Groq) que ya usan chat.php y eval_api.php. Por dentro decide el
// proveedor según LLM_PROVIDER (config.php) y, para Claude, traduce al formato de
// la Messages API de Anthropic y vuelve a traducir la respuesta. Así el resto de
// la app no cambia y se puede volver a Groq con un solo interruptor.
//
// El nombre openaiChat() es histórico: "openai" aquí significa "formato OpenAI",
// que es el que hablan tanto Groq como la capa interna de la app.
require_once __DIR__ . '/config.php';

// Excepción propia para fallos del modelo. Permite que chat.php distinga "la IA
// falló" de "la base de datos falló" y muestre un mensaje amigable acorde (#17).
class GroqException extends RuntimeException {}

// Excepción específica para el límite de velocidad (429). Se distingue de un fallo
// normal para (a) NO reintentar en cascada —eso gastaría más tokens y empeoraría—
// y (b) mostrar al usuario un mensaje claro de "espera unos segundos".
class GroqRateLimitException extends GroqException {
    public float $esperaSegundos;
    public function __construct(string $message, float $espera = 0) {
        parent::__construct($message);
        $this->esperaSegundos = $espera;
    }
}

// Recorta el historial que se ENVÍA al modelo (no el guardado en sesión): conserva el
// mensaje de sistema y solo los últimos $maxTurnos turnos del paciente, con las
// respuestas y resultados de herramienta que les siguen. Cada llamada cobra el
// historial completo, así que un historial sin recorte agota tokens rápido (clave en
// el plan gratis de Groq; también ahorra con Claude). El corte siempre empieza en un
// mensaje 'user', así nunca queda un resultado 'tool' sin su llamada previa.
function recortarHistorial(array $messages, int $maxTurnos = 4): array {
    if (count($messages) < 2) {
        return $messages;
    }
    $sistema = ($messages[0]['role'] ?? '') === 'system' ? [$messages[0]] : [];
    $resto   = $sistema ? array_slice($messages, 1) : $messages;

    $inicio = 0;
    $vistos = 0;
    for ($i = count($resto) - 1; $i >= 0; $i--) {
        if (($resto[$i]['role'] ?? '') === 'user') {
            $vistos++;
            if ($vistos === $maxTurnos) {
                $inicio = $i;
                break;
            }
        }
    }
    return array_merge($sistema, array_slice($resto, $inicio));
}

// =====================================================================
//  PUNTO DE ENTRADA — reparte entre Claude y Groq.
//  $toolChoice: 'auto' | 'required' | ['type'=>'function','function'=>['name'=>...]]
//  $model: fuerza un modelo concreto (lo usa la evaluación #8). Si empieza por
//          "claude" usa Claude; cualquier otro nombre explícito usa Groq. Sin
//          $model, manda el proveedor configurado (LLM_PROVIDER).
// =====================================================================
function openaiChat(array $messages, array $tools = [], $toolChoice = 'auto', ?string $model = null): array {
    $usarClaude = (LLM_PROVIDER === 'claude');
    if ($model) {
        $usarClaude = (stripos($model, 'claude') === 0);   // el modelo explícito define el proveedor
    }
    return $usarClaude
        ? claudeChat($messages, $tools, $toolChoice, $model)
        : groqChat($messages, $tools, $toolChoice, $model);
}

// =====================================================================
//  CLAUDE (Anthropic Messages API)
// =====================================================================
function claudeChat(array $messages, array $tools = [], $toolChoice = 'auto', ?string $model = null): array {
    if (!ANTHROPIC_API_KEY) {
        throw new RuntimeException('Falta la API key de Claude. Edita config.local.php.');
    }
    $modelo = $model ?: ANTHROPIC_MODEL;

    // --- 1) Traducir mensajes estilo OpenAI -> formato de Claude ---
    //   · system: en Claude es un campo aparte, no un mensaje del array.
    //   · role 'tool' (resultado): en Claude es un bloque tool_result dentro de un
    //     mensaje 'user'; varios resultados seguidos se fusionan en un solo mensaje.
    //   · assistant con tool_calls: en Claude son bloques tool_use.
    $system     = '';
    $claudeMsgs = [];
    foreach ($messages as $m) {
        $role = $m['role'] ?? 'user';

        if ($role === 'system') {
            $system .= ($system ? "\n" : '') . (is_string($m['content'] ?? null) ? $m['content'] : '');
            continue;
        }

        if ($role === 'tool') {
            $block = [
                'type'        => 'tool_result',
                'tool_use_id' => $m['tool_call_id'] ?? '',
                'content'     => is_string($m['content'] ?? null)
                                 ? $m['content']
                                 : json_encode($m['content'] ?? '', JSON_UNESCAPED_UNICODE),
            ];
            $last = count($claudeMsgs) - 1;
            if ($last >= 0 && $claudeMsgs[$last]['role'] === 'user'
                && is_array($claudeMsgs[$last]['content'])
                && (($claudeMsgs[$last]['content'][0]['type'] ?? '') === 'tool_result')) {
                $claudeMsgs[$last]['content'][] = $block;   // fusiona con los tool_result previos
            } else {
                $claudeMsgs[] = ['role' => 'user', 'content' => [$block]];
            }
            continue;
        }

        if ($role === 'assistant') {
            $content = [];
            $txt = is_string($m['content'] ?? null) ? trim($m['content']) : '';
            if ($txt !== '') { $content[] = ['type' => 'text', 'text' => $txt]; }
            if (!empty($m['tool_calls'])) {
                foreach ($m['tool_calls'] as $tc) {
                    $input = json_decode($tc['function']['arguments'] ?? '{}', true);
                    if (!is_array($input) || $input === []) { $input = new stdClass(); }
                    $content[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'] ?? ('toolu_' . bin2hex(random_bytes(8))),
                        'name'  => $tc['function']['name'] ?? '',
                        'input' => $input,
                    ];
                }
            }
            if ($content === []) { $content[] = ['type' => 'text', 'text' => '...']; }  // Claude no acepta contenido vacío
            $claudeMsgs[] = ['role' => 'assistant', 'content' => $content];
            continue;
        }

        // user normal
        $claudeMsgs[] = [
            'role'    => 'user',
            'content' => is_string($m['content'] ?? null)
                         ? $m['content']
                         : json_encode($m['content'] ?? '', JSON_UNESCAPED_UNICODE),
        ];
    }

    $payload = [
        'model'      => $modelo,
        'max_tokens' => 1024,              // respuestas de Clara son cortas; acota el costo
        'messages'   => $claudeMsgs,
    ];
    if ($system !== '') { $payload['system'] = $system; }

    // Haiku "razona" por defecto y gasta tokens de salida: lo desactivamos para
    // abaratar y acelerar (en modelos mayores se deja el pensamiento adaptativo).
    if (stripos($modelo, 'haiku') !== false) {
        $payload['thinking'] = ['type' => 'disabled'];
    }

    // --- 2) Herramientas: estilo OpenAI -> Claude ---
    if ($tools) {
        $payload['tools'] = array_map(function ($t) {
            $f = $t['function'] ?? $t;
            return [
                'name'         => $f['name'] ?? '',
                'description'  => $f['description'] ?? '',
                'input_schema' => $f['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()],
            ];
        }, $tools);

        if (is_array($toolChoice)) {
            // Forma forzada de OpenAI: ['type'=>'function','function'=>['name'=>...]]
            $name = $toolChoice['function']['name'] ?? ($toolChoice['name'] ?? '');
            $payload['tool_choice'] = $name ? ['type' => 'tool', 'name' => $name] : ['type' => 'auto'];
        } elseif ($toolChoice === 'required' || $toolChoice === 'any') {
            $payload['tool_choice'] = ['type' => 'any'];   // Haiku 5.5 sí permite forzar herramienta
        } else {
            $payload['tool_choice'] = ['type' => 'auto'];
        }
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // --- 3) Llamada HTTP, con reintento suave ante 429 (igual que el camino Groq) ---
    $intentos = 0;
    while (true) {
        $intentos++;
        $retryAfter = 0.0;
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: ' . ANTHROPIC_VERSION,
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                if (stripos($header, 'retry-after:') === 0) {
                    $retryAfter = (float) trim(substr($header, strlen('retry-after:')));
                }
                return strlen($header);
            },
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new GroqException("Error de conexión con Claude: $err");
        }
        $data = json_decode($res, true);
        if ($code === 200) {
            return claudeToOpenAI($data);
        }
        if ($code === 429) {
            if ($intentos < 2 && $retryAfter > 0 && $retryAfter <= 8) {
                usleep((int) (($retryAfter + 0.3) * 1000000));
                continue;
            }
            throw new GroqRateLimitException(
                'Límite de velocidad de Claude alcanzado (espera ' .
                ($retryAfter > 0 ? ceil($retryAfter) : 20) . ' s).',
                $retryAfter
            );
        }
        $msg = $data['error']['message'] ?? $res;
        throw new GroqException("Claude respondió $code: $msg");
    }
}

// Traduce la respuesta de Claude (bloques content[]) al formato OpenAI que espera
// la app: choices[0].message con content (texto) y tool_calls, más usage.
function claudeToOpenAI(array $data): array {
    $text      = '';
    $toolCalls = [];
    foreach (($data['content'] ?? []) as $block) {
        $type = $block['type'] ?? '';
        if ($type === 'text') {
            $text .= $block['text'] ?? '';
        } elseif ($type === 'tool_use') {
            $toolCalls[] = [
                'id'       => $block['id'] ?? ('toolu_' . bin2hex(random_bytes(8))),
                'type'     => 'function',
                'function' => [
                    'name'      => $block['name'] ?? '',
                    'arguments' => json_encode($block['input'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                ],
            ];
        }
        // los bloques 'thinking' (si los hubiera) se ignoran
    }
    $message = ['role' => 'assistant', 'content' => $text];
    if ($toolCalls) { $message['tool_calls'] = $toolCalls; }

    return [
        'choices' => [['message' => $message]],
        'usage'   => [   // nombres OpenAI para que eval_api.php los lea igual
            'prompt_tokens'     => (int) ($data['usage']['input_tokens'] ?? 0),
            'completion_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
        ],
    ];
}

// =====================================================================
//  GROQ (API compatible con OpenAI Chat Completions) — camino original.
// =====================================================================
function groqChat(array $messages, array $tools = [], $toolChoice = 'auto', ?string $model = null): array {
    if (!OPENAI_API_KEY) {
        throw new RuntimeException('Falta la API key de Groq. Edita config.local.php.');
    }

    // $model permite sobrescribir el modelo por defecto. Lo usa la evaluación del
    // agente (#8) para comparar varios modelos de Groq con exactamente el mismo código.
    $modelo  = $model ?: OPENAI_MODEL;
    $payload = ['model' => $modelo, 'messages' => $messages];
    // Los modelos gpt-oss "razonan" por dentro y gastan muchos tokens de salida.
    // Con reasoning_effort=low reducimos ese gasto — clave para no reventar el
    // límite gratis de Groq (8.000 tokens/min).
    if (strpos($modelo, 'gpt-oss') !== false) {
        $payload['reasoning_effort'] = 'low';
    }
    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = $toolChoice;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Hasta 2 intentos: si Groq responde 429 (límite por minuto) con una espera corta,
    // esperamos lo que nos indica y reintentamos UNA vez. Así los picos transitorios
    // no rompen el chat; si la espera es larga, avisamos al paciente.
    $intentos = 0;
    while (true) {
        $intentos++;
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT    => 40,
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new GroqException("Error de conexión con Groq: $err");
        }
        $data = json_decode($res, true);
        if ($code === 200) {
            return $data;
        }
        if ($code === 429) {
            $msg = $data['error']['message'] ?? $res;
            // Groq indica "Please try again in 4.8s": extraemos los segundos.
            $espera = 0.0;
            if (preg_match('/try again in ([0-9.]+)s/i', $msg, $mm)) {
                $espera = (float) $mm[1];
            }
            // Reintenta UNA sola vez si la espera es corta (<= 8s).
            if ($intentos < 2 && $espera > 0 && $espera <= 8) {
                usleep((int) (($espera + 0.3) * 1000000));
                continue;
            }
            throw new GroqRateLimitException(
                'Límite de velocidad de Groq alcanzado (espera ' .
                ($espera > 0 ? ceil($espera) : 20) . ' s).',
                $espera
            );
        }
        $msg = $data['error']['message'] ?? $res;
        throw new GroqException("Groq respondió $code: $msg");
    }
}
