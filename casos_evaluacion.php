<?php
// casos_evaluacion.php  -  Dataset de evaluación del agente (#8).
//
// Conjunto de prueba: cada caso es un síntoma en lenguaje natural y la
// especialidad ESPERADA (ground truth). Sirve para medir la precisión con la
// que el agente enruta un síntoma a la especialidad correcta, comparando
// distintos modelos con exactamente el mismo conjunto.
//
// Balanceado en las 6 especialidades (7 casos c/u = 42). Incluye casos
// "trampa" (ambiguos) para que la métrica sea exigente y defendible.
//
// Devuelve un arreglo de ['sintoma' => ..., 'esperada' => ...].

return [
    // ---- Medicina General ----
    ['sintoma' => 'Tengo gripe, malestar general y algo de fiebre',            'esperada' => 'Medicina General'],
    ['sintoma' => 'Me duele la garganta y tengo tos desde hace tres días',      'esperada' => 'Medicina General'],
    ['sintoma' => 'Necesito un chequeo general de salud',                       'esperada' => 'Medicina General'],
    ['sintoma' => 'Me siento cansado y sin energía últimamente',                'esperada' => 'Medicina General'],
    ['sintoma' => 'Tengo un resfriado con mocos y estornudos',                  'esperada' => 'Medicina General'],
    ['sintoma' => 'Tengo congestión nasal y dolor de cabeza leve',              'esperada' => 'Medicina General'],
    ['sintoma' => 'Quiero renovar mi certificado médico',                       'esperada' => 'Medicina General'],

    // ---- Pediatría ----
    ['sintoma' => 'Mi hijo de 5 años tiene fiebre desde anoche',                'esperada' => 'Pediatría'],
    ['sintoma' => 'A mi bebé le salió un sarpullido en todo el cuerpo',         'esperada' => 'Pediatría'],
    ['sintoma' => 'Mi niña de 3 años no quiere comer y está decaída',           'esperada' => 'Pediatría'],
    ['sintoma' => 'Mi bebé de 8 meses tiene diarrea hace dos días',             'esperada' => 'Pediatría'],
    ['sintoma' => 'Control de niño sano para mi hija de 2 años',                'esperada' => 'Pediatría'],
    ['sintoma' => 'Mi hijo se queja de dolor de oído',                          'esperada' => 'Pediatría'],
    ['sintoma' => 'Mi niño tiene tos y mocos desde hace una semana',            'esperada' => 'Pediatría'],

    // ---- Cardiología ----
    ['sintoma' => 'Me duele el pecho cuando camino rápido',                     'esperada' => 'Cardiología'],
    ['sintoma' => 'Siento palpitaciones fuertes en el corazón',                 'esperada' => 'Cardiología'],
    ['sintoma' => 'Tengo la presión alta y dolor en el pecho',                  'esperada' => 'Cardiología'],
    ['sintoma' => 'Se me acelera el corazón y me falta el aire',                'esperada' => 'Cardiología'],
    ['sintoma' => 'Tengo hipertensión y quiero un control',                     'esperada' => 'Cardiología'],
    ['sintoma' => 'Siento opresión en el pecho al hacer esfuerzo',              'esperada' => 'Cardiología'],
    ['sintoma' => 'A veces el corazón me late muy rápido de repente',           'esperada' => 'Cardiología'],

    // ---- Traumatología ----
    ['sintoma' => 'Me torcí el tobillo jugando fútbol',                         'esperada' => 'Traumatología'],
    ['sintoma' => 'Me caí y creo que me fracturé la muñeca',                    'esperada' => 'Traumatología'],
    ['sintoma' => 'Me duele mucho la rodilla desde una caída',                  'esperada' => 'Traumatología'],
    ['sintoma' => 'Me lastimé el hombro levantando peso',                       'esperada' => 'Traumatología'],
    ['sintoma' => 'Tengo dolor de espalda después de cargar cajas pesadas',     'esperada' => 'Traumatología'],
    ['sintoma' => 'Me golpeé el codo y está muy hinchado',                      'esperada' => 'Traumatología'],
    ['sintoma' => 'No puedo mover bien la muñeca tras una caída',               'esperada' => 'Traumatología'],

    // ---- Dermatología ----
    ['sintoma' => 'Me salió un sarpullido con picazón en el brazo',             'esperada' => 'Dermatología'],
    ['sintoma' => 'Tengo un lunar que cambió de color y forma',                 'esperada' => 'Dermatología'],
    ['sintoma' => 'Tengo mucho acné en la cara y espalda',                      'esperada' => 'Dermatología'],
    ['sintoma' => 'Se me cae bastante el cabello últimamente',                  'esperada' => 'Dermatología'],
    ['sintoma' => 'Tengo la piel muy reseca y con escamas',                     'esperada' => 'Dermatología'],
    ['sintoma' => 'Me salieron manchas rojas que no se van',                    'esperada' => 'Dermatología'],
    ['sintoma' => 'Tengo una verruga que quiero que me revisen',                'esperada' => 'Dermatología'],

    // ---- Ginecología ----
    ['sintoma' => 'Tengo dolor pélvico y un flujo diferente',                   'esperada' => 'Ginecología'],
    ['sintoma' => 'Necesito un control ginecológico de rutina',                 'esperada' => 'Ginecología'],
    ['sintoma' => 'Tengo un retraso menstrual y molestias',                     'esperada' => 'Ginecología'],
    ['sintoma' => 'Quiero hacerme el papanicolau',                              'esperada' => 'Ginecología'],
    ['sintoma' => 'Tengo cólicos menstruales muy fuertes cada mes',             'esperada' => 'Ginecología'],
    ['sintoma' => 'Quiero asesoría sobre métodos anticonceptivos',             'esperada' => 'Ginecología'],
    ['sintoma' => 'Tengo ardor y molestias íntimas',                           'esperada' => 'Ginecología'],
];
