<?php
// debug_fs.php
require_once 'config.php';
session_start();

header('Content-Type: text/plain');

$clientCode = $_SESSION['client_code'] ?? 'kino'; // Default or from session
echo "Debug File System Access\n";
echo "========================\n\n";

echo "Client Code: " . $clientCode . "\n";
echo "BASE_DIR: " . BASE_DIR . "\n";
echo "CLIENTS_DIR: " . CLIENTS_DIR . "\n\n";

$clientDir = CLIENTS_DIR . '/' . $clientCode;
$uploadsDir = $clientDir . '/uploads';

echo "Checking Paths:\n";
echo "Client Dir: $clientDir -> " . (is_dir($clientDir) ? "EXISTS" : "MISSING") . "\n";
echo "Uploads Dir: $uploadsDir -> " . (is_dir($uploadsDir) ? "EXISTS" : "MISSING") . "\n\n";

if (is_dir($uploadsDir)) {
    echo "Listing contents of Uploads Dir:\n";
    $files = scandir($uploadsDir);
    foreach ($files as $f) {
        if ($f == '.' || $f == '..')
            continue;
        $path = $uploadsDir . '/' . $f;
        $type = is_dir($path) ? "[DIR] " : "[FILE]";
        echo "  $type $f\n";

        if (is_dir($path)) {
            $subfiles = scandir($path);
            foreach ($subfiles as $sf) {
                if ($sf == '.' || $sf == '..')
                    continue;
                echo "      - $sf\n";
            }
        }
    }
} else {
    echo "Cannot list uploads: Directory not found.\n";
}

echo "\nGlobal GLOB Check:\n";
$globCheck = glob($uploadsDir . '/*', GLOB_ONLYDIR);
print_r($globCheck);
