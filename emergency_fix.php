<?php
/**
 * SCRIPT DE EMERGENCIA PARA REPARAR BASE DE DATOS
 * Subir a raíz y ejecutar: tusitio.com/emergency_fix.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Desactivar reporte de errores para ver salida limpia
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🛠️ KINO TRACE - REPARACIÓN DE EMERGENCIA</h1>";

if (!isset($_GET['client'])) {
    // Si no hay cliente, intentar reparar todos los que encontremos en la sesión o carpeta
    session_start();
    $clientCode = $_SESSION['client_code'] ?? null;

    if (!$clientCode) {
        // Listar clientes disponibles (filtrando basura)
        $ignored = ['.', '..', 'lost+found', 'logs'];
        $clients = array_diff(scandir(CLIENTS_DIR), $ignored);

        echo "<p>Por favor selecciona el cliente a reparar:</p><ul>";
        foreach ($clients as $c) {
            echo "<li><a href='?client=$c'>Reparar Cliente: $c</a></li>";
        }
        echo "</ul>";
        exit;
    }
} else {
    $clientCode = $_GET['client'];
}

echo "<h3>Analizando cliente: $clientCode</h3>";

try {
    $dbPath = client_db_path($clientCode);
    if (!file_exists($dbPath)) {
        die("❌ El archivo de base de datos no existe: $dbPath");
    }

    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. TAREA: Verificar e Insertar columna 'validado' en 'codigos'
    echo "<p>🔎 Verificando tabla 'codigos'...</p>";

    // Check if column exists
    $cols = $db->query("PRAGMA table_info(codigos)")->fetchAll(PDO::FETCH_ASSOC);
    $hasValidado = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'validado')
            $hasValidado = true;
    }

    if (!$hasValidado) {
        echo "<p>⚠️ Falta columna 'validado'. Intentando agregar...</p>";
        $db->exec("ALTER TABLE codigos ADD COLUMN validado INTEGER DEFAULT 0");
        echo "<p>✅ Columna 'validado' agregada con éxito.</p>";
    } else {
        echo "<p>✅ La columna 'validado' ya existe.</p>";
    }

    // 2. TAREA: Verificar tabla documentos (por si acaso faltan columnas V3)
    echo "<p>🔎 Verificando tabla 'documentos'...</p>";
    $colsDocs = $db->query("PRAGMA table_info(documentos)")->fetchAll(PDO::FETCH_ASSOC);
    $docCols = array_column($colsDocs, 'name');

    $missingDocs = [];
    if (!in_array('hash_archivo', $docCols))
        $missingDocs[] = "ADD COLUMN hash_archivo TEXT";
    if (!in_array('datos_extraidos', $docCols))
        $missingDocs[] = "ADD COLUMN datos_extraidos TEXT";
    if (!in_array('naviera', $docCols))
        $missingDocs[] = "ADD COLUMN naviera TEXT";

    if (!empty($missingDocs)) {
        foreach ($missingDocs as $sql) {
            echo "<p>⚠️ Ejecutando: ALTER TABLE documentos $sql ...</p>";
            $db->exec("ALTER TABLE documentos " . substr($sql, 4)); // Quitar ADD (SQLite usa sintaxis diferente a veces, pero ADD COLUMN es standard)
            // SQLite standard: ALTER TABLE x ADD COLUMN y z
        }
        echo "<p>✅ Tabla documentos actualizada.</p>";
    } else {
        echo "<p>✅ Tabla documentos está completa.</p>";
    }

    echo "<h2>✨ REPARACIÓN COMPLETADA CON ÉXITO ✨</h2>";
    echo "<p><a href='index.php'>[ VOLVER A LA APLICACIÓN ]</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR FATAL:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
