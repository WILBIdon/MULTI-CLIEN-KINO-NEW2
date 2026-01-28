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

// --- Path Resolution Logic (Same as viewer.php) ---
$pdfPath = null;
$filename = basename($rutaArchivo);
$type = strtolower($document['tipo']);
$folders = [$type, $type . 's', $type . 'es'];
$folders = array_unique($folders);

$possiblePaths = [];
$possiblePaths[] = $uploadsDir . $rutaArchivo;

foreach ($folders as $folder) {
    if (!empty($folder)) {
        $possiblePaths[] = $uploadsDir . $folder . '/' . $filename;
        if ($rutaArchivo !== $filename) {
            $possiblePaths[] = $uploadsDir . $folder . '/' . $rutaArchivo;
        }
    }
}
$possiblePaths[] = $uploadsDir . $filename; // Root fallback

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $pdfPath = $path;
        break;
    }
}

if (!$pdfPath) {
    die('Archivo PDF no encontrado en el servidor.');
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
