<?php
// guardrails.php  -  Capa de seguridad del agente (contribución de tesis).
//
// PRINCIPIO CENTRAL (anti-alucinación):
//   "El dinero SIEMPRE sale del SQL; el modelo NUNCA inventa montos."
//   El LLM solo DECIDE la especialidad; los valores monetarios provienen de la
//   base de datos. Este módulo formaliza y HACE CUMPLIR ese principio con
//   defensas verificables, en lugar de confiar solo en el prompt del sistema.
//
// Defensas implementadas:
//   G1  Validación/saneamiento de la entrada del paciente (largo, vacío).
//   G2  Validación de especialidad contra la lista blanca de la BD
//       (canonaliza acentos/mayúsculas; rechaza especialidades inventadas).
//   G3  Anti-alucinación de montos en el texto del modelo: aunque el modelo
//       escriba un precio en su respuesta, se intercepta y se neutraliza.
//   G4  Registro (log) de cada activación, para medir su frecuencia en la tesis.

const GR_MAX_MENSAJE = 500;   // caracteres máximos del mensaje del paciente

// ---------------------------------------------------------------------------
// Utilidad: normaliza texto para comparar (minúsculas + sin acentos + trim).
// ---------------------------------------------------------------------------
function gr_normalizar(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n'];
    return strtr($s, $map);
}

// ---------------------------------------------------------------------------
// G1 · Validación y saneamiento del mensaje del paciente.
//   - Rechaza mensajes vacíos.
//   - Recorta a un largo máximo (evita abuso / prompts gigantes).
// Devuelve el mensaje saneado; lanza excepción si es inválido.
// ---------------------------------------------------------------------------
function gr_validar_mensaje(?string $mensaje): string {
    $m = trim((string) $mensaje);
    if ($m === '') {
        throw new RuntimeException('El mensaje no puede estar vacío.');
    }
    if (mb_strlen($m, 'UTF-8') > GR_MAX_MENSAJE) {
        $m = mb_substr($m, 0, GR_MAX_MENSAJE, 'UTF-8');
        gr_log('G1_mensaje_recortado', ['largo_original' => mb_strlen((string)$mensaje, 'UTF-8')]);
    }
    return $m;
}

// ---------------------------------------------------------------------------
// G2 · Validación de especialidad contra la lista blanca de la BD.
//   El modelo puede alucinar una especialidad que no existe (p. ej. por el
//   camino de respaldo que parsea texto). Aquí la comparamos, sin acentos ni
//   mayúsculas, contra las especialidades REALES de la base de datos.
//
//   $catalogo: lista de nombres canónicos (SELECT nombre FROM especialidades).
//   Devuelve el nombre CANÓNICO exacto de la BD, o null si no existe.
// ---------------------------------------------------------------------------
function gr_validar_especialidad(string $propuesta, array $catalogo): ?string {
    $objetivo = gr_normalizar($propuesta);
    if ($objetivo === '') return null;

    // 1) Coincidencia exacta (normalizada).
    foreach ($catalogo as $canon) {
        if (gr_normalizar($canon) === $objetivo) {
            return $canon;
        }
    }
    // 2) Coincidencia por contención (p. ej. "cardiologia clinica" -> "Cardiología").
    foreach ($catalogo as $canon) {
        $c = gr_normalizar($canon);
        if ($c !== '' && (str_contains($objetivo, $c) || str_contains($c, $objetivo))) {
            gr_log('G2_especialidad_canonalizada', ['propuesta' => $propuesta, 'canon' => $canon]);
            return $canon;
        }
    }
    // No existe en la BD: se rechaza (el modelo la inventó).
    gr_log('G2_especialidad_invalida', ['propuesta' => $propuesta]);
    return null;
}

// ---------------------------------------------------------------------------
// G3 · Anti-alucinación de montos en el texto que ve el usuario.
//   Los montos SOLO deben aparecer en la tarjeta (calculada desde el SQL),
//   nunca en la prosa de Clara. Si el modelo escribe un precio igual, lo
//   detectamos y lo reemplazamos por una referencia neutra a la tarjeta.
//
//   Detecta: "$12", "$ 12,50", "12 dólares", "12 dolares", "USD 12", "12 usd".
//   Devuelve [texto_limpio, se_activo (bool)].
// ---------------------------------------------------------------------------
function gr_neutralizar_montos(string $texto): array {
    $patrones = [
        '/\$\s?\d[\d.,]*/u',                     // $12  |  $ 12,50
        '/\bUSD\s?\d[\d.,]*/iu',                 // USD 12
        '/\b\d[\d.,]*\s?(d[óo]lares?|usd)\b/iu', // 12 dólares | 12 usd
    ];
    $hubo = false;
    $limpio = $texto;
    foreach ($patrones as $re) {
        $limpio = preg_replace_callback($re, function () use (&$hubo) {
            $hubo = true;
            return 'el monto que ves en la tarjeta';
        }, $limpio);
    }
    if ($hubo) {
        // Limpieza cosmética de dobles espacios que deje el reemplazo.
        $limpio = trim(preg_replace('/\s{2,}/u', ' ', $limpio));
        if ($limpio === '') {
            $limpio = 'Te muestro la especialidad sugerida y tu copago en la tarjeta.';
        }
        gr_log('G3_monto_alucinado_neutralizado', ['original' => $texto]);
    }
    return [$limpio, $hubo];
}

// ---------------------------------------------------------------------------
// G4 · Registro de activaciones. Escribe una línea JSON por evento en
//   guardrails.log (misma carpeta). Sirve para la sección de resultados de la
//   tesis: cuántas veces se activó cada defensa. Nunca rompe la app si falla.
// ---------------------------------------------------------------------------
function gr_log(string $evento, array $detalle = []): void {
    try {
        $linea = json_encode([
            'ts'      => date('c'),
            'evento'  => $evento,
            'detalle' => $detalle,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(__DIR__ . '/guardrails.log', $linea, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // El registro es best-effort; jamás debe afectar la respuesta al usuario.
    }
}
