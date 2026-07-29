<?php
// llm.php  -  Llamada a la API de Groq (formato compatible con OpenAI Chat Completions)
require_once __DIR__ . '/config.php';

// Excepción propia para fallos del modelo (Groq). Permite que chat.php distinga
// "la IA falló" de "la base de datos falló" y muestre un mensaje amigable acorde (#17).
class GroqException extends RuntimeException {}

function openaiChat(array $messages, array $tools = [], $toolChoice = 'auto', ?string $model = null): array {
    if (!OPENAI_API_KEY) {
        throw new RuntimeException('Falta la API key. Edita config.local.php.');
    }

    // $model permite sobrescribir el modelo por defecto. Lo usa la evaluación del
    // agente (#8) para comparar varios modelos de Groq con exactamente el mismo código.
    $payload = ['model' => $model ?: OPENAI_MODEL, 'messages' => $messages];
    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = $toolChoice;
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
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
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? $res;
        throw new GroqException("Groq respondió $code: $msg");
    }
    return $data;
}
