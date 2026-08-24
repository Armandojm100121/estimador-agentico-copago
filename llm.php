<?php
// llm.php  -  Llamada a la API de Groq (formato compatible con OpenAI Chat Completions)
require_once __DIR__ . '/config.php';

// Excepción propia para fallos del modelo (Groq). Permite que chat.php distinga
// "la IA falló" de "la base de datos falló" y muestre un mensaje amigable acorde (#17).
class GroqException extends RuntimeException {}

// Excepción específica para el límite de velocidad (429). Se distingue de un fallo
// normal para (a) NO reintentar en cascada —eso gastaría más tokens y empeoraría—
// y (b) mostrar al paciente un mensaje claro de "espera unos segundos".
class GroqRateLimitException extends GroqException {
    public float $esperaSegundos;
    public function __construct(string $message, float $espera = 0) {
        parent::__construct($message);
        $this->esperaSegundos = $espera;
    }
}

function openaiChat(array $messages, array $tools = [], $toolChoice = 'auto', ?string $model = null): array {
    if (!OPENAI_API_KEY) {
        throw new RuntimeException('Falta la API key. Edita config.local.php.');
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
