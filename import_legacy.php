<?php
/**
 * Script para importar datos del SQL legado (if0_39064130_buscador) al nuevo sistema SQLite.
 * 
 * USO: Visita /import_legacy.php en el navegador.
 * 
 * Este script:
 * 1. Crea el cliente "kino" si no existe
 * 2. Lee el archivo SQL y extrae los INSERTs de documents y codes
 * 3. Inserta los datos en la base SQLite del cliente kino
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Configuración
$clientCode = 'kino';
$clientName = 'KINO Master';
$defaultPassword = 'kino2025';

// Usar URL de GitHub raw en lugar de archivo local para mantener deploy ligero
$sqlUrl = 'https://raw.githubusercontent.com/WILBIdon/MULTI-CLIEN-KINO-NEW2/main/if0_39064130_buscador%20(10).sql';

echo "<h1>Importación de Datos Legados</h1>";
echo "<pre>";

// 1. Crear cliente kino si no existe
$stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
$stmt->execute([$clientCode]);
$exists = (int) $stmt->fetchColumn() > 0;

if (!$exists) {
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    create_client_structure($clientCode, $clientName, $hash, 'KINO Inventario', '#1e3a8a', '#facc15');
    echo "✅ Cliente '$clientCode' creado con contraseña '$defaultPassword'\n";
} else {
    echo "ℹ️ Cliente '$clientCode' ya existe\n";
}

// 2. Abrir la base de datos del cliente
$clientDb = open_client_db($clientCode);

// 3. Obtener el SQL desde GitHub (o archivo local si existe)
$localFile = __DIR__ . '/if0_39064130_buscador (10).sql';
if (file_exists($localFile)) {
    $sqlContent = file_get_contents($localFile);
    echo "📂 Archivo SQL local cargado (" . round(strlen($sqlContent) / 1024) . " KB)\n";
} else {
    echo "🌐 Descargando SQL desde GitHub...\n";
    $sqlContent = @file_get_contents($sqlUrl);
    if ($sqlContent === false) {
        die("❌ No se pudo descargar el archivo SQL desde: $sqlUrl\nIntenta subir el archivo manualmente o verificar la URL.");
    }
    echo "📂 SQL descargado (" . round(strlen($sqlContent) / 1024) . " KB)\n";
}

// 4. Extraer e insertar documents
echo "\n--- Importando documentos ---\n";
preg_match_all("/INSERT INTO `documents`.*?VALUES\s*(.*?);/s", $sqlContent, $docMatches);

$documentCount = 0;
$documentMap = []; // old_id => new_id

if (!empty($docMatches[1])) {
    $valuesStr = implode(',', $docMatches[1]);
    // Extraer cada registro: (id, 'name', 'date', 'path', NULL/valor)
    preg_match_all("/\((\d+),\s*'([^']*)',\s*'([^']*)',\s*'([^']*)',\s*(NULL|'[^']*')\)/", $valuesStr, $records, PREG_SET_ORDER);

    $insertStmt = $clientDb->prepare(
        "INSERT INTO documentos (tipo, numero, fecha, ruta_archivo, notas) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($records as $rec) {
        $oldId = $rec[1];
        $name = $rec[2];
        $date = $rec[3];
        $path = $rec[4];

        try {
            $insertStmt->execute(['manifiesto', $name, $date, 'uploads/manifiestos/' . $path, 'Importado desde SQL legado']);
            $newId = $clientDb->lastInsertId();
            $documentMap[$oldId] = $newId;
            $documentCount++;
        } catch (Exception $e) {
            echo "⚠️ Error insertando documento $name: " . $e->getMessage() . "\n";
        }
    }
}
echo "✅ $documentCount documentos importados\n";

// 5. Extraer e insertar codes
echo "\n--- Importando códigos ---\n";
preg_match_all("/INSERT INTO `codes`.*?VALUES\s*(.*?);/s", $sqlContent, $codeMatches);

$codeCount = 0;
$skippedCodes = 0;

if (!empty($codeMatches[1])) {
    $valuesStr = implode(',', $codeMatches[1]);
    // Extraer cada registro: (id, document_id, 'code')
    preg_match_all("/\((\d+),\s*(\d+),\s*'([^']*)'\)/", $valuesStr, $records, PREG_SET_ORDER);

    $insertStmt = $clientDb->prepare(
        "INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)"
    );

    foreach ($records as $rec) {
        $oldDocId = $rec[2];
        $code = $rec[3];

        // Mapear al nuevo ID del documento
        if (isset($documentMap[$oldDocId])) {
            $newDocId = $documentMap[$oldDocId];
            try {
                $insertStmt->execute([$newDocId, $code]);
                $codeCount++;
            } catch (Exception $e) {
                echo "⚠️ Error insertando código $code: " . $e->getMessage() . "\n";
            }
        } else {
            $skippedCodes++;
        }
    }
}
echo "✅ $codeCount códigos importados\n";
if ($skippedCodes > 0) {
    echo "⚠️ $skippedCodes códigos omitidos (documento no encontrado)\n";
}

echo "\n=================================\n";
echo "🎉 IMPORTACIÓN COMPLETADA\n";
echo "=================================\n";
echo "Cliente: $clientCode\n";
echo "Documentos: $documentCount\n";
echo "Códigos: $codeCount\n";
echo "\nAhora puedes iniciar sesión con:\n";
echo "  Código: $clientCode\n";
echo "  Contraseña: $defaultPassword\n";
echo "</pre>";
?>