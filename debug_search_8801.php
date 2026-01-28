<?php
/**
 * Debug Search 8801
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

// Simulate session for client code (assuming 'kino' or similar based on previous context, but will try to find first DB)
// In a real scenario we'd need the client code. I'll check the 'clients' directory.

$clients = glob(CLIENTS_DIR . '/*', GLOB_ONLYDIR);
if (empty($clients)) {
    die("No clients found.\n");
}

$clientCode = basename($clients[0]); // Take the first client
echo "Checking client: $clientCode\n";

try {
    $db = open_client_db($clientCode);

    $searchTerm = '8801';
    echo "Searching for code matching '%$searchTerm%'\n\n";

    $stmt = $db->prepare("
        SELECT
            d.id, d.numero, d.tipo, d.fecha,
            c.codigo
        FROM documentos d
        JOIN codigos c ON d.id = c.documento_id
        WHERE UPPER(c.codigo) LIKE UPPER(?)
        ORDER BY d.fecha DESC
    ");

    $stmt->execute(['%' . $searchTerm . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        echo "Doc ID: " . $row['id'] . "\n";
        echo "Numero: " . $row['numero'] . "\n";
        echo "Matched Code: [" . $row['codigo'] . "]\n\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
