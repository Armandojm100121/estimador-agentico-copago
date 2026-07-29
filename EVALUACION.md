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

- `llama-3.3-70b-versatile` — el de producción.
- `llama-3.1-8b-instant` — alternativa más barata y rápida.

## Cómo reproducir

1. Inicia sesión en la app.
2. Abre **`evaluar.php`** (menú lateral → "Evaluación IA").
3. Elige los modelos y pulsa **Ejecutar evaluación**. Corre caso por caso con
   progreso en vivo.
4. Revisa precisión, latencia, costo y matriz de confusión por modelo; exporta
   el detalle con **Exportar CSV** para tu anexo de tesis.

## Hallazgo esperable (trade-off)

El modelo grande (70B) suele lograr **mayor precisión** a **mayor costo**; el
pequeño (8B) es **más barato** pero comete más errores e incluso puede **fallar
el function calling** en algún caso. Reportar este trade-off (precisión vs.
costo) es el resultado central de esta sección.

## Archivos

- `casos_evaluacion.php` — dataset (síntoma → especialidad esperada).
- `eval_modelos.php` — modelos y precios a comparar.
- `eval_api.php` — evalúa un caso con un modelo (precisión, latencia, tokens).
- `evaluar.php` — panel: corre el set, grafica métricas y matriz, exporta CSV.
