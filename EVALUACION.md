# Evaluación del agente — Metodología

> Contribución de tesis: convierte el trabajo de "construí una app" a "evalué un
> sistema". Se mide, de forma reproducible, la calidad del enrutamiento del
> agente (síntoma → especialidad) y su costo/latencia, comparando modelos.

## Pregunta de evaluación

Dado un síntoma en lenguaje natural, ¿con qué **precisión** el agente lo enruta
a la especialidad correcta, y a qué **latencia** y **costo** por consulta?

## Dataset

`casos_evaluacion.php`: 42 casos balanceados (7 por cada una de las 6
especialidades), con síntomas en lenguaje natural y la especialidad esperada
(*ground truth*). Incluye casos ambiguos ("dolor de espalda al cargar cajas",
"dolor de oído en un niño") para exigir a la métrica.

## Protocolo

- Se aísla la decisión central del agente: un prompt de clasificación fuerza la
  herramienta `buscar_copago` (igual que el turno 2 real) y se lee la
  especialidad elegida. No se calcula el copago: aquí solo importa la
  **clasificación**.
- La especialidad devuelta se canonaliza con el mismo guardrail de la app
  (`gr_validar_especialidad`) para una comparación justa (acentos/mayúsculas).
- Cada caso se corre con cada modelo, con **exactamente el mismo código** (solo
  cambia el parámetro `model` de la API). Endpoint: `eval_api.php`.

## Métricas

| Métrica | Definición |
|---------|-----------|
| **Precisión (accuracy)** | aciertos / total de casos |
| **Matriz de confusión** | esperada (fila) vs. predicha (columna); la diagonal son los aciertos |
| **Latencia media** | tiempo de respuesta de la API por consulta (ms) |
| **Costo por consulta** | tokens usados × precio del modelo (Groq), y proyección a 1.000 consultas |

## Modelos comparados

Definidos en `eval_modelos.php` (precios públicos de Groq, referenciales):

- `openai/gpt-oss-120b` — el modelo grande (mayor precisión esperada, más caro).
- `openai/gpt-oss-20b` — el de **producción** (`config.php`): más rápido y barato,
  encaja mejor en el límite gratis de Groq (8.000 tokens/min).

> Nota histórica: originalmente la app usaba `llama-3.3-70b-versatile`, pero Groq
> **retiró todos los modelos Llama** (ago. 2026), por lo que se migró a la familia
> **GPT-OSS**. El código de evaluación es el mismo; solo cambió el catálogo de
> modelos. Ambos GPT-OSS "razonan" internamente, así que se ejecutan con
> `reasoning_effort=low` para acotar el costo de tokens de salida.

## Cómo reproducir

1. Inicia sesión en la app.
2. Abre **`evaluar.php`** (menú lateral → "Evaluación IA").
3. Elige los modelos y pulsa **Ejecutar evaluación**. Corre caso por caso con
   progreso en vivo.
4. Revisa precisión, latencia, costo y matriz de confusión por modelo; exporta
   el detalle con **Exportar CSV** para tu anexo de tesis.

## Hallazgo esperable (trade-off)

El modelo grande (120B) suele lograr **mayor precisión** a **mayor costo**; el
pequeño (20B) es **más barato y rápido** pero puede cometer más errores e incluso
**fallar el function calling** en algún caso. Reportar este trade-off (precisión
vs. costo/latencia) es el resultado central de esta sección, y justifica la
elección del 20B en producción: precisión suficiente al menor costo.

## Archivos

- `casos_evaluacion.php` — dataset (síntoma → especialidad esperada).
- `eval_modelos.php` — modelos y precios a comparar.
- `eval_api.php` — evalúa un caso con un modelo (precisión, latencia, tokens).
- `evaluar.php` — panel: corre el set, grafica métricas y matriz, exporta CSV.
