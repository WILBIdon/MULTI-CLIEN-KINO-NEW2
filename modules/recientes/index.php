<?php
/**
 * Doc Recientes - Recent Documents
 *
 * Shows the most recently added/modified documents across all types.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

// Get recent documents
$recentDocs = $db->query("
    SELECT d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.fecha_creacion, d.ruta_archivo,
           (SELECT COUNT(*) FROM codigos WHERE documento_id = d.id) as code_count
    FROM documentos d
    ORDER BY d.fecha_creacion DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// For sidebar
$currentModule = 'recientes';
$baseUrl = '../../';
$pageTitle = 'Documentos Recientes';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doc Recientes - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Últimos 50 Documentos</h3>
                        <span class="badge badge-primary">
                            <?= count($recentDocs) ?> documentos
                        </span>
                    </div>

                    <?php if (empty($recentDocs)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <h4 class="empty-state-title">Sin documentos recientes</h4>
                            <p class="empty-state-text">Los documentos que subas aparecerán aquí.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Número</th>
                                        <th>Fecha Doc</th>
                                        <th>Proveedor</th>
                                        <th>Códigos</th>
                                        <th>Subido</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentDocs as $doc): ?>
                                        <tr>
                                            <td><span class="badge badge-primary">
                                                    <?= strtoupper($doc['tipo']) ?>
                                                </span></td>
                                            <td>
                                                <?= htmlspecialchars($doc['numero']) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($doc['fecha']) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($doc['proveedor'] ?: '-') ?>
                                            </td>
                                            <td><span class="code-tag">
                                                    <?= $doc['code_count'] ?>
                                                </span></td>
                                            <td style="font-size: 0.75rem; color: var(--text-secondary);">
                                                <?= date('d/m/Y H:i', strtotime($doc['fecha_creacion'])) ?>
                                            </td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <a href="../documento/view.php?id=<?= $doc['id'] ?>"
                                                        class=" btn btn-secondary btn-icon" title="Ver documento">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </a>
                                                    <?php if ($doc['ruta_archivo']):
                                                        // Construir ruta correcta del PDF
                                                        $pdfRuta = $doc['ruta_archivo'];
                                                        if (strpos($pdfRuta, '/') === false) {
                                                            $pdfRuta = $doc['tipo'] . '/' . $pdfRuta;
                                                        }
                                                        ?>
                                                        <a href="../../clients/<?= $code ?>/uploads/<?= $pdfRuta ?>" target="_blank"
                                                            class="btn btn-primary btn-icon" title="Ver PDF">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                            </svg>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>
</body>

</html>