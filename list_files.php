<?php
/**
 * Script de diagnóstico para listar archivos en el servidor
 * Ayuda a depurar problemas de "Archivo no encontrado"
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Habilitar visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnóstico de Archivos</h1>";
echo "<pre>";

$clientCode = $_GET['code'] ?? 'kino';
echo "Código de cliente: " . htmlspecialchars($clientCode) . "\n";

$baseDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
echo "Directorio base de uploads: " . $baseDir . "\n";

if (!is_dir($baseDir)) {
    echo "❌ ERROR: El directorio base de uploads NO EXISTE.\n";
    echo "Ruta intentada: $baseDir\n";
    echo "Permisos de CLIENTS_DIR (" . CLIENTS_DIR . "): " . substr(sprintf('%o', fileperms(CLIENTS_DIR)), -4) . "\n";
} else {
    echo "✅ El directorio base de uploads EXISTE.\n";

    echo "\n--- Listado de archivos (Recursivo) ---\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $count = 0;
    foreach ($iterator as $item) {
        $count++;
        if ($count > 100) {
            echo "... (demasiados archivos, trunca el listado) ...\n";
            break;
        }

        $subPath = $iterator->getSubPathName();
        $isDir = $item->isDir() ? '[DIR] ' : '';
        echo $isDir . $subPath . "\n";
    }

    if ($count == 0) {
        echo "(El directorio está vacío)\n";
    }
}

echo "\n--- Verificación de rutas de Documentos (DB) ---\n";
try {
    $db = open_client_db($clientCode);
    $stmt = $db->query("SELECT id, ruta_archivo FROM documentos ORDER BY id DESC LIMIT 5");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as $doc) {
        echo "Doc #{$doc['id']} Ruta DB: " . $doc['ruta_archivo'] . "\n";
        $fullPath = $baseDir . $doc['ruta_archivo'];
        if (file_exists($fullPath)) {
            echo "  ✅ Archivo encontrado en: $fullPath\n";
        } else {
            echo "  ❌ Archivo NO encontrado en: $fullPath\n";
        }
    }
} catch (Exception $e) {
    echo "Error conectando a DB: " . $e->getMessage();
}

echo "</pre>";
