<?php
// tokens.php  -  Generación y validación de tokens de correo (#15).
// Compartido por la recuperación de contraseña ('reset') y la verificación
// de correo ('verify'). Reglas de seguridad:
//   - Token aleatorio de 256 bits (random_bytes) -> imposible de adivinar.
//   - En la BD se guarda solo el SHA-256 del token, nunca el token en claro.
//   - Expira (por defecto 30 min) y es de un solo uso ('usado').
//   - Al crear uno nuevo se invalidan los anteriores del mismo tipo.

require_once __DIR__ . '/db.php';

/**
 * Crea un token para un usuario y devuelve el token EN CLARO (va en el enlace).
 * En la BD queda solo su hash.
 */
function crear_token(PDO $db, int $usuarioId, string $tipo = 'reset', int $minutos = 30): string
{
    // Invalida tokens previos del mismo tipo (evita que queden varios activos).
    $st = $db->prepare("UPDATE tokens_correo SET usado = 1 WHERE usuario_id = ? AND tipo = ? AND usado = 0");
    $st->execute([$usuarioId, $tipo]);

    $token = bin2hex(random_bytes(32));          // 64 caracteres hex
    $hash  = hash('sha256', $token);
    $expira = (new DateTime("+$minutos minutes"))->format('Y-m-d H:i:s');

    $st = $db->prepare(
        "INSERT INTO tokens_correo (usuario_id, token_hash, tipo, expira, usado)
         VALUES (?, ?, ?, ?, 0)"
    );
    $st->execute([$usuarioId, $hash, $tipo, $expira]);

    return $token;
}

/**
 * Valida un token del enlace. Devuelve la fila (id, usuario_id) si es válido,
 * no está usado y no ha expirado; null en cualquier otro caso.
 */
function validar_token(PDO $db, string $token, string $tipo = 'reset'): ?array
{
    if ($token === '' || !ctype_xdigit($token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $st = $db->prepare(
        "SELECT id, usuario_id FROM tokens_correo
         WHERE token_hash = ? AND tipo = ? AND usado = 0 AND expira > NOW()
         LIMIT 1"
    );
    $st->execute([$hash, $tipo]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Marca un token como usado (un solo uso). */
function marcar_token_usado(PDO $db, int $tokenId): void
{
    $st = $db->prepare("UPDATE tokens_correo SET usado = 1 WHERE id = ?");
    $st->execute([$tokenId]);
}
