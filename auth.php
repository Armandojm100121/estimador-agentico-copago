<?php
// auth.php  -  Utilidades de sesión y autenticación.
// Se incluye en las páginas que requieren un usuario logueado.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** ¿Hay un usuario con sesión iniciada? */
function estaLogueado(): bool {
    return !empty($_SESSION['user_id']);
}

/** Datos básicos del usuario logueado (o null). */
function usuarioActual(): ?array {
    if (!estaLogueado()) {
        return null;
    }
    return [
        'id'             => $_SESSION['user_id'],
        'nombre'         => $_SESSION['user_nombre'] ?? '',
        'email'          => $_SESSION['user_email'] ?? '',
        'plan_id'        => $_SESSION['plan_id'] ?? null,
        'plan_etiqueta'  => $_SESSION['plan_etiqueta'] ?? '',
    ];
}

/** Redirige al login si no hay sesión. Úsalo al inicio de páginas protegidas. */
function requiereLogin(): void {
    if (!estaLogueado()) {
        header('Location: login.php');
        exit;
    }
}

/** ¿El usuario logueado es administrador? (consulta la BD; refleja cambios al instante) */
function esAdmin(): bool {
    if (!estaLogueado()) {
        return false;
    }
    if (!function_exists('getDB')) {
        require_once __DIR__ . '/db.php';
    }
    try {
        $st = getDB()->prepare("SELECT es_admin FROM usuarios WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;   // si la columna aún no existe, nadie es admin
    }
}

/** Bloquea el acceso si el usuario no es administrador. Para páginas de gestión. */
function requiereAdmin(): void {
    requiereLogin();
    if (!esAdmin()) {
        http_response_code(403);
        exit('Acceso restringido: solo administradores.');
    }
}

/**
 * Deja la sesión lista para un usuario recién autenticado.
 * Guarda también el plan en las claves que espera chat.php
 * (plan_id y plan_etiqueta), para que el chat funcione sin volver a elegir plan.
 */
function iniciarSesionUsuario(array $u): void {
    // Regenerar el id de sesión evita el "session fixation"
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int) $u['id'];
    $_SESSION['user_nombre']   = $u['nombre'];
    $_SESSION['user_email']    = $u['email'];
    if (!empty($u['plan_id'])) {
        $_SESSION['plan_id']       = (int) $u['plan_id'];
        $_SESSION['plan_etiqueta'] = $u['plan_etiqueta'] ?? '';
    }
}

/** Iniciales para el avatar (p. ej. "María Cedeño" -> "MC"). */
function iniciales(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre));
    $ini = '';
    foreach ($partes as $p) {
        if ($p !== '' && mb_strlen($ini) < 2) {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    return $ini ?: 'U';
}
