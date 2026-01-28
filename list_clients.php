<?php
require_once __DIR__ . '/config.php';

$dbPath = CLIENTS_DIR . '/central.db';
if (!file_exists($dbPath)) {
    die("Central DB not found at $dbPath\n");
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $stmt = $db->query("SELECT * FROM control_clientes");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Clients found: " . count($clients) . "\n";
    foreach ($clients as $client) {
        echo "- Code: " . $client['codigo'] . " | Name: " . $client['nombre'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
