<?php
/**
 * Script de migración para importar datos de KINO desde MySQL dump
 * 
 * Este script convierte el dump de MySQL (if0_39064130_buscador) 
 * al formato SQLite usado por la aplicación.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Verificar admin
if (!isset($_SESSION['client_code']) || empty($_SESSION['is_admin'])) {
    die('Acceso denegado. Debe ser administrador.');
}

$targetClient = $_GET['client'] ?? 'kino';
$sqlFile = __DIR__ . '/if0_39064130_buscador (10).sql';

if (!file_exists($sqlFile)) {
    die('Archivo SQL no encontrado: ' . $sqlFile);
}

echo "<h2>Migración de datos KINO</h2>";
echo "<p>Cliente destino: <strong>$targetClient</strong></p>";

// Leer archivo SQL
$sqlContent = file_get_contents($sqlFile);

// Extraer datos de documents
preg_match_all('/INSERT INTO `documents`.*?VALUES\s*(.*?);/s', $sqlContent, $docMatches);
// Extraer datos de codes
preg_match_all('/INSERT INTO `codes`.*?VALUES\s*(.*?);/s', $sqlContent, $codeMatches);

// Abrir BD del cliente
$db = open_client_db($targetClient);

// Crear tablas si no existen
$db->exec("CREATE TABLE IF NOT EXISTS documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL DEFAULT 'documento',
    numero TEXT NOT NULL,
    fecha DATE NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    proveedor TEXT,
    naviera TEXT,
    peso_kg REAL,
    ruta_archivo TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS codigos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_id INTEGER NOT NULL,
    codigo TEXT NOT NULL,
    FOREIGN KEY (documento_id) REFERENCES documentos(id)
)");

echo "<p>Tablas creadas/verificadas ✓</p>";

// Procesar documentos
$docCount = 0;
$codeCount = 0;
$idMapping = []; // old_id => new_id

if (!empty($docMatches[1])) {
    $allDocValues = implode(',', $docMatches[1]);
    // Parse individual records: (id, 'title', 'date', 'file', null)
    preg_match_all('/\((\d+),\s*\'([^\']*)\',\s*\'([^\']*)\',\s*\'([^\']*)\',\s*NULL\)/s', $allDocValues, $docs);

    if (!empty($docs[0])) {
        $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo) VALUES (?, ?, ?, ?)");

        foreach ($docs[0] as $i => $match) {
            $oldId = $docs[1][$i];
            $title = $docs[2][$i];
            $date = $docs[3][$i];
            $file = $docs[4][$i];

            try {
                $stmtDoc->execute(['documento', $title, $date, $file]);
                $newId = $db->lastInsertId();
                $idMapping[$oldId] = $newId;
                $docCount++;
            } catch (Exception $e) {
                // Ignorar duplicados
            }
        }
    }
}

echo "<p>Documentos importados: <strong>$docCount</strong> ✓</p>";

// Procesar códigos
if (!empty($codeMatches[1])) {
    $allCodeValues = implode(',', $codeMatches[1]);
    // Parse: (id, document_id, 'code')
    preg_match_all('/\((\d+),\s*(\d+),\s*\'([^\']*)\'\)/s', $allCodeValues, $codes);

    if (!empty($codes[0])) {
        $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");

        foreach ($codes[0] as $i => $match) {
            $oldDocId = $codes[2][$i];
            $code = $codes[3][$i];

            // Usar el nuevo ID mapeado o el original si no existe mapeo
            $newDocId = $idMapping[$oldDocId] ?? $oldDocId;

            try {
                $stmtCode->execute([$newDocId, $code]);
                $codeCount++;
            } catch (Exception $e) {
                // Ignorar errores
            }
        }
    }
}

echo "<p>Códigos importados: <strong>$codeCount</strong> ✓</p>";
echo "<hr>";
echo "<p style='color: green; font-weight: bold;'>✅ Migración completada exitosamente</p>";
echo "<p><a href='modules/trazabilidad/dashboard.php'>Ir al Dashboard</a> | <a href='admin/panel.php'>Panel Admin</a></p>";
?>