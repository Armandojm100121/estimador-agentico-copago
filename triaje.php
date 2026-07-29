<?php
// triaje.php  -  Triaje de urgencias por niveles (verde / amarillo / rojo).
//
// ENFOQUE HÍBRIDO (contribución de tesis, complementa a guardrails.php):
//   1) El agente (LLM) razona el nivel de urgencia a partir de la conversación.
//   2) Una RED DE SEGURIDAD basada en reglas (señales de alarma clínicas) puede
//      ESCALAR el nivel, NUNCA reducirlo. Si el modelo subestima un síntoma
//      crítico, el sistema fuerza el nivel más alto. Solo escala hacia arriba.
//
//   Esta asimetría (el modelo solo puede volver el triaje MÁS cauto, jamás menos)
//   es lo que lo hace clínicamente defendible: ante la duda, se prioriza la
//   seguridad del paciente.
//
// AVISO: triaje educativo simplificado. NO reemplaza un protocolo clínico
// certificado (p. ej. Manchester Triage System) ni la valoración profesional.

require_once __DIR__ . '/guardrails.php';   // reutiliza gr_normalizar() y gr_log()

// Orden de severidad (mayor número = más grave).
const TRIAJE_RANK = ['verde' => 1, 'amarillo' => 2, 'rojo' => 3];

// ---------------------------------------------------------------------------
// Red de seguridad: busca señales de alarma en el texto del paciente y devuelve
// el nivel MÍNIMO que imponen ('rojo' | 'amarillo'), o null si no detecta nada.
// ---------------------------------------------------------------------------
function triaje_reglas(string $texto): ?string {
    $t = gr_normalizar($texto);   // minúsculas + sin acentos

    // 🔴 Dolor de pecho (posible cardíaco): se detecta de forma robusta combinando
    // la mención al "pecho" con una palabra de dolor/opresión, así cubre tanto
    // "dolor de pecho" como "me duele el pecho", "me aprieta el pecho", etc.
    // (Se excluye "arde el pecho", más asociado a reflujo, no a emergencia.)
    if (str_contains($t, 'pecho') && !str_contains($t, 'arde')) {
        foreach (['duele', 'dolor', 'opresion', 'aprieta', 'apreta', 'presion', 'apretado', 'punzada'] as $d) {
            if (str_contains($t, $d)) return 'rojo';
        }
    }

    // 🔴 Otras señales de emergencia (rojo)
    $rojo = [
        'no puedo respirar', 'dificultad para respirar', 'me falta el aire', 'falta de aire',
        'me ahogo', 'me cuesta respirar', 'no puedo respirar bien',
        'desmay', 'perdi el conocimiento', 'perdida de conocimiento', 'inconsciente', 'convulsion',
        'sangrado abundante', 'hemorragia', 'vomito con sangre', 'sangre en el vomito',
        'no siento el brazo', 'no puedo mover', 'cara torcida', 'boca torcida', 'no puedo hablar',
        'infarto', 'derrame', 'trombosis', 'labios morados',
        'dolor de cabeza muy fuerte de repente', 'peor dolor de cabeza de mi vida',
        'suicid', 'me quiero morir', 'intoxicacion', 'envenenamiento',
    ];
    // 🟡 Señales de atención pronta (amarillo)
    $amarillo = [
        'fiebre alta', 'fiebre muy alta', 'no baja la fiebre', 'deshidrat',
        'fractura', 'hueso roto', 'se me salio el hueso', 'no puedo caminar',
        'dolor intenso', 'dolor muy fuerte', 'dolor severo', 'dolor insoportable',
        'quemadura', 'vomito mucho', 'no para de vomitar', 'corte profundo',
        'golpe fuerte en la cabeza', 'golpe en la cabeza',
    ];

    foreach ($rojo as $k)     { if (str_contains($t, $k)) return 'rojo'; }
    foreach ($amarillo as $k) { if (str_contains($t, $k)) return 'amarillo'; }
    return null;
}

// ---------------------------------------------------------------------------
// Metadatos de presentación por nivel (etiqueta, si es emergencia, mensaje).
// ---------------------------------------------------------------------------
function triaje_meta(string $nivel): array {
    switch ($nivel) {
        case 'rojo':
            return [
                'nivel'      => 'rojo',
                'etiqueta'   => 'Posible emergencia',
                'emergencia' => true,
                'mensaje'    => 'Estos síntomas pueden ser graves. Acude a un servicio de emergencias '
                              . 'o llama al 911 ahora mismo. La estimación de copago es solo referencial.',
            ];
        case 'amarillo':
            return [
                'nivel'      => 'amarillo',
                'etiqueta'   => 'Atención pronta',
                'emergencia' => false,
                'mensaje'    => 'Conviene que te valoren pronto (hoy o mañana). No lo dejes pasar.',
            ];
        default:
            return [
                'nivel'      => 'verde',
                'etiqueta'   => 'Atención de rutina',
                'emergencia' => false,
                'mensaje'    => 'Puedes agendar una consulta de forma normal con la especialidad sugerida.',
            ];
    }
}

// ---------------------------------------------------------------------------
// Combina el nivel propuesto por el modelo con la red de seguridad.
//   REGLA CLAVE: solo se ESCALA (nunca se baja) el nivel de urgencia.
//   Devuelve los metadatos del nivel final + trazabilidad de la decisión.
// ---------------------------------------------------------------------------
function triaje_evaluar(?string $nivelModelo, string $textoSintomas): array {
    $modelo = isset(TRIAJE_RANK[$nivelModelo]) ? $nivelModelo : null;
    $reglas = triaje_reglas($textoSintomas);

    $final    = $modelo ?? 'verde';   // base: lo que dijo el modelo (o verde si no dijo)
    $escalado = false;

    // La red de seguridad solo puede subir el nivel, jamás bajarlo.
    if ($reglas !== null && TRIAJE_RANK[$reglas] > TRIAJE_RANK[$final]) {
        $final    = $reglas;
        $escalado = true;
        gr_log('T_triaje_escalado_por_seguridad', ['modelo' => $modelo, 'reglas' => $reglas]);
    }

    $meta = triaje_meta($final);
    $meta['nivel_modelo']           = $modelo;   // qué dijo el LLM (trazabilidad)
    $meta['escalado_por_seguridad'] = $escalado; // ¿la red de seguridad intervino?
    return $meta;
}
