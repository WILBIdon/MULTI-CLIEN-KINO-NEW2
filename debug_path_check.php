<?php
// Debug script to check why Doc 117 is not found
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Mock session for CLI
if (php_sapi_name() === 'cli') {
    $_SESSION['client_code'] = 'kino'; // Default to kino for testing
} else {
    session_start();
}

$clientCode = $_SESSION['client_code'];
echo "Client Code: $clientCode\n";

try {
    $db = open_client_db($clientCode);

    // Check Doc 117
    $docId = 117;
    $stmt = $db->prepare("SELECT * FROM documentos WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        die("Document $docId not found in DB.\n");
    }

    echo "Document $docId found:\n";
    echo "  Type: " . $doc['tipo'] . "\n";
    echo "  Number: " . $doc['numero'] . "\n";
    echo "  Ruta Archivo (DB): " . $doc['ruta_archivo'] . "\n";

    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
    echo "  Uploads Dir: $uploadsDir\n";

    // logic from download.php
    $rutaArchivo = $doc['ruta_archivo'];
    $filename = basename($rutaArchivo);
    $type = strtolower($doc['tipo']);
    $folders = [$type, $type . 's', $type . 'es'];
    $folders = array_unique($folders);

    echo "\nTesting Path Resolution Logic:\n";

    $tried = [];

    // 1. Exact DB path
    $path = $uploadsDir . $rutaArchivo;
    $exists = file_exists($path);
    echo "  [1] Try exact: $path -> " . ($exists ? "FOUND" : "NOT FOUND") . "\n";

    // 2. Folders
    foreach ($folders as $folder) {
        if (!empty($folder)) {
            // Folder + filename
            $p1 = $uploadsDir . $folder . '/' . $filename;
            $e1 = file_exists($p1);
            echo "  [2] Try folder '$folder' + filename: $p1 -> " . ($e1 ? "FOUND" : "NOT FOUND") . "\n";

            // Folder + full path (if different)
            if ($rutaArchivo !== $filename) {
                $p2 = $uploadsDir . $folder . '/' . $rutaArchivo;
                $e2 = file_exists($p2);
                echo "  [2] Try folder '$folder' + path: $p2 -> " . ($e2 ? "FOUND" : "NOT FOUND") . "\n";
            }
        }
    }

    // 3. Root
    $pRoot = $uploadsDir . $filename;
    $eRoot = file_exists($pRoot);
    echo "  [3] Try root: $pRoot -> " . ($eRoot ? "FOUND" : "NOT FOUND") . "\n";

    echo "\nDirectory Listing of Uploads:\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        echo "  " . $item->getSubPathName() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
