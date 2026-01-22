<?php
/**
 * Configuración de Depuración - KINO TRACE
 * 
 * Este archivo configura modos de depuración seguros que NO afectan
 * el funcionamiento normal de la aplicación.
 * 
 * USO: require_once __DIR__ . '/debug_config.php';
 */

// ==========================================
// CONFIGURACIÓN DE DEBUG POR IP
// ==========================================

// Lista de IPs permitidas para modo debug (agrega la tuya)
$debugIPs = [
    '127.0.0.1',        // Localhost
    '::1',              // Localhost IPv6
    // 'TU_IP_AQUI',    // Descomenta y agrega tu IP real
];

$currentIP = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
$isDebugMode = in_array($currentIP, $debugIPs) || php_sapi_name() === 'cli';

// ==========================================
// ACTIVAR CARACTERÍSTICAS DE DEBUG
// ==========================================

if ($isDebugMode) {
    // Habilitar logging en consola (solo en CLI)
    if (php_sapi_name() === 'cli' && class_exists('Logger')) {
        Logger::enableConsole();
    }

    // Activar modo debug en variables de entorno
    putenv('DEBUG=true');
    putenv('APP_ENV=development');

    // Función global para debug rápido
    if (!function_exists('dd')) {
        /**
         * Debug y Die - Solo en modo debug
         * Muestra variable y detiene ejecución (SOLO para testing local)
         */
        function dd(...$vars)
        {
            if (class_exists('Logger')) {
                Logger::debug('DD called', ['vars' => $vars]);
            }

            header('Content-Type: text/plain');
            echo "=== DEBUG MODE ===\n\n";
            foreach ($vars as $var) {
                var_dump($var);
                echo "\n---\n";
            }
            exit;
        }
    }

    if (!function_exists('debug_log')) {
        /**
         * Log de debug rápido (NO detiene ejecución)
         */
        function debug_log($message, $data = [])
        {
            if (class_exists('Logger')) {
                Logger::debug($message, $data);
            }
        }
    }
} else {
    // En producción, las funciones de debug no hacen nada
    if (!function_exists('dd')) {
        function dd(...$vars)
        {
            // No hace nada en producción
        }
    }

    if (!function_exists('debug_log')) {
        function debug_log($message, $data = [])
        {
            // No hace nada en producción
        }
    }
}

// ==========================================
// INDICADOR DE MODO DEBUG
// ==========================================

if ($isDebugMode && isset($_GET['debug_info'])) {
    // Endpoint seguro para verificar configuración
    // Acceder con: ?debug_info=1
    header('Content-Type: application/json');
    echo json_encode([
        'debug_mode' => true,
        'ip' => $currentIP,
        'sapi' => php_sapi_name(),
        'logger_available' => class_exists('Logger'),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}

// ==========================================
// CONSTANTES DE DEBUG
// ==========================================

define('DEBUG_MODE', $isDebugMode);
define('DEBUG_IP', $currentIP);

/**
 * Verifica si el modo debug está activo
 */
function is_debug_enabled(): bool
{
    return defined('DEBUG_MODE') && DEBUG_MODE === true;
}
