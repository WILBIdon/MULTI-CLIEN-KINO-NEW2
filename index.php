<?php
/**
 * Punto de entrada de la aplicación.
 *
 * Si el usuario ya está autenticado (es decir, existe un código de cliente
 * en la sesión), redirige directamente al módulo principal. De lo
 * contrario, carga la página de login.
 */

session_start();
require_once __DIR__ . '/config.php';

// Si el usuario está logueado, redirigimos al listado de documentos
if (isset($_SESSION['client_code'])) {
    // Redirigimos al tablero de trazabilidad que actúa como página de inicio.
    header('Location: modules/trazabilidad/dashboard.php');
    exit;
}

// De lo contrario, mostrar login
header('Location: login.php');
exit;

?>