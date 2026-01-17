<?php
/**
 * Login Page - KINO TRACE
 *
 * Authentication page with modern minimalist design.
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
        $stmt = $centralDb->prepare('SELECT codigo, password_hash FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Credenciales inválidas.';
        } else {
            $_SESSION['client_code'] = $row['codigo'];
            $_SESSION['is_admin'] = ($row['codigo'] === 'admin');
            header('Location: index.php');
            exit;
        }
    }
}

$clients = $centralDb->query('SELECT codigo, nombre FROM control_clientes WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">K</div>
                <h1 class="login-title">KINO TRACE</h1>
                <p class="login-subtitle">Gestión Documental</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label class="form-label" for="codigo">Cliente</label>
                    <select name="codigo" id="codigo" class="form-select" required>
                        <option value="">Seleccione un cliente...</option>
                        <?php foreach ($clients as $cli): ?>
                            <option value="<?= htmlspecialchars($cli['codigo']) ?>">
                                <?= htmlspecialchars($cli['nombre']) ?> (<?= htmlspecialchars($cli['codigo']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" name="password" id="password" class="form-input" required
                        placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    Ingresar
                </button>
            </form>
        </div>
    </div>

    <footer class="app-footer" style="position: fixed; bottom: 0; left: 0; right: 0; background: transparent;">
        Elaborado por <a href="#">KINO GENIUS</a>
    </footer>
</body>

</html>