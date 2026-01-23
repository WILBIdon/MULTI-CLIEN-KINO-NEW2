<?php
/**
 * Script de Optimización de Base de Datos
 * 
 * Crea índices en las tablas principales para mejorar
 * el rendimiento de las búsquedas frecuentes.
 * 
 * Uso: php optimize_db.php [client_code]
 */

require_once __DIR__ . '/autoload.php';

function optimize_client_db($clientCode)
{
    try {
        $db = open_client_db($clientCode);

        echo "🔧 Optimizando base de datos para cliente: $clientCode\n";

        // Crear índices en tabla documentos
        $indices = [
            'CREATE INDEX IF NOT EXISTS idx_documentos_tipo ON documentos(tipo)',
            'CREATE INDEX IF NOT EXISTS idx_documentos_numero ON documentos(numero)',
            'CREATE INDEX IF NOT EXISTS idx_documentos_fecha ON documentos(fecha)',
            'CREATE INDEX IF NOT EXISTS idx_documentos_hash ON documentos(hash_archivo)',
            'CREATE INDEX IF NOT EXISTS idx_documentos_estado ON documentos(estado)',

            // Índices en tabla codigos
            'CREATE INDEX IF NOT EXISTS idx_codigos_codigo ON codigos(codigo)',
            'CREATE INDEX IF NOT EXISTS idx_codigos_documento_id ON codigos(documento_id)',
            'CREATE INDEX IF NOT EXISTS idx_codigos_validado ON codigos(validado)',

            // Índices en tabla vinculos
            'CREATE INDEX IF NOT EXISTS idx_vinculos_origen ON vinculos(documento_origen_id)',
            'CREATE INDEX IF NOT EXISTS idx_vinculos_destino ON vinculos(documento_destino_id)',
            'CREATE INDEX IF NOT EXISTS idx_vinculos_tipo ON vinculos(tipo_vinculo)'
        ];

        $created = 0;
        foreach ($indices as $sql) {
            try {
                $db->exec($sql);
                $created++;
                echo "  ✓ Índice creado\n";
            } catch (PDOException $e) {
                echo "  ⚠ Ya existe o error: " . $e->getMessage() . "\n";
            }
        }

        // Ejecutar ANALYZE para actualizar estadísticas
        echo "\n📊 Actualizando estadísticas de la base de datos...\n";
        $db->exec('ANALYZE');

        // Ejecutar VACUUM para optimizar espacio
        echo "🗜️  Optimizando espacio en disco...\n";
        $db->exec('VACUUM');

        echo "\n✅ Optimización completada. Índices creados: $created\n";

        // Mostrar estadísticas
        $stats = [
            'Documentos' => $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn(),
            'Códigos' => $db->query('SELECT COUNT(*) FROM codigos')->fetchColumn(),
            'Vínculos' => $db->query('SELECT COUNT(*) FROM vinculos')->fetchColumn(),
        ];

        echo "\n📈 Estadísticas:\n";
        foreach ($stats as $table => $count) {
            echo "  - $table: " . number_format($count) . "\n";
        }

        // Mostrar tamaño de la base de datos
        $dbPath = client_db_path($clientCode);
        if (file_exists($dbPath)) {
            $size = filesize($dbPath);
            $sizeFormatted = formatBytes($size);
            echo "  - Tamaño DB: $sizeFormatted\n";
        }

        return true;

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        Logger::exception($e, ['client' => $clientCode, 'action' => 'optimize_db']);
        return false;
    }
}

function optimize_all_clients()
{
    global $centralDb;

    echo "🔍 Buscando todos los clientes...\n\n";

    $stmt = $centralDb->query('SELECT codigo FROM control_clientes WHERE activo = 1');
    $clients = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($clients)) {
        echo "⚠ No se encontraron clientes activos\n";
        return;
    }

    echo "📋 Se optimizarán " . count($clients) . " clientes\n\n";

    $success = 0;
    $failed = 0;

    foreach ($clients as $clientCode) {
        echo str_repeat('=', 60) . "\n";
        if (optimize_client_db($clientCode)) {
            $success++;
        } else {
            $failed++;
        }
        echo "\n";
    }

    echo str_repeat('=', 60) . "\n";
    echo "📊 Resumen:\n";
    echo "  ✅ Exitosos: $success\n";
    echo "  ❌ Fallidos: $failed\n";
}

function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

// Ejecutar desde línea de comandos
if (php_sapi_name() === 'cli') {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║   KINO TRACE - Optimización de Base de Datos              ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";

    if (isset($argv[1])) {
        // Optimizar cliente específico
        $clientCode = $argv[1];
        optimize_client_db($clientCode);
    } else {
        // Optimizar todos los clientes
        optimize_all_clients();
    }

    echo "\n✨ Proceso completado\n";
}
