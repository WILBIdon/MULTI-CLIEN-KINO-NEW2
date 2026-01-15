<?php
/**
 * Página de autenticación.
 *
 * Presenta un formulario para seleccionar un cliente (por código) y
 * proporcionar la contraseña. Si la autenticación es correcta, se
 * almacena el código de cliente en la sesión y se redirige al módulo
 * principal.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = sanitize_code($_POST['codigo'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($codigo === '' || $password === '') {
        $error = 'Debe escribir un código y una contraseña.';
    } else {
        // Buscar el cliente en la base central
        $stmt = $centralDb->prepare('SELECT codigo, password_hash FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Credenciales inválidas.';
        } else {
            // Autenticación correcta
            $_SESSION['client_code'] = $row['codigo'];
            // Si el código es "admin", marcar como rol admin
            $_SESSION['is_admin'] = ($row['codigo'] === 'admin');
            header('Location: index.php');
            exit;
        }
    }
}

// Obtener lista de clientes para el desplegable
$clients = $centralDb->query('SELECT codigo, nombre FROM control_clientes WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso a la App</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="login-container">
    <h1>Gestión Documental</h1>
    <p>Selecciona tu cliente e ingresa la contraseña.</p>
    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="codigo">Cliente</label>
        <select name="codigo" id="codigo" required>
            <option value="">Seleccione un cliente...</option>
            <?php foreach ($clients as $cli): ?>
                <option value="<?= htmlspecialchars($cli['codigo']) ?>">
                    <?= htmlspecialchars($cli['nombre']) ?> (<?= htmlspecialchars($cli['codigo']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <label for="password">Contraseña</label>
        <input type="password" name="password" id="password" required>
        <button type="submit">Ingresar</button>
    </form>
</div>
</body>
</html>