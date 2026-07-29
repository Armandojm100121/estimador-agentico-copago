<?php
// eval_modelos.php  -  Modelos de Groq a comparar en la evaluación (#8).
//
// 'in'/'out' = precio por 1,000,000 de tokens (USD), precios públicos de Groq
// (referenciales; pueden cambiar). Se usan para estimar el costo por consulta.
// El primero de la lista es el que usa la app en producción (config.php).

return [
    'llama-3.3-70b-versatile' => ['label' => 'Llama 3.3 70B (versatile)', 'in' => 0.59, 'out' => 0.79],
    'llama-3.1-8b-instant'    => ['label' => 'Llama 3.1 8B (instant)',    'in' => 0.05, 'out' => 0.08],
];
