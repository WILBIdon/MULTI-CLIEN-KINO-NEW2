<?php
/**
 * Página para validar códigos asociados a documentos.
 *
 * Permite marcar cada código como validado o pendiente. Los cambios se
 * reflejan directamente en la base de datos del cliente.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $val = (int) ($_POST['validado'] ?? 0);
        if ($codeId) {
            $stmt = $db->prepare('UPDATE codigos SET validado = ? WHERE id = ?');
            $stmt->execute([$val, $codeId]);
            $message = 'Código actualizado.';
        }
    }
} catch (Exception $ex) {
    $error = 'Error: ' . $ex->getMessage();
}

// Obtener códigos y sus documentos asociados
$rows = $db->query("SELECT codigos.id AS id, codigos.codigo AS codigo_text, codigos.descripcion AS descripcion, codigos.validado, documentos.tipo, documentos.numero FROM codigos JOIN documentos ON codigos.documento_id = documentos.id ORDER BY documentos.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Validar Códigos</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
<style>
.container { max-width: 800px; margin: 2rem auto; padding: 1rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
th, td { padding: 0.5rem; border: 1px solid #e5e7eb; text-align: left; }
button { padding: 0.25rem 0.5rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
.btn-valid { background: #22c55e; color: #ffffff; }
.btn-invalid { background: #ef4444; color: #ffffff; }
.message, .error { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
.message { background: #dcfce7; color: #065f46; }
.error { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="container">
    <h1>Validar Códigos</h1>
    <?php if ($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <table>
        <thead>
            <tr><th>Documento</th><th>Código</th><th>Descripción</th><th>Estado</th><th>Acción</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['tipo']) ?> Nº<?= htmlspecialchars($r['numero']) ?></td>
                    <td><?= htmlspecialchars($r['codigo_text']) ?></td>
                    <td><?= htmlspecialchars($r['descripcion'] ?? '') ?></td>
                    <td><?= $r['validado'] ? 'Validado' : 'Pendiente' ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="code_id" value="<?= $r['id'] ?>">
                            <?php if ($r['validado']): ?>
                                <input type="hidden" name="validado" value="0">
                                <button type="submit" class="btn-invalid">Marcar pendiente</button>
                            <?php else: ?>
                                <input type="hidden" name="validado" value="1">
                                <button type="submit" class="btn-valid">Validar</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p><a href="dashboard.php">← Volver al tablero</a></p>
</div>
</body>
</html>