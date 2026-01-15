<?php
/**
 * Panel de administración para gestionar clientes.
 *
 * Solo los usuarios autenticados como administradores pueden acceder a
 * esta página. Permite crear nuevos clientes, clonar clientes existentes,
 * habilitar/deshabilitar y eliminar clientes. La información de clientes
 * se almacena en la base central de control.
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';

// Verificar que el usuario sea administrador
if (!isset($_SESSION['client_code']) || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $code = sanitize_code($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $password = $_POST['password'] ?? '';
            $titulo = trim($_POST['titulo'] ?? '');
            $colorP = trim($_POST['color_primario'] ?? '#2563eb');
            $colorS = trim($_POST['color_secundario'] ?? '#F87171');
            if ($code === '' || $name === '' || $password === '') {
                $error = 'Debe completar todos los campos obligatorios.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                create_client_structure($code, $name, $hash, $titulo, $colorP, $colorS);
                $message = 'Cliente creado correctamente.';
            }
        } elseif ($action === 'clone') {
            $source = sanitize_code($_POST['source'] ?? '');
            $newCode = sanitize_code($_POST['new_code'] ?? '');
            $newName = trim($_POST['new_name'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            if ($source === '' || $newCode === '' || $newName === '' || $newPassword === '') {
                $error = 'Debe completar todos los campos de clonación.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                clone_client($source, $newCode, $newName, $hash);
                $message = 'Cliente clonado correctamente.';
            }
        } elseif ($action === 'toggle') {
            $toggleCode = sanitize_code($_POST['toggle_code'] ?? '');
            if ($toggleCode !== '') {
                $stmt = $centralDb->prepare('SELECT activo FROM control_clientes WHERE codigo = ?');
                $stmt->execute([$toggleCode]);
                $curr = (int)$stmt->fetchColumn();
                $new = $curr ? 0 : 1;
                $update = $centralDb->prepare('UPDATE control_clientes SET activo = ? WHERE codigo = ?');
                $update->execute([$new, $toggleCode]);
                $message = $new ? 'Cliente habilitado.' : 'Cliente deshabilitado.';
            }
        } elseif ($action === 'delete') {
            $delCode = sanitize_code($_POST['delete_code'] ?? '');
            if ($delCode !== '') {
                // Borrar el registro de la base de control
                $delStmt = $centralDb->prepare('DELETE FROM control_clientes WHERE codigo = ?');
                $delStmt->execute([$delCode]);
                // Eliminar el directorio del cliente junto con su base de datos
                $dir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $delCode;
                if (is_dir($dir)) {
                    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
                    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($files as $file) {
                        if ($file->isDir()) {
                            rmdir($file->getRealPath());
                        } else {
                            unlink($file->getRealPath());
                        }
                    }
                    rmdir($dir);
                }
                $message = 'Cliente eliminado.';
            }
        }
    }
} catch (Exception $ex) {
    $error = 'Ocurrió un error: ' . $ex->getMessage();
}

// Obtener la lista de clientes para mostrar y para clonar
$clients = $centralDb->query('SELECT codigo, nombre, activo FROM control_clientes ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$clientCodes = array_map(function ($c) {
    return $c['codigo'];
}, $clients);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel de Administración</title>
<link rel="stylesheet" href="../assets/css/styles.css">
<style>
.admin-container { max-width: 800px; margin: 2rem auto; padding: 1rem; }
.admin-container h1 { margin-bottom: 1rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
th, td { padding: 0.5rem; border: 1px solid #ddd; text-align: left; }
.status-active { color: green; font-weight: bold; }
.status-inactive { color: red; font-weight: bold; }
.form-section { margin-bottom: 2rem; padding: 1rem; border: 1px solid #ddd; border-radius: 8px; }
.form-section h2 { margin-bottom: 0.5rem; }
.form-row { margin-bottom: 0.75rem; }
.form-row label { display: block; margin-bottom: 0.25rem; font-weight: bold; }
.form-row input, .form-row select { width: 100%; padding: 0.5rem; }
button { padding: 0.5rem 1rem; border: none; background: #2563eb; color: white; border-radius: 4px; cursor: pointer; }
button:hover { background: #1d4ed8; }
.message { background: #dcfce7; color: #065f46; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
.error { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="admin-container">
    <h1>Panel de Administración</h1>
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <h2>Clientes Registrados</h2>
    <table>
        <thead>
            <tr><th>Código</th><th>Nombre</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $cli): ?>
            <tr>
                <td><?= htmlspecialchars($cli['codigo']) ?></td>
                <td><?= htmlspecialchars($cli['nombre']) ?></td>
                <td class="<?= $cli['activo'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $cli['activo'] ? 'Activo' : 'Inactivo' ?>
                </td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="toggle_code" value="<?= htmlspecialchars($cli['codigo']) ?>">
                        <button type="submit"><?= $cli['activo'] ? 'Deshabilitar' : 'Habilitar' ?></button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Está seguro de eliminar este cliente?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="delete_code" value="<?= htmlspecialchars($cli['codigo']) ?>">
                        <button type="submit">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="form-section">
        <h2>Crear Nuevo Cliente</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <label for="code">Código *</label>
                <input type="text" id="code" name="code" required>
            </div>
            <div class="form-row">
                <label for="name">Nombre *</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-row">
                <label for="password">Contraseña *</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-row">
                <label for="titulo">Título</label>
                <input type="text" id="titulo" name="titulo">
            </div>
            <div class="form-row">
                <label for="color_primario">Color primario</label>
                <input type="color" id="color_primario" name="color_primario" value="#2563eb">
            </div>
            <div class="form-row">
                <label for="color_secundario">Color secundario</label>
                <input type="color" id="color_secundario" name="color_secundario" value="#F87171">
            </div>
            <button type="submit">Crear cliente</button>
        </form>
    </div>

    <div class="form-section">
        <h2>Clonar Cliente</h2>
        <form method="post">
            <input type="hidden" name="action" value="clone">
            <div class="form-row">
                <label for="source">Cliente origen *</label>
                <select id="source" name="source" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($clientCodes as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="new_code">Nuevo código *</label>
                <input type="text" id="new_code" name="new_code" required>
            </div>
            <div class="form-row">
                <label for="new_name">Nuevo nombre *</label>
                <input type="text" id="new_name" name="new_name" required>
            </div>
            <div class="form-row">
                <label for="new_password">Contraseña *</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <button type="submit">Clonar cliente</button>
        </form>
    </div>
    <p><a href="../modules/trazabilidad/dashboard.php">← Volver al tablero</a> | <a href="../logout.php">Cerrar sesión</a></p>
</div>
</body>
</html>