<?php
// eval_modelos.php  -  Modelos de Groq a comparar en la evaluación (#8).
//
// 'in'/'out' = precio por 1,000,000 de tokens (USD), precios públicos de Groq
// (referenciales; pueden cambiar). Se usan para estimar el costo por consulta.
// El primero de la lista es el que usa la app en producción (config.php).

return [
    'openai/gpt-oss-120b' => ['label' => 'GPT-OSS 120B (grande)',  'in' => 0.15, 'out' => 0.60],
    'openai/gpt-oss-20b'  => ['label' => 'GPT-OSS 20B (rapido)',   'in' => 0.10, 'out' => 0.50],
];
