# Guardrails del agente — Arquitectura anti-alucinación

> Contribución de tesis: capa de seguridad que **hace cumplir**, de forma
> verificable, el principio *"el dinero SIEMPRE sale del SQL; el modelo NUNCA
> inventa montos"*. No se confía únicamente en el prompt del sistema: cada
> defensa se aplica en código (`guardrails.php`) y deja evidencia auditable.

## Principio central

En un asistente de salud, un monto inventado por el modelo (alucinación) puede
inducir a error al paciente. Por eso el LLM tiene un rol **acotado**: solo
DECIDE la especialidad médica. Todos los valores monetarios (copago, cobertura,
tarifa) se calculan a partir de la base de datos (la póliza real del afiliado).

```
Paciente ──► LLM (decide ESPECIALIDAD) ──► SQL (calcula DINERO) ──► Paciente
                     │                          ▲
                     └── Guardrails ────────────┘
        (validan entrada, especialidad y neutralizan montos alucinados)
```

## Modelo de amenaza y defensas

| ID | Amenaza | Defensa | Dónde |
|----|---------|---------|-------|
| **G1** | Entrada vacía o abusiva (prompt gigante) | Valida no-vacío y recorta a 500 caracteres | `gr_validar_mensaje()` |
| **G2** | El modelo propone una especialidad inexistente (p. ej. "Neurología") | Se compara contra la **lista blanca de la BD** (sin acentos/mayúsculas); si no existe, se rechaza **antes de tocar el SQL** | `gr_validar_especialidad()` + `buscarCopago()` |
| **G3** | El modelo escribe un **monto** en su texto (alucinación de dinero) | Se detecta (`$12`, `12 dólares`, `USD 20`…) y se **neutraliza**: el único número que ve el paciente sale del SQL | `gr_neutralizar_montos()` |
| **G4** | Falta de trazabilidad | Cada activación se registra como JSON en `guardrails.log` | `gr_log()` |

## Defensa en profundidad

La especialidad se restringe en **dos capas** independientes:

1. **En el modelo** — la herramienta `buscar_copago` declara la especialidad
   como un `enum` con las especialidades reales, de modo que el *function
   calling* ya está acotado.
2. **En el código** — aunque el modelo evada el `enum` (por ejemplo, por el
   camino de respaldo que parsea texto `<function=…>`), **G2** vuelve a validar
   contra la BD. Si la especialidad no existe, no se ejecuta ninguna consulta.

## Defensa clínica: triaje con red de seguridad (`triaje.php`)

El triaje de urgencias (verde/amarillo/rojo) aplica la misma filosofía que los
guardrails, pero en el plano **clínico**:

| ID | Riesgo | Defensa | Dónde |
|----|--------|---------|-------|
| **T** | El modelo **subestima** un síntoma grave (lo marca "verde" siendo una emergencia) | Una red de seguridad basada en señales de alarma **escala** el nivel; **solo hacia arriba, nunca lo baja** | `triaje_evaluar()` |

La asimetría es la clave defendible: el modelo puede volver el triaje **más
cauto**, jamás menos. Cada escalado se registra (`T_triaje_escalado_por_seguridad`)
en `guardrails.log`, igual que las demás defensas.

> Es un triaje **educativo simplificado**; no reemplaza un protocolo certificado
> (Manchester Triage System) ni la valoración de un profesional.

## Evidencia para la sección de resultados

Cada guardrail que se activa:

- se registra en `guardrails.log` (una línea JSON por evento, con marca de
  tiempo), y
- se devuelve al frontend en el campo `guardrails` de la respuesta, que muestra
  una nota visible en el chat (🛡️).

Esto permite reportar métricas como *"en N consultas de prueba, la defensa G3
se activó K veces, evitando K montos potencialmente alucinados"*, convirtiendo
una afirmación cualitativa en un resultado cuantitativo y defendible.

Para contar activaciones por tipo (ejemplo):

```bash
grep -o '"evento":"[^"]*"' guardrails.log | sort | uniq -c
```

## Archivos

- `guardrails.php` — módulo de defensas (G1–G4).
- `chat.php` — integra los guardrails en el flujo del agente.
- `index.php` — muestra la nota visible cuando un guardrail se activa.
- `guardrails.log` — evidencia de ejecución (no se versiona; se regenera).
