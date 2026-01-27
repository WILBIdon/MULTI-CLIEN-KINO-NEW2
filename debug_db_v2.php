<?php
// debug_db_v2.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$baseDir = __DIR__;
$clientsDir = $baseDir . '/clients';

echo "🔍 Searching for databases in: $clientsDir\n";

if (!is_dir($clientsDir)) {
    die("❌ Clients directory not found.\n");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($clientsDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$dbPath = null;
foreach ($files as $file) {
    if ($file->getExtension() === 'db') {
        $dbPath = $file->getPathname();
        echo "✅ Database found: $dbPath\n";
        break; // Take the first one found
    }
}

if (!$dbPath) {
    die("❌ No .db file found in clients directory.\n");
}

try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "\n📊 DB Statistics:\n";
    $tables = ['documentos', 'codigos', 'vinculos'];
    foreach ($tables as $t) {
        try {
            $count = $db->query("SELECT count(*) FROM $t")->fetchColumn();
            echo " - $t: \t$count rows\n";
        } catch (Exception $e) {
            echo " - $t: \t❌ Table not found\n";
        }
    }

    echo "\n🧐 Sample 'codigos' (First 5):\n";
    $stmt = $db->query("SELECT * FROM codigos LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "   (Table is empty)\n";
    } else {
        foreach ($rows as $r) {
            print_r($r);
            // Verify link
            if (isset($r['documento_id'])) {
                $pid = $r['documento_id'];
                $exists = $db->query("SELECT count(*) FROM documentos WHERE id = '$pid'")->fetchColumn();
                echo "   -> Linked to Doc ID $pid? " . ($exists ? "YES" : "NO (ORPHAN)") . "\n";
            }
        }
    }

} catch (Exception $e) {
    echo "❌ Fatal DB Error: " . $e->getMessage() . "\n";
}
