<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verificar que el usuario esté autenticado
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];

// Abrir la base de datos del cliente
$db = open_client_db($clientCode);

// Recuperar los manifiestos de la tabla documentos
$stmt = $db->prepare('SELECT id, numero, fecha, proveedor, ruta_archivo FROM documentos WHERE tipo = ? ORDER BY fecha DESC');
$stmt->execute(['manifiesto']);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifiestos</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body>
<div class="container">
    <h1>📦 Manifiestos</h1>
    <p>
        <a href="../trazabilidad/dashboard.php">🏠 Tablero</a> |
        <a href="../declaraciones/list.php">📄 Declaraciones</a> |
        Bienvenido, <?= htmlspecialchars($clientCode) ?> |
        <a href="../../logout.php">Cerrar sesión</a>
    </p>
    <a href="upload.php" class="btn btn-primary">➕ Subir Manifiesto</a>
    <?php if (empty($docs)): ?>
        <p>No hay manifiestos registrados.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $doc): ?>
                    <tr>
                        <td><?= htmlspecialchars($doc['numero']) ?></td>
                        <td><?= htmlspecialchars($doc['fecha']) ?></td>
                        <td><?= htmlspecialchars($doc['proveedor'] ?? '-') ?></td>
                        <td>
                            <a href="view.php?id=<?= $doc['id'] ?>" class="btn btn-success">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>