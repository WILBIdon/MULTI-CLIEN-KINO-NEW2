<?php
/**
 * Script de migración inicial.
 *
 * Este archivo crea un cliente administrador si aún no existe. Se puede
 * ejecutar una única vez al configurar la aplicación por primera vez.
 * 
 * También aplica optimizaciones de índices a las bases de datos de clientes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

/**
 * Apply performance indexes to a client database
 */
function apply_indexes(PDO $db): array
{
    $indexes = [
        'idx_codigos_codigo' => 'CREATE INDEX IF NOT EXISTS idx_codigos_codigo ON codigos(codigo)',
        'idx_codigos_documento' => 'CREATE INDEX IF NOT EXISTS idx_codigos_documento ON codigos(documento_id)',
        'idx_documentos_numero' => 'CREATE INDEX IF NOT EXISTS idx_documentos_numero ON documentos(numero)',
        'idx_documentos_fecha' => 'CREATE INDEX IF NOT EXISTS idx_documentos_fecha ON documentos(fecha)',
        'idx_documentos_tipo' => 'CREATE INDEX IF NOT EXISTS idx_documentos_tipo ON documentos(tipo)',
        'idx_codigos_validado' => 'CREATE INDEX IF NOT EXISTS idx_codigos_validado ON codigos(validado)'
    ];

    $applied = [];
    foreach ($indexes as $name => $sql) {
        try {
            $db->exec($sql);
            $applied[] = $name;
        } catch (Exception $e) {
            // Index might already exist or column missing, continue
        }
    }

    return $applied;
}

// Admin creation
$adminCode = 'admin';
$adminName = 'Administrador';
$defaultPassword = 'admin123';
$stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
$stmt->execute([$adminCode]);
$exists = (int) $stmt->fetchColumn() > 0;
if (!$exists) {
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    create_client_structure($adminCode, $adminName, $hash, 'Administración', '#111827', '#10b981');
    echo "Admin creado con contraseña predeterminada '{$defaultPassword}'. Cambie la contraseña al iniciar sesión.\n";
} else {
    echo "El cliente administrador ya existe.\n";
}

// Apply indexes to all clients
echo "\n--- Aplicando índices de optimización ---\n";
$stmt = $centralDb->query('SELECT codigo FROM control_clientes');
$clients = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($clients as $code) {
    $db = open_client_db($code);
    $applied = apply_indexes($db);
    echo "Cliente '{$code}': " . count($applied) . " índices aplicados\n";
}

echo "\n✅ Migración completada.\n";
