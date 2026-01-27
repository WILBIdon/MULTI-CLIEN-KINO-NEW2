<?php
// debug_db_status.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Mock session if needed or just use default/first client
if (session_status() == PHP_SESSION_NONE)
    session_start();
// Simulating a logged in state if keys are missing (or just grabbing the first DB found)
$clientCode = $_SESSION['client_code'] ?? null;

if (!$clientCode) {
    // Fallback: finding first client dir
    $clients = glob(CLIENTS_DIR . '/*', GLOB_ONLYDIR);
    if (!empty($clients)) {
        $clientCode = basename($clients[0]);
        echo "⚠️ No session found. Using first client found: $clientCode\n\n";
    } else {
        die("❌ No clients found in " . CLIENTS_DIR);
    }
}

try {
    $db = open_client_db($clientCode);

    echo "📊 DATABASE STATUS REPORT (Client: $clientCode)\n";
    echo "=================================================\n";

    // 1. Counts
    $docCount = $db->query("SELECT count(*) FROM documentos")->fetchColumn();
    $codeCount = $db->query("SELECT count(*) FROM codigos")->fetchColumn();
    $linkCount = $db->query("SELECT count(*) FROM vinculos")->fetchColumn();

    echo "Documentos: $docCount\n";
    echo "Códigos:    $codeCount\n";
    echo "Vínculos:   $linkCount\n\n";

    // 2. Check Codes Linkage
    if ($codeCount > 0) {
        echo "🧐 Inspecting First 10 Codes:\n";
        echo str_pad("ID", 6) . str_pad("DOC_ID", 10) . str_pad("CODIGO", 20) . "PARENT_AVAILABLE?\n";
        echo str_repeat("-", 50) . "\n";

        $stmt = $db->query("SELECT * FROM codigos LIMIT 10");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $docId = $row['documento_id'];
            $exists = $db->query("SELECT count(*) FROM documentos WHERE id = $docId")->fetchColumn();
            $status = $exists ? "✅ Yes" : "❌ ORPHANED";

            echo str_pad($row['id'], 6) . str_pad($docId, 10) . str_pad(substr($row['codigo'], 0, 18), 20) . "$status\n";
        }
    } else {
        echo "❌ No codes found in table 'codigos'. IMPORT FAILED.\n";
    }

    // 3. Check Document Columns (Verification of 'original_path')
    echo "\n🧐 Inspecting First 5 Documents (Path Info):\n";
    $stmt = $db->query("SELECT id, numero, original_path, ruta_archivo FROM documentos LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
