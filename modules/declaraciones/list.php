<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$stmt = $db->prepare('SELECT id, numero, fecha, proveedor FROM documentos WHERE tipo = ? ORDER BY fecha DESC');
$stmt->execute(['declaracion']);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Declaraciones</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body>
<div class="container">
    <h1>📄 Declaraciones</h1>
    <p>
        <a href="../trazabilidad/dashboard.php">🏠 Tablero</a> |
        <a href="../manifiestos/list.php">📦 Manifiestos</a> |
        Bienvenido, <?= htmlspecialchars($clientCode) ?> |
        <a href="../../logout.php">Cerrar sesión</a>
    </p>
    <a href="upload.php" class="btn btn-primary">➕ Subir Declaración</a>
    <?php if (empty($docs)): ?>
        <p>No hay declaraciones registradas.</p>
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