<?php
/**
 * Resolves the correct path for a document and redirects to the static file.
 * Used for "Original" / "Download" buttons to handle variable directory structures.
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    die('Acceso denegado');
}

$clientCode = $_SESSION['client_code'];

// Check if requesting database download
if (isset($_GET['type']) && $_GET['type'] === 'database') {
    $centralDbPath = CENTRAL_DB;

    if (!file_exists($centralDbPath)) {
        die('Base de datos central no encontrada');
    }

    if (!class_exists('ZipArchive')) {
        die('La extensión ZipArchive no está habilitada en este servidor.');
    }

    $zip = new ZipArchive();
    $timestamp = date('Y-m-d_H-i-s');
    $zipFilename = "backup_completo_{$timestamp}.zip";
    $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipFilename;

    if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("No se pudo crear el archivo ZIP temporal");
    }

    // 1. Agregar la base de datos central
    $zip->addFile($centralDbPath, 'central.db');

    // 2. Agregar las bases de datos de cada cliente
    if (is_dir(CLIENTS_DIR)) {
        $clients = scandir(CLIENTS_DIR);
        foreach ($clients as $clientCode) {
            if ($clientCode === '.' || $clientCode === '..')
                continue;

            $clientDirPath = CLIENTS_DIR . DIRECTORY_SEPARATOR . $clientCode;

            // Verificamos si es un directorio de cliente válido
            if (is_dir($clientDirPath)) {
                // La DB del cliente debería tener el mismo nombre que la carpeta + .db
                // Usamos la función helper si queremos estar seguros, pero aquí podemos construir la ruta
                $clientDbPath = $clientDirPath . DIRECTORY_SEPARATOR . $clientCode . '.db';

                if (file_exists($clientDbPath)) {
                    // La agregamos al zip dentro de una carpeta 'clientes/' para orden
                    $zip->addFile($clientDbPath, "clientes/{$clientCode}.db");
                }
            }
        }
    }

    $zip->close();

    // Send zip file to browser
    if (file_exists($tempZipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($tempZipPath);

        // Eliminar el archivo temporal después de enviarlo
        unlink($tempZipPath);
        exit;
    } else {
        die("Error al generar el archivo ZIP.");
    }
}

$db = open_client_db($clientCode);

$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;

if ($documentId <= 0) {
    die('ID de documento inválido');
}

// Get document info
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$rutaArchivo = $document['ruta_archivo'];

// --- Centralized Path Resolution ---
$pdfPath = resolve_pdf_path($clientCode, $document);

if (!$pdfPath) {
    $folders = get_available_folders($clientCode);
    $foldersStr = implode(', ', $folders);
    die("Archivo PDF no encontrado en el servidor.<br>Rutas revisadas automáticamente.<br>Carpetas disponibles: $foldersStr");
}

// Calculate relative path for URL
// Note: We need the relative URL from the webroot.
// Assuming this script is in /modules/resaltar/
// And uploads are in /clients/CODE/uploads/
// We need to return ../../clients/CODE/uploads/RELATIVE_PATH

// $uploadsDir is absolute path e.g. C:\...\clients\code\uploads\
// $pdfPath is absolute path e.g. C:\...\clients\code\uploads\manifiestos\file.pdf

// Get the part after uploads/
$relativePath = substr($pdfPath, strlen($uploadsDir));
// Ensure no leading slash issues
$relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

// Redirect to the static file
$redirectUrl = "../../clients/{$clientCode}/uploads/{$relativePath}";

header("Location: $redirectUrl");
exit;
