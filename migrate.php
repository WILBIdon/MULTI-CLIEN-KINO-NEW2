<?php
/**
 * Script de migración inicial.
 *
 * Este archivo crea un cliente administrador si aún no existe. Se puede
 * ejecutar una única vez al configurar la aplicación por primera vez.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

$adminCode = 'admin';
$adminName = 'Administrador';
$defaultPassword = 'admin123';
$stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
$stmt->execute([$adminCode]);
$exists = (int) $stmt->fetchColumn() > 0;
if (!$exists) {
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    create_client_structure($adminCode, $adminName, $hash, 'Administración', '#111827', '#10b981');
    echo "Admin creado con contraseña predeterminada '{$defaultPassword}'. Cambie la contraseña al iniciar sesión.";
} else {
    echo "El cliente administrador ya existe.";
}
